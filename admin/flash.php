<?php
// Display and clear session flash messages
if (isset($_SESSION['admin_success'])): ?>
  <div class="alert alert-success">
    <?php echo htmlspecialchars($_SESSION['admin_success']);
          unset($_SESSION['admin_success']); ?>
  </div>
<?php endif; ?>

<?php if (isset($_SESSION['admin_error'])): ?>
  <div class="alert alert-error">
    <?php echo htmlspecialchars($_SESSION['admin_error']);
          unset($_SESSION['admin_error']); ?>
  </div>
<?php endif; ?>