<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
require_once 'includes/csrf.php';
require_once 'config.php';
include 'includes/db.php';

$error  = '';
$errors = [];
$order  = null;

// ── Step 1: Find order ───────────────────────────────────────
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && isset($_POST['lookup'])
) {

  verifyCsrfToken();
  $orderId = intval($_POST['order_id']);
  $email   = trim(mysqli_real_escape_string(
    $conn,
    $_POST['email']
  ));

  $order = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT * FROM orders
         WHERE id = $orderId
         AND customer_email = '$email'"
  ));

  if (!$order) {
    $error = "We couldn't find that order.
                  Please check your order number
                  and email address.";
  } elseif (!in_array(
    $order['status'],
    [
      'paid',
      'processing',
      'dispatched',
      'shipped',
      'delivered'
    ]
  )) {
    $statusMessages = [
      'pending_payment' =>
      "Your order payment is still
             being processed.",
      'cancelled' =>
      "This order has been cancelled.",
      'refunded'  =>
      "This order has already
             been refunded.",
    ];
    $msg = $statusMessages[$order['status']]
      ?? "This order is not eligible.";
    $error = "⚠️ $msg";
    $order = null;
  } else {
    // Check for existing open request
    $existing = mysqli_fetch_assoc(mysqli_query(
      $conn,
      "SELECT id, status, type,
                    resolution_choice
             FROM returns
             WHERE order_id = $orderId
             ORDER BY created_at DESC
             LIMIT 1"
    ));

    if ($existing) {
      $blockedStatuses = [
        'pending'         =>
        "Your request (#"
          . $existing['id']
          . ") has been submitted and is
         awaiting review. We will respond
         within 2 working days.",
        'label_sent'      =>
        "Your return label has been sent
         to your email. Please return
         the item using the label provided.",
        'awaiting_return' =>
        "We are waiting to receive
         your returned item.",
        'awaiting_refund' =>
        "We have received your item
         and are processing your resolution.",
        'approved'        =>
        "Your request has been approved.
         Please check your email
         for next steps.",
        'refunded'        =>
        "Your refund has already
         been processed for this order.",
      ];

      // Rejected requests can re-submit
      // All other active statuses are blocked
      if (isset($blockedStatuses[$existing['status']])) {
        $error = "📋 "
          . $blockedStatuses[$existing['status']];
        $order = null;
      }
      // If rejected — allow through, show info message
      if ($existing['status'] === 'rejected') {
        // Show a gentle notice but don't block
        $info = "ℹ️ Your previous request was not
             approved. You may submit a new
             request below.";
      }
    }
  }
}

// ── Step 2: Submit return request ───────────────────────────
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && isset($_POST['submit_return'])
) {

  verifyCsrfToken();
  $orderId    = intval($_POST['order_id']);
  $email      = trim(mysqli_real_escape_string(
    $conn,
    $_POST['email']
  ));
  $reason     = trim(mysqli_real_escape_string(
    $conn,
    $_POST['reason']
  ));
  $type       = mysqli_real_escape_string(
    $conn,
    $_POST['type']
  );
  $resolution = mysqli_real_escape_string(
    $conn,
    $_POST['resolution_choice']
  );

  $order = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT * FROM orders
     WHERE id = $orderId
     AND customer_email = '$email'
     AND status IN (
       'paid','processing',
       'dispatched','shipped','delivered'
     )"
  ));

  $isCancellation = in_array($type, [
    'cancellation_before_dispatch',
    'cancellation_after_dispatch'
  ]);

  if (!$order) {
    $error = "Order not found or not eligible.";
  } elseif ($isCancellation && in_array(
    $order['status'],
    ['delivered']
  )) {
    $error = "This order has already been
              delivered. Please use the
              returns process instead.";
  } elseif (
    !$isCancellation
    && $order['status'] !== 'delivered'
  ) {
    $error = "Returns are only available
              for delivered orders.
              If you want to cancel,
              please select a cancellation
              option.";
  } elseif (empty($type)) {
    $errors[] = "Please select a return reason.";
  } elseif (empty($reason)) {
    $errors[] = "Please describe the issue.";
  } elseif (
    empty($resolution)
    && !$isCancellation
  ) {
    $errors[] = "Please select your preferred
                 resolution.";
  } else {
    $name = mysqli_real_escape_string(
      $conn,
      $order['customer_name']
    );

    mysqli_query(
      $conn,
      "INSERT INTO returns
             (order_id, customer_name,
              customer_email, reason,
              type, resolution_choice)
             VALUES ('$orderId','$name',
                     '$email','$reason',
                     '$type','$resolution')"
    );

    // Also block submissions within 30 seconds
    // of each other (double-click protection)
    $recentSubmit = mysqli_fetch_row(mysqli_query(
      $conn,
      "SELECT COUNT(*) FROM returns
     WHERE order_id = $orderId
     AND customer_email = '$email'
     AND created_at > NOW() - INTERVAL 30 SECOND"
    ));

    if ($recentSubmit[0] > 0) {
      $_SESSION['return_success'] =
        "Your request has been submitted.";
      header('Location: /home');
      exit();
    }

    $returnId = mysqli_insert_id($conn);

    require_once 'includes/mailer.php';
    sendReturnRequestEmail(
      $conn,
      $returnId,
      $order
    );

    $_SESSION['return_success'] =
      "Your return request #$returnId has
             been submitted. We will respond
             within 2 working days with your
             return label. Please keep the item
             and original packaging.";

    header('Location: /home');
    exit();
  }
}

