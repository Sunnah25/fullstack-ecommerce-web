<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Only show if coming from checkout
// (cart must have items or session must exist)
if (
  empty($_SESSION['cart'])
  && empty($_SESSION['pending_order_id'])
) {
  header('Location: /home');
  exit();
}

// Clean up pending order if exists
if (!empty($_SESSION['pending_order_id'])) {
  require_once 'config.php';
  include 'includes/db.php';
  $pendingId = intval(
    $_SESSION['pending_order_id']
  );
  // Delete pending_payment orders older
  // than 1 hour to keep DB clean
  // Delete order items first due to foreign key
  mysqli_query(
    $conn,
    "DELETE FROM order_items
     WHERE order_id = $pendingId"
  );
  mysqli_query(
    $conn,
    "DELETE FROM orders
     WHERE id = $pendingId
     AND status = 'pending_payment'"
  );
  unset($_SESSION['pending_order_id']);
}

$pageTitle = "Payment Cancelled — " . SHOP_NAME;
include 'includes/header.php';
?>

<div class="confirmation-page">
  <div class="confirmation-box">
    <div class="confirmation-icon">😔</div>
    <h1>Payment Cancelled</h1>
    <p>Your payment was cancelled. Your cart is still saved.</p>
    <p>If you had any problems, please contact us and we'll help!</p>
    <div class="confirmation-actions">
      <a href="/cart" class="btn-primary">Back to Cart</a>
      <a href="/contact"
        class="btn-secondary" style="margin-top:10px; display:inline-block;">
        Contact Us
      </a>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>