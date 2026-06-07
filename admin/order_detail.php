<?php
include 'auth.php';
include '../includes/db.php';
include '../includes/shipping.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    header('Location: /admin/orders');
    exit();
}

$stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$order) {
    header('Location: /admin/orders');
    exit();
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT oi.*, p.name, p.image
     FROM order_items oi
     JOIN products p ON oi.product_id = p.id
     WHERE oi.order_id = ?"
);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$items = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);



function buildTrackingUrl(string $tn, string $carrier, string $service, string $labelType, string $fallbackUrl): string
{
    if (
        stripos($carrier, 'royal') !== false
        || stripos($service, 'royal') !== false
        || preg_match('/^[A-Z]{2}\d+GB$/i', $tn)
    ) {
        return 'https://www.royalmail.com/track-your-item#/tracking-results/' . urlencode($tn);
    } elseif (
        stripos($carrier, 'evri') !== false
        || stripos($carrier, 'hermes') !== false
        || stripos($service, 'evri') !== false
    ) {
        return 'https://www.evri.com/track/' . urlencode($tn);
    } elseif ($labelType === 'original' && !empty($fallbackUrl)) {
        return $fallbackUrl;
    } elseif (!empty($tn)) {
        return 'https://www.royalmail.com/track-your-item#/tracking-results/' . urlencode($tn);
    }
    return '';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #<?php echo $id; ?> - Admin</title>
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

        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="admin-sidebar-logo">🌸 Admin Panel</div>
            <ul>
                <li><a href="/admin/dashboard">📊 Dashboard</a></li>
                <li><a href="/admin/orders" class="active">📦 Orders</a></li>
                <li><a href="/admin/products">🌸 Products</a></li>
                <li><a href="/admin/messages">✉️ Messages</a></li>
                <li><a href="/admin/logout">🚪 Logout</a></li>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">

            <a href="/admin/orders" class="btn-back">← Back to Orders</a>
            <h1>Order #<?php echo $id; ?></h1>
            <p class="admin-subtitle">
                Placed on <?php echo date('d M Y, H:i', strtotime($order['created_at'])); ?>
                &nbsp;|&nbsp;
                <span class="badge badge-<?php echo htmlspecialchars($order['status']); ?>">
                    <?php echo ucfirst($order['status']); ?>
                </span>
            </p>

            <div class="order-detail-grid">

                <!-- Customer Info -->
                <div class="detail-box">
                    <h3>Customer Details</h3>

                    <table class="detail-table">
                        <tr>
                            <td>Name</td>
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
                    </table>
                </div>



                <!-- Tracking History -->
                <?php
                $stmt = mysqli_prepare(
                    $conn,
                    "SELECT * FROM order_tracking
                     WHERE order_id = ?
                     ORDER BY created_at ASC"
                );
                mysqli_stmt_bind_param($stmt, "i", $id);
                mysqli_stmt_execute($stmt);
                $trackingHistory = mysqli_stmt_get_result($stmt);
                mysqli_stmt_close($stmt);
                $trackingCount = mysqli_num_rows($trackingHistory);
                mysqli_data_seek($trackingHistory, 0);
                ?>

                <div class="detail-box" style="margin-top:20px;">
                    <h3>📍 Shipment Tracking</h3>

                    <?php if ($trackingCount > 0): ?>
                        <div class="admin-table-wrapper">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Tracking Number</th>
                                        <th>Carrier / Service</th>
                                        <th>Date</th>
                                        <th>Track</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($t = mysqli_fetch_assoc(
                                        $trackingHistory
                                    )):
                                        // Build tracking URL
                                        $tn           = $t['tracking_number'];
                                        $isReplacement = $t['label_type'] === 'replacement';
                                        $trackUrl     = buildTrackingUrl(
                                            $tn,
                                            $t['carrier'] ?? '',
                                            $t['service_name'] ?? '',
                                            $t['label_type'],
                                            $order['tracking_url'] ?? ''
                                        );
                                    ?>
                                        <tr>
                                            <td>
                                                <span class="badge <?php echo
                                                                    $isReplacement
                                                                        ? 'badge-pending'
                                                                        : 'badge-dispatched'; ?>">
                                                    <?php echo $isReplacement
                                                        ? '🔄 Replacement'
                                                        : '📦 Original'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <code style="font-size:0.82rem;
                       color:<?php echo
                                        $isReplacement
                                            ? '#1565c0'
                                            : '#2e7d32'; ?>;">
                                                    <?php echo htmlspecialchars($tn); ?>
                                                </code>
                                            </td>
                                            <td style="font-size:0.82rem;">
                                                <?php echo htmlspecialchars(
                                                    $t['carrier'] ?? '—'
                                                ); ?>
                                                <?php if (
                                                    $t['service_name']
                                                    && $t['service_name']
                                                    !== $t['carrier']
                                                ): ?>
                                                    <br>
                                                    <span style="color:#aaa;
                         font-size:0.75rem;">
                                                        <?php echo htmlspecialchars(
                                                            $t['service_name']
                                                        ); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size:0.8rem;">
                                                <?php echo date(
                                                    'd M Y',
                                                    strtotime($t['created_at'])
                                                ); ?>
                                            </td>
                                            <td>
                                                <?php if ($trackUrl): ?>
                                                    <a href="<?php echo htmlspecialchars(
                                                                    $trackUrl
                                                                ); ?>"
                                                        target="_blank"
                                                        class="btn-view"
                                                        style="font-size:0.75rem; padding:4px 10px;" rel="noopener noreferrer">
                                                        Track →
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color:#ccc;
                       font-size:0.78rem;">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>

                        </div>
                        <div class="detail-mobile-cards">
                            <?php mysqli_data_seek($trackingHistory, 0);
                            while ($t = mysqli_fetch_assoc($trackingHistory)):
                                // rebuild trackUrl same as above
                                $tn           = $t['tracking_number'];
                                $isReplacement = $t['label_type'] === 'replacement';
                                $trackUrl     = buildTrackingUrl(
                                    $tn,
                                    $t['carrier'] ?? '',
                                    $t['service_name'] ?? '',
                                    $t['label_type'],
                                    $order['tracking_url'] ?? ''
                                );
                            ?>
                                <div class="detail-mobile-card">
                                    <div class="detail-mobile-card-header" onclick="this.closest('.detail-mobile-card').classList.toggle('open')">
                                        <div class="detail-mobile-card-left">
                                            <span class="detail-mobile-card-title"><?php echo htmlspecialchars($tn); ?></span>
                                            <span class="detail-mobile-card-sub"><?php echo htmlspecialchars($t['carrier'] ?? '—'); ?></span>
                                        </div>
                                        <span class="detail-mobile-card-chevron">▼</span>
                                    </div>
                                    <div class="detail-mobile-card-body">
                                        <div class="detail-mobile-card-row">
                                            <span>Type</span>
                                            <span><?php echo $isReplacement ? '🔄 Replacement' : '📦 Original'; ?></span>
                                        </div>
                                        <div class="detail-mobile-card-row">
                                            <span>Service</span>
                                            <span><?php echo htmlspecialchars($t['service_name'] ?? '—'); ?></span>
                                        </div>
                                        <div class="detail-mobile-card-row">
                                            <span>Date</span>
                                            <span><?php echo date('d M Y', strtotime($t['created_at'])); ?></span>
                                        </div>
                                        <?php if ($trackUrl): ?>
                                            <div class="detail-mobile-card-actions">
                                                <a href="<?php echo htmlspecialchars($trackUrl); ?>" target="_blank" rel="noopener noreferrer" class="btn-view">Track →</a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>


                    <?php else: ?>
                        <p style="color:#999; font-size:0.85rem;
             padding:10px 0;">
                            No tracking information yet.
                        </p>
                    <?php endif; ?>
                </div>


                <!-- Order Items -->
                <div class="detail-box">
                    <h3>Items Ordered</h3>
                    <div class="admin-table-wrapper">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Price</th>
                                    <th>Qty</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($item = mysqli_fetch_assoc($items)): ?>
                                    <tr>
                                        <td>
                                            <div style="display:flex; align-items:center; gap:10px;">
                                                <img src="<?php echo SHOP_URL; ?>/images/<?php echo htmlspecialchars($item['image']); ?>"
                                                    onerror="this.src='<?php echo SHOP_URL; ?>/images/placeholder.jpg'"
                                                    style="width:40px; height:40px; object-fit:cover;">
                                                <?php echo htmlspecialchars($item['name']); ?>
                                            </div>
                                        </td>
                                        <td>£<?php echo number_format($item['price'], 2); ?></td>
                                        <td><?php echo $item['quantity']; ?></td>
                                        <td>£<?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                                <tr>
                                    <td colspan="3" style="text-align:right;"><strong>Total</strong></td>
                                    <td><strong>£<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="detail-mobile-cards">
                        <?php mysqli_data_seek($items, 0);
                        while ($item = mysqli_fetch_assoc($items)): ?>
                            <div class="detail-mobile-card">
                                <div class="detail-mobile-card-header" onclick="this.closest('.detail-mobile-card').classList.toggle('open')">
                                    <div class="detail-mobile-card-left">
                                        <span class="detail-mobile-card-title"><?php echo htmlspecialchars($item['name']); ?></span>
                                        <span class="detail-mobile-card-sub">Qty: <?php echo $item['quantity']; ?></span>
                                    </div>
                                    <span class="detail-mobile-card-chevron">▼</span>
                                </div>
                                <div class="detail-mobile-card-body">
                                    <div class="detail-mobile-card-img">
                                        <img src="<?php echo SHOP_URL; ?>/images/<?php echo htmlspecialchars($item['image']); ?>"
                                            onerror="this.src='<?php echo SHOP_URL; ?>/images/placeholder.jpg'">
                                        <span><?php echo htmlspecialchars($item['name']); ?></span>
                                    </div>
                                    <div class="detail-mobile-card-row">
                                        <span>Price</span>
                                        <span>£<?php echo number_format($item['price'], 2); ?></span>
                                    </div>
                                    <div class="detail-mobile-card-row">
                                        <span>Qty</span>
                                        <span><?php echo $item['quantity']; ?></span>
                                    </div>
                                    <div class="detail-mobile-card-row">
                                        <span>Subtotal</span>
                                        <span>£<?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                        <div style="text-align:right; padding: 12px 14px; font-size:0.88rem; border-top: 1px solid #e0e0e0; margin-top: 4px;">
                            <strong>Total: £<?php echo number_format($order['total_amount'], 2); ?></strong>
                        </div>
                    </div>

                </div>

            </div>






        </main>
    </div>

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