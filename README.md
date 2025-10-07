# KopoKopo (M-Pesa) Payment Gateway for WHMCS

A production-ready M-Pesa payment gateway for WHMCS using KopoKopo APIs.

*Built and tested with mpesa till payment

Features:
- STK Push from the WHMCS invoice page (customer receives M-Pesa prompt)
- Webhook callback to automatically mark invoices as Paid
- Optional Manual Payment capture (record M-Pesa transaction codes)
- Manual codes are linked to webhooks: when KopoKopo notifies a payment, the module matches the receipt code to the submitted code and marks the invoice paid
- Sandbox/Production environments

## Requirements
- WHMCS 8.0+ (tested on 8.x)
- PHP 7.4+ (or version matching your WHMCS)
- A KopoKopo account with API credentials
- Public HTTPS URL for webhooks

## Installation
1) Unzip the package into your WHMCS root so paths look like:

   - modules/gateways/kopokopo.php
   - modules/gateways/callback/kopokopo.php
   - modules/gateways/callback/kopokopo_manual.php

2) In WHMCS Admin, go to: Setup > Payments > Payment Gateways
   - Activate "KopoKopo (M-Pesa STK Push)"
   - Configure the following:
     - Environment: sandbox or production
     - API Base URL: e.g., https://sandbox.kopokopo.com
     - Client ID / Client Secret: from your KopoKopo app
     - STK Till/Paybill: used for STK Push
     - Manual Till/Paybill (optional): shown in manual payment instructions (leave empty to reuse STK Till)
     - Webhook Secret (optional): if you implement signature verification

3) Configure your KopoKopo Webhook (or the callback URL in your app settings) to POST to:

   https://YOUR-WHMCS-DOMAIN/modules/gateways/callback/kopokopo.php?webhook=1

4) Test the flow in Sandbox first.

## Usage
- On any unpaid invoice, the gateway shows a phone number field.
- Customer enters their M-Pesa mobile (07xxxxxxxx or 2547xxxxxxxx) and clicks Pay.
- The module sends an STK Push request. Customer receives a prompt and confirms with PIN.
- KopoKopo posts a webhook to the callback URL, and the invoice is marked Paid on success.

### Manual Payment (optional)
- On the invoice page, a "Manual Payment" panel allows customers to submit their M-Pesa transaction code.
- The code is recorded (and shown in invoice notes). When KopoKopo sends the payment webhook (with the M-Pesa receipt/reference), the module matches it to the submitted code and automatically marks the invoice as Paid.
- This links manual payments to the callback, so there is no immediate apply without provider confirmation.

## Security Notes
- Do not disable SSL verification in production. The module leaves SSL verification enabled for production endpoints.
- Keep your Client Secret safe. Configure via WHMCS gateway settings (stored server-side).
- Manual payments are only applied when the provider webhook confirms the payment (no instant apply).

## Troubleshooting
- If invoices are not marked paid, check WHMCS Billing > Gateway Log for entries named "KopoKopo".
- Confirm the webhook URL is reachable (HTTP 200) and not blocked by firewalls.
- Some providers return different status strings; this module treats `success/completed/paid` as success.
- Ensure your server time/timezone is accurate.

## Uninstall
- Deactivate the gateway in Setup > Payments > Payment Gateways.
- Remove the files under modules/gateways/.
- Optional: drop the table `mod_manual_payments` if you no longer need stored entries.

## Support & License
- See LICENSE for terms (MIT).
- Open issues or contact the author for support.
