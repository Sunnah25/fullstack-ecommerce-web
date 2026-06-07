<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';
include 'includes/db.php';
include 'includes/header.php';

$order    = null;
$error    = '';
$searched = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $searched  = true;
    $orderId   = intval($_POST['order_id'] ?? 0);
    $email     = trim(mysqli_real_escape_string($conn,
                 $_POST['email'] ?? ''));

    if (!$orderId || empty($email)) {
        $error = "Please enter both your order
                  number and email address.";
    } else {
        $order = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT o.*,
                    s.sendcloud_label_url,
                    s.carrier as ship_carrier,
                    s.service_name,
                    s.label_method
             FROM orders o
             LEFT JOIN shipments s
                ON s.order_id = o.id
             WHERE o.id = $orderId
             AND o.customer_email = '$email'
             LIMIT 1"));

        if (!$order) {
            $error = "We couldn't find that order.
                      Please check your order number
                      and email address.";
        }
    }
}

// Status steps definition
$steps = [
    'paid'       => [
        'label'  => 'Order Confirmed',
        'icon'   => '✅',
        'desc'   => 'Your payment was successful and
                     your order is confirmed.',
    ],
    'processing' => [
        'label'  => 'Preparing',
        'icon'   => '📦',
        'desc'   => 'We are carefully preparing
                     your order for dispatch.',
    ],
    'dispatched' => [
        'label'  => 'Dispatched',
        'icon'   => '🏷️',
        'desc'   => 'Your order has been packed
                     and a shipping label created.',
    ],
    'shipped'    => [
        'label'  => 'In Transit',
        'icon'   => '🚚',
        'desc'   => 'Your parcel is on its way
                     to you right now!',
    ],
    'delivered'  => [
        'label'  => 'Delivered',
        'icon'   => '🌸',
        'desc'   => 'Your order has been delivered.
                     Enjoy your fragrance!',
    ],
];

// Map status to step index
$statusIndex = [
    'pending_payment' => -1,
    'paid'            =>  0,
    'processing'      =>  1,
    'dispatched'      =>  2,
    'shipped'         =>  3,
    'delivered'       =>  4,
    'cancelled'       => -2,
    'refunded'        => -2,
];

$pageTitle = "Track Your Order - " . SHOP_NAME;
?>

