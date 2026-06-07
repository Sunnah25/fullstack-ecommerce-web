<?php
// ============================================================
// ABANDONED ORDER CLEANUP
// Run via cron job — NOT on every page load
// Recommended schedule: every 30 minutes
//
// Windows Task Scheduler:
//   Program: C:\xampp\php\php.exe
//   Arguments: /var/www/html\cron\cleanup_orders.php
//
// Linux cron:
//   */30 * * * * php /var/www/cron/cleanup_orders.php
// ============================================================

require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/db.php';

$abandonedOrders = mysqli_query($conn,
    "SELECT id FROM orders
     WHERE status = 'pending_payment'
     AND created_at < NOW() - INTERVAL 2 HOUR");

if (!$abandonedOrders
    || mysqli_num_rows($abandonedOrders) === 0) {
    echo date('Y-m-d H:i:s')
       . " — No abandoned orders to clean up.\n";
    exit();
}

$ids = [];
while ($row = mysqli_fetch_assoc($abandonedOrders)) {
    $ids[] = intval($row['id']);
}

$idList = implode(',', $ids);

// Delete order items first (foreign key)
mysqli_query($conn,
    "DELETE FROM order_items
     WHERE order_id IN ($idList)");

// Then delete the orders
mysqli_query($conn,
    "DELETE FROM orders
     WHERE id IN ($idList)");

$count = count($ids);
$msg   = date('Y-m-d H:i:s')
       . " — Cleaned up $count abandoned order(s):"
       . " [" . $idList . "]\n";

echo $msg;
error_log($msg);