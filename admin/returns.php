<?php
include 'auth.php';
include '../includes/db.php';
require_once '../config.php';
require_once '../vendor/autoload.php';
require_once '../includes/upload_security.php';
require_once '../includes/csrf.php';

// ── POST-Redirect-GET ─────────────────────────────
// After any POST action redirect immediately
// This prevents email resend on page refresh
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrfToken();
}

$success = '';
$error   = '';

// Read flash messages from session
if (isset($_SESSION['admin_success'])) {
  $success = $_SESSION['admin_success'];
  unset($_SESSION['admin_success']);
}
if (isset($_SESSION['admin_error'])) {
  $error = $_SESSION['admin_error'];
  unset($_SESSION['admin_error']);
}

// ── SEND RETURN LABEL ────────────────────────────────────────
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && ($_POST['action'] ?? '') === 'send_label'
) {

  $rid       = intval($_POST['return_id']);
  $carrier   = trim($_POST['return_carrier'] ?? '');
  $refundAmt = floatval($_POST['refund_amount'] ?? 0);
  $notes     = trim($_POST['admin_notes'] ?? '');



  $stmt = mysqli_prepare(
    $conn,
    "SELECT r.*, o.customer_name, o.customer_email,
               o.customer_address, o.total_amount
       FROM returns r
       JOIN orders o ON r.order_id = o.id
       WHERE r.id = ?"
  );
  mysqli_stmt_bind_param($stmt, "i", $rid);
  mysqli_stmt_execute($stmt);
  $ret = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);

  if (!$ret) {
    $_SESSION['admin_error'] = "Return request not found.";
    header('Location: /admin/returns');
    exit();
  }


  if ($refundAmt < 0 || $refundAmt > floatval($ret['total_amount'] ?? 99999)) {
    $_SESSION['admin_error'] = "Invalid refund amount.";
    header('Location: /admin/returns');
    exit();
  }

  if (empty($_FILES['label_file']['name'])) {
    $_SESSION['admin_error'] =
      "Please upload a label file.";
    header('Location: /admin/returns');
    exit();
  } else {
    require_once '../includes/upload_security.php';

    $validation = validateUpload(
      $_FILES['label_file'],
      5,
      'label'
    );

    if (isset($validation['error'])) {
      $_SESSION['admin_error'] =
        $validation['error'];
      header('Location: /admin/returns');
      exit();
    } else {
      $labelsDir = '../labels/';
      if (!is_dir($labelsDir)) {
        mkdir($labelsDir, 0755, true);
      }

      $fileName = 'return_' . $rid . '_'
        . time() . '.'
        . $validation['extension'];

      $moved = moveUploadedFileSafe(
        $_FILES['label_file']['tmp_name'],
        $labelsDir,
        $fileName
      );

      if ($moved) {
        $expires = time() + (7 * 24 * 60 * 60);
        $token   = hash(
          'sha256',
          $fileName . $ret['order_id']
            . $expires . STRIPE_SECRET_KEY
        );
        $fileUrl = SHOP_URL
          . '/download_label.php?file='
          . urlencode($fileName)
          . '&order_id='
          . $ret['order_id']
          . '&expires=' . $expires
          . '&token=' . $token;



        $stmt = mysqli_prepare(
          $conn,
          "UPDATE returns SET
               status            = 'label_sent',
               return_carrier    = ?,
               return_label_file = ?,
               return_label_url  = ?,
               return_label_type = ?,
               admin_notes       = ?,
               refund_amount     = ?
             WHERE id = ?"
        );
        mysqli_stmt_bind_param(
          $stmt,
          "sssssdi",
          $carrier,
          $fileName,
          $fileUrl,
          $validation['extension'],
          $notes,
          $refundAmt,
          $rid
        );
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if ($ret) {
          require_once '../includes/mailer.php';
          $labelData = [
            'carrier'    => $carrier,
            'label_url'  => $fileUrl,
            'label_type' => $validation['extension'],
            'file_name'  => $fileName,
          ];
          $orderData = [
            'id'              =>
            $ret['order_id'],
            'customer_name'   =>
            $ret['customer_name'],
            'customer_email'  =>
            $ret['customer_email'],
            'customer_address' =>
            $ret['customer_address'],
          ];
          sendReturnLabelEmail(
            $conn,
            $rid,
            $orderData,
            $labelData
          );
        }
        $_SESSION['admin_success'] =
          "✅ Return label sent to customer!";
        header('Location: /admin/returns');
        exit();
      } else {
        $_SESSION['admin_error'] =
          "File upload failed. Please try again.";
        header('Location: /admin/returns');
        exit();
      }
    }
  }
}



