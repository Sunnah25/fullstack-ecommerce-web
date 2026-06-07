<?php
include 'auth.php';
include '../includes/db.php';
include '../includes/order_status.php';
include 'flash.php';
require_once '../includes/csrf.php';


// ── CANCEL ORDER ─────────────────────────────────────────
// ── CANCEL ORDER ─────────────────────────────────────────
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && ($_POST['action'] ?? '') === 'cancel'
) {
  verifyCsrfToken();
  $oid = intval($_POST['order_id']);

  // Verify order exists and is in a cancellable state
  $stmt = mysqli_prepare(
    $conn,
    "SELECT status FROM orders WHERE id = ?"
  );
  mysqli_stmt_bind_param($stmt, "i", $oid);
  mysqli_stmt_execute($stmt);
  $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);

  if (!$order || !in_array($order['status'], ['paid', 'processing'])) {
    $_SESSION['error'] = "Order cannot be cancelled in its current state.";
  } else {
    mysqli_begin_transaction($conn);
    try {
      $stmt = mysqli_prepare(
        $conn,
        "SELECT product_id, quantity FROM order_items WHERE order_id = ?"
      );
      mysqli_stmt_bind_param($stmt, "i", $oid);
      mysqli_stmt_execute($stmt);
      $items = mysqli_stmt_get_result($stmt);
      mysqli_stmt_close($stmt);

      while ($item = mysqli_fetch_assoc($items)) {
        $stmt = mysqli_prepare(
          $conn,
          "UPDATE products SET stock = stock + ? WHERE id = ?"
        );
        mysqli_stmt_bind_param(
          $stmt,
          "ii",
          $item['quantity'],
          $item['product_id']
        );
        if (!mysqli_stmt_execute($stmt)) {
          throw new Exception(mysqli_error($conn));
        }
        mysqli_stmt_close($stmt);
      }

      updateOrderStatus($conn, $oid, 'cancelled');
      mysqli_commit($conn);
      $_SESSION['success'] = "Order #$oid cancelled and stock restored.";
    } catch (Exception $e) {
      mysqli_rollback($conn);
      $_SESSION['error'] = "Failed to cancel order. Please try again.";
    }
  }
}

// ── MARK DELIVERED (manual fallback only) ────────────────
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && ($_POST['action'] ?? '') === 'mark_delivered'
) {
  verifyCsrfToken();
  $oid = intval($_POST['order_id']);

  $stmt = mysqli_prepare(
    $conn,
    "SELECT status FROM orders WHERE id = ?"
  );
  mysqli_stmt_bind_param($stmt, "i", $oid);
  mysqli_stmt_execute($stmt);
  $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);

  if ($order && in_array($order['status'], ['dispatched', 'shipped'])) {
    updateOrderStatus($conn, $oid, 'delivered');
    $_SESSION['success'] = "Order #$oid marked as delivered.";
  } else {
    $_SESSION['error'] = "Cannot mark as delivered — order has not been dispatched yet.";
  }
}

// ── FETCH ORDERS ─────────────────────────────────────────
$tab    = $_GET['tab'] ?? 'awaiting';
$search = trim($_GET['search'] ?? '');

function runOrderQuery(mysqli $conn, string $sql, string $types, array $params): mysqli_result
{
  $stmt = mysqli_prepare($conn, $sql);
  if ($params) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
  }
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  mysqli_stmt_close($stmt);
  return $result;
}

