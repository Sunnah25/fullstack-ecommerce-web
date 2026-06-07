<?php
include 'auth.php';
include '../includes/db.php';
require_once '../config.php';
require_once '../includes/csrf.php';
include '../includes/order_status.php';

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = $_POST['action'] ?? '';

    // ── Single order actions ─────────────────────
    if ($action === 'single_delivered') {
        $oid = intval($_POST['order_id'] ?? 0);
        if ($oid > 0) {
            require_once '../includes/mailer.php';

            // Check current status to prevent duplicate delivery emails
            $chk = mysqli_prepare($conn, "SELECT status FROM orders WHERE id = ?");
            mysqli_stmt_bind_param($chk, "i", $oid);
            mysqli_stmt_execute($chk);
            $currentStatus = mysqli_fetch_assoc(mysqli_stmt_get_result($chk))['status'] ?? '';
            mysqli_stmt_close($chk);

            if ($currentStatus === 'delivered') {
                $_SESSION['admin_success'] = "Order #$oid was already marked as delivered.";
            } elseif (updateOrderStatus($conn, $oid, 'delivered')) {
                try {
                    sendDeliveryEmail($conn, $oid);
                } catch (Throwable $e) {
                    error_log("Delivery email failed for order #$oid: " . $e->getMessage());
                }
                $_SESSION['admin_success'] = "✅ Order #$oid marked as delivered. Delivery email sent.";
            } else {
                $_SESSION['admin_error'] = "Failed to update order #$oid. Please try again.";
            }
        }
        header('Location: /admin/update-shipments');
        exit();
    }

    if ($action === 'single_shipped') {
        $oid = intval($_POST['order_id'] ?? 0);
        if ($oid > 0) {
            if (updateOrderStatus($conn, $oid, 'shipped')) {
                $_SESSION['admin_success'] = "✅ Order #$oid marked as In Transit.";
            } else {
                $_SESSION['admin_error'] = "Failed to update order #$oid. Please try again.";
            }
        }
        header('Location: /admin/update-shipments');
        exit();
    }

    // ── Bulk actions ─────────────────────────────
    if ($action === 'mark_delivered') {
        $orderIds = $_POST['order_ids'] ?? [];
        if (!empty($orderIds)) {
            require_once '../includes/mailer.php';
            $count = 0;
            mysqli_begin_transaction($conn);
            try {
                $updatedOrders = [];
                foreach ($orderIds as $oid) {
                    $oid = intval($oid);
                    if ($oid <= 0) continue;

                    // Skip already delivered orders
                    $chk = mysqli_prepare($conn, "SELECT status FROM orders WHERE id = ?");
                    mysqli_stmt_bind_param($chk, "i", $oid);
                    mysqli_stmt_execute($chk);
                    $currentStatus = mysqli_fetch_assoc(mysqli_stmt_get_result($chk))['status'] ?? '';
                    mysqli_stmt_close($chk);
                    if ($currentStatus === 'delivered') continue;

                    if (!updateOrderStatus($conn, $oid, 'delivered')) {
                        throw new Exception("Failed to update order #$oid");
                    }
                    $updatedOrders[] = $oid;
                    $count++;
                }
                mysqli_commit($conn);

                // Only email orders that were actually updated this run
                foreach ($updatedOrders as $oid) {
                    try {
                        sendDeliveryEmail($conn, $oid);
                    } catch (Throwable $e) {
                        error_log("Delivery email failed for order #$oid: " . $e->getMessage());
                    }
                }
                $_SESSION['admin_success'] = "✅ $count order(s) marked as delivered.";
            } catch (Exception $e) {
                mysqli_rollback($conn);
                error_log("Bulk mark_delivered failed: " . $e->getMessage());
                $_SESSION['admin_error'] = "Failed to update orders. Please try again.";
            }
        }
        header('Location: /admin/update-shipments');
        exit();
    }

    if ($action === 'mark_shipped') {
        $orderIds = $_POST['order_ids'] ?? [];
        if (!empty($orderIds)) {
            $count = 0;
            mysqli_begin_transaction($conn);
            try {
                foreach ($orderIds as $oid) {
                    $oid = intval($oid);
                    if ($oid <= 0) continue;
                    if (!updateOrderStatus($conn, $oid, 'shipped')) {
                        throw new Exception("Failed to update order #$oid");
                    }
                    $count++;
                }
                mysqli_commit($conn);
                $_SESSION['admin_success'] = "✅ $count order(s) marked as In Transit.";
            } catch (Exception $e) {
                mysqli_rollback($conn);
                error_log("Bulk mark_shipped failed: " . $e->getMessage());
                $_SESSION['admin_error'] = "Failed to update orders. Please try again.";
            }
        }
        header('Location: /admin/update-shipments');
        exit();
    }
}

