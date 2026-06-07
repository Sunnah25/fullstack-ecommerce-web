<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'vendor/autoload.php';
require_once 'config.php';
include 'includes/db.php';

// ── BLOCK DIRECT/REPEAT ACCESS ───────────────────
if (empty($_GET['session_id'])) {
    header('Location: /home');
    exit();
}

$stripeSessionId = $_GET['session_id'];

// Check if already processed
$alreadyProcessed = mysqli_fetch_row(
    mysqli_query(
        $conn,
        "SELECT COUNT(*) FROM orders
         WHERE stripe_session_id = '"
            . mysqli_real_escape_string(
                $conn,
                $stripeSessionId
            )
            . "' AND status != 'pending_payment'"
    )
);

if ($alreadyProcessed[0] > 0) {
    $pageTitle = "Thank You — " . SHOP_NAME;
    include 'includes/header.php';
?>
    <div class="confirmation-page">
        <div class="confirmation-box">
            <div class="confirmation-icon">🌸</div>
            <h1>Thank You!</h1>
            <p>Your order has been confirmed.
                Please check your email for
                your order confirmation.</p>
            <div class="confirmation-actions">
                <a href="/products"
                    class="btn-primary">
                    Continue Shopping
                </a>
            </div>
        </div>
    </div>
<?php
    include 'includes/footer.php';
    exit();
}

// ── PROCESS PAYMENT ──────────────────────────────
$orderId = null;

try {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    $stripeSession = \Stripe\Checkout\Session
        ::retrieve($stripeSessionId);

    if ($stripeSession->payment_status === 'paid') {
        if (!isset($stripeSession->metadata->order_id)) {
            error_log("Stripe session $stripeSessionId
                has no order_id in metadata.");
            header('Location: /home');
            exit();
        }

        $orderId = intval(
            $stripeSession->metadata->order_id
        );

        // Verify this session ID matches our DB record
        $dbCheck = mysqli_fetch_row(mysqli_query(
            $conn,
            "SELECT COUNT(*) FROM orders
             WHERE id = $orderId
             AND stripe_session_id = '"
                . mysqli_real_escape_string(
                    $conn,
                    $stripeSessionId
                ) . "'"
        ));

        if (!$dbCheck || $dbCheck[0] === 0) {
            error_log("Stripe session $stripeSessionId
                does not match order $orderId in DB.");
            header('Location: /home');
            exit();
        }

        // Update order to paid — only if still pending
        $paymentIntentId = mysqli_real_escape_string(
            $conn,
            $stripeSession->payment_intent ?? ''
        );

        mysqli_query(
            $conn,
            "UPDATE orders SET
       status             = 'paid',
       stripe_session_id  = '"
                . mysqli_real_escape_string(
                    $conn,
                    $stripeSessionId
                ) . "',
       payment_intent_id  = '$paymentIntentId'
     WHERE id = $orderId
     AND status = 'pending_payment'"
        );

        // Only process if genuinely new payment
        if (mysqli_affected_rows($conn) > 0) {

            // ── Reduce stock (atomic + race-safe) ──
            // Single loop does everything:
            // 1. Only reduces if stock >= qty (atomic)
            // 2. Prevents negative stock
            // 3. Logs oversell if it happens

            mysqli_begin_transaction($conn);
            try {
                $items = mysqli_query(
                    $conn,
                    "SELECT * FROM order_items
                     WHERE order_id = $orderId"
                );

                while ($item = mysqli_fetch_assoc($items)) {
                    $pid = intval($item['product_id']);
                    $qty = intval($item['quantity']);

                    mysqli_query(
                        $conn,
                        "UPDATE products
                         SET stock = stock - $qty
                         WHERE id = $pid
                         AND stock >= $qty"
                    );

                    if (mysqli_affected_rows($conn) === 0) {
                        mysqli_query(
                            $conn,
                            "UPDATE products
                             SET stock = 0
                             WHERE id = $pid
                             AND stock > 0"
                        );

                        error_log("OVERSELL WARNING:
                            Product $pid on order $orderId
                            qty $qty — stock ran out
                            during checkout");
                    }
                }

                mysqli_commit($conn);
            } catch (Exception $stockEx) {
                mysqli_rollback($conn);
                error_log("Stock update failed for
                    order $orderId: "
                    . $stockEx->getMessage());
            }

            // Send confirmation email ONCE
            require_once 'includes/mailer.php';
            sendOrderConfirmation($conn, $orderId);

            require_once 'includes/logger.php';

            // Log successful payment
            logPayment(
                $conn,
                $orderId,
                'checkout_completed',
                'success',
                $stripeSession->amount_total / 100,
                $stripeSessionId,
                'Payment confirmed via Stripe'
            );

            // Clear cart
            $_SESSION['cart'] = [];
        }
    }
} catch (Exception $e) {
    require_once 'includes/logger.php';
    logError(
        'payment_success',
        $e->getMessage()
    );
    logPayment(
        $conn,
        0,
        'checkout_error',
        'failed',
        0,
        $stripeSessionId ?? '',
        $e->getMessage()
    );
    header('Location: /home');
    exit();
}

if (!$orderId) {
    header('Location: /home');
    exit();
}

$pageTitle = "Order Confirmed — " . SHOP_NAME;
include 'includes/header.php';
?>

<div class="confirmation-page">
    <div class="confirmation-box">
        <div class="confirmation-icon">🌸</div>
        <h1>Payment Successful!</h1>
        <p>Your order <strong>#<?php echo $orderId; ?></strong>
            is confirmed.</p>
        <p>A confirmation email has been sent
            to your email address.</p>
        <p>Thank you for shopping with
            <?php echo SHOP_NAME; ?>! 💛</p>
        <div class="confirmation-actions">
            <a href="/products"
                class="btn-primary">
                Continue Shopping
            </a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>