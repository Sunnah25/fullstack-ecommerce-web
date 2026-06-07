<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'vendor/autoload.php';
require_once 'config.php';
include 'includes/db.php';
require_once 'includes/csrf.php';

// Redirect if cart is empty
if (empty($_SESSION['cart'])) {
    header('Location: ' . BASE_PATH . '/products');
    exit();
}

// Load cart items
$cartItems  = [];
$totalPrice = 0;
$ids        = implode(',', array_map(
    'intval',
    array_keys($_SESSION['cart'])
));

if ($ids) {
    $result = mysqli_query(
        $conn,
        "SELECT * FROM products
         WHERE id IN ($ids)"
    );
    while ($product = mysqli_fetch_assoc($result)) {
        $cartEntry = $_SESSION['cart'][$product['id']]
            ?? null;

        // Handle both new array format and old integer format
        if (is_array($cartEntry)) {
            if (!isset($cartEntry['qty'])) continue;
            $qty         = max(1, intval($cartEntry['qty']));
            $lockedPrice = floatval($cartEntry['price'] ?? 0);
        } elseif (is_numeric($cartEntry)) {
            // Old format fallback
            $qty         = max(1, intval($cartEntry));
            $discountPct = floatval(
                $product['discount_percent'] ?? 0
            );
            $lockedPrice = $discountPct > 0
                ? round($product['price']
                    * (1 - $discountPct / 100), 2)
                : floatval($product['price']);
        } else {
            continue;
        }

        // Ensure price is always positive
        if ($lockedPrice <= 0) {
            $discountPct = floatval(
                $product['discount_percent'] ?? 0
            );
            $lockedPrice = $discountPct > 0
                ? round($product['price']
                    * (1 - $discountPct / 100), 2)
                : floatval($product['price']);
        }

        $product['qty']          = $qty;
        $product['charge_price'] = $lockedPrice;
        $product['sale_price']   = ($lockedPrice < floatval($product['price'])) ? $lockedPrice : null;
        $product['subtotal']     = $qty * $lockedPrice;
        $totalPrice             += $product['subtotal'];
        $cartItems[]             = $product;
    }
}

$errors  = [];
$name    = '';
$email   = '';
$phone   = '';
$address = '';
$city    = '';
$postcode = '';
$notes   = '';

