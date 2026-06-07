<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$pageTitle = "Our Fragrances";
include 'includes/db.php';

$metaDesc = 'Browse our full collection of
             premium fragrances. For her,
             for him and unisex. Free UK
             delivery on all orders.';
include 'includes/header.php';

// ── Filters from URL ─────────────────────────────────────────
$search    = trim($_GET['search'] ?? '');
$catSlug   = trim($_GET['category'] ?? 'all');
$sort      = trim($_GET['sort'] ?? 'newest');
$page      = max(1, intval($_GET['page'] ?? 1));
$perPage   = 12;
$offset    = ($page - 1) * $perPage;

// ── Build query ──────────────────────────────────────────────
$where = ["p.stock >= 0"];

if (!empty($search)) {
    $s = mysqli_real_escape_string($conn, $search);
    $where[] = "(p.name LIKE '%$s%'
                 OR p.description LIKE '%$s%')";
}

if ($catSlug && $catSlug !== 'all') {
    $slugSafe = mysqli_real_escape_string($conn, $catSlug);
    if ($catSlug === 'new-arrivals') {
        $where[] = "p.is_new = 1";
    } elseif ($catSlug === 'best-sellers') {
        $where[] = "p.is_bestseller = 1";
    } else {
        $where[] = "c.slug = '$slugSafe'";
    }
}

$whereSQL = implode(' AND ', $where);

$orderSQL = match ($sort) {
    'price_asc'  => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'name_asc'   => 'p.name ASC',
    'newest'     => 'p.created_at DESC',
    default      => 'p.created_at DESC',
};

// Count total
$totalResult = mysqli_query(
    $conn,
    "SELECT COUNT(*) FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $whereSQL"
);
$totalProducts = mysqli_fetch_row($totalResult)[0];
$totalPages    = ceil($totalProducts / $perPage);

// Get products
$products = mysqli_query(
    $conn,
    "SELECT p.*, c.name as category_name
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $whereSQL
     ORDER BY $orderSQL
     LIMIT $perPage OFFSET $offset"
);

// Get categories
$categories = mysqli_query(
    $conn,
    "SELECT * FROM categories ORDER BY sort_order"
);
?>

<?php if (isset($_SESSION['cart_error'])): ?>
    <div class="alert alert-error"
        style="max-width:1200px; margin:20px auto 0;">
        ⚠️ <?php echo $_SESSION['cart_error'];
            unset($_SESSION['cart_error']); ?>
    </div>
<?php endif; ?>

