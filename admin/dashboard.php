<?php
include 'auth.php';
include '../includes/db.php';

// Only count PAID orders (not pending_payment)
$totalOrders = mysqli_fetch_row(mysqli_query(
    $conn,
    "SELECT COUNT(*) FROM orders
     WHERE status NOT IN
     ('pending_payment','cancelled')"
))[0];

// Only count revenue from paid/dispatched/complete orders
$totalRevenue = mysqli_fetch_row(mysqli_query(
    $conn,
    "SELECT COALESCE(SUM(total_amount), 0)
     FROM orders
     WHERE status IN
     ('paid','processing','dispatched','complete')"
))[0];

// Orders waiting to be dispatched
$toDispatch = mysqli_fetch_row(mysqli_query(
    $conn,
    "SELECT COUNT(*) FROM orders
     WHERE status IN ('paid','processing')"
))[0];

$totalProducts = mysqli_fetch_row(mysqli_query(
    $conn,
    "SELECT COUNT(*) FROM products"
))[0];

$totalMessages = mysqli_fetch_row(mysqli_query(
    $conn,
    "SELECT COUNT(*) FROM messages
     WHERE is_read = 0"
))[0];

$pendingReturns = mysqli_fetch_row(mysqli_query(
    $conn,
    "SELECT COUNT(*) FROM returns
     WHERE status = 'pending'"
))[0];

// Recent PAID orders only
$recentOrders = mysqli_query(
    $conn,
    "SELECT * FROM orders
     WHERE status NOT IN ('pending_payment')
     ORDER BY created_at DESC LIMIT 5"
);