// ── Handle form submission ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    // Sanitise inputs
    $name     = sanitiseString(
        $conn,
        $_POST['name']     ?? '',
        150
    );
    $email    = sanitiseEmail(
        $_POST['email']    ?? ''
    );
    $phone    = sanitiseString(
        $conn,
        $_POST['phone']    ?? '',
        20
    );
    $address  = sanitiseString(
        $conn,
        $_POST['address']  ?? '',
        255
    );
    $city     = sanitiseString(
        $conn,
        $_POST['city']     ?? '',
        100
    );
    $postcode = sanitiseString(
        $conn,
        $_POST['postcode'] ?? '',
        10
    );
    $notes    = sanitiseString(
        $conn,
        $_POST['notes']    ?? '',
        500
    );

    $fullAddress = "$address, $city, $postcode";

    // Validate
    if (empty($name))
        $errors[] = "Full name is required.";
    if (empty($email))
        $errors[] = "Valid email is required.";
    if (empty($address))
        $errors[] = "Street address is required.";
    if (empty($city))
        $errors[] = "City is required.";
    if (empty($postcode))
        $errors[] = "Postcode is required.";

    // Stock check
    foreach ($cartItems as $item) {
        $pid       = intval($item['id']);
        $cartEntry = $_SESSION['cart'][$pid] ?? null;
        if (is_array($cartEntry)) {
            $qtyWanted = intval($cartEntry['qty'] ?? 0);
        } else {
            $qtyWanted = intval($cartEntry);
        }
        $stock     = mysqli_fetch_assoc(
            mysqli_query(
                $conn,
                "SELECT stock, name
             FROM products WHERE id = $pid"
            )
        );
        if ($stock && $qtyWanted > $stock['stock']) {
            if ($stock['stock'] <= 0) {
                $errors[] = htmlspecialchars(
                    $stock['name']
                )
                    . " is out of stock.";
            } else {
                $errors[] = "Only "
                    . $stock['stock'] . ' of "'
                    . htmlspecialchars($stock['name'])
                    . '" available.';
            }
        }
    }

    // ── Only proceed if no errors ────────────────
    if (empty($errors)) {

        $sessionId = mysqli_real_escape_string(
            $conn,
            session_id()
        );

        // Prevent race condition from multiple tabs
        $lock = mysqli_fetch_row(mysqli_query(
            $conn,
            "SELECT GET_LOCK('checkout_"
                . $sessionId . "', 3)"
        ));

        if (!$lock || !$lock[0]) {
            $errors[] = "Request already processing.
                         Please wait a moment.";
        } else {
            $orderId = null;

            // Check for existing pending order
            $existing = mysqli_fetch_assoc(
                mysqli_query(
                    $conn,
                    "SELECT id FROM orders
                 WHERE session_id = '$sessionId'
                 AND status = 'pending_payment'
                 AND created_at > NOW()
                     - INTERVAL 10 MINUTE
                 LIMIT 1"
                )
            );

            if ($existing) {
                $orderId = intval($existing['id']);
                mysqli_query(
                    $conn,
                    "UPDATE orders SET
                       customer_name    = '$name',
                       customer_email   = '$email',
                       customer_phone   = '$phone',
                       customer_address = '$fullAddress',
                       total_amount     = '$totalPrice'
                     WHERE id = $orderId"
                );
            } else {
                mysqli_query(
                    $conn,
                    "INSERT INTO orders
                     (customer_name, customer_email,
                      customer_phone, customer_address,
                      total_amount, status, session_id)
                     VALUES ('$name','$email','$phone',
                             '$fullAddress',
                             '$totalPrice',
                             'pending_payment',
                             '$sessionId')"
                );
                $orderId = mysqli_insert_id($conn);

                foreach ($cartItems as $item) {
                    $pid   = intval($item['id']);
                    $qty   = intval($item['qty']);
                    $chargedPrice = floatval(
                        $item['charge_price'] ?? $item['price']
                    );
                    if ($chargedPrice <= 0) {
                        $chargedPrice = floatval($item['price']);
                    }
                    mysqli_query(
                        $conn,
                        "INSERT INTO order_items
     (order_id, product_id, quantity, price)
     VALUES ('$orderId','$pid','$qty',
             '$chargedPrice')"
                    );
                }
            }

            // Release DB lock
            mysqli_query(
                $conn,
                "SELECT RELEASE_LOCK('checkout_"
                    . $sessionId . "')"
            );

            if ($orderId) {
                // Store in session
                $_SESSION['pending_order_id']    =
                    $orderId;
                $_SESSION['pending_order_email'] =
                    $email;

                // Reconnect if MySQL dropped during Stripe call
                if (!mysqli_ping($conn)) {
                    mysqli_close($conn);
                    include 'includes/db.php';
                }

                // Build Stripe line items BEFORE
                // making any HTTP call, so all DB
                // writes happen while connection is fresh
                $lineItems = [];
                foreach ($cartItems as $item) {
                    $chargePrice = floatval($item['charge_price']);
                    if ($chargePrice <= 0) {
                        $chargePrice = floatval($item['price']);
                    }
                    $isOnSale = $chargePrice < floatval($item['price']);
                    $lineItems[] = [
                        'price_data' => [
                            'currency'     => strtolower(SHOP_CURRENCY),
                            'product_data' => [
                                'name' => $item['name']
                                    . ($isOnSale
                                        ? ' (-' . intval(
                                            $item['discount_percent'] ?? 0
                                        )
                                        . '% sale)'
                                        : ''),
                            ],
                            'unit_amount'  => intval($chargePrice * 100),
                        ],
                        'quantity' => intval($item['qty']),
                    ];
                }

                // ── All DB work is done ──────────
                // Now make the Stripe HTTP call
                \Stripe\Stripe::setApiKey(
                    STRIPE_SECRET_KEY
                );
                $stripeSession =
                    \Stripe\Checkout\Session::create([
                        'payment_method_types' => ['card'],
                        'line_items'   => $lineItems,
                        'mode'         => 'payment',
                        'customer_email' => $email,
                        'success_url'  => SHOP_URL
                            . '/payment-success'
                            . '?session_id='
                            . '{CHECKOUT_SESSION_ID}',
                        'cancel_url'   => SHOP_URL
                            . '/payment-cancel',
                        'metadata'     => [
                            'order_id' => $orderId,
                        ],
                    ]);

                // Reconnect in case MySQL dropped
                // during the Stripe HTTP call
                if (!mysqli_ping($conn)) {
                    mysqli_close($conn);
                    include 'includes/db.php';
                }

                // Save Stripe session ID
                $stripeSid =
                    mysqli_real_escape_string(
                        $conn,
                        $stripeSession->id
                    );
                mysqli_query(
                    $conn,
                    "UPDATE orders
                     SET stripe_session_id = '$stripeSid'
                     WHERE id = $orderId"
                );

                // ── Redirect to Stripe ───────────
                header('Location: ' . $stripeSession->url);
                exit();
            }
        }
    }
}

$pageTitle = "Checkout — " . SHOP_NAME;
include 'includes/header.php';
?>

