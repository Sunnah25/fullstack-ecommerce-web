<?php
require_once 'config.php';
include 'includes/db.php';
include 'includes/order_status.php';

// ── Only accept POST ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// ── Get raw payload ──────────────────────────────
$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE']
          ?? '';

if (empty($payload) || empty($sigHeader)) {
    http_response_code(400);
    exit('Bad request.');
}

// ── Verify Stripe signature ──────────────────────
// This is the CRITICAL security check
// Without this anyone can fake a payment
$webhookSecret = getenv('STRIPE_WEBHOOK_SECRET')
              ?: '';

if (empty($webhookSecret)) {
    // Log warning but don't crash on localhost
    // MUST be set on live server
    error_log("WARNING: STRIPE_WEBHOOK_SECRET
               not set. Webhook unverified!");
} else {
    require_once 'vendor/autoload.php';
    try {
        $event = \Stripe\Webhook::constructEvent(
            $payload,
            $sigHeader,
            $webhookSecret
        );
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        // REJECT — invalid signature
        // This blocks fake/forged webhook requests
        http_response_code(400);
        error_log("Stripe webhook signature
                   verification FAILED: "
                 . $e->getMessage());
        exit('Invalid signature.');
    } catch (Exception $e) {
        http_response_code(400);
        exit('Webhook error.');
    }
}

// ── Parse event without signature
//    (localhost dev only) ───────────────────────
if (empty($webhookSecret)) {
    $event = json_decode($payload);
    if (!$event) {
        http_response_code(400);
        exit('Invalid JSON.');
    }
}

// ── Log webhook ──────────────────────────────────
mysqli_query($conn,
    "INSERT INTO webhook_logs
     (source, payload, processed)
     VALUES ('stripe', '"
     . mysqli_real_escape_string($conn, $payload)
     . "', 0)");

// ── Handle events ────────────────────────────────
$eventType = $event->type ?? '';

switch ($eventType) {

    case 'checkout.session.completed':
        $session = $event->data->object;
        if ($session->payment_status === 'paid') {
            $orderId = intval(
                $session->metadata->order_id ?? 0);
            if ($orderId) {
                // Only update if still pending
                // (idempotent — safe to run twice)
                $updated = mysqli_query($conn,
                    "UPDATE orders
                     SET status = 'paid'
                     WHERE id = $orderId
                     AND status = 'pending_payment'");

                if (mysqli_affected_rows($conn) > 0) {
                    error_log("Stripe webhook:
                               Order #$orderId
                               confirmed paid.");
                }
            }
        }
        break;

    case 'payment_intent.payment_failed':
        $pi      = $event->data->object;
        $piId    = $pi->id ?? '';
        if ($piId) {
            error_log("Payment failed: $piId");
        }
        break;

    case 'charge.dispute.created':
        // Customer raised a chargeback
        $dispute = $event->data->object;
        error_log("⚠️ CHARGEBACK CREATED: "
                . $dispute->id);
        // TODO: notify admin by email
        break;
}

// Mark webhook as processed
mysqli_query($conn,
    "UPDATE webhook_logs
     SET processed = 1
     WHERE source = 'stripe'
     ORDER BY id DESC LIMIT 1");

// Always return 200 to Stripe
http_response_code(200);
echo json_encode(['received' => true]);
?>