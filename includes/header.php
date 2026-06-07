<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>




<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle)
                ? htmlspecialchars($pageTitle)
                : 'Genova Perfumes'; ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <!-- Default meta — pages override these -->
    <?php
    $metaDesc = $metaDesc
        ?? 'Genova Perfumes — Premium fragrances
        for her, for him and unisex. Free UK
        delivery on all orders.';
    $metaTitle = $pageTitle
        ?? 'Genova Perfumes — Premium Fragrances';
    ?>

    <meta name="description"
        content="<?php echo htmlspecialchars(
                        $metaDesc
                    ); ?>">
    <meta name="robots"
        content="index, follow">

    <!-- Open Graph for social sharing -->
    <meta property="og:title"
        content="<?php echo htmlspecialchars(
                        $metaTitle
                    ); ?>">
    <meta property="og:description"
        content="<?php echo htmlspecialchars(
                        $metaDesc
                    ); ?>">
    <meta property="og:type"
        content="website">
    <meta property="og:url"
        content="<?php echo SHOP_URL; ?>">
    <meta property="og:site_name"
        content="<?php echo htmlspecialchars(SHOP_NAME, ENT_QUOTES, 'UTF-8'); ?>">

    <!-- Canonical URL -->
    <link rel="canonical"
        href="<?php echo htmlspecialchars(SHOP_URL . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'), ENT_QUOTES, 'UTF-8'); ?>">
</head>

<body>

    <?php if (isset($_SESSION['return_success'])): ?>
        <div style="background:#f0faf0; border-bottom:3px solid #4caf50;
            padding:16px 40px; text-align:center;
            font-family:Georgia,serif; font-size:0.9rem;
            color:#2e7d32;">
            ✅ <?php echo $_SESSION['return_success'];
                unset($_SESSION['return_success']); ?>
        </div>
    <?php endif; ?>


    <?php if (isset($_SESSION['complaint_success'])): ?>
        <div style="background:#fff8e1;
            border-bottom:3px solid #f59e0b;
            padding:16px 40px; text-align:center;
            font-family:Georgia,serif;
            font-size:0.9rem; color:#92400e;">
            ⚠️ <?php echo $_SESSION['complaint_success'];
                unset($_SESSION['complaint_success']); ?>
        </div>
    <?php endif; ?>

    <!-- Cookie Consent Banner -->
    <?php if (!isset($_COOKIE['cookie_consent'])): ?>
        <div id="cookieBanner" class="cookie-banner">
            <div class="cookie-content">
                <div class="cookie-text">
                    <strong>🍪 We use cookies</strong>
                    <p>We use essential cookies to keep your shopping cart
                        working. We don't use any tracking or advertising cookies.
                        See our <a href="/privacy-policy"
                            target="_blank">Privacy Policy</a> for details.</p>
                </div>
                <div class="cookie-actions">
                    <button onclick="acceptCookies()"
                        class="btn-cookie-accept">
                        Accept & Continue
                    </button>
                    <a href="/privacy-policy"
                        class="btn-cookie-learn">Learn More</a>
                </div>
            </div>
        </div>

        <script>
            function acceptCookies() {
                // Set consent cookie for 365 days
                const d = new Date();
                d.setTime(d.getTime() + (365 * 24 * 60 * 60 * 1000));
                document.cookie = "cookie_consent=accepted; expires=" +
                    d.toUTCString() + "; path=/; SameSite=Lax";
                document.getElementById('cookieBanner').style.display = 'none';
            }
        </script>
    <?php endif; ?>

    <nav>
        <a href="/home" class="logo">🌸 Genova Perfumes</a>

        <!-- Hamburger button (mobile only) -->
        <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <ul id="navMenu">

            <li><a href="/home">Home</a></li>
            <li><a href="/products">Products</a></li>
            <li><a href="/contact">Contact</a></li>
            <li><a href="/cart">🛒 Cart
                    <?php
                    $cartCount = 0;
                    if (!empty($_SESSION['cart'])) {
                        foreach ($_SESSION['cart'] as $entry) {
                           if (is_array($entry)) {
                              $cartCount += intval($entry['qty']??0);
                           } else {
                              $cartCount += intval($entry);
                           }
                        }
                    }
                    ?>
                    <?php if ($cartCount > 0): ?>
                       <span class="cart-count"><?php echo $cartCount; ?></span>
                    <?php endif; ?>
                </a></li>
        </ul>
    </nav>

    <script>
        const menuToggle = document.getElementById('menuToggle');
        const navMenu = document.getElementById('navMenu');

        menuToggle.addEventListener('click', function() {
            navMenu.classList.toggle('open');
            menuToggle.classList.toggle('active');
        });

        // Close menu when clicking a link
        document.querySelectorAll('#navMenu a').forEach(link => {
            link.addEventListener('click', function() {
                navMenu.classList.remove('open');
                menuToggle.classList.remove('active');
            });
        });
    </script>
