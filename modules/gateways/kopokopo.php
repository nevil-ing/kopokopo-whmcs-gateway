<?php
/**
 * KopoKopo Payment Gateway for WHMCS (M-Pesa STK Push)
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function kopokopo_MetaData()
{
    return [
        'DisplayName' => 'KopoKopo (M-Pesa STK Push)',
        'APIVersion'  => '1.1',
    ];
}

function kopokopo_Config()
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'KopoKopo (M-Pesa STK Push)'
        ],
        'environment' => [
            'FriendlyName' => 'Environment',
            'Type'        => 'dropdown',
            'Options'     => 'sandbox,production',
            'Default'     => 'sandbox',
            'Description' => 'Choose KopoKopo environment',
        ],
        'apiBaseUrl' => [
            'FriendlyName' => 'API Base URL',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => 'https://sandbox.kopokopo.com',
            'Description'  => 'Base URL for KopoKopo API',
        ],
        'clientId' => [
            'FriendlyName' => 'Client ID',
            'Type'         => 'text',
            'Size'         => '60',
        ],
        'clientSecret' => [
            'FriendlyName' => 'Client Secret',
            'Type'         => 'password',
            'Size'         => '60',
        ],
        'apiKey' => [
            'FriendlyName' => 'API Key (optional)',
            'Type'         => 'password',
            'Size'         => '60',
        ],
        'tillNumber' => [
            'FriendlyName' => 'Default Till/Paybill (optional)',
            'Type'         => 'text',
            'Size'         => '30',
        ],
'webhookSecret' => [
            'FriendlyName' => 'Webhook Secret (optional)',
            'Type'         => 'password',
            'Size'         => '60',
            'Description'  => 'For validating incoming webhooks if you implement signature checks',
        ],
    ];
}

function kopokopo_normalize_msisdn($raw)
{
    $n = preg_replace('/\D+/', '', (string)$raw);
    if ($n === '') return '';
    if (preg_match('/^254\d{9}$/', $n)) return $n;
    if (preg_match('/^0[17]\d{8}$/', $n)) return '254' . substr($n, 1);
    if (strlen($n) > 12 && substr($n, 0, 3) === '254') return substr($n, 0, 12);
    return $n;
}

function kopokopo_human_readable_phone($raw)
{
    $n = preg_replace('/\D+/', '', (string)$raw);
    if (preg_match('/^254([17]\d{8})$/', $n)) {
        return '0' . substr($n, 3);
    }
    return $raw;
}

function kopokopo_link($params)
{
    $invoiceId   = (int)$params['invoiceid'];
    $amount      = $params['amount'];
    $currency    = $params['currency'];
    $systemUrl   = rtrim($params['systemurl'], '/');
    $defaultTill = $params['tillNumber'];

    $clientPhone = '';
    if (!empty($params['clientdetails']['phonenumber'])) {
        $clientPhone = $params['clientdetails']['phonenumber'];
    } elseif (!empty($params['clientdetails']['phonenumberformatted'])) {
        $clientPhone = $params['clientdetails']['phonenumberformatted'];
    }
    $prefillMsisdn = htmlspecialchars(kopokopo_human_readable_phone($clientPhone));

    $callbackUrl = $systemUrl . '/modules/gateways/callback/kopokopo.php';
    $manualUrl   = $systemUrl . '/modules/gateways/callback/kopokopo_manual.php';

    $html = '<form method="post" action="' . htmlspecialchars($callbackUrl) . '" onsubmit="return kopoValidate(this)" style="max-width:520px">';
    $html .= '<input type="hidden" name="action" value="initiate">';
    $html .= '<input type="hidden" name="invoiceid" value="' . $invoiceId . '">';
    $html .= '<input type="hidden" name="amount" value="' . htmlspecialchars($amount) . '">';
    $html .= '<input type="hidden" name="currency" value="' . htmlspecialchars($currency) . '">';

    $html .= '<div style="margin:8px 0;">'
        . '<label style="display:block;margin-bottom:4px;">Mobile Number (M-Pesa):</label>'
        . '<input type="tel" name="msisdn" placeholder="e.g. 07XXXXXXXX or 2547XXXXXXXX" value="' . $prefillMsisdn . '" required style="width:100%;padding:8px;">'
        . '<small style="color:#64748b;display:block;margin-top:4px;">We prefilled this from your profile where possible.</small>'
        . '</div>';

    $html .= '<div style="margin-top:8px;">';
    $html .= '<button type="submit" class="btn btn-primary">Pay Now via M-Pesa</button>';
    $html .= '</div>';

    $html .= '<p style="margin-top:10px;font-size:12px;color:#64748b;">An M-Pesa prompt will be sent to the number above. Enter your PIN to complete payment.</p>';
    $html .= '<p style="margin-top:6px;font-size:12px;color:#64748b;">If STK Push fails or for manual payment, <a href="#" id="kopo-manual-link" style="color:#0d6efd;text-decoration:underline;">click here</a>.</p>';

    $html .= '</form>';

    $html .= \<<<HTML
<div id="kopo-manual-panel" class="card" style="max-width:720px;margin-top:12px;display:none;">
  <div class="card-header"><strong>Manual Payment (Buy Goods & Services)</strong></div>
  <div class="card-body">
    <ol class="small-text mb-3" id="kopo-manual-instructions">
      <li>Open SIM Toolkit (M-Pesa).</li>
      <li>Select Lipa na M-Pesa.</li>
      <li>Select Buy Goods and Services.</li>
      <li>Enter our Till Number: <strong><span id="kopo-till-number">••••••</span></strong>.</li>
      <li>Enter Amount: <strong>{$amount}</strong> (or full invoice amount).</li>
      <li>Confirm payment. You will receive an M-Pesa SMS with a Transaction Code.</li>
      <li>Enter the Transaction Code below for quick tracking.</li>
    </ol>
    <form id="kopo-manual-form" onsubmit="return false;" style="text-align:left;">
      <input type="hidden" name="invoiceid" value="{$invoiceId}">
      <div class="form-group">
        <label for="kopo-code">M-Pesa Transaction Code</label>
        <input type="text" class="form-control" id="kopo-code" name="code" placeholder="e.g. QBC1XYZ234" required>
      </div>
      <div class="form-group">
        <label for="kopo-phone">Payer Phone (optional)</label>
        <input type="text" class="form-control" id="kopo-phone" name="phone" placeholder="07XXXXXXXX">
      </div>
      <div class="form-group">
        <label for="kopo-name">Payer Name (optional)</label>
        <input type="text" class="form-control" id="kopo-name" name="payerName" placeholder="Your name as on M-Pesa">
      </div>
      <div class="form-group">
        <label for="kopo-amount">Amount Paid (optional)</label>
        <input type="text" class="form-control" id="kopo-amount" name="amount" placeholder="{$amount}">
      </div>
      <div class="text-right">
        <button type="submit" class="btn btn-success">Submit Transaction Code</button>
      </div>
      <div id="kopo-manual-feedback" class="mt-2 small-text"></div>
    </form>
  </div>
</div>
HTML;

    $html .= "<script>(function(){\n".
             "var panel=document.getElementById('kopo-manual-panel');\n".
             "document.addEventListener('click',function(e){var t=e.target;if(t&&t.id==='kopo-manual-link'){e.preventDefault();if(panel){panel.style.display='block';panel.scrollIntoView({behavior:'smooth',block:'start'});}}});\n".
             "var till=document.getElementById('kopo-till-number');if(till){fetch('" . addslashes($manualUrl) . "?action=config',{credentials:'same-origin'}).then(function(r){return r.ok?r.json():{};}).then(function(j){if(j&&j.till_number){till.textContent=j.till_number;}});}\n".
             "var form=document.getElementById('kopo-manual-form');if(form){form.addEventListener('submit',function(){var fd=new FormData(form);var btn=form.querySelector('button[type=submit]');var fb=document.getElementById('kopo-manual-feedback');fb.textContent='';if(btn)btn.disabled=true;fetch('" . addslashes($manualUrl) . "',{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(j){if(j&&j.success){fb.textContent='Thanks! Your transaction code was received' + (j.applied?' and applied to your invoice.':'.');fb.className='text-success small-text';form.reset();}else{fb.textContent=(j&&j.message)?j.message:'Failed to submit. Please check your code and try again.';fb.className='text-danger small-text';}}).catch(function(){fb.textContent='Network error. Please try again.';fb.className='text-danger small-text';}).finally(function(){if(btn)btn.disabled=false;});});}\n".
             "})();</script>";

    $html .= \<<<JS
<script>
function kopoValidate(f){
  let m=f.msisdn.value.trim().replace(/\D+/g,'');
  if(/^0[17]\d{8}$/.test(m) || /^254\d{9}$/.test(m)) return true;
  alert('Enter a valid Kenyan mobile number e.g. 07XXXXXXXX or 2547XXXXXXXX');
  return false;
}
</script>
JS;

    return $html;
}
