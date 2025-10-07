<?php
// Manual M-Pesa submission handler (record and optional auto-apply)

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

header('Content-Type: application/json');

$gatewayModuleName = 'kopokopo';
$gatewayParams = getGatewayVariables($gatewayModuleName);
if (!$gatewayParams) {
    echo json_encode(['success' => false, 'message' => 'Gateway not active']);
    exit;
}

function respond($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr);
    exit;
}

// Expose till number config
if (($_GET['action'] ?? '') === 'config') {
    respond([
        'success' => true,
        'till_number' => (string)($gatewayParams['tillNumber'] ?? ''),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Invalid request method'], 405);
}

$uid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
if ($uid <= 0) {
    respond(['success' => false, 'message' => 'Please log in to submit a payment.'], 401);
}

$invoiceId = isset($_POST['invoiceid']) ? (int) $_POST['invoiceid'] : 0;
$code = strtoupper(trim($_POST['code'] ?? ''));
$phone = trim($_POST['phone'] ?? '');
$payerName = trim($_POST['payerName'] ?? '');
$amount = trim($_POST['amount'] ?? '');

if ($invoiceId <= 0) {
    respond(['success' => false, 'message' => 'Missing invoice ID.']);
}
if ($code === '') {
    respond(['success' => false, 'message' => 'Transaction code is required.']);
}
if (!preg_match('/^[A-Za-z0-9]{8,15}$/', $code)) {
    respond(['success' => false, 'message' => 'Please enter a valid transaction code.']);
}

$invoice = Capsule::table('tblinvoices')
    ->select('id', 'userid', 'status', 'total', 'notes')
    ->where('id', $invoiceId)
    ->first();

if (!$invoice || (int)$invoice->userid !== $uid) {
    respond(['success' => false, 'message' => 'Invoice not found.']);
}

try {
    if (!Capsule::schema()->hasTable('mod_manual_payments')) {
        Capsule::schema()->create('mod_manual_payments', function ($table) {
            /** @var \Illuminate\Database\Schema\Blueprint $table */
            $table->increments('id');
            $table->integer('userid');
            $table->integer('invoiceid');
            $table->string('tx_code', 32);
            $table->string('phone', 32)->nullable();
            $table->string('payer_name', 128)->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('source', 32)->default('manual');
            $table->timestamps();
            $table->unique(['invoiceid', 'tx_code']);
        });
    }
} catch (\Throwable $e) {
    // continue
}

try {
    Capsule::table('mod_manual_payments')->insert([
        'userid' => $uid,
        'invoiceid' => $invoiceId,
        'tx_code' => $code,
        'phone' => $phone !== '' ? $phone : null,
        'payer_name' => $payerName !== '' ? $payerName : null,
        'amount' => is_numeric($amount) ? (float)$amount : null,
        'source' => 'manual',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
} catch (\Throwable $e) {
    respond(['success' => false, 'message' => 'This transaction code was already submitted for this invoice.']);
}

try {
    $append = "Manual payment submitted: Code=" . $code;
    if ($phone) { $append .= ", Phone=" . $phone; }
    if ($payerName) { $append .= ", Name=" . $payerName; }
    if ($amount !== '') { $append .= ", Amount=" . $amount; }
    $append .= ", Date=" . date('Y-m-d H:i');

    $newNotes = trim((string)$invoice->notes);
    if ($newNotes !== '') { $newNotes .= "\n"; }
    $newNotes .= $append;

    Capsule::table('tblinvoices')->where('id', $invoiceId)->update(['notes' => $newNotes]);
} catch (\Throwable $e) {}

// We do not apply payments here. The webhook will reconcile using the submitted transaction code.
respond(['success' => true]);