$openComplaints = mysqli_fetch_row(mysqli_query(
    $conn,
    "SELECT COUNT(*) FROM complaints
   WHERE status = 'open'"
))[0];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Admin</title>
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
                <li><a href="/admin/dashboard" class="active">📊 Dashboard</a></li>
                <li><a href="/admin/orders">📦 Orders</a></li>
                <li><a href="/admin/products">🌸 Products</a></li>
                <li><a href="/admin/messages">✉️ Messages</a></li>
                <li><a href="/admin/returns">🔄 Returns</a></li>
                <li><a href="/admin/complaints">⚠️ Complaints</a></li>
                <li><a href="/admin/logout">🚪 Logout</a></li>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">
            <h1>Dashboard</h1>
            <p class="admin-subtitle">Welcome back, <?php echo htmlspecialchars($_SESSION['admin_username']); ?>! 👋</p>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number">
                        <?php echo $totalOrders; ?>
                    </div>
                    <div class="stat-label">Total Orders
                        <?php if ($toDispatch > 0): ?>
                            <span class="unread-badge">
                                <?php echo $toDispatch; ?> to dispatch
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-number">
                        £<?php echo number_format($totalRevenue, 2); ?>
                    </div>
                    <div class="stat-label">Revenue (Paid Orders)</div>
                </div>

                <div class="stat-card">
                    <div class="stat-number">
                        <?php echo $totalProducts; ?>
                    </div>
                    <div class="stat-label">Products</div>
                </div>

                <div class="stat-card">
                    <div class="stat-number">
                        <?php echo $totalMessages; ?>
                    </div>
                    <div class="stat-label">Unread Messages</div>
                </div>

                <?php if ($pendingReturns > 0): ?>
                    <div class="stat-card">
                        <div class="stat-number">
                            <?php echo $pendingReturns; ?>
                        </div>
                        <div class="stat-label">Pending Returns</div>
                    </div>
                <?php endif; ?>

                <?php if ($openComplaints > 0): ?>
                    <div class="stat-card"
                        style="border-top-color:#e53935;">
                        <div class="stat-number"
                            style="color:#e53935;">
                            <?php echo $openComplaints; ?>
                        </div>
                        <div class="stat-label">Open Complaints</div>
                    </div>
                <?php endif; ?>
            </div>



            <!-- Monitoring Section -->
            <div class="detail-box" style="margin-top:30px;">
                <h3>📊 System Monitoring</h3>

                <div style="display:grid;
              grid-template-columns:repeat(3,1fr);
              gap:16px; margin-bottom:20px;">

                    <?php
                    // Failed payments in last 24h
                    $failedPayments = mysqli_fetch_row(mysqli_query(
                        $conn,
                        "SELECT COUNT(*) FROM payment_logs
         WHERE status = 'failed'
         AND created_at > NOW() - INTERVAL 24 HOUR"
                    ))[0] ?? 0;

                    // Unprocessed webhooks
                    $pendingWebhooks = mysqli_fetch_row(mysqli_query(
                        $conn,
                        "SELECT COUNT(*) FROM webhook_logs
         WHERE processed = 0
         AND created_at > NOW() - INTERVAL 1 HOUR"
                    ))[0] ?? 0;

                    // Orders stuck in pending
                    $stuckOrders = mysqli_fetch_row(mysqli_query(
                        $conn,
                        "SELECT COUNT(*) FROM orders
         WHERE status = 'pending_payment'
         AND created_at < NOW() - INTERVAL 2 HOUR"
                    ))[0] ?? 0;
                    ?>

                    <div style="background:<?php echo
                                            $failedPayments > 0
                                                ? '#fff5f5' : '#f0faf0'; ?>;
        border:1px solid <?php echo
                            $failedPayments > 0
                                ? '#ffcdd2' : '#c8e6c9'; ?>;
        padding:16px; text-align:center;">
                        <div style="font-size:1.5rem;
                  font-weight:bold;
                  color:<?php echo
                        $failedPayments > 0
                            ? '#e53935' : '#2e7d32'; ?>;">
                            <?php echo $failedPayments; ?>
                        </div>
                        <div style="font-size:0.78rem;
                  color:#666; margin-top:4px;">
                            Failed Payments (24h)
                        </div>
                    </div>

                    <div style="background:<?php echo
                                            $pendingWebhooks > 0
                                                ? '#fff8e1' : '#f0faf0'; ?>;
        border:1px solid <?php echo
                            $pendingWebhooks > 0
                                ? '#ffe082' : '#c8e6c9'; ?>;
        padding:16px; text-align:center;">
                        <div style="font-size:1.5rem;
                  font-weight:bold;
                  color:<?php echo
                        $pendingWebhooks > 0
                            ? '#f59e0b' : '#2e7d32'; ?>;">
                            <?php echo $pendingWebhooks; ?>
                        </div>
                        <div style="font-size:0.78rem;
                  color:#666; margin-top:4px;">
                            Unprocessed Webhooks
                        </div>
                    </div>

                    <div style="background:<?php echo
                                            $stuckOrders > 0
                                                ? '#fff8e1' : '#f0faf0'; ?>;
        border:1px solid <?php echo
                            $stuckOrders > 0
                                ? '#ffe082' : '#c8e6c9'; ?>;
        padding:16px; text-align:center;">
                        <div style="font-size:1.5rem;
                  font-weight:bold;
                  color:<?php echo
                        $stuckOrders > 0
                            ? '#f59e0b' : '#2e7d32'; ?>;">
                            <?php echo $stuckOrders; ?>
                        </div>
                        <div style="font-size:0.78rem;
                  color:#666; margin-top:4px;">
                            Stuck Pending Orders
                        </div>
                    </div>

                </div>

                <!-- Recent payment log -->
                <?php
                $recentLogs = mysqli_query(
                    $conn,
                    "SELECT * FROM payment_logs
       ORDER BY created_at DESC LIMIT 10"
                );
                if (mysqli_num_rows($recentLogs) > 0): ?>
                    <h4 style="font-size:0.82rem;
              letter-spacing:2px;
              text-transform:uppercase;
              color:#999; margin-bottom:10px;">
                        Recent Payment Activity
                    </h4>
                    <table class="admin-table dashboard-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Order</th>
                                <th>Event</th>
                                <th>Status</th>
                                <th>Amount</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($log = mysqli_fetch_assoc(
                                $recentLogs
                            )): ?>
                                <tr>
                                    <td style="font-size:0.75rem; color:#999;">
                                        <?php echo date(
                                            'd M H:i',
                                            strtotime($log['created_at'])
                                        ); ?>
                                    </td>
                                    <td>
                                        <?php echo $log['order_id']
                                            ? '#' . $log['order_id'] : '—'; ?>
                                    </td>
                                    <td style="font-size:0.78rem;">
                                        <?php echo htmlspecialchars(
                                            $log['event_type']
                                        ); ?>
                                    </td>
                                    <td>
                                        <span style="font-size:0.72rem;
                       padding:2px 8px;
                       background:<?php echo
                                    $log['status'] === 'success'
                                        ? '#e8f5e9' : '#fff5f5'; ?>;
                       color:<?php echo
                                $log['status'] === 'success'
                                    ? '#2e7d32' : '#e53935'; ?>;">
                                            <?php echo ucfirst($log['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $log['amount'] > 0
                                            ? '£' . number_format($log['amount'], 2)
                                            : '—'; ?>
                                    </td>
                                    <td style="font-size:0.75rem; color:#999;
                   max-width:200px;
                   overflow:hidden;
                   text-overflow:ellipsis;
                   white-space:nowrap;">
                                        <?php echo htmlspecialchars(
                                            $log['notes'] ?? ''
                                        ); ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <!-- MOBILE CARDS: Payment Logs -->
                <div class="dashboard-mobile-cards">
                    <?php mysqli_data_seek($recentLogs, 0);
                    while ($log = mysqli_fetch_assoc($recentLogs)): ?>
                        <div class="mobile-order-card">
                            <div class="mobile-order-card-header" onclick="this.closest('.mobile-order-card').classList.toggle('open')">
                                <div class="mobile-order-card-left">
                                    <span class="mobile-order-card-id"><?php echo htmlspecialchars($log['event_type']); ?></span>
                                    <span class="mobile-order-card-name"><?php echo $log['order_id'] ? '#' . $log['order_id'] : '—'; ?></span>
                                </div>
                                <div class="mobile-order-card-right">
                                    <span class="badge <?php echo $log['status'] === 'success' ? 'badge-delivered' : 'badge-cancelled'; ?>">
                                        <?php echo ucfirst($log['status']); ?>
                                    </span>
                                    <span class="mobile-order-card-chevron">▼</span>
                                </div>
                            </div>
                            <div class="mobile-order-card-body">
                                <div class="mobile-order-card-row">
                                    <span>Amount</span>
                                    <span><?php echo $log['amount'] > 0 ? '£' . number_format($log['amount'], 2) : '—'; ?></span>
                                </div>
                                <div class="mobile-order-card-row">
                                    <span>Time</span>
                                    <span><?php echo date('d M H:i', strtotime($log['created_at'])); ?></span>
                                </div>
                                <div class="mobile-order-card-row">
                                    <span>Notes</span>
                                    <span><?php echo htmlspecialchars($log['notes'] ?? '—'); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>



            <!-- Recent Orders -->
            <div class="admin-table-wrap">
                <h2>Recent Orders</h2>
                <table class="admin-table dashboard-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Customer</th>
                            <th>Email</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($recentOrders) > 0): ?>
                            <?php while ($order = mysqli_fetch_assoc($recentOrders)): ?>
                                <tr>
                                    <td>#<?php echo $order['id']; ?></td>
                                    <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($order['customer_email']); ?></td>
                                    <td>£<?php echo number_format($order['total_amount'], 2); ?></td>
                                    <td><span class="badge badge-<?php echo $order['status']; ?>"><?php echo $order['status']; ?></span></td>
                                    <td><?php echo date('d M Y', strtotime($order['created_at'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center; color:#999;">No orders yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- MOBILE CARDS: Recent Orders -->
                <div class="dashboard-mobile-cards">
                    <?php mysqli_data_seek($recentOrders, 0);
                    while ($order = mysqli_fetch_assoc($recentOrders)): ?>
                        <div class="mobile-order-card">
                            <div class="mobile-order-card-header" onclick="this.closest('.mobile-order-card').classList.toggle('open')">
                                <div class="mobile-order-card-left">
                                    <span class="mobile-order-card-id">#<?php echo $order['id']; ?></span>
                                    <span class="mobile-order-card-name"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                                </div>
                                <div class="mobile-order-card-right">
                                    <span class="badge badge-<?php echo $order['status']; ?>"><?php echo $order['status']; ?></span>
                                    <span class="mobile-order-card-chevron">▼</span>
                                </div>
                            </div>
                            <div class="mobile-order-card-body">
                                <div class="mobile-order-card-row">
                                    <span>Total</span>
                                    <span>£<?php echo number_format($order['total_amount'], 2); ?></span>
                                </div>
                                <div class="mobile-order-card-row">
                                    <span>Email</span>
                                    <span><?php echo htmlspecialchars($order['customer_email']); ?></span>
                                </div>
                                <div class="mobile-order-card-row">
                                    <span>Date</span>
                                    <span><?php echo date('d M Y', strtotime($order['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
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