if (!empty($search)) {
  $s    = '%' . ltrim($search, '#') . '%';
  $like = [$s, $s, $s, $s];

  $awaitingOrders = runOrderQuery(
    $conn,
    "SELECT o.*, s.tracking_number as ship_tracking, s.carrier as ship_carrier
         FROM orders o LEFT JOIN shipments s ON s.order_id = o.id
         WHERE o.status IN ('paid','processing')
           AND (o.customer_name LIKE ? OR o.customer_email LIKE ? OR o.id LIKE ? OR o.tracking_number LIKE ?)
         ORDER BY o.created_at ASC",
    "ssss",
    $like
  );
  $dispatchedOrders = runOrderQuery(
    $conn,
    "SELECT o.*, s.tracking_number as ship_tracking, s.carrier as ship_carrier,
                s.sendcloud_label_url, s.label_method
         FROM orders o LEFT JOIN shipments s ON s.order_id = o.id
         WHERE o.status IN ('dispatched','shipped')
           AND (o.customer_name LIKE ? OR o.customer_email LIKE ? OR o.id LIKE ? OR o.tracking_number LIKE ?)
         ORDER BY o.dispatched_at DESC",
    "ssss",
    $like
  );
  $deliveredOrders = runOrderQuery(
    $conn,
    "SELECT o.*, s.tracking_number as ship_tracking
         FROM orders o LEFT JOIN shipments s ON s.order_id = o.id
         WHERE o.status = 'delivered'
           AND (o.customer_name LIKE ? OR o.customer_email LIKE ? OR o.id LIKE ? OR o.tracking_number LIKE ?)
         ORDER BY o.delivered_at DESC",
    "ssss",
    $like
  );
  $allOrders = runOrderQuery(
    $conn,
    "SELECT o.*, s.tracking_number as ship_tracking, s.carrier as ship_carrier
         FROM orders o LEFT JOIN shipments s ON s.order_id = o.id
         WHERE o.status != 'pending_payment'
           AND (o.customer_name LIKE ? OR o.customer_email LIKE ? OR o.id LIKE ? OR o.tracking_number LIKE ?)
         ORDER BY o.created_at DESC",
    "ssss",
    $like
  );
} else {
  $awaitingOrders = runOrderQuery(
    $conn,
    "SELECT o.*, s.tracking_number as ship_tracking, s.carrier as ship_carrier
         FROM orders o LEFT JOIN shipments s ON s.order_id = o.id
         WHERE o.status IN ('paid','processing')
         ORDER BY o.created_at ASC",
    "",
    []
  );
  $dispatchedOrders = runOrderQuery(
    $conn,
    "SELECT o.*, s.tracking_number as ship_tracking, s.carrier as ship_carrier,
                s.sendcloud_label_url, s.label_method
         FROM orders o LEFT JOIN shipments s ON s.order_id = o.id
         WHERE o.status IN ('dispatched','shipped')
         ORDER BY o.dispatched_at DESC",
    "",
    []
  );
  $deliveredOrders = runOrderQuery(
    $conn,
    "SELECT o.*, s.tracking_number as ship_tracking
         FROM orders o LEFT JOIN shipments s ON s.order_id = o.id
         WHERE o.status = 'delivered'
         ORDER BY o.delivered_at DESC",
    "",
    []
  );
  $allOrders = runOrderQuery(
    $conn,
    "SELECT o.*, s.tracking_number as ship_tracking, s.carrier as ship_carrier
         FROM orders o LEFT JOIN shipments s ON s.order_id = o.id
         WHERE o.status != 'pending_payment'
         ORDER BY o.created_at DESC",
    "",
    []
  );
}

$awaitingCount   = mysqli_num_rows($awaitingOrders);
$dispatchedCount = mysqli_num_rows($dispatchedOrders);
$deliveredCount  = mysqli_num_rows($deliveredOrders);
$allCount        = mysqli_num_rows($allOrders);

