<?php
include 'auth.php';
include '../includes/db.php';
include '../includes/order_status.php';
require_once '../config.php';
include '../includes/sendcloud.php';
require_once '../includes/mailer.php';
require_once '../includes/csrf.php';

$orderId = intval($_GET['order_id'] ?? 0);
if (!$orderId) {
    header('Location: /admin/orders');
    exit();
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM orders WHERE id = ?"
);
mysqli_stmt_bind_param($stmt, "i", $orderId);
mysqli_stmt_execute($stmt);
$order = mysqli_fetch_assoc(
    mysqli_stmt_get_result($stmt)
);
mysqli_stmt_close($stmt);

if (!$order || !in_array($order['status'], ['paid', 'processing'])) {
    header('Location: /admin/orders');
    exit();
}

// Get items and calculate weight
$itemsStmt = mysqli_prepare(
    $conn,
    "SELECT oi.*, p.name, p.weight_grams
     FROM order_items oi
     JOIN products p ON oi.product_id = p.id
     WHERE oi.order_id = ?"
);
mysqli_stmt_bind_param($itemsStmt, "i", $orderId);
mysqli_stmt_execute($itemsStmt);
$itemsResult = mysqli_stmt_get_result($itemsStmt);

$totalWeight = 0;
$orderItems  = [];
while ($item = mysqli_fetch_assoc($itemsResult)) {
    $totalWeight += $item['weight_grams'] * $item['quantity'];
    $orderItems[] = $item;
}
mysqli_stmt_close($itemsStmt);
$totalWeight += 50; // packaging