// Flash messages
$success = '';
$error   = '';
if (isset($_SESSION['admin_success'])) {
    $success = $_SESSION['admin_success'];
    unset($_SESSION['admin_success']);
}
if (isset($_SESSION['admin_error'])) {
    $error = $_SESSION['admin_error'];
    unset($_SESSION['admin_error']);
}

// Load orders — oldest dispatched first
$orders = mysqli_query(
    $conn,
    "SELECT o.*,
            ot.tracking_number AS track_num,
            ot.carrier         AS track_carrier,
            ot.service_name    AS track_service
     FROM orders o
     LEFT JOIN order_tracking ot
        ON ot.order_id = o.id
        AND ot.label_type = 'original'
     WHERE o.status IN ('dispatched','shipped')
     ORDER BY o.dispatched_at ASC,
              o.created_at    ASC"
);

$total = mysqli_num_rows($orders);

// Count urgency groups for stats
$green = $amber = $red = 0;
if ($total > 0) {
    mysqli_data_seek($orders, 0);
    while ($o = mysqli_fetch_assoc($orders)) {
        $days = $o['dispatched_at']
            ? floor((time() - strtotime($o['dispatched_at'])) / 86400)
            : 0;
        if ($days <= 2)      $green++;
        elseif ($days <= 5)  $amber++;
        else                 $red++;
    }
    mysqli_data_seek($orders, 0);
}