<div class="products-page-wrap">

    <!-- PAGE HEADER -->
    <div class="products-page-header">
        <h1>Our Fragrances</h1>
        <p><?php echo $totalProducts; ?> fragrances found
            <?php if ($search): ?>
                for "<strong><?php echo htmlspecialchars($search); ?></strong>"
            <?php endif; ?>
        </p>
    </div>

    <!-- SEARCH BAR -->
    <div class="products-search-bar">
        <form method="GET" action="" id="filterForm">
            <div class="search-input-wrap">
                <span class="search-icon">🔍</span>
                <input type="text"
                    name="search"
                    id="searchInput"
                    placeholder="Search fragrances..."
                    value="<?php echo htmlspecialchars($search); ?>"
                    autocomplete="off">
                <?php if ($search): ?>
                    <a href="/products"
                        class="search-clear">✕</a>
                <?php endif; ?>
            </div>
            <input type="hidden" name="category"
                id="hiddenCategory"
                value="<?php echo htmlspecialchars($catSlug); ?>">
            <input type="hidden" name="sort"
                id="hiddenSort"
                value="<?php echo htmlspecialchars($sort); ?>">
        </form>
    </div>

    <div class="products-layout">

        <!-- SIDEBAR FILTERS -->
        <aside class="products-sidebar">

            <!-- Categories -->
            <div class="filter-section">
                <h3>Categories</h3>
                <ul class="filter-list">
                    <?php
                    mysqli_data_seek($categories, 0);
                    while ($cat = mysqli_fetch_assoc($categories)):
                        $isActive = $catSlug === $cat['slug'];
                    ?>
                        <li>
                            <a href="?category=<?php echo $cat['slug'];
                                                ?>&sort=<?php echo htmlspecialchars($sort);
                                                        ?><?php echo $search
                                                                ? '&search=' . urlencode($search) : ''; ?>"
                                class="filter-link
               <?php echo $isActive ? 'active' : ''; ?>">
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </a>
                        </li>
                    <?php endwhile; ?>
                </ul>
            </div>

            <!-- Sort -->
            <div class="filter-section">
                <h3>Sort By</h3>
                <ul class="filter-list">
                    <?php
                    $sortOptions = [
                        'newest'     => 'Newest First',
                        'price_asc'  => 'Price: Low to High',
                        'price_desc' => 'Price: High to Low',
                        'name_asc'   => 'Name A-Z',
                    ];
                    foreach ($sortOptions as $val => $label):
                        $isActive = $sort === $val;
                    ?>
                        <li>
                            <a href="?sort=<?php echo $val;
                                            ?>&category=<?php echo htmlspecialchars($catSlug);
                                                        ?><?php echo $search
                                                                ? '&search=' . urlencode($search) : ''; ?>"
                                class="filter-link
               <?php echo $isActive ? 'active' : ''; ?>">
                                <?php echo $label; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Price Range -->
            <div class="filter-section">
                <h3>Price Range</h3>
                <div class="price-range-wrap">
                    <input type="range"
                        id="priceRange"
                        min="0" max="500"
                        value="500"
                        class="price-slider">
                    <div class="price-range-labels">
                        <span>£0</span>
                        <span id="priceValue">£500</span>
                    </div>
                </div>
            </div>

            <!-- Stock Filter -->
            <div class="filter-section">
                <label class="filter-checkbox">
                    <input type="checkbox"
                        id="inStockOnly"
                        <?php echo (isset($_GET['instock'])
                            && $_GET['instock'] == 1)
                            ? 'checked' : ''; ?>>
                    In Stock Only
                </label>
            </div>

        </aside>

        <!-- PRODUCTS GRID -->
        <div class="products-main">

            <!-- Mobile filter toggle -->
            <div class="mobile-filter-bar">
                <button class="btn-filter-toggle"
                    onclick="toggleFilters()">
                    ⚙️ Filters
                </button>
                <span class="products-count">
                    <?php echo $totalProducts; ?> products
                </span>
                <select onchange="window.location='?sort='
                +this.value+'&category=<?php
                                        echo htmlspecialchars($catSlug); ?><?php
                                                                            echo $search
                                                                                ? '&search=' . urlencode($search) : ''; ?>'"
                    class="mobile-sort-select">
                    <?php foreach ($sortOptions as $val => $label): ?>
                        <option value="<?php echo $val; ?>"
                            <?php echo $sort === $val ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (mysqli_num_rows($products) > 0): ?>

                <div class="products-grid" id="productsGrid">
                    <?php while ($product = mysqli_fetch_assoc(
                        $products
                    )): ?>

                        <div class="product-card"
                            data-price="<?php echo $product['price']; ?>"
                            data-stock="<?php echo $product['stock']; ?>"
                            style="cursor:pointer;"
                            onclick="window.location='/product?id=<?php
                                                                                    echo $product['id']; ?>'">

                            <div class="product-card-image">
                                <img src="<?php echo SHOP_URL; ?>/images/<?php
                                                                            echo htmlspecialchars($product['image']); ?>"
                                    alt="<?php echo htmlspecialchars(
                                                $product['name']
                                            ); ?>"
                                    onerror="this.src='<?php echo SHOP_URL; ?>/images/placeholder.jpg'">

                                <div class=" product-card-overlay">
                                <a href="/product?id=<?php
                                                                        echo $product['id']; ?>"
                                    class="btn-view-product"
                                    onclick="event.stopPropagation()">
                                    View Fragrance
                                </a>
                            </div>

                            <!-- Badges -->
                            <div class="product-badges">
                                <?php if ($product['is_new']): ?>
                                    <span class="badge-new">New</span>
                                <?php endif; ?>
                                <?php if ($product['is_bestseller']): ?>
                                    <span class="badge-best">
                                        Best Seller
                                    </span>
                                <?php endif; ?>
                                <?php if ($product['stock'] == 0): ?>
                                    <span class="badge-out">
                                        Out of Stock
                                    </span>
                                <?php endif; ?>
                                <?php if (
                                    $product['stock'] > 0
                                    && $product['stock'] <= 5
                                ): ?>
                                    <span class="badge-low">
                                        Only <?php echo $product['stock']; ?> left
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="product-info">
                            <?php if (
                                $product['category_name']
                                && $product['category_name'] !== 'All'
                            ): ?>
                                <span class="product-category">
                                    <?php echo htmlspecialchars(
                                        $product['category_name']
                                    ); ?>
                                </span>
                            <?php endif; ?>

                            <h2><?php echo htmlspecialchars(
                                    $product['name']
                                ); ?></h2>

                            <p><?php echo htmlspecialchars(
                                    substr(
                                        $product['description'],
                                        0,
                                        75
                                    )
                                ) . '...'; ?></p>

                            <div class="product-footer">
                                <?php
                                $salePrice = getSalePrice(
                                    $product['price'],
                                    $product['discount_percent'] ?? 0
                                );
                                ?>
                                <?php if ($salePrice): ?>
                                    <div class="price-wrap">
                                        <span class="price-original">
                                            £<?php echo number_format($product['price'], 2); ?>
                                        </span>
                                        <span class="price-sale">
                                            £<?php echo number_format($salePrice, 2); ?>
                                        </span>
                                        <span class="sale-badge">
                                            -<?php echo intval($product['discount_percent']); ?>%
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span class="price">
                                        £<?php echo number_format($product['price'], 2); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($product['stock'] > 0): ?>
                                    <a href="/product?id=<?php
                                                                            echo $product['id']; ?>"
                                        class="btn-add"
                                        onclick="event.stopPropagation()">
                                        View →
                                    </a>
                                <?php else: ?>
                                    <span class="out-of-stock">
                                        Out of Stock
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                </div>

            <?php endwhile; ?>
        </div>

        <!-- PAGINATION -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1;
                                    ?>&category=<?php echo htmlspecialchars($catSlug);
                                                ?>&sort=<?php echo htmlspecialchars($sort);
                                                                ?><?php echo $search
                                                                        ? '&search=' . urlencode($search) : ''; ?>"
                        class="page-btn">← Prev</a>
                <?php endif; ?>

                <?php
                    $start = max(1, $page - 2);
                    $end   = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++):
                ?>
                    <a href="?page=<?php echo $i;
                                    ?>&category=<?php echo htmlspecialchars($catSlug);
                                                ?>&sort=<?php echo htmlspecialchars($sort);
                                                                ?><?php echo $search
                                                                        ? '&search=' . urlencode($search) : ''; ?>"
                        class="page-btn
               <?php echo $i === $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1;
                                    ?>&category=<?php echo htmlspecialchars($catSlug);
                                                ?>&sort=<?php echo htmlspecialchars($sort);
                                                                ?><?php echo $search
                                                                        ? '&search=' . urlencode($search) : ''; ?>"
                        class="page-btn">Next →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>

        <div class="products-empty">
            <div class="empty-icon">🔍</div>
            <h2>No fragrances found</h2>
            <?php if ($search): ?>
                <p>No results for
                    "<strong><?php echo htmlspecialchars(
                                    $search
                                ); ?></strong>".</p>
                <p>Try different keywords or
                    browse all fragrances.</p>
            <?php else: ?>
                <p>No products in this category yet.</p>
            <?php endif; ?>
            <a href="/products"
                class="btn-primary"
                style="margin-top:20px;
                    display:inline-block;">
                View All Fragrances
            </a>
        </div>

    <?php endif; ?>

    </div>