// ── SENDCLOUD RETURN LABEL ───────────────────────────────────
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && ($_POST['action'] ?? '') === 'sendcloud_return_label'
) {

  $rid        = intval($_POST['return_id']);
  $orderId    = intval($_POST['order_id']);
  $optionCode = trim($_POST['option_code'] ?? '');
  $refundAmt  = floatval($_POST['refund_amount'] ?? 0);
  $adminNotes = trim($_POST['admin_notes'] ?? '');
  // Get order details
  $stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $orderId);
  mysqli_stmt_execute($stmt);
  $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);

  if ($refundAmt < 0 || $refundAmt > floatval($order['total_amount'] ?? 99999)) {
    $_SESSION['admin_error'] = "Invalid refund amount.";
    header('Location: /admin/returns');
    exit();
  }

  if ($order) {
    include '../includes/sendcloud.php';
    $result = createReturnLabel($order, $optionCode);

    file_put_contents(
      '../sendcloud_return_response.json',
      json_encode($result, JSON_PRETTY_PRINT)
    );

    if (isset($result['error'])) {
      // If Sendcloud fails use dev mode
      if (
        defined('SENDCLOUD_DEV_MODE')
        && SENDCLOUD_DEV_MODE
      ) {
        $result = [
          'return_id' => 0,
          'parcel_id' => 'DEV-' . time(),
          'label_url' => '#',
        ];
      } else {
        $_SESSION['admin_error'] =
          "Sendcloud error: "
          . htmlspecialchars($result['error'])
          . " — Please use Option 2 instead.";
        header('Location: /admin/returns');
        exit();
      }
    }

    // v3 returns API responds with { return_id, parcel_id }
    // label_url is fetched separately and attached by createReturnLabel()
    if (!isset($error) && isset($result['parcel_id'])) {
      $tracking = (string)($result['parcel_id'] ?? '');
      $rawLabelUrl = $result['label_url'] ?? '';
      $carrier  = 'Sendcloud';

      // Whitelist allowed Sendcloud label domains
      $labelUrl = '';
      if (!empty($rawLabelUrl)) {
        $parsedUrl = parse_url($rawLabelUrl);
        $allowedHosts = ['sendcloud.com', 'label.sendcloud.sc', 'sendcloud.sc'];
        $host = strtolower($parsedUrl['host'] ?? '');
        $hostAllowed = false;
        foreach ($allowedHosts as $allowed) {
          if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
            $hostAllowed = true;
            break;
          }
        }
        if ($hostAllowed) {
          $labelUrl = $rawLabelUrl;
        } else {
          error_log("Sendcloud returned unexpected label URL domain: $host");
        }
      }

      $stmt = mysqli_prepare(
        $conn,
        "UPDATE returns SET
             status           = 'label_sent',
             return_carrier   = ?,
             return_label_url = ?,
             return_tracking  = ?,
             admin_notes      = ?,
             refund_amount    = ?
           WHERE id = ?"
      );
      mysqli_stmt_bind_param(
        $stmt,
        "ssssdi",
        $carrier,
        $labelUrl,
        $tracking,
        $adminNotes,
        $refundAmt,
        $rid
      );
      mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);

      // Email label to customer
      $labelData = [
        'carrier'   => 'Evri/Royal Mail',
        'label_url' => $labelUrl
          ?: '#',
      ];
      $orderData = [
        'id'               => $orderId,
        'customer_name'    => $order['customer_name'],
        'customer_email'   => $order['customer_email'],
        'customer_address' => $order['customer_address'],
      ];

      require_once '../includes/mailer.php';
      sendReturnLabelEmail(
        $conn,
        $rid,
        $orderData,
        $labelData
      );

      $_SESSION['admin_success'] =
        "✅ Sendcloud return label generated
     and emailed to customer!";
      header('Location: /admin/returns');
      exit();
    }
  }
}



// ── MARK ITEM RECEIVED ───────────────────────────────────────
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && ($_POST['action'] ?? '') === 'mark_received'
) {
  $rid = intval($_POST['return_id']);
  $stmt = mysqli_prepare(
    $conn,
    "UPDATE returns SET item_received = 1,
         item_received_at = NOW(), status = 'awaiting_refund'
       WHERE id = ?"
  );
  mysqli_stmt_bind_param($stmt, "i", $rid);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
  $_SESSION['admin_success'] =
    "Item marked as received.
     Now process the resolution below.";
  header('Location: /admin/returns');
  exit();
}