$pageTitle = "Returns — " . SHOP_NAME;
include 'includes/header.php';
?>

<div class="returns-page">
  <h1>Returns & Cancellations</h1>
  <p class="returns-intro">
    Need to cancel an order or return an item?
    Enter your order details below to get started.
  </p>

  <!-- Important Notice -->
  <?php if (
    isset($order)
    && $order['status'] === 'delivered'
  ): ?>
    <div class="complaint-notice"
      style="margin-bottom:28px;">
      <span>📦</span>
      <div>
        <strong>Please keep the original packaging
          and do not use the item.</strong>
        <p>For wrong or damaged items this helps
          us process your return faster.</p>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <?php echo $error; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $e): ?>
        <p>⚠️ <?php echo $e; ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$order): ?>
    <!-- STEP 1: Find Order -->
    <div class="returns-box">
      <h2>Step 1 — Find Your Order</h2>
      <form method="POST" action=""
        class="returns-form">
        <?php echo csrfField(); ?>
        <input type="hidden" name="lookup" value="1">
        <div class="form-group">
          <label>Order Number *</label>
          <input type="number" name="order_id"
            placeholder="e.g. 9"
            value="<?php echo isset(
                      $_POST['order_id']
                    )
                      ? intval($_POST['order_id'])
                      : ''; ?>"
            required>
          <small>Found in your confirmation email</small>
        </div>
        <div class="form-group">
          <label>Email Address *</label>
          <input type="email" name="email"
            placeholder="Email used at checkout"
            value="<?php echo isset($_POST['email'])
                      ? htmlspecialchars(
                        $_POST['email']
                      ) : ''; ?>"
            required>
        </div>
        <button type="submit" class="btn-primary">
          Find My Order →
        </button>
      </form>
    </div>

  <?php else: ?>
    <!-- STEP 2: Return Form -->
    <div class="returns-box">
      <h2>Step 2 — Tell Us What Happened</h2>

      <div class="order-found-card">
        <p>✅ <strong>Order #<?php echo $order['id']; ?>
            found</strong></p>
        <p>Placed <?php echo date(
                    'd M Y',
                    strtotime($order['created_at'])
                  ); ?></p>
        <p>Total: <strong>£<?php echo number_format(
                              $order['total_amount'],
                              2
                            ); ?></strong></p>
        <p>Status: <span class="badge badge-<?php echo $order['status']; ?>">
            <?php echo ucfirst($order['status']); ?>
          </span></p>
      </div>

      <?php if (isset($info)): ?>
        <div class="alert alert-error"
          style="background:#fff8e1;
            border-color:#f59e0b;
            color:#92400e;">
          <?php echo $info; ?>
        </div>
      <?php endif; ?>

      <form method="POST" action=""
        class="returns-form">
        <?php echo csrfField(); ?>
        <input type="hidden" name="submit_return"
          value="1">
        <input type="hidden" name="order_id"
          value="<?php echo $order['id']; ?>">
        <input type="hidden" name="email"
          value="<?php echo htmlspecialchars(
                    $order['customer_email']
                  ); ?>">

        <div class="form-group">
          <label>Reason for Return *</label>
          <select name="type" required
            style="width:100%;
                       padding:12px 16px;
                       border:1px solid #ddd;
                       font-family:Georgia,serif;
                       font-size:0.95rem;
                       background:#fff;">
            <option value="">— Select —</option>

            <?php if (in_array(
              $order['status'],
              ['paid', 'processing']
            )): ?>
              <!-- Not yet dispatched -->
              <option value="cancellation_before_dispatch">
                Cancel my order — full refund
                (not yet dispatched)
              </option>

            <?php elseif (in_array(
              $order['status'],
              ['dispatched', 'shipped']
            )): ?>
              <!-- Already dispatched -->
              <option value="cancellation_after_dispatch">
                Cancel my order — partial refund
                (£3.99 handling fee applies,
                item must be returned)
              </option>

            <?php elseif ($order['status'] === 'delivered'): ?>
              <!-- Delivered — full return options -->
              <option value="wrong_item">
                Wrong item received
              </option>
              <option value="missing_item">
                Missing item — part of order missing
              </option>
              <option value="damaged_item">
                Damaged item — arrived broken or damaged
              </option>
              <option value="not_as_described">
                Not as described
              </option>
              <option value="return_for_refund">
                I would like to return for a refund
              </option>
            <?php endif; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Please Describe the Issue *</label>
          <textarea name="reason"
            placeholder="Describe what happened