$success = $error = '';
$labelCreated = false;
$shipmentData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_label'])) {
    verifyCsrfToken();

    $weight = max(1, intval($_POST['weight']   ?? 100));
    $length = max(1, floatval($_POST['length'] ?? 20));
    $width  = max(1, floatval($_POST['width']  ?? 15));
    $height = max(1, floatval($_POST['height'] ?? 10));
    $methodName = trim($_POST['shipping_method_name'] ?? '');
    $carrier    = trim($_POST['carrier'] ?? '');
    $useManual  = isset($_POST['use_manual']);



    if ($useManual) {
        $allowedCarriers = ['Royal Mail', 'Evri', 'Other'];
        if (!in_array($carrier, $allowedCarriers, true)) {
            $carrier = 'Other';
        }
        $tracking = trim($_POST['manual_tracking'] ?? '');
        if (empty($tracking)) {
            $error = "Please enter a tracking number.";
        } else {
            $trackingUrl = '';
            if (stripos($carrier, 'royal') !== false) {
                $trackingUrl = 'https://www.royalmail.com/track-your-item#/tracking-results/' . urlencode($tracking);
            } elseif (stripos($carrier, 'evri') !== false || stripos($carrier, 'hermes') !== false) {
                $trackingUrl = 'https://www.evri.com/track/' . urlencode($tracking);
            }

            try {
                mysqli_begin_transaction($conn);

                // Fix 4: Re-check status inside transaction
                // Prevents two admins dispatching same order
                $checkStmt = mysqli_prepare(
                    $conn,
                    "SELECT status FROM orders
         WHERE id = ? FOR UPDATE"
                );
                mysqli_stmt_bind_param($checkStmt, "i", $orderId);
                mysqli_stmt_execute($checkStmt);
                $currentStatus = mysqli_fetch_assoc(
                    mysqli_stmt_get_result($checkStmt)
                )['status'] ?? '';
                mysqli_stmt_close($checkStmt);

                if (!in_array(
                    $currentStatus,
                    ['paid', 'processing']
                )) {
                    mysqli_rollback($conn);
                    $error = "This order has already been
                  dispatched or is not eligible.";
                } else {
                    if (empty($tracking)) {
                        throw new Exception('Missing tracking number.');
                    }

                    $stmt = mysqli_prepare(
                        $conn,
                        "INSERT INTO shipments
             (order_id, carrier, service_name,
              tracking_number, tracking_url,
              parcel_weight, parcel_length,
              parcel_width, parcel_height,
              label_method, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,
                     'manual', 'created')"
                    );
                    mysqli_stmt_bind_param(
                        $stmt,
                        "issssdddd",
                        $orderId,
                        $carrier,
                        $methodName,
                        $tracking,
                        $trackingUrl,
                        $weight,
                        $length,
                        $width,
                        $height
                    );
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception(mysqli_error($conn));
                    }
                    mysqli_stmt_close($stmt);

                    $stmt = mysqli_prepare(
                        $conn,
                        "UPDATE orders SET
               tracking_number=?,
               tracking_url=?,
               shipping_method=?,
               dispatched_at=NOW()
             WHERE id=?"
                    );
                    mysqli_stmt_bind_param(
                        $stmt,
                        "sssi",
                        $tracking,
                        $trackingUrl,
                        $methodName,
                        $orderId
                    );
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception(mysqli_error($conn));
                    }
                    mysqli_stmt_close($stmt);

                    if (!updateOrderStatus($conn, $orderId, 'dispatched')) {
                        throw new Exception('Failed to update order status.');
                    }

                    mysqli_commit($conn);

                    // Fix 7: Email/external calls AFTER commit
                    // Dispatch succeeds even if these fail
                    try {
                        sendDispatchEmail($conn, $orderId);
                    } catch (Throwable $e) {
                        error_log("Dispatch email failed: "
                            . $e->getMessage());
                    }

                    $success = "✅ Order #$orderId dispatched!"
                        . " Tracking: <strong>"
                        . htmlspecialchars($tracking)
                        . "</strong>";
                    $labelCreated = true;
                }
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                error_log("Manual dispatch failed: "
                    . $e->getMessage());
                $error = "Failed to create shipment.
              Please try again.";
            }
        }
    } else {
        $optionCode = $_POST['shipping_option_code'] ?? '';
        if (empty($optionCode)) {
            $error = "Please select a shipping service.";
        } else {
            $parcel = ['weight' => $weight, 'length' => $length, 'width' => $width, 'height' => $height];
            /*$result = createShipment($order, $parcel, $optionCode);

            file_put_contents('../sendcloud_response.json', json_encode($result, JSON_PRETTY_PRINT));

            if (isset($result['error'])) {
                $error = "Sendcloud error: " . htmlspecialchars($result['error']) . " — Use manual fallback below.";*/


            $result = createShipment($order, $parcel, $optionCode);

            // Save response for debugging
            error_log("Sendcloud response: "
                . json_encode($result));

            // ── DEVELOPMENT MODE ─────────────────────────────────
            // Remove this block when Sendcloud payment is verified
            // Automatically true in development, false in production
            // No need to manually toggle before launch
            define(
                'SENDCLOUD_DEV_MODE',
                (getenv('ENVIRONMENT') ?: 'development')
                    !== 'production'
            );
            if (SENDCLOUD_DEV_MODE && isset($result['error'])) {
                $result = [
                    'parcel' => [
                        'id'              => 'DEV-' . time(),
                        'tracking_number' => 'TEST' . rand(100000, 999999) . 'GB',
                        'tracking_url'    => 'https://www.royalmail.com/track-your-item',
                        'label'           => [
                            'label_printer' => '#',
                        ],
                    ],
                ];
            }
            // ── END DEVELOPMENT MODE ─────────────────────────────

            if (isset($result['error'])) {
                $error = "Sendcloud error: "
                    . htmlspecialchars($result['error'])
                    . " — Use manual fallback below.";
            } elseif (isset($result['parcel'])) {
                $p           = $result['parcel'];
                $tracking    = $p['tracking_number'] ?? '';
                $trackingUrl = $p['tracking_url'] ?? '';
                $labelUrl    = getLabelUrl($p);
                $scId        = (string)($p['id'] ?? '');

                try {
                    mysqli_begin_transaction($conn);

                    // Fix 4: Re-check status
                    $checkStmt = mysqli_prepare(
                        $conn,
                        "SELECT status FROM orders
         WHERE id = ? FOR UPDATE"
                    );
                    mysqli_stmt_bind_param($checkStmt, "i", $orderId);
                    mysqli_stmt_execute($checkStmt);
                    $currentStatus = mysqli_fetch_assoc(
                        mysqli_stmt_get_result($checkStmt)
                    )['status'] ?? '';
                    mysqli_stmt_close($checkStmt);

                    if (!in_array(
                        $currentStatus,
                        ['paid', 'processing']
                    )) {
                        mysqli_rollback($conn);
                        $error = "This order has already been
                  dispatched or is not eligible.";
                    } else {
                        if (empty($tracking)) {
                            throw new Exception('Sendcloud returned no tracking number.');
                        }

                        $stmt = mysqli_prepare(
                            $conn,
                            "INSERT INTO shipments
             (order_id, sendcloud_shipment_id,
              carrier, service_name,
              sendcloud_label_url,
              tracking_number, tracking_url,
              parcel_weight, parcel_length,
              parcel_width, parcel_height,
              label_method, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, 'sendcloud', 'created')"
                        );
                        mysqli_stmt_bind_param(
                            $stmt,
                            "issssssdddd",
                            $orderId,
                            $scId,
                            $carrier,
                            $methodName,
                            $labelUrl,
                            $tracking,
                            $trackingUrl,
                            $weight,
                            $length,
                            $width,
                            $height
                        );
                        if (!mysqli_stmt_execute($stmt)) {
                            throw new Exception(mysqli_error($conn));
                        }
                        mysqli_stmt_close($stmt);

                        $stmt = mysqli_prepare(
                            $conn,
                            "UPDATE orders SET
               tracking_number=?,
               tracking_url=?,
               shipping_method=?,
               dispatched_at=NOW()
             WHERE id=?"
                        );
                        mysqli_stmt_bind_param(
                            $stmt,
                            "sssi",
                            $tracking,
                            $trackingUrl,
                            $methodName,
                            $orderId
                        );
                        if (!mysqli_stmt_execute($stmt)) {
                            throw new Exception(mysqli_error($conn));
                        }
                        mysqli_stmt_close($stmt);

                        if (!updateOrderStatus($conn, $orderId, 'dispatched')) {
                            throw new Exception('Failed to update order status.');
                        }

                        mysqli_commit($conn);

                        // Fix 7: External calls AFTER commit
                        try {
                            sendDispatchEmail($conn, $orderId);
                        } catch (Throwable $e) {
                            error_log("Dispatch email failed: "
                                . $e->getMessage());
                        }

                        $shipmentData = $p;
                        $labelCreated = true;
                        $success = "✅ Label created! Tracking: <strong>"
                            . htmlspecialchars($tracking)
                            . "</strong>";
                    }
                } catch (Throwable $e) {
                    mysqli_rollback($conn);
                    error_log("Sendcloud dispatch failed: "
                        . $e->getMessage());
                    $error = "Failed to create shipment.
              Please try again.";
                }
            } else {
                $error = "Unexpected response. Please use manual fallback.";
            }
        }
    }
}