// ── PROCESS REFUND ───────────────────────────────────────────
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && ($_POST['action'] ?? '') === 'process_refund'
) {

  $rid = intval($_POST['return_id']);

  // Check not already refunded or processing
  $stmt = mysqli_prepare(
    $conn,
    "SELECT r.*, o.total_amount, o.customer_email,
               o.customer_name, o.payment_intent_id
       FROM returns r
       JOIN orders o ON r.order_id = o.id
       WHERE r.id = ?
       AND r.status NOT IN ('refunded','rejected','processing_refund')"
  );
  mysqli_stmt_bind_param($stmt, "i", $rid);
  mysqli_stmt_execute($stmt);
  $ret = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);

  if (!$ret) {
    $_SESSION['admin_error'] =
      "This request has already been
             processed or is not eligible.";
    header('Location: /admin/returns');
    exit();
  }

  // Atomically claim this refund
  // Prevents double-click race condition
  $claimStmt = mysqli_prepare(
    $conn,
    "UPDATE returns SET status = 'processing_refund'
     WHERE id = ? AND status NOT IN ('refunded','rejected','processing_refund')"
  );
  mysqli_stmt_bind_param($claimStmt, "i", $rid);
  mysqli_stmt_execute($claimStmt);
  $claimAffected = mysqli_stmt_affected_rows($claimStmt);
  mysqli_stmt_close($claimStmt);

  if ($claimAffected === 0) {
    $_SESSION['admin_error'] =
      "Already being processed.
             Please wait and refresh.";
    header('Location: /admin/returns');
    exit();
  }

  // Calculate refund amount — allow admin override from cancellation forms
  if (isset($_POST['override_refund_amount']) && $_POST['override_refund_amount'] !== '') {
    $refundAmount = floatval($_POST['override_refund_amount']);
  } else {
    $refundAmount = floatval(
      $ret['refund_amount'] ?: $ret['total_amount']
    );
  }

  if ($refundAmount <= 0 || $refundAmount > floatval($ret['total_amount'])) {
    $stmt = mysqli_prepare($conn, "UPDATE returns SET status = 'awaiting_refund' WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $rid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $_SESSION['admin_error'] = "Invalid refund amount. Must be between £0.01 and £" . number_format($ret['total_amount'], 2) . ".";
    header('Location: /admin/returns');
    exit();
  }

  $refundId = '';

  try {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

    // Use stored payment_intent_id — fast!
    // No need to fetch 100 sessions
    $paymentIntentId =
      $ret['payment_intent_id'] ?? '';

    if (empty($paymentIntentId)) {
      // Fallback for old orders without
      // payment_intent_id stored
      // Only needed during transition period
      $orderRow = mysqli_fetch_assoc(
        mysqli_query(
          $conn,
          "SELECT stripe_session_id
                 FROM orders
                 WHERE id = "
            . intval($ret['order_id'])
        )
      );

      if (!empty($orderRow['stripe_session_id'])) {
        $session = \Stripe\Checkout\Session
          ::retrieve(
            $orderRow['stripe_session_id']
          );
        $paymentIntentId =
          $session->payment_intent ?? '';
      }
    }

    if (empty($paymentIntentId)) {
      throw new Exception(
        "No payment intent found for
                 this order. Please refund
                 manually in Stripe dashboard."
      );
    }

    $refund = \Stripe\Refund::create([
      'payment_intent' => $paymentIntentId,
      'amount'         => intval(
        $refundAmount * 100
      ),
    ]);
    $refundId = $refund->id;

    // Success — update DB in transaction
    mysqli_begin_transaction($conn);
    try {
      $stmt = mysqli_prepare(
        $conn,
        "UPDATE returns SET status = 'refunded', refund_id = ?, resolved_at = NOW() WHERE id = ?"
      );
      mysqli_stmt_bind_param($stmt, "si", $refundId, $rid);
      if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_error($conn));
      mysqli_stmt_close($stmt);

      $orderIdInt = intval($ret['order_id']);
      $stmt = mysqli_prepare($conn, "UPDATE orders SET status = 'refunded' WHERE id = ?");
      mysqli_stmt_bind_param($stmt, "i", $orderIdInt);
      if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_error($conn));
      mysqli_stmt_close($stmt);

      // Restore stock
      $stmt = mysqli_prepare($conn, "SELECT product_id, quantity FROM order_items WHERE order_id = ?");
      mysqli_stmt_bind_param($stmt, "i", $orderIdInt);
      mysqli_stmt_execute($stmt);
      $orderItems = mysqli_stmt_get_result($stmt);
      mysqli_stmt_close($stmt);

      while ($item = mysqli_fetch_assoc($orderItems)) {
        $stmt = mysqli_prepare($conn, "UPDATE products SET stock = stock + ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $item['quantity'], $item['product_id']);
        if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_error($conn));
        mysqli_stmt_close($stmt);
      }

      mysqli_commit($conn);
    } catch (Exception $dbEx) {
      mysqli_rollback($conn);
      error_log("Refund DB update failed for return #$rid (Stripe ID: $refundId): " . $dbEx->getMessage());
      $_SESSION['admin_error'] = "Refund was processed by Stripe (ID: $refundId) but database update failed. Please update manually and contact support.";
      header('Location: /admin/returns');
      exit();
    }

    require_once '../includes/mailer.php';
    try {
      sendRefundConfirmationEmail($conn, $ret['order_id'], $refundAmount);
    } catch (Throwable $e) {
      error_log("Refund email failed for order #{$ret['order_id']}: " . $e->getMessage());
    }

    $_SESSION['admin_success'] =
      "✅ Refund of £" . number_format($refundAmount, 2) . " processed successfully! Stripe ID: $refundId";
  } catch (Exception $e) {
    // IMPORTANT: Reset status so admin can retry the refund
    $resetStmt = mysqli_prepare($conn, "UPDATE returns SET status = 'awaiting_refund' WHERE id = ?");
    mysqli_stmt_bind_param($resetStmt, "i", $rid);
    mysqli_stmt_execute($resetStmt);
    mysqli_stmt_close($resetStmt);



    error_log("Refund failed for return #$rid: " . $e->getMessage());
    $_SESSION['admin_error'] = "❌ Refund failed. Please check logs or process manually in the Stripe dashboard.";
  }

  header('Location: /admin/returns');
  exit();
}

