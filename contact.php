<?php
session_start();
$pageTitle = "Contact - Genova Perfumes";
include 'includes/header.php';
include 'includes/db.php';
require_once 'includes/csrf.php';

$success = '';
if (isset($_SESSION['contact_success'])) {
  $success = $_SESSION['contact_success'];
  unset($_SESSION['contact_success']);
}
$error   = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrfToken();


  // Rate limit: max 3 messages per 10 minutes
  $limit = checkRateLimit('contact', 3, 600);

  if ($limit['limited']) {
    $error = "Too many messages sent. Please wait "
      . $limit['wait']
      . " minute(s) before trying again.";
  } else {

    $name    = trim(mysqli_real_escape_string($conn, $_POST['name']));
    $email   = trim(mysqli_real_escape_string($conn, $_POST['email']));
    $subject = trim(mysqli_real_escape_string($conn, $_POST['subject']));
    $message = trim(mysqli_real_escape_string($conn, $_POST['message']));

    // Basic validation
    if (empty($name) || empty($email) || empty($message)) {
      $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = "Please enter a valid email address.";
    } else {
      $sql = "INSERT INTO messages (name, email, subject, message)
              VALUES ('$name', '$email', '$subject', '$message')";

      if (mysqli_query($conn, $sql)) {
        $_SESSION['contact_success'] =
          "✅ Thank you! Your message has been sent.
     We will get back to you within 24 hours.";
        header('Location: /contact');
        exit();
      } else {
        $error = "Something went wrong. Please try again.";
      }
    }
  }
}
?>

<div class="contact-page">

  <h1>Contact Us</h1>
  <p>We'd love to hear from you. Send us a message and we'll respond within 24 hours.</p>

  <?php if ($success): ?>
    <div class="alert alert-success"
      style="max-width:700px; margin:0 auto 20px;">
      <?php echo htmlspecialchars($success); ?>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <form class="contact-form" method="POST" action="">
    <?php echo csrfField(); ?>

    <input type="text"
      name="name"
      placeholder="Your Name *"
      value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>"
      required>

    <input type="email"
      name="email"
      placeholder="Your Email *"
      value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>"
      required>

    <input type="text"
      name="subject"
      placeholder="Subject"
      value="<?php echo isset($subject) ? htmlspecialchars($subject) : ''; ?>">

    <textarea name="message"
      placeholder="Your Message *"
      required><?php echo isset($message) ? htmlspecialchars($message) : ''; ?></textarea>

    <button type="submit" class="btn-primary">Send Message ✉️</button>

  </form>

  <div class="contact-details">
    <h3>Other Ways to Reach Us</h3>
    <p>📍 123 Fragrance Lane, London, UK</p>
    <p>📞 +44 20 1234 5678</p>
    <p>📧 hello@auraperfumes.com</p>
    <p>🕐 Mon–Sat: 9am – 6pm</p>
  </div>

</div>

<?php include 'includes/footer.php'; ?>