$shippingMethods = getShippingMethods($totalWeight);
$hasOptions = !isset($shippingMethods['error']) && !empty($shippingMethods);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Shipment — Order #<?php echo $orderId; ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/admin.css">
</head>

<body class="admin-body">
    <button class="admin-menu-toggle" id="adminMenuToggle" aria-label="Toggle menu">
        <span></span>
        <span></span>
        <span></span>
    </button>
    <div class="admin-overlay" id="adminOverlay"></div>
    <div class="admin-layout">

        <aside class="admin-sidebar">
            <div class="admin-sidebar-logo">🌸 Admin Panel</div>
            <ul>
                <li><a href="/admin/dashboard">📊 Dashboard</a></li>
                <li><a href="/admin/orders" class="active">📦 Orders</a></li>
                <li><a href="/admin/products">🌸 Products</a></li>
                <li><a href="/admin/messages">✉️ Messages</a></li>
                <li><a href="/admin/returns">🔄 Returns</a></li>
                <li><a href="/admin/logout">🚪 Logout</a></li>
            </ul>
        </aside>

        <main class="admin-main">
            <a href="/admin/orders?tab=awaiting" class="btn-back">← Back to Orders</a>
            <h1>Create Shipment</h1>
            <p class="admin-subtitle">Order #<?php echo $orderId; ?> — <?php echo htmlspecialchars($order['customer_name']); ?></p>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo $success; ?>
                    <br><br>
                    <?php if ($shipmentData && getLabelUrl($shipmentData)): ?>
                        <a href="<?php echo htmlspecialchars(getLabelUrl($shipmentData)); ?>" target="_blank"
                            class="btn-primary" style="display:inline-block; margin-right:10px;">
                            🖨️ Download & Print Label
                        </a>
                    <?php endif; ?>
                    <a href="/admin/orders?tab=dispatched" class="btn-secondary" style="display:inline-block;">
                        View Dispatched Orders →
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if (!$labelCreated): ?>
                <div class="shipment-layout">

                    <!-- Order Summary -->
                    <div class="detail-box shipment-order-summary">
                        <h3>📦 Order Summary</h3>
                        <table class="detail-table">
                            <tr>
                                <td>Customer</td>
                                <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                            </tr>
                            <tr>
                                <td>Email</td>
                                <td><?php echo htmlspecialchars($order['customer_email']); ?></td>
                            </tr>
                            <tr>
                                <td>Phone</td>
                                <td><?php echo htmlspecialchars($order['customer_phone'] ?: '—'); ?></td>
                            </tr>
                            <tr>
                                <td>Address</td>
                                <td><?php echo htmlspecialchars($order['customer_address']); ?></td>
                            </tr>
                            <tr>
                                <td>Total</td>
                                <td><strong>£<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                            </tr>
                        </table>
                        <div style="margin-top:20px;">
                            <h3>🛍️ Items</h3>
                            <?php foreach ($orderItems as $item): ?>
                                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f0f0f0; font-size:0.88rem;">
                                    <span><?php echo htmlspecialchars($item['name']); ?> × <?php echo $item['quantity']; ?></span>
                                    <span style="color:#999;"><?php echo $item['weight_grams'] * $item['quantity']; ?>g</span>
                                </div>
                            <?php endforeach; ?>
                            <div style="display:flex; justify-content:space-between; padding:8px 0; font-size:0.82rem; color:#999;">
                                <span>+ Packaging</span><span>50g</span>
                            </div>
                            <div style="display:flex; justify-content:space-between; padding:10px 0 0; font-weight:bold; font-size:0.92rem; border-top:1px solid #1a1a1a;">
                                <span>Total Weight</span><span><?php echo $totalWeight; ?>g</span>
                            </div>
                        </div>
                    </div>

                    <!-- Right column -->
                    <div>

                        <!-- Sendcloud -->
                        <div class="detail-box" style="margin-bottom:20px;">
                            <h3>🏷️ Create Label via Sendcloud</h3>
                            <p style="font-size:0.82rem; color:#999; margin-bottom:16px;">
                                Select a service below and click Create Label. The label PDF downloads immediately.
                            </p>
                            <form method="POST" action="">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="create_label" value="1">
                                <div class="form-row" style="margin-bottom:16px;">
                                    <div class="form-group">
                                        <label>Weight (grams)</label>
                                        <input type="number" name="weight" value="<?php echo $totalWeight; ?>" min="1" required
                                            style="width:100%; padding:10px; border:1px solid #ddd; font-family:Georgia,serif;">
                                    </div>
                                    <div class="form-group">
                                        <label>Length (cm)</label>
                                        <input type="number" name="length" value="20" min="1" step="0.1" required
                                            style="width:100%; padding:10px; border:1px solid #ddd; font-family:Georgia,serif;">
                                    </div>
                                </div>
                                <div class="form-row" style="margin-bottom:16px;">
                                    <div class="form-group">
                                        <label>Width (cm)</label>
                                        <input type="number" name="width" value="15" min="1" step="0.1" required
                                            style="width:100%; padding:10px; border:1px solid #ddd; font-family:Georgia,serif;">
                                    </div>
                                    <div class="form-group">
                                        <label>Height (cm)</label>
                                        <input type="number" name="height" value="10" min="1" step="0.1" required
                                            style="width:100%; padding:10px; border:1px solid #ddd; font-family:Georgia,serif;">
                                    </div>
                                </div>

                                <div class="form-group" style="margin-bottom:16px;">
                                    <label>Shipping Service</label>
                                    <?php if ($hasOptions): ?>
                                        <div class="shipping-method-list">
                                            <?php foreach ($shippingMethods as $i => $m): ?>
                                                <label class="shipping-method-option">
                                                    <input type="radio" name="shipping_option_code"
                                                        value="<?php echo htmlspecialchars($m['option_code']); ?>"
                                                        data-name="<?php echo htmlspecialchars($m['name']); ?>"
                                                        data-carrier="<?php echo htmlspecialchars($m['carrier_name']); ?>"
                                                        <?php echo $i === 0 ? 'checked' : ''; ?> required>
                                                    <div class="method-info">
                                                        <strong><?php echo htmlspecialchars($m['name']); ?></strong>
                                                        <span><?php echo htmlspecialchars($m['carrier_name']); ?></span>
                                                        <?php if ($m['max_weight_kg'] > 0): ?>
                                                            <span>Up to 20kg</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if (!empty($m['price']) && $m['price'] > 0): ?>
                                                        <span class="method-price">£<?php echo number_format($m['price'], 2); ?></span>
                                                    <?php endif; ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-error">
                                            <?php echo isset($shippingMethods['error'])
                                                ? htmlspecialchars($shippingMethods['error'])
                                                : 'No services available.'; ?>
                                            Use the manual fallback below.
                                        </div>
                                    <?php endif; ?>
                                    <input type="hidden" name="shipping_method_name" id="selectedMethodName">
                                    <input type="hidden" name="carrier" id="selectedCarrier">
                                </div>

                                <?php if ($hasOptions): ?>
                                    <button type="submit" class="btn-primary"
                                        style="width:100%; padding:14px; font-size:1rem; letter-spacing:2px;">
                                        🏷️ Create Label &amp; Dispatch Order
                                    </button>
                                <?php endif; ?>
                            </form>
                        </div>

                        <!-- Manual fallback -->
                        <div class="detail-box manual-fallback">
                            <h3>🔄 Manual Fallback</h3>
                            <p style="font-size:0.82rem; color:#888; margin-bottom:16px;">
                                Use when Sendcloud limit is reached, or after buying a label from the carrier website.
                            </p>
                            <form method="POST" action="">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="create_label" value="1">
                                <input type="hidden" name="use_manual" value="1">
                                <input type="hidden" name="weight" value="<?php echo $totalWeight; ?>">
                                <input type="hidden" name="length" value="20">
                                <input type="hidden" name="width" value="15">
                                <input type="hidden" name="height" value="10">

                                <div class="form-group" style="margin-bottom:12px;">
                                    <label>Carrier</label>
                                    <select name="carrier" id="manualCarrier" onchange="updateCarrierLink(this.value)"
                                        style="width:100%; padding:10px; border:1px solid #ddd; font-family:Georgia,serif; background:#fff;">
                                        <option value="Royal Mail">Royal Mail</option>
                                        <option value="Evri">Evri</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>

                                <div id="carrierLinkWrap" style="margin-bottom:16px;">
                                    <a id="carrierLinkBtn" href="https://send.royalmail.com" target="_blank"
                                        class="btn-primary"
                                        style="display:inline-block; width:100%; text-align:center; padding:12px; font-size:0.88rem; letter-spacing:1px; box-sizing:border-box;">
                                        🏷️ Buy Label on Royal Mail Website →
                                    </a>
                                    <p style="font-size:0.75rem; color:#aaa; margin-top:6px; text-align:center;">
                                        Opens in new tab. Come back after buying your label.
                                    </p>
                                </div>

                                <div class="form-group" style="margin-bottom:12px;">
                                    <label>Service Name</label>
                                    <input type="text" name="shipping_method_name" placeholder="e.g. Royal Mail Tracked 48"
                                        style="width:100%; padding:10px; border:1px solid #ddd; font-family:Georgia,serif;">
                                </div>
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label>Tracking Number *</label>
                                    <input type="text" name="manual_tracking" placeholder="e.g. RM123456789GB" required
                                        style="width:100%; padding:10px; border:1px solid #ddd; font-family:Georgia,serif;">
                                </div>
                                <button type="submit" class="btn-secondary" style="width:100%;">
                                    ✓ Save Tracking &amp; Mark Dispatched
                                </button>
                            </form>
                        </div>

                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        document.querySelectorAll('input[name="shipping_option_code"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.getElementById('selectedMethodName').value = this.dataset.name;
                document.getElementById('selectedCarrier').value = this.dataset.carrier;
            });
            if (radio.checked) {
                document.getElementById('selectedMethodName').value = radio.dataset.name;
                document.getElementById('selectedCarrier').value = radio.dataset.carrier;
            }
        });

        const carrierLinks = {
            'Royal Mail': {
                url: 'https://send.royalmail.com',
                text: '🏷️ Buy Label on Royal Mail Website →'
            },
            'Evri': {
                url: 'https://www.evri.com/send-a-parcel',
                text: '🏷️ Buy Label on Evri Website →'
            },
            'Other': {
                url: null,
                text: null
            }
        };

        function updateCarrierLink(carrier) {
            const wrap = document.getElementById('carrierLinkWrap');
            const btn = document.getElementById('carrierLinkBtn');
            const info = carrierLinks[carrier];
            if (info && info.url) {
                wrap.style.display = 'block';
                btn.href = info.url;
                btn.textContent = info.text;
            } else {
                wrap.style.display = 'none';
            }
        }
        updateCarrierLink('Royal Mail');
    </script>

    <!-- ============================================
         ADMIN MOBILE MENU SCRIPT
         ============================================ -->
    <script>
        const adminMenuToggle = document.querySelector('.admin-menu-toggle');
        const adminSidebar = document.querySelector('.admin-sidebar');

        adminMenuToggle?.addEventListener('click', function() {
            adminSidebar.classList.toggle('open');
            adminMenuToggle.classList.toggle('active');
        });

        // Close sidebar when clicking a link
        document.querySelectorAll('.admin-sidebar a').forEach(link => {
            link.addEventListener('click', function() {
                adminSidebar.classList.remove('open');
                adminMenuToggle.classList.remove('active');
            });
        });

        // Close sidebar when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.admin-sidebar') &&
                !e.target.closest('.admin-menu-toggle')) {
                adminSidebar.classList.remove('open');
                adminMenuToggle.classList.remove('active');
            }
        });
    </script>
</body>

</html>