function buildTrackUrl($tracking, $carrier)
{
    if (empty($tracking)) return '';
    $c = strtolower($carrier ?? '');
    if (
        stripos($c, 'royal') !== false
        || preg_match('/^[A-Z]{2}\d+[A-Z]{2}$/i', $tracking)
    ) {
        return 'https://www.royalmail.com/track-your-item#/tracking-results/'
            . urlencode($tracking);
    }
    if (
        stripos($c, 'evri') !== false
        || stripos($c, 'hermes') !== false
    ) {
        return 'https://www.evri.com/track/'
            . urlencode($tracking);
    }
    if (stripos($c, 'dpd') !== false) {
        return 'https://www.dpd.co.uk/apps/tracking/?ref='
            . urlencode($tracking);
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Shipments — Admin</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/admin.css">
    <style>
        .dispatch-page {
            max-width: 900px;
        }

        /* Stats */
        .dispatch-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 24px;
        }

        .d-stat {
            background: #fff;
            border: 1px solid #ede0d0;
            padding: 16px;
            text-align: center;
        }

        .d-stat-num {
            font-size: 2rem;
            font-weight: bold;
            line-height: 1;
            margin-bottom: 4px;
        }

        .d-stat-lbl {
            font-size: 0.7rem;
            color: #999;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        /* Tip box */
        .dispatch-tip {
            background: #fdf8f4;
            border: 1px solid #ede0d0;
            border-left: 4px solid #d4af7a;
            padding: 14px 18px;
            margin-bottom: 22px;
            font-size: 0.83rem;
            color: #666;
            line-height: 1.7;
        }

        .dispatch-tip strong {
            color: #1a1a1a;
        }

        /* Legend */
        .dispatch-legend {
            font-size: 0.75rem;
            color: #999;
            text-align: right;
            margin-bottom: 14px;
            line-height: 1.8;
        }

        /* Bulk toolbar */
        .bulk-toolbar {
            background: #1a1a1a;
            padding: 11px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .bulk-toolbar label {
            color: #888;
            font-size: 0.78rem;
            display: flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
        }

        .bulk-toolbar input[type="checkbox"] {
            accent-color: #d4af7a;
            width: 14px;
            height: 14px;
        }

        .bulk-sep {
            color: #333;
        }

        .btn-b-transit {
            background: #3d2b1f;
            color: #d4af7a;
            border: none;
            padding: 7px 14px;
            font-family: Georgia, serif;
            font-size: 0.75rem;
            letter-spacing: 1px;
            cursor: pointer;
        }

        .btn-b-transit:hover {
            background: #5a3e2b;
        }

        .btn-b-delivered {
            background: #1b5e20;
            color: #a5d6a7;
            border: none;
            padding: 7px 14px;
            font-family: Georgia, serif;
            font-size: 0.75rem;
            letter-spacing: 1px;
            cursor: pointer;
        }

        .btn-b-delivered:hover {
            background: #2e7d32;
        }

        .sel-count {
            margin-left: auto;
            color: #555;
            font-size: 0.75rem;
        }

        /* Cards */
        .s-card {
            background: #fff;
            border: 1px solid #ede0d0;
            border-left: 4px solid #ddd;
            margin-bottom: 8px;
            transition: box-shadow 0.2s;
        }

        .s-card:hover {
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.07);
        }

        .s-card.urg-green {
            border-left-color: #4caf50;
        }

        .s-card.urg-amber {
            border-left-color: #f59e0b;
        }

        .s-card.urg-red {
            border-left-color: #e53935;
        }

        .s-card-top {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 16px;
            border-bottom: 1px solid #f5f0eb;
            flex-wrap: wrap;
        }

        .s-card-top input[type="checkbox"] {
            accent-color: #d4af7a;
            width: 15px;
            height: 15px;
            flex-shrink: 0;
            cursor: pointer;
        }

        .s-ref {
            font-size: 0.92rem;
            color: #d4af7a;
            letter-spacing: 1px;
            font-weight: bold;
        }

        .s-name {
            font-size: 0.85rem;
            color: #1a1a1a;
        }

        .s-addr {
            font-size: 0.73rem;
            color: #aaa;
            margin-top: 1px;
        }

        .urg-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            font-size: 0.7rem;
            letter-spacing: 1px;
            white-space: nowrap;
            margin-left: auto;
        }

        .urg-pill.green {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .urg-pill.amber {
            background: #fff8e1;
            color: #e65100;
        }

        .urg-pill.red {
            background: #ffebee;
            color: #c62828;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.65;
            }
        }

        .status-lozenge {
            font-size: 0.68rem;
            padding: 2px 8px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .loz-dispatched {
            background: #e3f2fd;
            color: #1565c0;
        }

        .loz-shipped {
            background: #f3e5f5;
            color: #6a1b9a;
        }

        .s-card-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 16px;
            gap: 10px;
            flex-wrap: wrap;
        }

        .track-info {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .track-badge {
            font-family: monospace;
            font-size: 0.85rem;
            background: #f5f0eb;
            padding: 4px 10px;
            color: #1a1a1a;
            letter-spacing: 1px;
        }

        .no-track {
            font-size: 0.75rem;
            color: #e53935;
            background: #fff5f5;
            padding: 4px 10px;
            border: 1px dashed #ffcdd2;
        }

        .btn-track-link {
            color: #d4af7a;
            border: 1px solid #d4af7a;
            padding: 5px 12px;
            font-family: Georgia, serif;
            font-size: 0.72rem;
            letter-spacing: 1px;
            text-decoration: none;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .btn-track-link:hover {
            background: #d4af7a;
            color: #1a1a1a;
        }

        .carrier-txt {
            font-size: 0.7rem;
            color: #ccc;
        }

        .card-actions {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn-transit {
            background: #3d2b1f;
            color: #d4af7a;
            border: none;
            padding: 7px 12px;
            font-family: Georgia, serif;
            font-size: 0.72rem;
            letter-spacing: 1px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-transit:hover {
            background: #5a3e2b;
        }

        .btn-delivered {
            background: #1b5e20;
            color: #a5d6a7;
            border: none;
            padding: 7px 12px;
            font-family: Georgia, serif;
            font-size: 0.72rem;
            letter-spacing: 1px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-delivered:hover {
            background: #2e7d32;
        }

        .btn-view-sm {
            color: #999;
            font-size: 0.72rem;
            text-decoration: none;
            padding: 7px 10px;
            border: 1px solid #e0e0e0;
            letter-spacing: 1px;
            transition: all 0.2s;
        }

        .btn-view-sm:hover {
            border-color: #1a1a1a;
            color: #1a1a1a;
        }

        /* Empty */
        .dispatch-empty {
            text-align: center;
            padding: 80px 20px;
        }

        .dispatch-empty-icon {
            font-size: 3rem;
            margin-bottom: 14px;
        }

        .dispatch-empty h2 {
            color: #1a1a1a;
            letter-spacing: 2px;
            font-weight: normal;
            margin-bottom: 8px;
        }

        .dispatch-empty p {
            color: #999;
        }

        @media(max-width:600px) {
            .dispatch-stats {
                grid-template-columns: 1fr 1fr;
            }

            .sel-count {
                margin-left: 0;
            }
        }
    </style>
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
                <li><a href="/admin/complaints">⚠️ Complaints</a></li>
                <li><a href="/admin/logout">🚪 Logout</a></li>
            </ul>
        </aside>

        <main class="admin-main dispatch-page">

            <!-- Header -->
            <a href="/admin/orders?tab=dispatched"
                class="btn-back">← Back to Orders</a>
            <h1 style="margin:8px 0 4px;">
                🚚 Update Shipments
            </h1>
            <p class="admin-subtitle" style="margin-bottom:20px;">
                Check carrier tracking then update status here.
            </p>

            <?php if ($success): ?>
                <div class="alert alert-success"
                    style="margin-bottom:20px;">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"
                    style="margin-bottom:20px;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($total === 0): ?>

                <div class="dispatch-empty">
                    <div class="dispatch-empty-icon">🎉</div>
                    <h2>All Clear!</h2>
                    <p>No active shipments right now.</p>
                    <a href="/admin/orders"
                        class="btn-primary"
                        style="display:inline-block; margin-top:20px;">
                        View All Orders
                    </a>
                </div>

            <?php else: ?>

                <!-- Stats -->
                <div class="dispatch-stats">
                    <div class="d-stat">
                        <div class="d-stat-num"
                            style="color:#1a1a1a;">
                            <?php echo $total; ?>
                        </div>
                        <div class="d-stat-lbl">Total Active</div>
                    </div>
                    <div class="d-stat">
                        <div class="d-stat-num"
                            style="color:<?php echo $red > 0 ? '#e53935' : '#4caf50'; ?>">
                            <?php echo $red; ?>
                        </div>
                        <div class="d-stat-lbl">Need Attention</div>
                    </div>
                    <div class="d-stat">
                        <div class="d-stat-num" style="color:#4caf50;">
                            <?php echo $green; ?>
                        </div>
                        <div class="d-stat-lbl">On Track</div>
                    </div>
                </div>

                <!-- Legend -->
                <div class="dispatch-legend">
                    Oldest dispatched shown first &nbsp;|&nbsp;
                    <span style="color:#e53935;">🔴 6+ days — act now</span> &nbsp;
                    <span style="color:#f59e0b;">🟡 3-5 days — check tracking</span> &nbsp;
                    <span style="color:#4caf50;">🟢 0-2 days — on track</span>
                </div>

                <!-- Tip -->
                <div class="dispatch-tip">
                    <strong>Daily routine (2 min):</strong>
                    Click <strong>Check Tracking →</strong> to open
                    the carrier website. Come back and click
                    <strong>✅ Delivered</strong> when confirmed.
                    Use checkboxes to update multiple orders at once.
                </div>

                <!-- ════════════════════════════════════════
         BULK FORM — wraps entire list
         Each card has its OWN single-action form
         Bulk uses checkboxes in this outer form
    ════════════════════════════════════════ -->
                <form method="POST" action="" id="bulkForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action"
                        id="bulkAction" value="">

                    <!-- Bulk toolbar -->
                    <div class="bulk-toolbar">
                        <label>
                            <input type="checkbox" id="selectAll"
                                onchange="toggleAll(this)">
                            Select All
                        </label>
                        <span class="bulk-sep">|</span>
                        <button type="button"
                            class="btn-b-transit"
                            onclick="submitBulk('mark_shipped')">
                            🚚 Mark In Transit
                        </button>
                        <button type="button"
                            class="btn-b-delivered"
                            onclick="submitBulk('mark_delivered')">
                            ✅ Mark Delivered
                        </button>
                        <span class="sel-count" id="selCount">
                            0 selected
                        </span>
                    </div>

                    <?php while ($o = mysqli_fetch_assoc($orders)):
                        $tracking  = $o['track_num']
                            ?? $o['tracking_number'] ?? '';
                        $carrier   = $o['track_carrier']
                            ?? $o['shipping_method'] ?? '';
                        $trackUrl  = buildTrackUrl($tracking, $carrier);
                        $days      = $o['dispatched_at']
                            ? floor((time() - strtotime(
                                $o['dispatched_at']
                            )) / 86400)
                            : 0;
                        if ($days <= 2) {
                            $urgClass = 'urg-green';
                            $pillCls  = 'green';
                            $icon     = '🟢';
                            $urgTxt   = 'On track';
                        } elseif ($days <= 5) {
                            $urgClass = 'urg-amber';
                            $pillCls  = 'amber';
                            $icon     = '🟡';
                            $urgTxt   = 'Check tracking';
                        } else {
                            $urgClass = 'urg-red';
                            $pillCls  = 'red';
                            $icon     = '🔴';
                            $urgTxt   = 'Overdue — act now';
                        }
                        $dayLabel   = $days == 1 ? '1 day' : "$days days";
                        $statusCls  = $o['status'] === 'shipped'
                            ? 'loz-shipped' : 'loz-dispatched';
                        $statusLbl  = $o['status'] === 'shipped'
                            ? '🚚 In Transit' : '🏷️ Dispatched';
                        $oid = intval($o['id']);
                    ?>

                        <div class="s-card <?php echo $urgClass; ?>">

                            <!-- Top -->
                            <div class="s-card-top">
                                <!-- Bulk checkbox (part of outer form) -->
                                <input type="checkbox"
                                    name="order_ids[]"
                                    value="<?php echo $oid; ?>"
                                    class="bulk-cb"
                                    onchange="updateCount()">

                                <div style="flex:1;min-width:0;">
                                    <div style="display:flex;align-items:center;
                        gap:8px;flex-wrap:wrap;">
                                        <span class="s-ref">
                                            #<?php echo str_pad(
                                                    $oid,
                                                    6,
                                                    '0',
                                                    STR_PAD_LEFT
                                                ); ?>
                                        </span>
                                        <span class="s-name">
                                            <?php echo htmlspecialchars(
                                                $o['customer_name']
                                            ); ?>
                                        </span>
                                        <span class="status-lozenge <?php
                                                                    echo $statusCls; ?>">
                                            <?php echo $statusLbl; ?>
                                        </span>
                                    </div>
                                    <div class="s-addr">
                                        📍 <?php echo htmlspecialchars(
                                                $o['customer_address']
                                            ); ?>
                                    </div>
                                </div>

                                <div class="urg-pill <?php echo $pillCls; ?>">
                                    <?php echo $icon; ?>
                                    <?php echo $dayLabel; ?> — <?php echo $urgTxt; ?>
                                </div>
                            </div>

                            <!-- Bottom — single-action forms are SEPARATE -->
                            <div class="s-card-bottom">

                                <!-- Tracking info -->
                                <div class="track-info">
                                    <?php if ($tracking): ?>
                                        <span class="track-badge">
                                            <?php echo htmlspecialchars($tracking); ?>
                                        </span>
                                        <?php if ($trackUrl): ?>
                                            <a href="<?php echo htmlspecialchars(
                                                            $trackUrl
                                                        ); ?>"
                                                target="_blank"
                                                class="btn-track-link">
                                                Check Tracking →
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($carrier): ?>
                                            <span class="carrier-txt">
                                                via <?php echo htmlspecialchars($carrier); ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="no-track">
                                            ⚠️ No tracking number
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Actions — each is its OWN form
               completely independent from bulkForm -->
                                <div class="card-actions">

                                    <?php if ($o['status'] === 'dispatched'): ?>
                                        <form method="POST" action=""
                                            style="display:inline;">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action"
                                                value="single_shipped">
                                            <input type="hidden" name="order_id"
                                                value="<?php echo $oid; ?>">
                                            <button type="submit"
                                                class="btn-transit"
                                                onclick="return confirm('Mark #<?php echo $oid; ?> as In Transit?')">
                                                🚚 In Transit
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="POST" action=""
                                        style="display:inline;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action"
                                            value="single_delivered">
                                        <input type="hidden" name="order_id"
                                            value="<?php echo $oid; ?>">
                                        <button type="submit"
                                            class="btn-delivered"
                                            onclick="return confirm('Mark #<?php echo $oid; ?> as Delivered? Customer will receive delivery email.')">
                                            ✅ Delivered
                                        </button>
                                    </form>

                                    <a href="/admin/order-detail?id=<?php
                                                                                    echo $oid; ?>"
                                        class="btn-view-sm">
                                        View →
                                    </a>

                                </div>
                            </div>
                        </div>

                    <?php endwhile; ?>
                </form>

            <?php endif; ?>
        </main>
    </div>

    <script>
        function toggleAll(source) {
            document.querySelectorAll('.bulk-cb')
                .forEach(cb => cb.checked = source.checked);
            updateCount();
        }

        function updateCount() {
            const n = document.querySelectorAll(
                '.bulk-cb:checked').length;
            document.getElementById('selCount')
                .textContent = n + ' selected';
        }

        function submitBulk(action) {
            const checked = document.querySelectorAll(
                '.bulk-cb:checked');
            if (checked.length === 0) {
                return;
            }
            const lbl = action === 'mark_delivered' ?
                'delivered' : 'in transit';
            if (!confirm('Mark ' + checked.length +
                    ' order(s) as ' + lbl + '?')) return;
            document.getElementById('bulkAction').value = action;
            document.getElementById('bulkForm').submit();
        }
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