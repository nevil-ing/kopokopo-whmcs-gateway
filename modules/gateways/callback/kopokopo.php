<?php
/**
 * KopoKopo Gateway Callback & Webhook
 */

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'kopokopo';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams) {
    http_response_code(200);
    echo 'Gateway not active';
    exit;
}

function kk_log($title, $data, $status = 'Info') {
    logTransaction('KopoKopo', is_array($data) ? print_r($data, true) : $data, $status);
}

function kk_resolve_hosts($baseUrl, $environment) {
    $env = strtolower((string)$environment);
    if ($env === 'production') {
        return [
            'oauth' => 'https://app.kopokopo.com',
            'api'   => 'https://api.kopokopo.com',
        ];
    }
    return [
        'oauth' => 'https://sandbox.kopokopo.com',
        'api'   => 'https://sandbox.kopokopo.com',
    ];
}

function kk_get_access_token($baseUrl, $clientId, $clientSecret, $environment = 'sandbox') {
    $hosts = kk_resolve_hosts($baseUrl, $environment);
    $url = rtrim($hosts['oauth'], '/') . '/oauth/token';
    $payload = http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
    ]);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: WHMCS-Kopokopo/1.0'
        ],
    ];
    if (strtolower((string)$environment) !== 'production') {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = false;
    }
    curl_setopt_array($ch, $opts);

    $maxRetries = 3;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        kk_log("Token Attempt $attempt", ['url'=>$url,'code'=>$code,'err'=>$err], $err ? 'Failure' : 'Info');
        if (!$err && $code === 200) {
            break;
        }
        if ($attempt < $maxRetries && in_array($code, [502,503,504])) {
            sleep(2);
            continue;
        }
        break;
    }

    curl_close($ch);

    if (!isset($resp) || $err || $code !== 200) {
        kk_log('Token Final Error', ['code'=>$code,'resp'=>substr($resp ?? '', 0, 500),'err'=>$err], 'Failure');
        return false;
    }

    $json = json_decode($resp, true);
    return $json['access_token'] ?? false;
}

function kk_format_kopo_phone($phone) {
    $clean = preg_replace('/\D+/', '', (string)$phone);
    if (substr($clean, 0, 3) === '254') {
        return '+' . $clean;
    }
    if (substr($clean, 0, 1) === '0') {
        return '+254' . substr($clean, 1);
    }
    return '+254' . substr($clean, -9);
}

function kk_initiate_incoming_payment($baseUrl, $token, $payload, $environment = 'sandbox') {
    $hosts = kk_resolve_hosts($baseUrl, $environment);
    $url = rtrim($hosts['api'], '/') . '/api/v1/incoming_payments';
    kk_log('Incoming Payment Req', ['url'=>$url,'payload'=>$payload]);
    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: WHMCS-Kopokopo/1.0'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ];
    if (strtolower((string)$environment) !== 'production') {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = false;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    kk_log('Incoming Payment HTTP', ['code'=>$code, 'resp'=>$resp, 'err'=>$err]);
    if ($err || $resp === false) return [ 'error' => 'curl_error', 'message' => $err ];
    $json = json_decode($resp, true);
    if (!in_array($code, [200,201,202])) {
        return [ 'error' => 'http_error', 'code' => $code, 'response' => $json ?: $resp ];
    }
    return $json ?: ['ok'=>true];
}

// JSON webhook handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['webhook'])) {
    $payload = file_get_contents('php://input');
    $data = json_decode($payload, true);

    // Optional signature verification can go here (using $gatewayParams['webhookSecret'])

    if (!$data) {
        kk_log('Webhook invalid JSON', $payload, 'Failure');
        http_response_code(400);
        echo 'Invalid JSON';
        exit;
    }

    $invoiceId = null; $status = null; $amount = null; $txnId = null; $receipt = null;

    if (isset($data['data']['attributes'])) {
        $attr = $data['data']['attributes'];
        $status = strtolower($attr['status'] ?? '');
        if (isset($attr['amount']['value'])) {
            $amount = $attr['amount']['value'];
        } elseif (isset($attr['event']['resource']['amount'])) {
            $amount = $attr['event']['resource']['amount'];
        }
        // Try to extract a receipt/reference (M-Pesa code) for manual payments mapping
        if (isset($attr['mpesa_receipt'])) {
            $receipt = strtoupper(trim($attr['mpesa_receipt']));
        } elseif (isset($attr['reference']) && !is_numeric($attr['reference'])) {
            $receipt = strtoupper(trim($attr['reference']));
        } elseif (isset($attr['event']['resource']['reference'])) {
            $receipt = strtoupper(trim($attr['event']['resource']['reference']));
        }
        if (isset($attr['metadata']['invoice_id'])) {
            $invoiceId = (int)$attr['metadata']['invoice_id'];
        } elseif (isset($attr['reference']) && is_numeric($attr['reference'])) {
            $invoiceId = (int)$attr['reference'];
        }
        $txnId = $data['data']['id'] ?? ($attr['transaction_id'] ?? '');
    } else {
        $reference = $data['reference'] ?? '';
        $status    = strtolower($data['status'] ?? '');
        $amount    = $data['amount'] ?? 0;
        $txnId     = $data['transaction_id'] ?? ($data['id'] ?? '');
        // If reference looks like a receipt (alphanumeric) store as receipt; if numeric, treat as invoice id
        if ($reference !== '' && !ctype_digit((string)$reference)) {
            $receipt = strtoupper(trim($reference));
        } else {
            $invoiceId = (int)$reference;
        }
    }

    // If invoiceId not present but we have a receipt code, try to map via manual submissions table
    if (!$invoiceId && $receipt) {
        try {
            $map = Capsule::table('mod_manual_payments')
                ->whereRaw('UPPER(tx_code) = ?', [$receipt])
                ->orderBy('id', 'desc')
                ->first();
            if ($map) {
                $invoiceId = (int)$map->invoiceid;
            }
        } catch (\Throwable $e) {
            // log and continue
            kk_log('Manual map lookup failed', $e->getMessage(), 'Failure');
        }
    }

    if (!$invoiceId || !$txnId) {
        kk_log('Webhook missing fields after mapping', ['data'=>$data,'receipt'=>$receipt], 'Failure');
        http_response_code(400);
        echo 'Missing fields';
        exit;
    }

    try {
        checkCbInvoiceID($invoiceId, $gatewayModuleName);
        checkCbTransID($txnId);

        if (in_array($status, ['success','completed','paid'])) {
            addInvoicePayment(
                $invoiceId,
                $txnId,
                (float)$amount,
                0.00,
                $gatewayModuleName
            );
            kk_log('Payment applied', ['invoice' => $invoiceId, 'amount' => $amount, 'txn' => $txnId], 'Success');
            echo 'OK';
            exit;
        } else {
            kk_log('Webhook non-success status', $data, 'Info');
            echo 'IGNORED';
            exit;
        }
    } catch (\Throwable $e) {
        kk_log('Webhook exception', $e->getMessage(), 'Failure');
        http_response_code(200);
        echo 'ERR';
        exit;
    }
}