// ── ADD REPLACEMENT TRACKING ─────────────────────────────────
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && ($_POST['action'] ?? '') === 'add_replacement_tracking'
) {

  $rid        = intval($_POST['return_id']);
  $orderId    = intval($_POST['order_id']);
  $tracking = trim($_POST['tracking_number'] ?? '');
  $carrier  = trim($_POST['carrier'] ?? '');
  $service  = trim($_POST['service_name'] ?? '');
  if (empty($tracking)) {
    $_SESSION['admin_error'] =
      "Please enter a tracking number.";
    header('Location: /admin/returns');
    exit();
  } else {
    // Add to order_tracking table
    $stmt = mysqli_prepare(
      $conn,
      "INSERT INTO order_tracking
         (order_id, tracking_number, carrier, service_name, label_type, notes)
         VALUES (?, ?, ?, ?, 'replacement', 'Replacement shipment')"
    );
    mysqli_stmt_bind_param($stmt, "isss", $orderId, $tracking, $carrier, $service);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Update return record
    $stmt = mysqli_prepare(
      $conn,
      "UPDATE returns SET
           replacement_tracking = ?,
           replacement_dispatched_at = NOW(),
           status = 'resolved',
           resolved_at = NOW()
         WHERE id = ?"
    );
    mysqli_stmt_bind_param($stmt, "si", $tracking, $rid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $orderId);
    mysqli_stmt_execute($stmt);
    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($order) {

      require_once '../includes/mailer.php';
      sendReplacementDispatchEmail(
        $conn,
        $orderId,
        $tracking,
        $carrier
      );
    }

    $_SESSION['admin_success'] =
      "✅ Replacement tracking added
     and customer notified!";
    header('Location: /admin/returns');
    exit();
  }
}


// ── REJECT ───────────────────────────────────────────────────
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && ($_POST['action'] ?? '') === 'reject'
) {
  $rid   = intval($_POST['return_id']);
  $rejectNotes = trim($_POST['admin_notes'] ?? '');
  if (empty($rejectNotes)) {
    $rejectNotes = 'Request rejected.';
  }

  $stmt = mysqli_prepare(
    $conn,
    "SELECT r.*, o.customer_name, o.customer_email, o.status as order_status
       FROM returns r
       JOIN orders o ON r.order_id = o.id
       WHERE r.id = ?"
  );
  mysqli_stmt_bind_param($stmt, "i", $rid);
  mysqli_stmt_execute($stmt);
  $ret = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);

  $stmt = mysqli_prepare(
    $conn,
    "UPDATE returns SET status = 'rejected', admin_notes = ?, resolved_at = NOW() WHERE id = ?"
  );

  mysqli_stmt_bind_param($stmt, "si", $rejectNotes, $rid);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);

  if ($ret) {
    require_once '../includes/mailer.php';
    sendReturnRejectedEmail($conn, $rid, $ret);
  }

  $_SESSION['admin_success'] =
    "Request rejected and customer notified.";
  header('Location: /admin/returns');
  exit();
}




// Load returns
$returns = mysqli_query(
  $conn,
  "SELECT r.*, o.total_amount,
            o.customer_address,
            o.status as order_status
     FROM returns r
     JOIN orders o ON r.order_id = o.id
     ORDER BY
       CASE r.status
         WHEN 'pending'          THEN 0
         WHEN 'label_sent'       THEN 1
         WHEN 'awaiting_refund'  THEN 2
         ELSE 3
       END,
       r.created_at DESC"
);

$pendingCount = mysqli_fetch_row(mysqli_query(
  $conn,
  "SELECT COUNT(*) FROM returns
     WHERE status IN ('pending','awaiting_refund',
                      'label_sent')"
))[0];