<div class="tracking-page">

  <div class="tracking-header">
    <h1>Track Your Order</h1>
    <p>Enter your order number and email address
       to see your delivery status.</p>
  </div>

  <!-- Search Form -->
  <div class="tracking-search-box">
    <form method="POST" action="" class="tracking-form">
      <div class="tracking-form-row">
        <div class="form-group">
          <label>Order Number</label>
          <input type="number" name="order_id"
                 placeholder="e.g. 9"
                 value="<?php echo isset($orderId)
                         ? $orderId : ''; ?>"
                 required>
          <small>Found in your confirmation email</small>
        </div>
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email"
                 placeholder="Email used at checkout"
                 value="<?php echo isset($email)
                         ? htmlspecialchars($email)
                         : ''; ?>"
                 required>
        </div>
        <div class="tracking-btn-wrap">
          <button type="submit" class="btn-primary">
            Track Order →
          </button>
        </div>
      </div>
    </form>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error"
         style="max-width:700px; margin:0 auto 30px;">
      <?php echo $error; ?>
    </div>
  <?php endif; ?>

  <?php if ($order): ?>

    <?php
    $currentStatus = $order['status'];
    $currentIndex  = $statusIndex[$currentStatus] ?? 0;
    $isCancelled   = in_array($currentStatus,
                     ['cancelled', 'refunded']);
    ?>

    <!-- Order Info -->
    <div class="tracking-order-card">

      <div class="tracking-order-header">
        <div>
          <span class="tracking-order-number">
            Order #<?php echo str_pad($order['id'],
                    6, '0', STR_PAD_LEFT); ?>
          </span>
          <span class="tracking-order-date">
            Placed <?php echo date('d F Y',
                strtotime($order['created_at'])); ?>
          </span>
        </div>
        <div class="tracking-order-total">
          £<?php echo number_format(
              $order['total_amount'], 2); ?>
        </div>
      </div>

      <?php if ($isCancelled): ?>

        <div class="tracking-cancelled">
          <span>❌</span>
          <div>
            <strong>
              Order <?php echo ucfirst($currentStatus); ?>
            </strong>
            <p>This order has been
               <?php echo $currentStatus; ?>.
               <?php if ($currentStatus === 'refunded'): ?>
                 Your refund has been processed.
               <?php endif; ?>
            </p>
          </div>
        </div>

      <?php else: ?>

        <!-- Progress Steps -->
        <div class="tracking-steps">
          <?php foreach ($steps as $key => $step):
            $stepIndex = $statusIndex[$key];
            $isDone    = $currentIndex >= $stepIndex;
            $isCurrent = $currentIndex === $stepIndex;
          ?>
          <div class="tracking-step
               <?php echo $isDone ? 'done' : ''; ?>
               <?php echo $isCurrent ? 'current' : ''; ?>">

            <div class="step-icon-wrap">
              <div class="step-icon">
                <?php echo $isDone
                    ? $step['icon'] : '○'; ?>
              </div>
              <?php if ($key !== 'delivered'): ?>
                <div class="step-line
                     <?php echo $isDone
                         ? 'done' : ''; ?>">
                </div>
              <?php endif; ?>
            </div>

            <div class="step-content">
              <div class="step-label">
                <?php echo $step['label']; ?>
              </div>
              <?php if ($isCurrent): ?>
                <div class="step-desc">
                  <?php echo $step['desc']; ?>
                </div>
              <?php endif; ?>

              <?php
              // Show timestamps
              $timeField = match($key) {
                'paid'       => $order['created_at'],
                'dispatched' => $order['dispatched_at'],
                'shipped'    => $order['shipped_at'],
                'delivered'  => $order['delivered_at'],
                default      => null,
              };
              if ($isDone && $timeField): ?>
                <div class="step-time">
                  <?php echo date('d M Y, H:i',
                      strtotime($timeField)); ?>
                </div>
              <?php endif; ?>
            </div>

          </div>
          <?php endforeach; ?>
        </div>

        <!-- Tracking Info -->
        <?php if ($order['tracking_number']): ?>
        <div class="tracking-info-box">
          <div class="tracking-info-left">
            <span class="tracking-info-label">
              Tracking Number
            </span>
            <span class="tracking-info-number">
              <?php echo htmlspecialchars(
                  $order['tracking_number']); ?>
            </span>
            <span class="tracking-info-carrier">
              via <?php echo htmlspecialchars(
                  $order['service_name']
                  ?? $order['ship_carrier']
                  ?? 'carrier'); ?>
            </span>
          </div>
          <?php if ($order['tracking_url']): ?>
          <a href="<?php echo htmlspecialchars(
                       $order['tracking_url']); ?>"
             target="_blank"
             class="btn-primary"
             style="white-space:nowrap;">
            Track on Carrier Site →
          </a>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Delivery Address -->
        <div class="tracking-address">
          <span class="tracking-info-label">
            📍 Delivering To
          </span>
          <span><?php echo htmlspecialchars(
              $order['customer_address']); ?></span>
        </div>

      <?php endif; ?>

    </div>

    <!-- Need Help -->
    <div class="tracking-help">
      <p>Need help with your order?</p>
      <div style="display:flex; gap:12px;
                  justify-content:center;
                  flex-wrap:wrap; margin-top:12px;">
        <a href="/contact"
           class="btn-secondary">Contact Us</a>
        <a href="/returns"
           class="btn-secondary">Returns & Refunds</a>
      </div>
    </div>

  <?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>