// STK initiation from invoice page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'initiate') {
    $invoiceId = (int)($_POST['invoiceid'] ?? 0);
    $amountStr = $_POST['amount'] ?? '0.00';
    $currency  = $_POST['currency'] ?? 'KES';
    $msisdn    = trim($_POST['msisdn'] ?? '');
    $till      = trim($_POST['till'] ?? '');

    if (!$invoiceId || !$msisdn) {
        echo '<p>Missing invoice or mobile number.</p>';
        exit;
    }

    $baseUrl  = $gatewayParams['apiBaseUrl'];
    $clientId = $gatewayParams['clientId'];
    $secret   = $gatewayParams['clientSecret'];

    $token = kk_get_access_token($baseUrl, $clientId, $secret, ($gatewayParams['environment'] ?? 'sandbox'));
    if (!$token) {
        kk_log('Token error', 'Could not obtain access token', 'Failure');
        echo '<p>Could not initiate payment at this time. Please try again later.</p>';
        exit;
    }

    $callbackUrl = rtrim($gatewayParams['systemurl'], '/') . '/modules/gateways/callback/kopokopo.php?webhook=1';

    $cur = strtoupper($currency);
    if ($cur === 'KSH') $cur = 'KES';
    $amountInt = (int) round((float)$amountStr);

    $clientRow = Capsule::table('tblinvoices')
        ->join('tblclients', 'tblinvoices.userid', '=', 'tblclients.id')
        ->where('tblinvoices.id', $invoiceId)
        ->select('tblclients.firstname', 'tblclients.lastname', 'tblclients.email')
        ->first();

    $firstName = $clientRow->firstname ?? 'Customer';
    $lastName  = $clientRow->lastname ?? 'Payment';
    $email     = $clientRow->email ?? 'noemail@example.com';

    $payload = [
        'payment_channel' => 'M-PESA STK Push',
        'till_number'     => $till ?: ($gatewayParams['tillNumber'] ?? ''),
        'subscriber'      => [
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'phone_number' => kk_format_kopo_phone($msisdn),
            'email'        => $email,
        ],
        'amount'          => [
            'currency' => $cur,
            'value'    => $amountInt,
        ],
        'metadata'        => [
            'invoice_id' => (string)$invoiceId,
        ],
        '_links'          => [
            'callback_url' => $callbackUrl,
        ],
    ];

    $resp = kk_initiate_incoming_payment($baseUrl, $token, $payload, ($gatewayParams['environment'] ?? 'sandbox'));
    kk_log('Incoming Payment Resp', $resp ?: 'No response');

    echo '<div style="font-family:Arial,sans-serif;max-width:500px;margin:30px auto;">';
    if ($resp && !isset($resp['error'])) {
        echo '<h3>Payment Request Sent</h3>';
        echo '<p>An M-Pesa prompt has been sent to ' . htmlspecialchars($msisdn) . '. Enter your PIN to complete payment.</p>';
        echo '<p>Invoice: #' . (int)$invoiceId . ' Amount: ' . htmlspecialchars($amountInt) . ' ' . htmlspecialchars($cur) . '</p>';
        echo '<p><a href="' . htmlspecialchars($gatewayParams['systemurl']) . 'viewinvoice.php?id=' . (int)$invoiceId . '">Return to Invoice</a></p>';
        echo '<script>setTimeout(function(){window.location.href = ' . json_encode($gatewayParams['systemurl'] . 'viewinvoice.php?id=' . (int)$invoiceId . '&payinit=1') . ';}, 3000);</script>';
    } else {
        $msg = 'Please try again or contact support.';
        if (isset($resp['response'])) {
            $msg .= ' Details: ' . htmlspecialchars(is_array($resp['response']) ? json_encode($resp['response']) : (string)$resp['response']);
        }
        echo '<h3>Unable to Initiate Payment</h3>';
        echo '<p>' . $msg . '</p>';
        echo '<p><a href="' . htmlspecialchars($gatewayParams['systemurl']) . 'viewinvoice.php?id=' . (int)$invoiceId . '">Back to Invoice</a></p>';
    }
    echo '</div>';
    exit;
}

http_response_code(200);
header('Content-Type: text/plain');
echo 'KopoKopo callback is online';