//include '../includes/sendcloud.php';
//$shippingMethods = getShippingMethods(1000);
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Returns - Admin</title>
  <link rel="stylesheet"
    href="/css/style.css">
  <link rel="stylesheet"
    href="/css/admin.css">
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
        <li><a href="/admin/orders">
            📦 Orders</a></li>
        <li><a href="/admin/products">
            🌸 Products</a></li>
        <li><a href="/admin/messages">
            ✉️ Messages</a></li>
        <li><a href="/admin/returns"
            class="active">🔄 Returns</a></li>
        <li><a href="/admin/complaints">⚠️ Complaints</a></li>
        <li><a href="/admin/logout">
            🚪 Logout</a></li>
      </ul>
    </aside>

    <main class="admin-main">
      <h1>Returns
        <?php if ($pendingCount > 0): ?>
          <span class="unread-badge">
            <?php echo $pendingCount; ?> need action
          </span>
        <?php endif; ?>
      </h1>
      <p class="admin-subtitle">
        Manage return and cancellation requests, send labels
        and process refunds and replacements.
      </p>

      <?php if ($success): ?>
        <div class="alert alert-success">
          <?php echo $success; ?>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-error">
          <?php echo $error; ?>
        </div>
      <?php endif; ?>

      <?php if (mysqli_num_rows($returns) > 0): ?>
        <div class="returns-admin-list">

          <?php while ($ret = mysqli_fetch_assoc($returns)):
            $resolutionChoice = $ret['resolution_choice']
              ?? '';
            $needsReplacement = $resolutionChoice
              === 'replacement';
          ?>
            <div class="return-card
           return-<?php echo $ret['status']; ?>">

              <!-- Header -->
              <div class="return-card-header">
                <div>
                  <span class="return-type-badge">
                    <?php echo ucfirst(str_replace(
                      '_',
                      ' ',
                      $ret['type']
                    )); ?>
                  </span>
                  <strong>Order #<?php
                                  echo $ret['order_id']; ?></strong>
                  — <?php echo htmlspecialchars(
                      $ret['customer_name']
                    ); ?>
                  (<?php echo htmlspecialchars(
                      $ret['customer_email']
                    ); ?>)
                </div>
                <div style="display:flex;
                    align-items:center; gap:12px;">
                  <!-- Resolution badge -->
                  <span style="font-size:0.72rem;
                       padding:3px 10px;
                       background:<?php echo
                                  $needsReplacement
                                    ? '#e3f2fd'
                                    : '#f0faf0'; ?>;
                       color:<?php echo
                              $needsReplacement
                                ? '#1565c0'
                                : '#2e7d32'; ?>;">
                    <?php echo $needsReplacement
                      ? '🔄 Replacement'
                      : '💳 Refund'; ?>
                  </span>
                  <span class="badge badge-<?php
                                            echo match ($ret['status']) {
                                              'pending'        => 'pending',
                                              'label_sent'     => 'dispatched',
                                              'awaiting_refund' => 'pending',
                                              'refunded'       => 'complete',
                                              'rejected'       => 'cancelled',
                                              default          => 'pending',
                                            }; ?>">
                    <?php echo ucfirst(str_replace(
                      '_',
                      ' ',
                      $ret['status']
                    )); ?>
                  </span>
                  <span style="font-size:0.78rem;
                       color:#bbb;">
                    <?php echo date(
                      'd M Y',
                      strtotime($ret['created_at'])
                    ); ?>
                  </span>
                </div>
              </div>

              <!-- Reason -->
              <div class="return-reason">
                <strong>Reason:</strong><br>
                <?php echo nl2br(htmlspecialchars(
                  $ret['reason']
                )); ?>
              </div>

              <?php if ($ret['admin_notes']): ?>
                <div class="return-admin-notes">
                  <strong>Admin notes:</strong>
                  <?php echo htmlspecialchars(
                    $ret['admin_notes']
                  ); ?>
                </div>
              <?php endif; ?>

              <?php if ($ret['refund_id']): ?>
                <div class="return-refund-id">
                  💳 Refund processed:
                  <code><?php echo $ret['refund_id']; ?></code>
                </div>
              <?php endif; ?>

              <?php if ($ret['replacement_tracking']): ?>
                <div class="return-refund-id"
                  style="background:#e3f2fd;
                  border-color:#90caf9;">
                  🚚 Replacement tracking:
                  <code><?php echo htmlspecialchars(
                          $ret['replacement_tracking']
                        ); ?></code>
                </div>
              <?php endif; ?>

              <!-- ══ PENDING: Send Label ══ -->
              <?php if ($ret['status'] === 'pending'): ?>
                <div class="return-action-form">

                  <?php if (in_array(
                    $ret['type'],
                    [
                      'cancellation_before_dispatch',
                      'cancellation_after_dispatch'
                    ]
                  )): ?>

                    <div style="background:#f0faf0;
            border:1px solid #c8e6c9;
            border-left:4px solid #4caf50;
            padding:16px 18px;
            margin-bottom:16px;">

                      <?php if (
                        $ret['type'] ===
                        'cancellation_before_dispatch'
                      ): ?>
                        <p style="font-size:0.85rem; font-weight:bold;
              color:#1b5e20; margin-bottom:8px;">
                          ✅ Pre-dispatch cancellation.
                          No return needed. Process full refund.
                        </p>
                      <?php else: ?>
                        <p style="font-size:0.85rem; font-weight:bold;
              color:#1b5e20; margin-bottom:8px;">
                          🚚 Post-dispatch cancellation.
                          Customer keeps the item.
                          Deduct your handling fee below.
                        </p>
                      <?php endif; ?>

                      <form method="POST" action="">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action"
                          value="process_refund">
                        <input type="hidden" name="return_id"
                          value="<?php echo $ret['id']; ?>">

                        <div style="margin-bottom:14px;">
                          <label class="admin-field-label">
                            Refund Amount (£) *
                            <?php if (
                              $ret['type'] ===
                              'cancellation_after_dispatch'
                            ): ?>
                              <span style="font-weight:normal;
                       color:#888; font-size:0.75rem;
                       text-transform:none;
                       letter-spacing:0;">
                                — deduct your handling fee from
                                £<?php echo number_format(
                                    $ret['total_amount'],
                                    2
                                  ); ?>
                              </span>
                            <?php endif; ?>
                          </label>
                          <input type="number"
                            name="override_refund_amount"
                            step="0.01" min="0"
                            max="<?php echo $ret['total_amount']; ?>"
                            value="<?php echo
                                    $ret['type'] ===
                                      'cancellation_before_dispatch'
                                      ? number_format(
                                        $ret['total_amount'],
                                        2
                                      )
                                      : number_format(
                                        max(
                                          0,
                                          $ret['total_amount'] - 3.99
                                        ),
                                        2
                                      ); ?>"
                            required
                            style="width:200px; padding:10px;
                    border:1px solid #ddd;
                    font-family:Georgia,serif;
                    font-size:1rem;">
                          <small style="display:block; color:#aaa;
                    font-size:0.75rem; margin-top:4px;">
                            Order total: £<?php echo number_format(
                                            $ret['total_amount'],
                                            2
                                          ); ?>
                            <?php if (
                              $ret['type'] ===
                              'cancellation_before_dispatch'
                            ): ?>
                              — Full refund (no deductions)
                            <?php else: ?>
                              — Enter amount after deducting
                              your handling fee
                            <?php endif; ?>
                          </small>
                        </div>

                        <button type="submit"
                          class="btn-primary"
                          style="background:#4caf50;"
                          onclick="return confirm(
              'Process refund? This cannot be undone.')">
                          💳 Process Refund Now
                        </button>
                      </form>
                    </div>

                  <?php else: ?>
                    <!-- Normal return: label options -->

                    <!-- ══ OPTION 1: Sendcloud Auto ══ -->
                    <div class="return-label-option"
                      style="border-left:4px solid #d4af7a;
              margin-bottom:16px;">
                      <h4>⭐ Option 1 — Sendcloud (Automatic)</h4>
                      <p>Sendcloud generates a real prepaid return
                        label automatically via Royal Mail or Evri.</p>

                      <?php if (isset($sendcloudError)): ?>
                        <div class="alert alert-error"
                          style="margin-bottom:12px;">
                          <?php echo $sendcloudError; ?>
                        </div>
                      <?php endif; ?>

                      <form method="POST" action="">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action"
                          value="sendcloud_return_label">
                        <input type="hidden" name="return_id"
                          value="<?php echo $ret['id']; ?>">
                        <input type="hidden" name="order_id"
                          value="<?php echo $ret['order_id']; ?>">

                        <div style="display:grid;
                  grid-template-columns:1fr 1fr;
                  gap:12px; margin-bottom:12px;">
                          <div>
                            <label class="admin-field-label">
                              Shipping Service
                            </label>
                            <select name="option_code"
                              style="width:100%; padding:10px;
                         border:1px solid #ddd;
                         font-family:Georgia,serif;
                         background:#fff;">
                              <?php
                              // Use dedicated return methods function
                              include_once '../includes/sendcloud.php';
                              $returnMethods = getReturnShippingMethods();
                              if (!empty($returnMethods)) {
                                foreach ($returnMethods as $m) {
                                  echo '<option value="'
                                    . htmlspecialchars($m['option_code'])
                                    . '">'
                                    . htmlspecialchars($m['carrier_name'])
                                    . ' — '
                                    . htmlspecialchars($m['name'])
                                    . '</option>';
                                }
                              } else {
                                echo '<option value="">No services available</option>';
                              }
                              ?>
                            </select>
                          </div>
                          <div>
                            <label class="admin-field-label">
                              Refund Amount (£)
                            </label>
                            <input type="number"
                              name="refund_amount"
                              step="0.01"
                              value="<?php echo
                                      $ret['total_amount']; ?>"
                              style="width:100%; padding:10px;
                        border:1px solid #ddd;
                        font-family:Georgia,serif;">
                          </div>
                        </div>


                        <button type="submit" class="btn-primary">
                          🏷️ Generate & Email Return Label
                        </button>
                      </form>
                    </div>

                    <!-- ══ DIVIDER ══ -->
                    <div class="return-label-divider">
                      — OR use Option 2 if Sendcloud is unavailable —
                    </div>

                    <!-- Option 2: Manual Upload -->
                    <div class="return-label-option"
                      style="border-left:4px solid #999;
                    margin-top:12px;">
                      <h4>Option 2 — Upload Label Manually</h4>
                      <div style="display:flex; gap:10px;
                      margin-bottom:12px;
                      flex-wrap:wrap;">
                        <a href="https://www.royalmail.com/sending/send-an-item"
                          target="_blank"
                          class="btn-dispatch"
                          style="background:#cc0000;
                      font-size:0.78rem;">
                          🏷️ Buy Royal Mail Label →
                        </a>
                        <a href="https://www.evri.com/send-a-parcel"
                          target="_blank"
                          class="btn-dispatch"
                          style="background:#9c27b0;
                      font-size:0.78rem;">
                          🏷️ Buy Evri Label →
                        </a>
                      </div>
                      <form method="POST" action=""
                        enctype="multipart/form-data">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action"
                          value="send_label">
                        <input type="hidden" name="return_id"
                          value="<?php echo $ret['id']; ?>">
                        <div style="display:grid;
                        grid-template-columns:1fr 1fr;
                        gap:12px; margin-bottom:12px;">
                          <div>
                            <label class="admin-field-label">
                              Carrier
                            </label>
                            <select name="return_carrier"
                              style="width:100%;
                               padding:10px;
                               border:1px solid #ddd;
                               font-family:Georgia,serif;
                               background:#fff;">
                              <option value="Royal Mail">
                                Royal Mail
                              </option>
                              <option value="Evri">Evri</option>
                            </select>
                          </div>
                          <div>
                            <label class="admin-field-label">
                              Refund Amount (£)
                            </label>
                            <input type="number"
                              name="refund_amount"
                              step="0.01"
                              value="<?php echo
                                      $ret['total_amount']; ?>"
                              style="width:100%;
                              padding:10px;
                              border:1px solid #ddd;
                              font-family:Georgia,serif;">
                          </div>
                        </div>
                        <div style="margin-bottom:12px;">
                          <label class="admin-field-label">
                            Upload Label File *
                            (PDF or QR image)
                          </label>
                          <div class="label-upload-area"
                            onclick="document.getElementById(
                     'lf-<?php echo $ret['id']; ?>'
                   ).click()">
                            <div class="label-upload-icon">📎</div>
                            <p id="lfn-<?php echo $ret['id']; ?>">
                              Click to select file
                            </p>
                            <small>PDF, JPG or PNG</small>
                          </div>
                          <input type="file" name="label_file"
                            id="lf-<?php echo $ret['id']; ?>"
                            accept=".pdf,.jpg,.jpeg,.png"
                            required style="display:none;"
                            onclick="this.value=null;"
                            onchange="
         updateFileName(this,
           'lfn-<?php echo $ret['id']; ?>');
         checkFileSize(this,
           'ferr-<?php echo $ret['id']; ?>',
           'fsub-<?php echo $ret['id']; ?>');">
                        </div>
                        <div style="display:flex;
                        gap:10px; flex-wrap:wrap;">
                          <div id="ferr-<?php echo $ret['id']; ?>"
                            style="color:#e53935; font-size:0.82rem;
            margin-bottom:8px; display:none;">
                          </div>
                          <button type="submit"
                            id="fsub-<?php echo $ret['id']; ?>"
                            class="btn-secondary">
                            📤 Upload & Email Label to Customer
                          </button>
                        </div>
                      </form>
                    </div>

                    <!-- Reject -->
                    <!--<div style="margin-top:12px; padding-top:12px; border-top:1px solid #f0f0f0;">
                      <button type="button" class="btn-delete" style="padding:8px 16px;"
                        onclick="document.getElementById('rej-<?php echo $ret['id']; ?>').style.display='block'">
                        ✕ Reject Request
                      </button>
                      <div id="rej-<?php echo $ret['id']; ?>"
                        style="display:none; margin-top:12px;">
                        <form method="POST" action="">
                          <input type="hidden"
                            name="action" value="reject">
                          <input type="hidden"
                            name="return_id"
                            value="<?php echo $ret['id']; ?>">
                          <textarea name="admin_notes" required
                            placeholder="Reason for rejection..."
                            style="width:100%; padding:10px;
                               border:1px solid #ddd;
                               font-family:Georgia,serif;
                               height:70px;
                               resize:vertical;
                               margin-bottom:10px;">
                          </textarea>
                          <button type="submit"
                            class="btn-delete"
                            style="padding:8px 16px;">
                            Confirm Rejection
                          </button>
                        </form>
                      </div>
                    </div>-->

                </div>



              <?php endif; // end cancellation vs normal 
              ?>

              <!-- ══ LABEL SENT: Mark received ══ -->
            <?php elseif (
                $ret['status']
                === 'label_sent'
              ): ?>
              <div class="return-action-form">
                <p style="font-size:0.85rem; color:#666;
                  margin-bottom:12px;">
                  ⏳ Waiting for customer to return item.
                </p>
                <form method="POST" action="">
                  <?php echo csrfField(); ?>
                  <input type="hidden"
                    name="action" value="mark_received">
                  <input type="hidden"
                    name="return_id"
                    value="<?php echo $ret['id']; ?>">
                  <button type="submit"
                    class="btn-dispatch">
                    📦 Mark Item as Received
                  </button>
                </form>
              </div>

              <!-- ══ AWAITING REFUND: Process resolution ══ -->
            <?php elseif (
                $ret['status']
                === 'awaiting_refund'
              ): ?>
              <div class="return-action-form">

                <?php if ($needsReplacement): ?>
                  <!-- REPLACEMENT FLOW -->
                  <div style="background:#e3f2fd;
                    border:1px solid #90caf9;
                    border-left:4px solid #1565c0;
                    padding:16px 18px;
                    margin-bottom:16px;">
                    <p style="font-size:0.85rem;
                    font-weight:bold;
                    color:#0d47a1;
                    margin-bottom:12px;">
                      🔄 Customer requested replacement.
                      Create a new shipment and add the
                      tracking number below.
                    </p>
                    <p style="font-size:0.82rem;
                    color:#555; margin-bottom:14px;">
                      1. Go to Admin → Orders → Create Label
                      for this order<br>
                      2. Or buy a label manually<br>
                      3. Enter the new tracking number below
                    </p>
                    <form method="POST" action="">
                      <?php echo csrfField(); ?>
                      <input type="hidden"
                        name="action"
                        value="add_replacement_tracking">
                      <input type="hidden"
                        name="return_id"
                        value="<?php echo $ret['id']; ?>">
                      <input type="hidden"
                        name="order_id"
                        value="<?php
                                echo $ret['order_id']; ?>">
                      <div style="display:grid;
                        grid-template-columns:1fr 1fr;
                        gap:12px; margin-bottom:12px;">
                        <div>
                          <label class="admin-field-label">
                            New Tracking Number *
                          </label>
                          <input type="text"
                            name="tracking_number"
                            placeholder="e.g. RM123456789GB"
                            required
                            style="width:100%;
                              padding:10px;
                              border:1px solid #ddd;
                              font-family:Georgia,serif;">
                        </div>
                        <div>
                          <label class="admin-field-label">
                            Carrier
                          </label>
                          <select name="carrier"
                            style="width:100%;
                               padding:10px;
                               border:1px solid #ddd;
                               font-family:Georgia,serif;
                               background:#fff;">
                            <option value="Royal Mail">
                              Royal Mail
                            </option>
                            <option value="Evri">Evri</option>
                          </select>
                        </div>
                      </div>
                      <div style="margin-bottom:12px;">
                        <label class="admin-field-label">
                          Service Name
                        </label>
                        <input type="text"
                          name="service_name"
                          placeholder="e.g. Royal Mail Tracked 48"
                          style="width:100%; padding:10px;
                            border:1px solid #ddd;
                            font-family:Georgia,serif;">
                      </div>
                      <button type="submit"
                        class="btn-primary"
                        style="background:#1565c0;">
                        🚚 Add Tracking &
                        Notify Customer
                      </button>
                    </form>
                  </div>

                <?php else: ?>
                  <!-- REFUND FLOW -->
                  <div style="background:#f0faf0;
                    border:1px solid #c8e6c9;
                    border-left:4px solid #4caf50;
                    padding:16px 18px;
                    margin-bottom:16px;">
                    <p style="font-size:0.85rem;
                    font-weight:bold;
                    color:#1b5e20;
                    margin-bottom:8px;">
                      💳 Customer requested refund.
                      Item has been received.
                      Process the refund now.
                    </p>
                    <p style="font-size:0.82rem;
                    color:#555; margin-bottom:14px;">
                      Refund amount: <strong>£<?php
                                              echo number_format(
                                                $ret['refund_amount']
                                                  ?: $ret['total_amount'],
                                                2
                                              );
                                              ?></strong>
                    </p>
                    <form method="POST" action="">
                      <?php echo csrfField(); ?>
                      <input type="hidden"
                        name="action"
                        value="process_refund">
                      <input type="hidden"
                        name="return_id"
                        value="<?php echo $ret['id']; ?>">
                      <button type="submit"
                        class="btn-primary"
                        style="background:#4caf50;"
                        onclick="return confirm(
                      'Process refund of £<?php
                                          echo number_format(
                                            $ret['refund_amount']
                                              ?: $ret['total_amount'],
                                            2
                                          ); ?>?')">
                        💳 Process Refund Now
                      </button>
                    </form>
                  </div>
                <?php endif; ?>

              </div>
            <?php elseif (
                $ret['status']
                === 'processing_refund'
              ): ?>

              <div style="background:#fff8e1;
            border:1px solid #ffe082;
            padding:14px 18px;
            font-size:0.85rem;
            color:#92400e;">
                ⏳ <strong>Refund is being processed.</strong>
                Please wait. If this persists for more than
                5 minutes, contact Stripe support or
                <a href="https://dashboard.stripe.com"
                  target="_blank"
                  style="color:#d4af7a;">
                  process manually in Stripe →
                </a>
              </div>

            <?php endif; ?>

            <?php if (!in_array(
              $ret['status'],
              ['refunded', 'rejected', 'resolved']
            )): ?>
              <div style="padding:12px 0 4px;
              border-top:1px solid #f0f0f0;
              margin-top:4px;">
                <form method="POST" action="">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="action" value="reject">
                  <input type="hidden" name="return_id"
                    value="<?php echo $ret['id']; ?>">
                  <div style="margin-bottom:10px;">
                    <label class="admin-field-label">Rejection reason (optional)</label>
                    <input type="text" name="admin_notes"
                      placeholder="e.g. Outside return window"
                      style="width:100%; padding:8px 10px;
                               border:1px solid #ddd;
                               font-family:Georgia,serif;
                               font-size:0.85rem;">
                  </div>
                  <button type="submit" class="btn-delete"
                    onclick="return confirm('Reject this request and notify the customer?')">
                    ✕ Reject Request
                  </button>
                </form>
              </div>
            <?php endif; ?>
            </div><!-- /return-card -->
          <?php endwhile; ?>
        </div>

      <?php else: ?>
        <div class="cart-empty">
          <p>🔄 No return requests yet.</p>
        </div>
      <?php endif; ?>

    </main>
  </div>

  <script>
    function updateFileName(input, labelId) {
      const label = document.getElementById(labelId);
      if (input.files && input.files[0]) {
        label.textContent = '✅ ' + input.files[0].name;
        label.style.color = '#2e7d32';
      }
    }


    function checkFileSize(input, errorId, btnId) {
      const maxMB = 5;
      const maxBytes = maxMB * 1024 * 1024;
      const errorDiv = document.getElementById(errorId);
      const submitBtn = document.getElementById(btnId);

      if (!input.files || !input.files[0]) return;

      const file = input.files[0];

      if (file.size > maxBytes) {
        errorDiv.textContent =
          '⚠️ File too large. Maximum size is 5MB.';
        errorDiv.style.display = 'block';

        // Disable submit
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.style.opacity = '0.5';
        }

        // Replace input with fresh clone
        // so user can select another file
        const newInput = input.cloneNode(true);
        newInput.value = '';
        // Re-attach onchange with same functions
        newInput.onchange = function() {
          updateFileName(this,
            input.id.replace('lf-', 'lfn-'));
          checkFileSize(this, errorId, btnId);
        };
        input.parentNode.replaceChild(newInput, input);

        // Reset the upload area display text
        const uploadArea = newInput
          .closest('.label-upload-area') ??
          newInput.previousElementSibling;
        if (uploadArea) {
          const p = uploadArea.querySelector('p') ??
            uploadArea;
          if (p && p.tagName !== 'INPUT') {
            p.textContent = 'Click to select file';
            p.style.color = '';
          }
        }

      } else {
        // File is fine
        errorDiv.style.display = 'none';
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.style.opacity = '1';
        }
      }
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