</div>
</div>

<script>
    // Live search with debounce
    let searchTimer;
    document.getElementById('searchInput')
        .addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 500);
        });

    // Price range filter
    const priceSlider = document.getElementById('priceRange');
    const priceValue = document.getElementById('priceValue');
    if (priceSlider) {
        priceSlider.addEventListener('input', function() {
            priceValue.textContent = '£' + this.value;
            filterByPrice(this.value);
        });
    }

    function filterByPrice(maxPrice) {
        document.querySelectorAll('.product-card')
            .forEach(card => {
                const price = parseFloat(card.dataset.price);
                card.style.display =
                    price <= maxPrice ? 'block' : 'none';
            });
    }

    // In stock filter
    const inStockCheck = document.getElementById('inStockOnly');
    if (inStockCheck) {
        inStockCheck.addEventListener('change', function() {
            document.querySelectorAll('.product-card')
                .forEach(card => {
                    const stock = parseInt(card.dataset.stock);
                    if (this.checked) {
                        card.style.display =
                            stock > 0 ? 'block' : 'none';
                    } else {
                        card.style.display = 'block';
                    }
                });
        });
    }

    // Mobile filter toggle
    function toggleFilters() {
        const sidebar = document.querySelector(
            '.products-sidebar');
        sidebar.classList.toggle('open');
    }
</script>

<?php include 'includes/footer.php'; ?>