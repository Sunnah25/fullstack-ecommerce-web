<?php
include 'auth.php';
include '../includes/db.php';
require_once '../includes/csrf.php';

// Mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'read') {
  verifyCsrfToken();
  $rid  = intval($_POST['message_id']);
  $stmt = mysqli_prepare($conn, "UPDATE messages SET is_read = 1 WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $rid);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
  header('Location: /admin/messages');
  exit();
}

// Mark as unread
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unread') {
  verifyCsrfToken();
  $uid  = intval($_POST['message_id']);
  $stmt = mysqli_prepare($conn, "UPDATE messages SET is_read = 0 WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $uid);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
  header('Location: /admin/messages');
  exit();
}

// Delete message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  verifyCsrfToken();
  $did  = intval($_POST['message_id']);
  $stmt = mysqli_prepare($conn, "DELETE FROM messages WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $did);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
  header('Location: /admin/messages?deleted=1');
  exit();
}

// Load all messages — unread first
$messages = mysqli_query(
  $conn,
  "SELECT * FROM messages ORDER BY is_read ASC, created_at DESC"
);

$unreadCount = mysqli_fetch_row(mysqli_query(
  $conn,
  "SELECT COUNT(*) FROM messages WHERE is_read = 0"
))[0];
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Messages - Admin</title>
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
        <li><a href="/admin/dashboard">📊 Dashboard</a></li>
        <li><a href="/admin/orders">📦 Orders</a></li>
        <li><a href="/admin/products">🌸 Products</a></li>
        <li><a href="/admin/messages" class="active">✉️ Messages</a></li>
        <li><a href="/admin/complaints">⚠️ Complaints</a></li>
        <li><a href="/admin/logout">🚪 Logout</a></li>
      </ul>
    </aside>

    <!-- Main Content -->
    <main class="admin-main">
      <h1>Messages
        <?php if ($unreadCount > 0): ?>
          <span class="unread-badge"><?php echo $unreadCount; ?> new</span>
        <?php endif; ?>
      </h1>
      <p class="admin-subtitle">Customer enquiries from your contact form.</p>

      <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-error">🗑️ Message deleted.</div>
      <?php endif; ?>

      <?php if (mysqli_num_rows($messages) > 0): ?>

        <div class="messages-list">
          <?php while ($msg = mysqli_fetch_assoc($messages)): ?>

            <div class="message-card <?php echo $msg['is_read'] ? 'is-read' : 'is-unread'; ?>">

              <div class="message-header">
                <div class="message-sender">
                  <span class="sender-name"><?php echo htmlspecialchars($msg['name']); ?></span>
                  <span class="sender-email"><?php echo htmlspecialchars($msg['email']); ?></span>
                </div>
                <div class="message-meta">
                  <?php if (!$msg['is_read']): ?>
                    <span class="badge badge-unread">Unread</span>
                  <?php else: ?>
                    <span class="badge badge-read">Read</span>
                  <?php endif; ?>
                  <span class="message-date">
                    <?php echo date('d M Y, H:i', strtotime($msg['created_at'])); ?>
                  </span>
                </div>
              </div>

              <?php if ($msg['subject']): ?>
                <div class="message-subject">
                  📌 <?php echo htmlspecialchars($msg['subject']); ?>
                </div>
              <?php endif; ?>

              <div class="message-body">
                <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
              </div>

              <div class="message-actions">
                <a href="mailto:<?php echo htmlspecialchars($msg['email']); ?>"
                  class="btn-reply">✉️ Reply by Email</a>

                <?php if (!$msg['is_read']): ?>
                  <form method="POST" action="" style="display:inline;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="read">
                    <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                    <button type="submit" class="btn-mark">✓ Mark as Read</button>
                  </form>
                <?php else: ?>
                  <form method="POST" action="" style="display:inline;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="unread">
                    <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                    <button type="submit" class="btn-mark">↩ Mark as Unread</button>
                  </form>
                <?php endif; ?>

                <form method="POST" action="" style="display:inline;"
                  onsubmit="return confirm('Delete this message permanently?')">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                  <button type="submit" class="btn-delete">🗑️ Delete</button>
                </form>
              </div>

            </div>

          <?php endwhile; ?>
        </div>

      <?php else: ?>
        <div class="cart-empty">
          <p>✉️ No messages yet.</p>
          <p style="font-size:0.85rem; margin-top:8px;">
            Messages from your contact form will appear here.
          </p>
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