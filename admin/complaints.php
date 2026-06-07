<?php
include 'auth.php';
include '../includes/db.php';
include 'flash.php';
require_once '../includes/csrf.php';
require_once '../config.php';
require_once '../includes/mailer.php';



// ── RESPOND TO COMPLAINT ─────────────────────────────────────
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $_POST['action'] === 'respond'
) {

    verifyCsrfToken();
    $cid      = intval($_POST['complaint_id']);
    $response = trim($_POST['admin_response'] ?? '');

    if (empty($response)) {
        $_SESSION['admin_error'] =
            "Please write a response.";
        header('Location: /admin/complaints');
        exit();
    } else {
        // Get complaint BEFORE updating
        $stmt = mysqli_prepare(
            $conn,
            "SELECT c.*, o.customer_name, o.customer_email
             FROM complaints c
             JOIN orders o ON c.order_id = o.id
             WHERE c.id = ?"
        );
        mysqli_stmt_bind_param($stmt, "i", $cid);
        mysqli_stmt_execute($stmt);
        $complaint = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$complaint) {
            $_SESSION['admin_error'] =
                "Complaint not found.";
            header('Location: /admin/complaints');
            exit();
        } else {
            // Send email first
            $emailSent = sendComplaintResponseEmail(
                $conn,
                $cid,
                $response,
                $complaint
            );

            if ($emailSent) {
                // Only update DB if email sent
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE complaints
                     SET status = 'responded',
                         admin_response = ?,
                         resolved_at = NOW()
                     WHERE id = ?"
                );
                mysqli_stmt_bind_param($stmt, "si", $response, $cid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['admin_success'] =
                    "✅ Response sent to customer!";
                header('Location: /admin/complaints');
                exit();
            } else {
                $_SESSION['admin_error'] =
                    "❌ Failed to send email. Please check your SMTP settings and try again. Response was NOT saved.";
                header('Location: /admin/complaints');
                exit();
            }
        }
    }
}



$complaints = mysqli_query(
    $conn,
    "SELECT c.*, o.total_amount
     FROM complaints c
     JOIN orders o ON c.order_id = o.id
     ORDER BY
       CASE c.status WHEN 'open' THEN 0
       ELSE 1 END,
       c.created_at DESC"
);

$openCount = mysqli_fetch_row(mysqli_query(
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
    <title>Complaints - Admin</title>
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
                <li><a href="/admin/returns">
                        🔄 Returns</a></li>
                <li><a href="/admin/complaints"
                        class="active">⚠️ Complaints</a></li>
                <li><a href="/admin/logout">
                        🚪 Logout</a></li>
            </ul>
        </aside>

        <main class="admin-main">
            <h1>Complaints
                <?php if ($openCount > 0): ?>
                    <span class="unread-badge">
                        <?php echo $openCount; ?> open
                    </span>
                <?php endif; ?>
            </h1>
            <p class="admin-subtitle">
                Customer feedback and service complaints.
                Respond and communicate — refund/return
                requests are handled in the Returns section.
            </p>

            <?php include 'flash.php'; ?>

            <?php if (mysqli_num_rows($complaints) > 0): ?>
                <div class="returns-admin-list">
                    <?php while ($c = mysqli_fetch_assoc(
                        $complaints
                    )): ?>

                        <div class="return-card return-<?php
                                                        echo $c['status'] === 'open'
                                                            ? 'pending' : 'approved'; ?>">

                            <div class="return-card-header">
                                <div>
                                    <span class="return-type-badge"
                                        style="background:<?php
                                                            echo $c['status'] === 'open'
                                                                ? '#e53935' : '#1a1a1a'; ?>">
                                        <?php echo ucfirst(str_replace(
                                            '_',
                                            ' ',
                                            $c['complaint_type']
                                        )); ?>
                                    </span>
                                    <strong>Order #<?php
                                                    echo $c['order_id']; ?></strong>
                                    — <?php echo htmlspecialchars(
                                            $c['customer_name']
                                        ); ?>
                                </div>
                                <div style="display:flex;
                    align-items:center; gap:12px;">
                                    <span class="badge badge-<?php
                                                                echo $c['status'] === 'open'
                                                                    ? 'pending' : 'complete'; ?>">
                                        <?php echo ucfirst($c['status']); ?>
                                    </span>
                                    <span style="font-size:0.78rem;
                       color:#bbb;">
                                        <?php echo date(
                                            'd M Y',
                                            strtotime($c['created_at'])
                                        ); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="return-reason">
                                <strong>Customer complaint:</strong><br>
                                <?php echo nl2br(htmlspecialchars(
                                    $c['description']
                                )); ?>
                            </div>

                            <?php if ($c['admin_response']): ?>
                                <div class="return-admin-notes">
                                    <strong>Your response:</strong>
                                    <?php echo htmlspecialchars(
                                        $c['admin_response']
                                    ); ?>
                                </div>
                            <?php endif; ?>

                            <?php if (
                                $c['status'] === 'open'
                                || $c['status'] === 'responded'
                            ): ?>
                                <div class="return-action-form">
                                    <form method="POST" action="">
                                        <input type="hidden"
                                            name="action" value="respond">
                                        <input type="hidden"
                                            name="complaint_id"
                                            value="<?php echo $c['id']; ?>">
                                        <?php echo csrfField(); ?>
                                        <div style="margin-bottom:12px;">
                                            <label class="admin-field-label">
                                                <?php echo $c['admin_response']
                                                    ? 'Send Follow-up Response'
                                                    : 'Respond to Customer *'; ?>
                                            </label>
                                            <textarea name="admin_response"
                                                required
                                                placeholder="Write your response to the customer..."
                                                style="width:100%; padding:10px;
                     border:1px solid #ddd;
                     font-family:Georgia,serif;
                     height:90px;
                     resize:vertical;">
    </textarea>
                                        </div>
                                        <button type="submit" class="btn-primary">
                                            ✉️ Send Response to Customer
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>

                        </div>
                    <?php endwhile; ?>
                </div>

            <?php else: ?>
                <div class="cart-empty">
                    <p>⚠️ No complaints yet. 🌸</p>
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