<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
include 'includes/db.php';
require_once 'includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
}

$error   = '';
$errors  = [];
$order   = null;

// Step 1: Find order
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['lookup'])
) {
    $orderId = intval($_POST['order_id']);
    $email   = trim(mysqli_real_escape_string(
        $conn,
        $_POST['email']
    ));
    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM orders
         WHERE id = ?
         AND customer_email = ?
         AND status NOT IN
         ('pending_payment','cancelled')"
    );
    mysqli_stmt_bind_param($stmt, "is", $orderId, $email);
    mysqli_stmt_execute($stmt);
    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$order) {
        $error = "We couldn't find that order.
                  Please check your order number
                  and email address.";
    }
}

// Step 2: Submit complaint
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['submit_complaint'])
) {


    $orderId   = intval($_POST['order_id']);
    $email     = trim(mysqli_real_escape_string(
        $conn,
        $_POST['email']
    ));
    $type      = mysqli_real_escape_string(
        $conn,
        $_POST['complaint_type']
    );
    $desc      = trim(mysqli_real_escape_string(
        $conn,
        $_POST['description']
    ));

    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM orders
         WHERE id = ?
         AND customer_email = ?"
    );
    mysqli_stmt_bind_param($stmt, "is", $orderId, $email);
    mysqli_stmt_execute($stmt);
    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);


    // Check for duplicate complaint
    // in last 60 seconds from same order
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) FROM complaints
         WHERE order_id = ?
         AND customer_email = ?
         AND created_at > NOW() - INTERVAL 60 SECOND"
    );
    mysqli_stmt_bind_param($stmt, "is", $orderId, $email);
    mysqli_stmt_execute($stmt);
    $duplicate = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($duplicate[0] > 0) {
        // Silent duplicate — just redirect
        // as if successful
        $_SESSION['complaint_success'] =
            "Your complaint has been submitted.";
        header('Location: /home');
        exit();
    }
    if (strlen($desc) > 5000) {
        $errors[] = "Description is too long (max 5000 characters).";
    }

    if (!$order) {
        $error = "Order not found.";
    } elseif (empty($type)) {
        $errors[] = "Please select a complaint type.";
    } elseif (empty($desc)) {
        $errors[] = "Please describe the issue.";
    } else {
        $name = $order['customer_name'];

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO complaints
             (order_id, customer_name,
              customer_email, complaint_type,
              description)
             VALUES (?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $stmt,
            "issss",
            $orderId,
            $name,
            $email,
            $type,
            $desc
        );
        $result = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if (!$result) {
            $errors[] = "Unable to submit complaint.";
        } else {
            $complaintId = mysqli_insert_id($conn);


            if (empty($errors)) {
                // Send confirmation email
                require_once 'includes/mailer.php';
                sendComplaintConfirmationEmail(
                    $conn,
                    $complaintId,
                    $order,
                    $type,
                    $desc
                );

                $_SESSION['complaint_success'] =
                    "Your complaint #$complaintId has been
                submitted. We take all complaints very
                seriously and will respond within
                24 hours. Please check your email
                for confirmation.";

                header('Location: /home');
                exit();
            }
        }
    }
}

$pageTitle = "Report an Issue — " . SHOP_NAME;
include 'includes/header.php';
?>

<div class="complaint-page">

    <div class="tracking-header">
        <h1>Report an Issue</h1>
        <p>Received something wrong or damaged?
            Let us know right away and we will
            make it right.</p>
    </div>


    <?php if ($error): ?>
        <div class="alert alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $e): ?>
                <p>⚠️ <?php echo htmlspecialchars($e); ?></p>
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
                        value="<?php echo isset($_POST['order_id'])
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
        <!-- STEP 2: Submit Complaint -->
        <div class="returns-box">
            <h2>Step 2 — Describe the Issue</h2>

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
            </div>

            <form method="POST" action=""
                class="returns-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="submit_complaint"
                    value="1">
                <input type="hidden" name="order_id"
                    value="<?php echo $order['id']; ?>">
                <input type="hidden" name="email"
                    value="<?php echo htmlspecialchars(
                                $order['customer_email']
                            ); ?>">

                <div class="form-group">
                    <label>Issue Type *</label>
                    <select name="complaint_type" required
                        style="width:100%; padding:12px 16px;
                       border:1px solid #ddd;
                       font-family:Georgia,serif;
                       font-size:0.95rem;
                       background:#fff;">
                        <option value="">— Select issue type —</option>
                        <option value="delivery_problem">
                            Delivery problem — late, missing scan etc.
                        </option>
                        <option value="packaging_complaint">
                            Packaging complaint — poor or damaged packaging
                        </option>
                        <option value="service_issue">
                            Service issue — problem with our service
                        </option>
                        <option value="general_concern">
                            General concern or feedback
                        </option>
                        <option value="other">
                            Other
                        </option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Description *</label>
                    <textarea name="description"
                        placeholder="Please describe the issue
in detail. For wrong items, tell us what you received
and what you ordered. For damaged items, describe
the damage..."
                        required
                        style="height:140px;"></textarea>
                </div>

                <div class="returns-policy-note">
                    <p>📋 <strong>What happens next:</strong></p>
                    <p>We will review your complaint within
                        <strong>24 hours</strong> and contact
                        you by email with resolution options.
                    </p>
                    <p>For wrong or damaged items we will
                        either <strong>send the correct item</strong>
                        or issue a <strong>full refund</strong>
                        — whichever you prefer.</p>
                    <p>Please keep the item and packaging
                        until we contact you.</p>
                </div>

                <button type="submit" class="btn-primary">
                    Submit Complaint →
                </button>
            </form>
        </div>
    <?php endif; ?>

    <!-- How we handle complaints -->
    <div class="complaint-process">
        <h2>How We Handle Complaints</h2>
        <div class="process-steps">
            <div class="process-step">
                <div class="process-number">1</div>
                <div class="process-content">
                    <h3>You Report</h3>
                    <p>Submit your complaint with order
                        details and description of the issue.</p>
                </div>
            </div>
            <div class="process-step">
                <div class="process-number">2</div>
                <div class="process-content">
                    <h3>We Review</h3>
                    <p>Our team reviews your complaint
                        within 24 hours and contacts you
                        by email.</p>
                </div>
            </div>
            <div class="process-step">
                <div class="process-number">3</div>
                <div class="process-content">
                    <h3>You Choose</h3>
                    <p>For wrong or damaged items you
                        choose — replacement sent or
                        full refund processed.</p>
                </div>
            </div>
            <div class="process-step">
                <div class="process-number">4</div>
                <div class="process-content">
                    <h3>Resolved</h3>
                    <p>We make it right quickly and
                        follow up to ensure you are
                        completely satisfied.</p>
                </div>
            </div>
        </div>
    </div>

</div>

<?php include 'includes/footer.php'; ?>