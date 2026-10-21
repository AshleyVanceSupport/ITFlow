<?php

/*
 * guest_ajax.php
 * Similar to post.php/ajax.php, but for unauthenticated requests using Asynchronous JavaScript
 * Always returns data in JSON format, unless otherwise specified
 */

require_once "../config.php";

// Set Timezone
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once "../libs/totp/totp.php";


/*
 * Creates & Returns a Stripe Payment Intent for a particular invoice ID
 */

if (isset($_GET['stripe_create_pi'])) {

    header('Content-Type: application/json');

    // Params from POST (guest_pay_invoice_stripe.js)
    $jsonStr = file_get_contents('php://input');
    $jsonObj = json_decode($jsonStr, true);
    $invoice_id = intval($jsonObj['invoice_id']);
    $url_key = escapeSql($jsonObj['url_key']);

    // Query invoice details
    $invoice_sql = mysqli_query(
        $mysqli,
        "SELECT client_id, client_name, invoice_amount, invoice_currency_code, invoice_number,
            invoice_prefix FROM invoices
        LEFT JOIN clients ON invoice_client_id = client_id
        WHERE invoice_id = $invoice_id
        AND invoice_url_key = '$url_key'
        AND invoice_status NOT IN ('Draft','Paid','Cancelled')
        LIMIT 1"
    );
    if (!$invoice_sql || mysqli_num_rows($invoice_sql) !== 1) {
        exit(json_encode(['error' => 'Invalid Invoice ID/SQL query']));
    }

    $row = mysqli_fetch_assoc($invoice_sql);
    $invoice_prefix = escapeHtml($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $invoice_amount = floatval($row['invoice_amount']);
    $invoice_currency_code = escapeHtml($row['invoice_currency_code']);
    $client_id = intval($row['client_id']);
    $client_name = escapeHtml($row['client_name']);

    // Add up all the payments for the invoice and get the total amount paid to the invoice
    $sql_amount_paid = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS amount_paid FROM payments WHERE payment_invoice_id = $invoice_id");
    $row_amt = mysqli_fetch_assoc($sql_amount_paid);
    $amount_paid = floatval($row_amt['amount_paid']);
    $balance_to_pay = $invoice_amount - $amount_paid;

    $balance_to_pay = round($balance_to_pay, 2);

    // In cents, so a balance under 1.00 can still be paid
    if (round($balance_to_pay * 100) <= 0) {
        exit(json_encode(['error' => 'No balance outstanding']));
    }

    // Setup Stripe from payment_providers - switched on, and the invoice under its threshold (as the Pay button)
    $stripe_provider = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT payment_provider_id, payment_provider_private_key, payment_provider_threshold FROM payment_providers WHERE payment_provider_name = 'Stripe' AND payment_provider_active = 1 LIMIT 1"));
    if (!$stripe_provider) {
        exit(json_encode(['error' => 'Stripe not enabled / configured']));
    }
    $stripe_threshold = floatval($stripe_provider['payment_provider_threshold']);
    if ($stripe_threshold > 0 && $stripe_threshold <= $invoice_amount) {
        exit(json_encode(['error' => 'Invoice is over the online payment limit']));
    }
    $stripe_provider_id = intval($stripe_provider['payment_provider_id']);
    $stripe_secret_key = $stripe_provider['payment_provider_private_key'];

    require_once '../includes/stripe_init.php';

    // Get client's Stripe customer ID
    $stripe_customer_query = mysqli_query($mysqli, "
        SELECT payment_provider_client FROM client_payment_provider
        WHERE client_id = $client_id AND payment_provider_id = $stripe_provider_id
        LIMIT 1
    ");
    $stripe_customer = mysqli_fetch_assoc($stripe_customer_query);
    $stripe_customer_id = $stripe_customer ? escapeSql($stripe_customer['payment_provider_client']) : null;

    $pi_description = "ITFlow: $client_name payment of $invoice_currency_code $balance_to_pay for $invoice_prefix$invoice_number";

    try {
        \Stripe\Stripe::setApiKey($stripe_secret_key);

        $paymentIntent = \Stripe\PaymentIntent::create([
            'amount' => intval(round($balance_to_pay * 100)), // Stripe expects cents
            'currency' => $invoice_currency_code,
            'description' => $pi_description,
            'metadata' => [
                'itflow_client_id' => $client_id,
                'itflow_client_name' => $client_name,
                'itflow_invoice_number' => $invoice_prefix . $invoice_number,
                'itflow_invoice_id' => $invoice_id,
            ],
            'payment_method_types' => ['card'],
            'customer' => $stripe_customer_id,
        ]);

        $output = [
            'clientSecret' => $paymentIntent->client_secret,
        ];

        echo json_encode($output);

    } catch (\Throwable $e) {
        http_response_code(500);
        error_log("Stripe PI create failed (invoice $invoice_id): " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }

    exit;
}

/*
 * Returns the current TOTP code for a shared credential
 * Authenticated via the share ID + key, same as guest_view_item.php
 */
if (isset($_GET['get_share_totp_token'])) {

    header('Content-Type: application/json');

    $item_id = intval($_GET['id']);
    $item_key = escapeSql($_GET['key']);

    // Active + expiry checks apply, so revoking the share stops codes immediately.
    //  BINARY so the type matches exactly, as the agent-side permission check does.
    $sql = mysqli_query($mysqli, "SELECT item_related_id, item_client_id, item_views, item_view_limit FROM shared_items WHERE item_id = $item_id AND item_key = '$item_key' AND item_type = BINARY 'Credential' AND item_active = 1 AND item_expire_at > NOW() LIMIT 1");

    if (!$sql || mysqli_num_rows($sql) !== 1) {
        exit(json_encode(['error' => 'invalid']));
    }

    $row = mysqli_fetch_assoc($sql);

    // The page view already used up the last view, so codes keep rotating for a short
    //  while after it (the view is logged with the share as its entity), then stop
    if (intval($row['item_view_limit']) > 0 && intval($row['item_views']) >= intval($row['item_view_limit'])) {
        $recent_view = mysqli_query($mysqli, "SELECT log_id FROM logs WHERE log_type = 'Share' AND log_action = 'View' AND log_entity_id = $item_id AND log_created_at > NOW() - INTERVAL 10 MINUTE LIMIT 1");
        if (!$recent_view || mysqli_num_rows($recent_view) !== 1) {
            exit(json_encode(['error' => 'invalid']));
        }
    }
    $credential_id = intval($row['item_related_id']);
    $client_id = intval($row['item_client_id']);

    $credential_sql = mysqli_query($mysqli, "SELECT credential_otp_secret FROM credentials WHERE credential_id = $credential_id AND credential_client_id = $client_id LIMIT 1");

    if (!$credential_sql || mysqli_num_rows($credential_sql) !== 1) {
        exit(json_encode(['error' => 'invalid']));
    }

    // Tidied the same way as the agent side - seeds saved with spaces or as an otpauth:// link still work
    $totp_secret = credentialOtpSeed(mysqli_fetch_assoc($credential_sql)['credential_otp_secret']);

    if (empty($totp_secret)) {
        exit(json_encode(['error' => 'invalid']));
    }

    echo json_encode([
        'token' => TokenAuth6238::getTokenCode($totp_secret),
        'expires_in' => 30 - (time() % 30)
    ]);
    exit;
}