in detail. For wrong items tell us what you received.
For damaged items describe the damage..."
            required
            style="height:120px;"></textarea>
        </div>

        <?php if (
          !in_array(
            $_POST['type'] ?? $order['status'],
            ['cancellation_before_dispatch']
          )
          && $order['status'] === 'delivered'
        ): ?>
          <div class="form-group">
            <label>How Would You Like This Resolved? *</label>
            <div class="resolution-options">
              <label class="resolution-option">
                <input type="radio"
                  name="resolution_choice"
                  value="refund" required>
                <div class="resolution-info">
                  <strong>💳 Full Refund</strong>
                  <p>Receive a full refund to your original
                    payment method after we receive the
                    returned item.</p>
                </div>
              </label>
              <label class="resolution-option">
                <input type="radio"
                  name="resolution_choice"
                  value="replacement">
                <div class="resolution-info">
                  <strong>🔄 Send Replacement</strong>
                  <p>We send you the correct item after
                    receiving your return. No charge.</p>
                </div>
              </label>
            </div>
          </div>
        <?php else: ?>
          <!-- Cancellation — resolution is always refund -->
          <input type="hidden" name="resolution_choice"
            value="refund">
        <?php endif; ?>

        <div class="returns-policy-note">
          <?php if (in_array(
            $order['status'],
            ['paid', 'processing']
          )): ?>
            <p>📋 <strong>Cancellation before dispatch:</strong></p>
            <p>Your order has not yet been dispatched.
              If you cancel now you will receive a
              <strong>full refund</strong> within
              5-10 working days. No return needed.
            </p>

          <?php elseif (in_array(
            $order['status'],
            ['dispatched', 'shipped']
          )): ?>
            <p>📋 <strong>Cancellation after dispatch:</strong></p>
            <p>Your order has already been dispatched.
              You can still cancel but a
              <strong>handling fee</strong> will be
              deducted from your refund to cover
              our outbound shipping costs.
            </p>
            <p>You do <strong>not</strong> need to
              return the item.</p>

          <?php else: ?>
            <p>📋 <strong>What happens next:</strong></p>
            <p>We will review your request and email
              you a <strong>free prepaid return label
              </strong> within 2 working days.</p>
            <p>Once we receive your item we will
              process your chosen resolution within
              5-10 working days.</p>
          <?php endif; ?>
        </div>

        <!-- Correct button based on status -->
        <?php if (in_array(
          $order['status'],
          [
            'paid',
            'processing',
            'dispatched',
            'shipped'
          ]
        )): ?>
          <button type="submit" class="btn-primary"
            style="background:#e53935;"
            onclick="return confirm(
            'Are you sure you want to cancel this order?')">
            ✕ Submit Cancellation Request
          </button>
        <?php else: ?>
          <button type="submit" class="btn-primary">
            Submit Return Request →
          </button>
        <?php endif; ?>
      </form>
    </div>
  <?php endif; ?>

  <!-- Policy Cards -->
  <div class="returns-policy-grid">
    <div class="policy-card">
      <div class="policy-icon">✅</div>
      <h3>Delivered Orders Only</h3>
      <p>Returns available for 30 days
        after delivery.</p>
    </div>
    <div class="policy-card">
      <div class="policy-icon">🏷️</div>
      <h3>Free Return Label</h3>
      <p>We email a prepaid return label —
        no cost to you.</p>
    </div>
    <div class="policy-card">
      <div class="policy-icon">💳</div>
      <h3>Your Choice</h3>
      <p>Choose full refund or replacement
        item — your decision.</p>
    </div>
  </div>

</div>


<script>
  // Fallback for browsers without :has() support
  document.querySelectorAll(
      'input[name="resolution_choice"]')
    .forEach(radio => {
      radio.addEventListener('change', function() {
        // Remove active from all
        document.querySelectorAll(
            '.resolution-option')
          .forEach(opt => {
            opt.style.borderColor = '#ddd';
            opt.style.background = '#fff';
          });
        // Add active to selected
        this.closest('.resolution-option')
          .style.borderColor = '#d4af7a';
        this.closest('.resolution-option')
          .style.background = '#fffdf9';
      });
    });
</script>


<?php include 'includes/footer.php'; ?>