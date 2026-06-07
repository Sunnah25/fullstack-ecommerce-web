<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'includes/db.php';
require_once 'includes/csrf.php';

// Get product ID from URL
$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /products');
    exit();
}

// Fetch product
$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM products WHERE id = ?"
);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$product = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$product) {
    header('Location: /products');
    exit();
}

// Handle Add to Cart
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'add'
) {
    verifyCsrfToken();

    if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
    $qty = max(1, intval($_POST['quantity'] ?? 1));

    // Validate qty against actual stock
    $stockCheck = mysqli_fetch_assoc(
        mysqli_stmt_get_result(
            $s = (function () use ($conn, $id) {
                $st = mysqli_prepare(
                    $conn,
                    "SELECT stock, price, discount_percent
                     FROM products WHERE id = ?"
                );
                mysqli_stmt_bind_param($st, "i", $id);
                mysqli_stmt_execute($st);
                return $st;
            })()
        )
    );
    mysqli_stmt_close($s);

    if (!$stockCheck || $stockCheck['stock'] <= 0) {
        header('Location: /product?id='
            . $id . '&error=outofstock');
        exit();
    }

    $qty = min($qty, intval($stockCheck['stock']));

    // Lock price at time of adding to cart
    $discountPct = floatval($stockCheck['discount_percent'] ?? 0);
    $lockedPrice = $discountPct > 0
        ? round($stockCheck['price'] * (1 - $discountPct / 100), 2)
        : floatval($stockCheck['price']);

    $currentQty = isset($_SESSION['cart'][$id])
        ? intval($_SESSION['cart'][$id]['qty'] ?? 0)
        : 0;
    $newQty = min(
        $currentQty + $qty,
        intval($stockCheck['stock'])
    );

    $_SESSION['cart'][$id] = [
        'qty'   => $newQty,
        'price' => $lockedPrice,
    ];

    header('Location: /cart');
    exit();
}



$metaDesc = htmlspecialchars(
    substr($product['description'], 0, 155)
);

$pageTitle = htmlspecialchars($product['name']) . " - Genova Perfumes";
include 'includes/header.php';
?>

<div class="product-detail-page">

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="/home">Home</a>
        <span>›</span>
        <a href="/products">Fragrances</a>
        <span>›</span>
        <span><?php echo htmlspecialchars($product['name']); ?></span>
    </div>

    <div class="product-detail-layout">

        <!-- LEFT: Product Images Gallery -->
        <div class="product-detail-images">

            <!-- Main Image -->
            <div class="product-main-image">
                <img id="mainImage"
                    src="<?php echo SHOP_URL; ?>/images/<?php
                                                        echo htmlspecialchars($product['image']); ?>"
                    alt="<?php echo htmlspecialchars($product['name']); ?>"
                    onerror="this.src='<?php echo SHOP_URL; ?>/images/placeholder.jpg'">
            </div>

            <?php
            // Load all images
            $images = [];
            $stmt = mysqli_prepare(
                $conn,
                "SELECT * FROM product_images
                 WHERE product_id = ?
                 ORDER BY is_primary DESC, sort_order ASC"
            );
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            $imgResult = mysqli_stmt_get_result($stmt);
            mysqli_stmt_close($stmt);
            while ($img = mysqli_fetch_assoc($imgResult)) {
                $images[] = $img;
            }
            // Fallback if no images in table
            if (empty($images) && $product['image']) {
                $images[] = [
                    'image'      => $product['image'],
                    'is_primary' => 1,
                ];
            }
            ?>

            <!-- Thumbnails (only show if more than 1 image) -->
            <?php if (count($images) > 1): ?>
                <div class="product-thumbnails">
                    <?php foreach ($images as $i => $img): ?>
                        <div class="thumbnail <?php echo $i === 0 ? 'active' : ''; ?>"
                            onclick="switchImage(