mysqli_data_seek($awaitingOrders,   0);
mysqli_data_seek($dispatchedOrders, 0);
mysqli_data_seek($deliveredOrders,  0);
mysqli_data_seek($allOrders,        0);
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Orders - Admin</title>
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
        <li><a href="/admin/dashboard">
            📊 Dashboard</a></li>
        <li><a href="/admin/orders"
            class="active">📦 Orders</a></li>
        <li><a href="/admin/products">
            🌸 Products</a></li>
        <li><a href="/admin/messages">
            ✉️ Messages</a></li>
        <li><a href="/admin/returns">
            🔄 Returns</a></li>
        <li><a href="/admin/complaints">⚠️ Complaints</a></li>
        <li><a href="/admin/logout">
            🚪 Logout</a></li>
      </ul>
    </aside>

    <main class="admin-main">
      <h1>Orders</h1>
      <p class="admin-subtitle">
        Manage, dispatch and track all customer orders.
      </p>

      <?php include 'flash.php'; ?>


      <!-- Search -->
      <form method="GET" action="" style="margin-bottom:20px;">
        <?php if (isset($_GET['tab'])): ?>
          <input type="hidden" name="tab"
            value="<?php echo htmlspecialchars($tab); ?>">
        <?php endif; ?>
        <div style="display:flex; gap:10px; max-width:500px;">
          <input type="text"
            name="search"
            value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
            placeholder="Search by name, email or order #..."
            style="flex:1; padding:10px 14px;
                  border:1px solid #ddd;
                  font-family:Georgia,serif;
                  font-size:0.88rem;
                  outline:none;">
          <button type="submit" class="btn-primary"
            style="padding:10px 20px;
                   font-size:0.82rem;
                   letter-spacing:1px;">
            Search
          </button>
          <?php if (!empty($_GET['search'])): ?>
            <a href="?tab=<?php echo htmlspecialchars($tab); ?>"
              class="btn-secondary"
              style="padding:10px 16px; font-size:0.82rem;">
              ✕ Clear
            </a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Tabs -->
      <!-- Tabs -->
      <div class="order-tabs">
        <a href="?tab=awaiting&search=<?php echo urlencode($search); ?>"
          class="order-tab <?php echo $tab === 'awaiting' ? 'active' : ''; ?>">
          📬 Awaiting Dispatch
          <?php if ($awaitingCount > 0): ?>
            <span class="tab-count"><?php echo $awaitingCount; ?></span>
          <?php endif; ?>
        </a>
        <a href="?tab=dispatched&search=<?php echo urlencode($search); ?>"
          class="order-tab <?php echo $tab === 'dispatched' ? 'active' : ''; ?>">
          🚚 Dispatched
          <span class="tab-count tab-count-grey">
            <?php echo $dispatchedCount; ?>
          </span>
        </a>
        <a href="?tab=delivered&search=<?php echo urlencode($search); ?>"
          class="order-tab <?php echo $tab === 'delivered' ? 'active' : ''; ?>">
          ✅ Delivered
          <span class="tab-count tab-count-grey">
            <?php echo $deliveredCount; ?>
          </span>
        </a>
        <a href="?tab=all&search=<?php echo urlencode($search); ?>"
          class="order-tab <?php echo $tab === 'all' ? 'active' : ''; ?>">
          📋 All Orders
          <span class="tab-count tab-count-grey">
            <?php echo $allCount; ?>
          </span>
        </a>
      </div>

      <!-- ══════════════════════════════════
         TAB 1: AWAITING DISPATCH
    ══════════════════════════════════ -->
      <?php if ($tab === 'awaiting'): ?>
        <div class="admin-table-wrap">
          <h2>Awaiting Dispatch</h2>
          <?php if ($awaitingCount > 0): ?>
            <div class="admin-table-scroll">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Address</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php while ($o = mysqli_fetch_assoc(
                    $awaitingOrders
                  )): ?>
                    <tr>
                      <td><strong>#<?php echo $o['id']; ?></strong></td>
                      <td>
                        <?php echo htmlspecialchars(
                          $o['customer_name']
                        ); ?>
                        <br>
                        <span style="font-size:0.75rem; color:#999;">
                          <?php echo htmlspecialchars(
                            $o['customer_email']
                          ); ?>
                        </span>
                      </td>
                      <td style="font-size:0.82rem; max-width:160px;">
                        <?php echo htmlspecialchars(
                          $o['customer_address']
                        ); ?>
                      </td>
                      <td>
                        <strong>
                          £<?php echo number_format(
                              $o['total_amount'],
                              2
                            ); ?>
                        </strong>
                      </td>
                      <td style="font-size:0.8rem;">
                        <?php echo date(
                          'd M Y',
                          strtotime($o['created_at'])
                        ); ?>
                      </td>
                      <td>
                        <div style="display:flex;
                          gap:6px; flex-wrap:wrap;">
                          <a href="/admin/order-detail?id=<?php
                                                                          echo $o['id']; ?>"
                            class="btn-view">View</a>

                          <a href="/admin/create-shipment?order_id=<?php
                                                                                    echo $o['id']; ?>"
                            class="btn-dispatch">
                            🏷️ Create Label
                          </a>

                          <form method="POST" action=""
                            style="display:inline;">
                            <input type="hidden" name="action"
                              value="cancel">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="order_id"
                              value="<?php echo $o['id']; ?>">
                            <button type="submit"
                              class="btn-cancel-order"
                              onclick="return confirm(
                            'Cancel order #<?php
                                            echo $o['id']; ?>?Stock will be restored.')">
                              ✕ Cancel
                            </button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="orders-empty">
              🎉 All orders have been dispatched!
            </div>
          <?php endif; ?>



          <!-- MOBILE CARDS: Awaiting -->
          <div class="mobile-order-cards">
            <?php mysqli_data_seek($awaitingOrders, 0);
            while ($o = mysqli_fetch_assoc($awaitingOrders)): ?>
              <div class="mobile-order-card">
                <div class="mobile-order-card-header" onclick="this.closest('.mobile-order-card').classList.toggle('open')">
                  <div class="mobile-order-card-left">
                    <span class="mobile-order-card-id">#<?php echo $o['id']; ?></span>
                    <span class="mobile-order-card-name"><?php echo htmlspecialchars($o['customer_name']); ?></span>
                  </div>
                  <div class="mobile-order-card-right">
                    <span class="mobile-order-card-amount">£<?php echo number_format($o['total_amount'], 2); ?></span>
                    <span class="mobile-order-card-chevron">▼</span>
                  </div>
                </div>
                <div class="mobile-order-card-body">
                  <div class="mobile-order-card-row">
                    <span>Address</span>
                    <span><?php echo htmlspecialchars($o['customer_address']); ?></span>
                  </div>
                  <div class="mobile-order-card-row">
                    <span>Email</span>
                    <span><?php echo htmlspecialchars($o['customer_email']); ?></span>
                  </div>
                  <div class="mobile-order-card-row">
                    <span>Date</span>
                    <span><?php echo date('d M Y', strtotime($o['created_at'])); ?></span>
                  </div>
                  <div class="mobile-order-card-actions">
                    <a href="/admin/order-detail?id=<?php echo $o['id']; ?>" class="btn-view">View</a>
                    <a href="/admin/create-shipment?order_id=<?php echo $o['id']; ?>" class="btn-dispatch">🏷️ Create Label</a>
                    <form method="POST" action="">
                      <?php echo csrfField(); ?>
                      <input type="hidden" name="action" value="cancel">
                      <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                      <button type="submit" class="btn-cancel-order"
                        onclick="return confirm('Cancel order #<?php echo $o['id']; ?>? Stock will be restored.')">
                        ✕ Cancel
                      </button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endwhile; ?>
          </div>
        </div>

        <!-- ══════════════════════════════════
         TAB 2: DISPATCHED
    ══════════════════════════════════ -->
      <?php elseif ($tab === 'dispatched'): ?>
        <div class="admin-table-wrap">
          <div style="display:flex; justify-content:space-between; align-items:center; padding: 18px 24px; border-bottom: 1px solid #e0e0e0; margin-bottom: 0;">
            <h2 style="margin:0; padding:0; border:none; font-size:1rem; color:#1a1a1a; letter-spacing:2px;">Dispatched & In Transit</h2>
            <a href="/admin/update-shipments" class="btn-primary"
              style="font-size:0.82rem; padding:8px 16px; letter-spacing:1px;">
              🚚 Update Shipments
            </a>
          </div>
          <?php if ($dispatchedCount > 0): ?>
            <div class="admin-table-scroll">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Total</th>
                    <th>Carrier</th>
                    <th>Tracking</th>
                    <th>Status</th>
                    <th>Dispatched</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php while ($o = mysqli_fetch_assoc(
                    $dispatchedOrders
                  )): ?>
                    <tr>
                      <td>
                        <strong>#<?php echo $o['id']; ?></strong>
                      </td>
                      <td>
                        <?php echo htmlspecialchars(
                          $o['customer_name']
                        ); ?>
                      </td>
                      <td>
                        £<?php echo number_format(
                            $o['total_amount'],
                            2
                          ); ?>
                      </td>
                      <td style="font-size:0.82rem;">
                        <?php echo htmlspecialchars(
                          $o['ship_carrier'] ?? '—'
                        ); ?>
                      </td>
                      <td>
                        <?php if ($o['tracking_number']): ?>
                          <code style="font-size:0.75rem;
                             color:#1565c0;">
                            <?php echo htmlspecialchars(
                              $o['tracking_number']
                            ); ?>
                          </code>
                        <?php else: ?>
                          <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="badge
                <?php echo getStatusBadge(
                      $o['status']
                    ); ?>">
                          <?php echo getStatusLabel(
                            $o['status']
                          ); ?>
                        </span>
                      </td>
                      <td style="font-size:0.8rem;">
                        <?php echo $o['dispatched_at']
                          ? date(
                            'd M Y',
                            strtotime($o['dispatched_at'])
                          )
                          : '—'; ?>
                      </td>
                      <td>
                        <div style="display:flex;
                          gap:6px; flex-wrap:wrap;">
                          <a href="/admin/order-detail?id=<?php
                                                                          echo $o['id']; ?>"
                            class="btn-view">View</a>

                          <?php if ($o['sendcloud_label_url']): ?>
                            <a href="<?php echo htmlspecialchars(
                                        $o['sendcloud_label_url']
                                      ); ?>"
                              target="_blank"
                              class="btn-dispatch"
                              style="background:#3d2b1f;">
                              🖨️ Label
                            </a>
                          <?php endif; ?>



                        </div>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="orders-empty">
              No dispatched orders yet.
            </div>
          <?php endif; ?>


          <!-- MOBILE CARDS: Dispatched -->
          <div class="mobile-order-cards">
            <?php mysqli_data_seek($dispatchedOrders, 0);
            while ($o = mysqli_fetch_assoc($dispatchedOrders)): ?>
              <div class="mobile-order-card">
                <div class="mobile-order-card-header" onclick="this.closest('.mobile-order-card').classList.toggle('open')">
                  <div class="mobile-order-card-left">
                    <span class="mobile-order-card-id">#<?php echo $o['id']; ?></span>
                    <span class="mobile-order-card-name"><?php echo htmlspecialchars($o['customer_name']); ?></span>
                  </div>
                  <div class="mobile-order-card-right">
                    <span class="badge <?php echo getStatusBadge($o['status']); ?>"><?php echo getStatusLabel($o['status']); ?></span>
                    <span class="mobile-order-card-chevron">▼</span>
                  </div>
                </div>
                <div class="mobile-order-card-body">
                  <div class="mobile-order-card-row">
                    <span>Total</span>
                    <span>£<?php echo number_format($o['total_amount'], 2); ?></span>
                  </div>
                  <div class="mobile-order-card-row">
                    <span>Carrier</span>
                    <span><?php echo htmlspecialchars($o['ship_carrier'] ?? '—'); ?></span>
                  </div>
                  <div class="mobile-order-card-row">
                    <span>Tracking</span>
                    <span><?php echo htmlspecialchars($o['tracking_number'] ?? '—'); ?></span>
                  </div>
                  <div class="mobile-order-card-row">
                    <span>Dispatched</span>
                    <span><?php echo $o['dispatched_at'] ? date('d M Y', strtotime($o['dispatched_at'])) : '—'; ?></span>
                  </div>
                  <div class="mobile-order-card-actions">
                    <a href="/admin/order-detail?id=<?php echo $o['id']; ?>" class="btn-view">View</a>
                    <?php if ($o['sendcloud_label_url']): ?>
                      <a href="<?php echo htmlspecialchars($o['sendcloud_label_url']); ?>" target="_blank" class="btn-dispatch" style="background:#3d2b1f;">🖨️ Label</a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endwhile; ?>
          </div>
        </div>

        <!-- ══════════════════════════════════
         TAB 3: DELIVERED
    ══════════════════════════════════ -->
      <?php elseif ($tab === 'delivered'): ?>
        <div class="admin-table-wrap">
          <h2>Delivered Orders</h2>
          <?php if ($deliveredCount > 0): ?>
            <div class="admin-table-scroll">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Total</th>
                    <th>Tracking</th>
                    <th>Delivered</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php while ($o = mysqli_fetch_assoc(
                    $deliveredOrders
                  )): ?>
                    <tr>
                      <td>
                        <strong>#<?php echo $o['id']; ?></strong>
                      </td>
                      <td>
                        <?php echo htmlspecialchars(
                          $o['customer_name']
                        ); ?>
                      </td>
                      <td>
                        £<?php echo number_format(
                            $o['total_amount'],
                            2
                          ); ?>
                      </td>
                      <td>
                        <?php if ($o['ship_tracking']): ?>
                          <code style="font-size:0.75rem;
                             color:#2e7d32;">
                            <?php echo htmlspecialchars(
                              $o['ship_tracking']
                            ); ?>
                          </code>
                        <?php else: ?>
                          <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                      </td>
                      <td style="font-size:0.8rem;">
                        <?php echo $o['delivered_at']
                          ? date(
                            'd M Y',
                            strtotime($o['delivered_at'])
                          )
                          : '—'; ?>
                      </td>
                      <td>
                        <a href="/admin/order-detail?id=<?php
                                                                        echo $o['id']; ?>"
                          class="btn-view">View →</a>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="orders-empty">
              No delivered orders yet.
            </div>
          <?php endif; ?>

          <!-- MOBILE CARDS: Delivered -->
          <div class="mobile-order-cards">
            <?php mysqli_data_seek($deliveredOrders, 0);
            while ($o = mysqli_fetch_assoc($deliveredOrders)): ?>
              <div class="mobile-order-card">
                <div class="mobile-order-card-header" onclick="this.closest('.mobile-order-card').classList.toggle('open')">
                  <div class="mobile-order-card-left">
                    <span class="mobile-order-card-id">#<?php echo $o['id']; ?></span>
                    <span class="mobile-order-card-name"><?php echo htmlspecialchars($o['customer_name']); ?></span>
                  </div>
                  <div class="mobile-order-card-right">
                    <span class="mobile-order-card-amount">£<?php echo number_format($o['total_amount'], 2); ?></span>
                    <span class="mobile-order-card-chevron">▼</span>
                  </div>
                </div>
                <div class="mobile-order-card-body">
                  <div class="mobile-order-card-row">
                    <span>Tracking</span>
                    <span><?php echo htmlspecialchars($o['ship_tracking'] ?? '—'); ?></span>
                  </div>
                  <div class="mobile-order-card-row">
                    <span>Delivered</span>
                    <span><?php echo $o['delivered_at'] ? date('d M Y', strtotime($o['delivered_at'])) : '—'; ?></span>
                  </div>
                  <div class="mobile-order-card-actions">
                    <a href="/admin/order-detail?id=<?php echo $o['id']; ?>" class="btn-view">View →</a>
                  </div>
                </div>
              </div>
            <?php endwhile; ?>
          </div>
        </div>

        <!-- ══════════════════════════════════
         TAB 4: ALL ORDERS
    ══════════════════════════════════ -->
      <?php elseif ($tab === 'all'): ?>
        <div class="admin-table-wrap">
          <h2>All Orders</h2>
          <div class="admin-table-scroll">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Customer</th>
                  <th>Total</th>
                  <th>Status</th>
                  <th>Tracking</th>
                  <th>Date</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($allCount > 0):
                  while ($o = mysqli_fetch_assoc($allOrders)): ?>
                    <tr>
                      <td>
                        <strong>#<?php echo $o['id']; ?></strong>
                      </td>
                      <td>
                        <?php echo htmlspecialchars(
                          $o['customer_name']
                        ); ?>
                        <br>
                        <span style="font-size:0.75rem; color:#999;">
                          <?php echo htmlspecialchars(
                            $o['customer_email']
                          ); ?>
                        </span>
                      </td>
                      <td>
                        £<?php echo number_format(
                            $o['total_amount'],
                            2
                          ); ?>
                      </td>
                      <td>
                        <span class="badge
                <?php echo getStatusBadge(
                      $o['status']
                    ); ?>">
                          <?php echo getStatusLabel(
                            $o['status']
                          ); ?>
                        </span>
                      </td>
                      <td>
                        <?php if ($o['ship_tracking']): ?>
                          <code style="font-size:0.75rem;">
                            <?php echo htmlspecialchars(
                              $o['ship_tracking']
                            ); ?>
                          </code>
                        <?php else: ?>
                          <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                      </td>
                      <td style="font-size:0.8rem;">
                        <?php echo date(
                          'd M Y',
                          strtotime($o['created_at'])
                        ); ?>
                      </td>
                      <td>
                        <a href="/admin/order-detail?id=<?php
                                                                        echo $o['id']; ?>"
                          class="btn-view">View →</a>
                      </td>
                    </tr>
                  <?php endwhile;
                else: ?>
                  <tr>
                    <td colspan="7"
                      style="text-align:center;
                       color:#999; padding:30px;">
                      No orders yet.
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <!-- MOBILE CARDS: All Orders -->
          <div class="mobile-order-cards">
            <?php mysqli_data_seek($allOrders, 0);
            while ($o = mysqli_fetch_assoc($allOrders)): ?>
              <div class="mobile-order-card">
                <div class="mobile-order-card-header" onclick="this.closest('.mobile-order-card').classList.toggle('open')">
                  <div class="mobile-order-card-left">
                    <span class="mobile-order-card-id">#<?php echo $o['id']; ?></span>
                    <span class="mobile-order-card-name"><?php echo htmlspecialchars($o['customer_name']); ?></span>
                  </div>
                  <div class="mobile-order-card-right">
                    <span class="badge <?php echo getStatusBadge($o['status']); ?>"><?php echo getStatusLabel($o['status']); ?></span>
                    <span class="mobile-order-card-chevron">▼</span>
                  </div>
                </div>
                <div class="mobile-order-card-body">
                  <div class="mobile-order-card-row">
                    <span>Total</span>
                    <span>£<?php echo number_format($o['total_amount'], 2); ?></span>
                  </div>
                  <div class="mobile-order-card-row">
                    <span>Email</span>
                    <span><?php echo htmlspecialchars($o['customer_email']); ?></span>
                  </div>
                  <div class="mobile-order-card-row">
                    <span>Tracking</span>
                    <span><?php echo htmlspecialchars($o['ship_tracking'] ?? '—'); ?></span>
                  </div>
                  <div class="mobile-order-card-row">
                    <span>Date</span>
                    <span><?php echo date('d M Y', strtotime($o['created_at'])); ?></span>
                  </div>
                  <div class="mobile-order-card-actions">
                    <a href="/admin/order-detail?id=<?php echo $o['id']; ?>" class="btn-view">View →</a>
                  </div>
                </div>
              </div>
            <?php endwhile; ?>
          </div>
        </div>
      <?php endif; ?>

    </main>
  </div>

  <!-- ============================================
         ADMIN MOBILE MENU SCRIPT
         ============================================ -->
  <script>
    const adminMenuToggle = document.querySelector('.admin-menu-toggle');
    const adminSidebar = document.querySelector('.admin-sidebar');

    adminMenuToggle?.addEventListener('click', function(e) {
      e.stopPropagation();
      adminSidebar.classList.toggle('open');
      adminMenuToggle.classList.toggle('active');
    });

    // Close sidebar when clicking a sidebar link (navigate to different page)
    document.querySelectorAll('.admin-sidebar a').forEach(link => {
      link.addEventListener('click', function(e) {
        // Only close if it's a different page link, not tab links
        if (!this.href.includes('?tab=')) {
          adminSidebar.classList.remove('open');
          adminMenuToggle.classList.remove('active');
        }
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