<div class="checkout-page">
    <h1>Checkout</h1>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $e): ?>
                <p>⚠️ <?php echo htmlspecialchars($e); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="checkout-layout">

        <!-- Delivery Details -->
        <div class="checkout-form-wrap">
            <h2>Delivery Details</h2>
            <form class="checkout-form"
                method="POST" action=""
                id="checkoutForm"
                novalidate>
                <?php echo csrfField(); ?>

                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="name"
                            value="<?php echo htmlspecialchars(
                                        $name
                                    ); ?>"
                            placeholder="Jane Smith"
                            required>
                    </div>
                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email"
                            value="<?php echo htmlspecialchars(
                                        $email
                                    ); ?>"
                            placeholder="jane@email.com"
                            required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone"
                        value="<?php echo htmlspecialchars(
                                    $phone
                                ); ?>"
                        placeholder="+44 7700 900000">
                </div>

                <div class="form-group">
                    <label>Street Address *</label>
                    <input type="text" name="address"
                        value="<?php echo htmlspecialchars(
                                    $address
                                ); ?>"
                        placeholder="123 Rose Lane"
                        required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>City *</label>
                        <input type="text" name="city"
                            value="<?php echo htmlspecialchars(
                                        $city
                                    ); ?>"
                            placeholder="London"
                            required>
                    </div>
                    <div class="form-group">
                        <label>Postcode *</label>
                        <input type="text" name="postcode"
                            value="<?php echo htmlspecialchars(
                                        $postcode
                                    ); ?>"
                            placeholder="SW1A 1AA"
                            required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Order Notes (optional)</label>
                    <textarea name="notes"
                        placeholder="Special delivery instructions..."><?php
                                                                        echo htmlspecialchars($notes);
                                                                        ?></textarea>
                </div>

                <div class="payment-notice">
                    <p>🚚 <strong>Free Delivery</strong>
                        on all orders!</p>
                    <p>🔒 <strong>Secure Payment via Stripe</strong>
                        — your card details are never stored
                        on our servers.</p>
                </div>

                <p class="checkout-legal">
                    By placing your order you agree to our
                    <a href="<?php echo BASE_PATH; ?>/terms"
                        target="_blank">Terms & Conditions</a>
                    and
                    <a href="<?php echo BASE_PATH; ?>/privacy-policy"
                        target="_blank">Privacy Policy</a>.
                </p>

                <button type="submit"
                    class="btn-primary btn-place-order"
                    id="checkoutSubmit">
                    Pay Now —
                    £<?php echo number_format(
                            $totalPrice,
                            2
                        ); ?> →
                </button>

            </form>
        </div>

        <!-- Order Summary -->
        <div class="order-summary">
            <h2>Order Summary</h2>
            <div class="summary-items">
                <?php foreach ($cartItems as $item): ?>
                    <div class="summary-item">
                        <img src="<?php echo SHOP_URL; ?>/images/<?php
                                                                    echo htmlspecialchars($item['image']); ?>"
                            onerror="this.src='<?php echo SHOP_URL;
                                                ?>/images/placeholder.jpg'"
                            alt="<?php echo htmlspecialchars(
                                        $item['name']
                                    ); ?>">
                        <div class="summary-item-info">
                            <p class="summary-item-name">
                                <?php echo htmlspecialchars(
                                    $item['name']
                                ); ?>
                            </p>
                            <p class="summary-item-qty">
                                Qty: <?php echo $item['qty']; ?>
                            </p>
                            <?php if ($item['sale_price']): ?>
                                <p style="font-size:0.75rem; color:#e53935;">
                                    <?php echo intval($item['discount_percent']); ?>% off applied
                                </p>
                            <?php endif; ?>
                        </div>
                        <span class="summary-item-price">
                            £<?php echo number_format(
                                    $item['subtotal'],
                                    2
                                ); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="summary-total">
                <span>Delivery</span>
                <span style="color:#4caf50;
                     font-weight:bold;">FREE</span>
            </div>
            <div class="summary-total"
                style="margin-top:10px;
                  border-top:2px solid #1a1a1a;
                  padding-top:16px;">
                <span>Total</span>
                <span><strong>£<?php echo number_format(
                                    $totalPrice,
                                    2
                                ); ?></strong></span>
            </div>
        </div>

    </div>
</div>

<script>
    // Prevent double submission
    // Only runs AFTER server validates and redirects
    // So if there are PHP errors the button stays active
    let formSubmitting = false;

    document.getElementById('checkoutForm')
        .addEventListener('submit', function(e) {

            // Run HTML5 validation first
            const inputs = this.querySelectorAll(
                '[required]');
            let valid = true;
            inputs.forEach(function(input) {
                if (!input.value.trim()) {
                    valid = false;
                    input.style.borderColor = '#e53935';
                } else {
                    input.style.borderColor = '';
                }
            });

            if (!valid) {
                e.preventDefault();
                return;
            }

            // If already submitting prevent double click
            if (formSubmitting) {
                e.preventDefault();
                return;
            }

            formSubmitting = true;
            const btn = document.getElementById(
                'checkoutSubmit');
            btn.disabled = true;
            btn.textContent = 'Redirecting to payment...';

            // Safety net: re-enable after 10 seconds
            // in case redirect fails for any reason
            setTimeout(function() {
                formSubmitting = false;
                btn.disabled = false;
                btn.textContent = 'Pay Now — £<?php
                                                echo number_format($totalPrice, 2); ?> →';
            }, 10000);
        });
</script>

<?php include 'includes/footer.php'; ?>