'<?php echo SHOP_URL; ?>/images/<?php
                                echo htmlspecialchars($img['image']); ?>',this)">
                            <img src="<?php echo SHOP_URL; ?>/images/<?php
                                                                        echo htmlspecialchars($img['image']); ?>"
                                onerror="this.src='<?php echo SHOP_URL; ?>/images/placeholder.jpg'"
                                alt="<?php echo htmlspecialchars($product['name']); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>

        <!-- RIGHT: Product Info -->
        <div class="product-detail-info">

            <span class="product-detail-eyebrow">Genova Perfumes</span>
            <h1><?php echo htmlspecialchars($product['name']); ?></h1>

            <div class="product-detail-price">
                <?php
                $discountPct = floatval($product['discount_percent'] ?? 0);
                $salePrice   = $discountPct > 0
                    ? round($product['price'] * (1 - $discountPct / 100), 2)
                    : null;
                ?>
                <?php if ($salePrice): ?>
                    <span class="price-original">
                        £<?php echo number_format($product['price'], 2); ?>
                    </span>
                    <span class="price-sale">
                        £<?php echo number_format($salePrice, 2); ?>
                    </span>
                    <span class="sale-badge">
                        -<?php echo intval($discountPct); ?>%
                    </span>
                <?php else: ?>
                    £<?php echo number_format($product['price'], 2); ?>
                <?php endif; ?>
            </div>

            <div class="product-detail-description">
                <?php echo nl2br(htmlspecialchars($product['description'])); ?>
            </div>

            <?php if ($product['stock'] > 0): ?>

                <!-- Stock indicator -->
                <div class="stock-indicator in-stock">
                    ✓ In Stock
                    <?php if ($product['stock'] <= 5): ?>
                        <span class="low-stock">
                            — Only <?php echo $product['stock']; ?> left!
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Add to Cart Form -->
                <form method="POST" action="" class="add-to-cart-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add">

                    <div class="quantity-selector">
                        <label>Quantity</label>
                        <div class="qty-controls">
                            <button type="button"
                                onclick="changeQty(-1)">-</button>
                            <input type="number"
                                name="quantity"
                                id="qtyInput"
                                value="1"
                                min="1"
                                max="<?php echo $product['stock']; ?>">
                            <button type="button"
                                onclick="changeQty(1)">+</button>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary btn-add-to-cart">
                        Add to Cart 🛒
                    </button>

                </form>

            <?php else: ?>
                <div class="stock-indicator out-of-stock-detail">
                    ✕ Out of Stock
                </div>
                <p style="color:#999; font-size:0.88rem; margin-top:8px;">
                    Contact us to be notified when this returns.
                </p>
            <?php endif; ?>

            <!-- Product Details -->
            <div class="product-detail-meta">
                <div class="meta-item">
                    <span class="meta-label">🚚 Delivery</span>
                    <span class="meta-value">Free UK delivery on all orders</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">↩️ Returns</span>
                    <span class="meta-value">
                        <a href="/returns">
                            30-day return policy
                        </a>
                    </span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">🔒 Payment</span>
                    <span class="meta-value">Secure payment via Stripe</span>
                </div>
            </div>

        </div>
    </div>

    <!-- Related Products -->
    <?php
    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM products
         WHERE id != ? AND stock > 0
         ORDER BY RAND()
         LIMIT 3"
    );
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $related = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);
    if (mysqli_num_rows($related) > 0):
    ?>
        <div class="related-products">
            <div class="section-header">
                <span class="section-eyebrow">You May Also Like</span>
                <h2>More Fragrances</h2>
            </div>
            <div class="products-grid">
                <?php while ($rel = mysqli_fetch_assoc($related)): ?>
                    <div class="product-card"
                        style="cursor:pointer;"
                        onclick="window.location='/product?id=<?php
                                                                                echo $rel['id']; ?>'">
                        <div class="product-card-image">
                            <img src="<?php echo SHOP_URL; ?>/images/<?php
                                                                        echo htmlspecialchars($rel['image']); ?>"
                                alt="<?php echo htmlspecialchars($rel['name']); ?>"
                                onerror="this.src='<?php echo SHOP_URL; ?>/images/placeholder.jpg'">
                            <div class="product-card-overlay">
                                <a href="/product?id=<?php echo $rel['id']; ?>"
                                    class="btn-view-product"
                                    onclick="event.stopPropagation()">
                                    View Fragrance
                                </a>
                            </div>
                        </div>
                        <div class="product-info">
                            <h2><?php echo htmlspecialchars($rel['name']); ?></h2>
                            <p><?php echo htmlspecialchars(
                                    substr($rel['description'], 0, 70)
                                ) . '...'; ?>
                            </p>
                            <div class="product-footer">
                                <?php
                                $relDiscount  = floatval($rel['discount_percent'] ?? 0);
                                $relSalePrice = $relDiscount > 0
                                    ? round($rel['price'] * (1 - $relDiscount / 100), 2)
                                    : null;
                                ?>
                                <?php if ($relSalePrice): ?>
                                    <div class="price-wrap">
                                        <span class="price-original">
                                            £<?php echo number_format($rel['price'], 2); ?>
                                        </span>
                                        <span class="price-sale">
                                            £<?php echo number_format($relSalePrice, 2); ?>
                                        </span>
                                        <span class="sale-badge">
                                            -<?php echo intval($relDiscount); ?>%
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span class="price">
                                        £<?php echo number_format($rel['price'], 2); ?>
                                    </span>
                                <?php endif; ?>
                                <a href="/product?id=<?php echo $rel['id']; ?>"
                                    class="btn-add"
                                    onclick="event.stopPropagation()">View →</a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    <?php endif; ?>




</div>

<script>
    function switchImage(src, el) {
        document.getElementById('mainImage').src = src;
        document.querySelectorAll('.thumbnail')
            .forEach(t => t.classList.remove('active'));
        el.classList.add('active');
    }

    function changeQty(change) {
        const input = document.getElementById('qtyInput');
        const max = parseInt(input.max);
        let val = parseInt(input.value) + change;
        if (val < 1) val = 1;
        if (val > max) val = max;
        input.value = val;
    }
</script>

<?php include 'includes/footer.php'; ?>