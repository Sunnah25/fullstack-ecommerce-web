<?php
include 'auth.php';
include '../includes/db.php';
require_once '../includes/csrf.php';

// ── CSRF check on all POST requests ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
}


// ── DELETE product (POST only, with transaction) ─────────
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'delete'
) {
    $did = intval($_POST['product_id']);

    // Collect paths BEFORE touching DB
    $imgPaths = [];
    $pathStmt = mysqli_prepare(
        $conn,
        "SELECT image FROM product_images WHERE product_id = ?"
    );
    mysqli_stmt_bind_param($pathStmt, "i", $did);
    mysqli_stmt_execute($pathStmt);
    $pathRes = mysqli_stmt_get_result($pathStmt);
    while ($row = mysqli_fetch_assoc($pathRes)) {
        $imgPaths[] = '../images/' . $row['image'];
    }
    mysqli_stmt_close($pathStmt);

    mysqli_begin_transaction($conn);
    try {
        // Delete order_items references first
        $stmt = mysqli_prepare(
            $conn,
            "DELETE FROM order_items WHERE product_id = ?"
        );
        if (!$stmt) throw new Exception("Prepare failed (order_items): " . mysqli_error($conn));
        mysqli_stmt_bind_param($stmt, "i", $did);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Delete order_items failed: " . mysqli_stmt_error($stmt));
        }
        mysqli_stmt_close($stmt);

        //Then delete images
        $stmt = mysqli_prepare(
            $conn,
            "DELETE FROM product_images WHERE product_id = ?"
        );
        if(!$stmt) throw new Exception("Prepare failed (images): " . mysqli_error($conn));
        mysqli_stmt_bind_param($stmt, "i", $did);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Delete images failed: " .  mysqli_stmt_error($stmt));
        }
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare(
            $conn,
            "DELETE FROM products WHERE id = ?"
        );
        if (!$stmt) throw new Exception("Prepare failed (products): " . mysqli_error($conn));
        mysqli_stmt_bind_param($stmt, "i", $did);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Delete product failed: " . mysqli_stmt_error($stmt));
        }
        mysqli_stmt_close($stmt);

        mysqli_commit($conn);

        // Delete files only after DB commit succeeded
        // Validate path stays inside images folder
        // prevents arbitrary file deletion if DB is compromised
        $baseDir = realpath(__DIR__ . '/../images');
        foreach ($imgPaths as $path) {
            $realPath = realpath($path);
            if (
                $realPath
                && $baseDir
                && str_starts_with($realPath, $baseDir)
            ) {
                unlink($realPath);
            }
        }

        header('Location: /admin/products?deleted=1');
        exit();
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Product delete failed for ID $did: " . $e->getMessage());
        $error = "Failed to delete product. Please try again.";
    }
}

// ── DELETE a product image (POST) ────────────────────────
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'delete_image'
) {
    $imgId = intval($_POST['image_id']);
    $pid   = intval($_POST['pid']);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM product_images WHERE id = ? AND product_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $imgId, $pid);
    mysqli_stmt_execute($stmt);
    $img = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($img) {
        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare(
                $conn,
                "DELETE FROM product_images WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, "i", $imgId);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception(mysqli_error($conn));
            }
            mysqli_stmt_close($stmt);

            $nextImage = null;
            if ($img['is_primary']) {
                $stmt = mysqli_prepare(
                    $conn,
                    "SELECT id, image FROM product_images
                     WHERE product_id = ? LIMIT 1"
                );
                mysqli_stmt_bind_param($stmt, "i", $pid);
                mysqli_stmt_execute($stmt);
                $next = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                if ($next) {
                    $stmt = mysqli_prepare(
                        $conn,
                        "UPDATE product_images
                         SET is_primary = 1 WHERE id = ?"
                    );
                    mysqli_stmt_bind_param($stmt, "i", $next['id']);
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception(mysqli_error($conn));
                    }
                    mysqli_stmt_close($stmt);

                    $stmt = mysqli_prepare(
                        $conn,
                        "UPDATE products SET image = ? WHERE id = ?"
                    );
                    mysqli_stmt_bind_param($stmt, "si", $next['image'], $pid);
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception(mysqli_error($conn));
                    }
                    mysqli_stmt_close($stmt);
                    $nextImage = $next['image'];
                }
            }

            mysqli_commit($conn);

            // Delete file only after DB commit succeeded
            $baseDir  = realpath(__DIR__ . '/../images');
            $realPath = realpath('../images/' . $img['image']);
            if (
                $realPath
                && $baseDir
                && str_starts_with($realPath, $baseDir)
            ) {
                unlink($realPath);
            }
        } catch (Exception $e) {
            mysqli_rollback($conn);
            error_log("Image delete failed for ID $imgId: " . $e->getMessage());
            $error = "Failed to delete image. Please try again.";
        }
    }
    header("Location: /admin/products?updated=1");
    exit();
}

// ── SET PRIMARY image (POST) ─────────────────────────────
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'set_primary'
) {
    $imgId = intval($_POST['image_id']);
    $pid   = intval($_POST['pid']);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM product_images WHERE id = ? AND product_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $imgId, $pid);
    mysqli_stmt_execute($stmt);
    $img = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($img) {
        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE product_images SET is_primary = 0
             WHERE product_id = ?"
            );
            mysqli_stmt_bind_param($stmt, "i", $pid);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception(mysqli_error($conn));
            }
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE product_images SET is_primary = 1
             WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, "i", $imgId);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception(mysqli_error($conn));
            }
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE products SET image = ? WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, "si", $img['image'], $pid);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception(mysqli_error($conn));
            }
            mysqli_stmt_close($stmt);

            mysqli_commit($conn);
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Failed to update primary image. Please try again.";
        }
    }
    header("Location: /admin/products?updated=1");
    exit();
}

// ── QUICK EDIT ───────────────────────────────────────────
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'quick_edit'
) {
    $pid      = intval($_POST['product_id']);
    $price    = floatval($_POST['price']);
    $stock    = intval($_POST['stock']);
    $featured = intval($_POST['featured'] ?? 0);
    if ($price  < 0) $price    = 0;
    if ($stock  < 0) $stock    = 0;
    if ($featured < 0 || $featured > 1) $featured = 0;

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE products
         SET price=?, stock=?, featured=?
         WHERE id=?"
    );
    mysqli_stmt_bind_param(
        $stmt,
        "diii",
        $price,
        $stock,
        $featured,
        $pid
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);


    header('Location: /admin/products?updated=1');
    exit();
}

// ── FULL EDIT ────────────────────────────────────────────
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'edit'
) {
    $eid         = intval($_POST['product_id']);
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = floatval($_POST['price']);
    $stock       = intval($_POST['stock']);
    $featured    = isset($_POST['featured']) ? 1 : 0;
    $weight      = intval($_POST['weight_grams']  ?? 100);
    $length      = floatval($_POST['length_cm']   ?? 10);
    $width       = floatval($_POST['width_cm']    ?? 10);
    $height      = floatval($_POST['height_cm']   ?? 10);
    $discount    = floatval($_POST['discount_percent'] ?? 0);
    if ($price  < 0)   $price   = 0;
    if ($stock  < 0)   $stock   = 0;
    if ($weight < 1)   $weight  = 1;
    if ($length < 0.1) $length  = 0.1;
    if ($width  < 0.1) $width   = 0.1;
    if ($height < 0.1) $height  = 0.1;
    if ($discount < 0)  $discount = 0;
    if ($discount > 90) $discount = 90;


    if ($name === '' || mb_strlen($name) > 200 || mb_strlen($description) > 5000) {
        if ($name === '') {
            $error = "Product name cannot be empty.";
        } elseif (mb_strlen($name) > 200) {
            $error = "Product name cannot exceed 200 characters.";
        } else {
            $error = "Description cannot exceed 5000 characters.";
        }
    } else {



        // Handle new image uploads
        $newPrimaryImage = null;
        if (!empty($_FILES['images']['name'][0])) {
            $allowed  = ['jpg', 'jpeg', 'png', 'webp'];
            $allowedMime = [
                'image/jpeg',
                'image/png',
                'image/webp'
            ];
            $maxBytes = 5 * 1024 * 1024;

            $maxSortStmt = mysqli_prepare(
                $conn,
                "SELECT MAX(sort_order) FROM product_images
             WHERE product_id = ?"
            );
            mysqli_stmt_bind_param($maxSortStmt, "i", $eid);
            mysqli_stmt_execute($maxSortStmt);
            $maxSort = mysqli_fetch_row(
                mysqli_stmt_get_result($maxSortStmt)
            )[0] ?? 0;
            mysqli_stmt_close($maxSortStmt);

            $firstNew = true;
            foreach ($_FILES['images']['name'] as $i => $fname) {
                if (empty($fname)) continue;

                // Validate extension
                $ext = strtolower(
                    pathinfo($fname, PATHINFO_EXTENSION)
                );
                if (!in_array($ext, $allowed)) continue;

                // Validate MIME type
                // Validate MIME type
                $mime = mime_content_type(
                    $_FILES['images']['tmp_name'][$i]
                );
                if (!in_array($mime, $allowedMime)) continue;

                // Validate size
                if ($_FILES['images']['size'][$i] > $maxBytes) continue;

                // Verify it is actually a real image
                // Prevents fake images and malicious uploads
                $imageInfo = getimagesize(
                    $_FILES['images']['tmp_name'][$i]
                );
                if ($imageInfo === false) continue;

                $newName = bin2hex(random_bytes(16)) . '.' . $ext;
                if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                if (move_uploaded_file(
                    $_FILES['images']['tmp_name'][$i],
                    '../images/' . $newName
                )) {
                    $maxSort++;
                    $isPrimary = 0;

                    // Check if product has no primary image
                    if ($firstNew) {
                        $chkStmt = mysqli_prepare(
                            $conn,
                            "SELECT COUNT(*) FROM product_images
                         WHERE product_id = ? AND is_primary = 1"
                        );
                        mysqli_stmt_bind_param($chkStmt, "i", $eid);
                        mysqli_stmt_execute($chkStmt);
                        $hasPrimary = mysqli_fetch_row(
                            mysqli_stmt_get_result($chkStmt)
                        )[0];
                        mysqli_stmt_close($chkStmt);

                        if (!$hasPrimary) {
                            $isPrimary = 1;
                            $newPrimaryImage = $newName;
                        }
                        $firstNew = false;
                    }

                    $imgStmt = mysqli_prepare(
                        $conn,
                        "INSERT INTO product_images
                     (product_id, image, is_primary, sort_order)
                     VALUES (?, ?, ?, ?)"
                    );
                    mysqli_stmt_bind_param(
                        $imgStmt,
                        "isii",
                        $eid,
                        $newName,
                        $isPrimary,
                        $maxSort
                    );
                    mysqli_stmt_execute($imgStmt);
                    mysqli_stmt_close($imgStmt);
                }
            }
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE products
         SET name=?, description=?, price=?,
             discount_percent=?, stock=?, featured=?,
             weight_grams=?, length_cm=?, width_cm=?,
             height_cm=?
         WHERE id=?"
        );
        mysqli_stmt_bind_param(
            $stmt,
            "ssddiiddddi",
            $name,
            $description,
            $price,
            $discount,
            $stock,
            $featured,
            $weight,
            $length,
            $width,
            $height,
            $eid
        );
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // Update primary image separately if needed
        if ($newPrimaryImage) {
            $imgUpdStmt = mysqli_prepare(
                $conn,
                "UPDATE products SET image=? WHERE id=?"
            );
            mysqli_stmt_bind_param(
                $imgUpdStmt,
                "si",
                $newPrimaryImage,
                $eid
            );
            mysqli_stmt_execute($imgUpdStmt);
            mysqli_stmt_close($imgUpdStmt);
        }


        header('Location: /admin/products?updated=1');
        exit();
    }
}

// ── PAGINATION + SEARCH ──────────────────────────────────
$search      = trim($_GET['search'] ?? '');
$page        = max(1, intval($_GET['page'] ?? 1));
$perPage     = 20;
$offset      = ($page - 1) * $perPage;

if (!empty($search)) {
    $countStmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) FROM products
         WHERE name LIKE ? OR description LIKE ? OR id LIKE ?"
    );
    $like = '%' . $search . '%';
    mysqli_stmt_bind_param(
        $countStmt,
        "sss",
        $like,
        $like,
        $like
    );
    mysqli_stmt_execute($countStmt);
    $totalProducts = mysqli_fetch_row(
        mysqli_stmt_get_result($countStmt)
    )[0];
    mysqli_stmt_close($countStmt);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM products
         WHERE name LIKE ? OR description LIKE ? OR id LIKE ?
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?"
    );
    mysqli_stmt_bind_param(
        $stmt,
        "sssii",
        $like,
        $like,
        $like,
        $perPage,
        $offset
    );
} else {
    $countStmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) FROM products"
    );
    mysqli_stmt_execute($countStmt);
    $totalProducts = mysqli_fetch_row(
        mysqli_stmt_get_result($countStmt)
    )[0];
    mysqli_stmt_close($countStmt);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM products
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $perPage, $offset);
}

mysqli_stmt_execute($stmt);
$products   = mysqli_stmt_get_result($stmt);
$totalPages = ceil($totalProducts / $perPage);



// Pre-fetch image counts for all products on this page
$productIds    = [];
$productsArray = [];
while ($row = mysqli_fetch_assoc($products)) {
    $productsArray[] = $row;
    $productIds[]    = $row['id'];
}
$imgCounts = [];
if (!empty($productIds)) {
    $inList   = implode(',', $productIds);
    $imgQuery = mysqli_query(
        $conn,
        "SELECT product_id, COUNT(*) as cnt
         FROM product_images
         WHERE product_id IN ($inList)
         GROUP BY product_id"
    );
    while ($row = mysqli_fetch_assoc($imgQuery)) {
        $imgCounts[$row['product_id']] = $row['cnt'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - Admin</title>
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

        <aside class="admin-sidebar">
            <div class="admin-sidebar-logo">🌸 Admin Panel</div>
            <ul>
                <li><a href="/admin/dashboard">📊 Dashboard</a></li>
                <li><a href="/admin/orders">📦 Orders</a></li>
                <li><a href="/admin/products" class="active">🌸 Products</a></li>
                <li><a href="/admin/messages">✉️ Messages</a></li>
                <li><a href="/admin/returns">🔄 Returns</a></li>
                <li><a href="/admin/complaints">⚠️ Complaints</a></li>
                <li><a href="/admin/logout">🚪 Logout</a></li>
            </ul>
        </aside>

        <main class="admin-main">
            <div style="display:flex; justify-content:space-between;
                align-items:center; margin-bottom:6px;">
                <h1>Products</h1>
                <a href="/admin/add_product.php"
                    class="btn-primary"
                    style="display:flex; align-items:center; gap:8px;
                    padding:10px 20px; font-size:0.88rem;">
                    ＋ Add New Product
                </a>
            </div>
            <p class="admin-subtitle">
                Manage your fragrance collection.
            </p>

            <!-- Search -->
            <form method="GET" action="" style="margin-bottom:20px;">
                <div style="display:flex; gap:10px; max-width:500px;">
                    <input type="text" name="search"
                        value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="Search by name or ID..."
                        style="flex:1; padding:10px 14px;
                               border:1px solid #ddd;
                               font-family:Georgia,serif;
                               font-size:0.88rem; outline:none;">
                    <button type="submit" class="btn-primary"
                        style="padding:10px 20px; font-size:0.82rem;
                               letter-spacing:1px;">
                        Search
                    </button>
                    <?php if (!empty($search)): ?>
                        <a href="/admin/products"
                            class="btn-secondary"
                            style="padding:10px 16px; font-size:0.82rem;">
                            ✕ Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Alerts -->
            <?php if (isset($_GET['added'])):   ?>
                <div class="alert alert-success">✅ Product added!</div>
            <?php endif; ?>
            <?php if (isset($_GET['updated'])): ?>
                <div class="alert alert-success">✅ Product updated!</div>
            <?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?>
                <div class="alert alert-error">🗑️ Product deleted.</div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Products Table -->
            <div class="admin-table-wrap">
                <h2>All Products (<?php echo $totalProducts; ?>)</h2>

                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Name</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Featured</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($productsArray) > 0):
                            foreach ($productsArray as $p):
                                $imgCount = $imgCounts[$p['id']] ?? 0; ?>
                                <tr id="row-<?php echo $p['id']; ?>">
                                    <td>
                                        <div style="position:relative; display:inline-block;">
                                            <img src="<?php echo SHOP_URL; ?>/images/<?php echo htmlspecialchars($p['image']); ?>"
                                                onerror="this.src='<?php echo SHOP_URL; ?>/images/placeholder.jpg'"
                                                style="width:55px; height:55px; object-fit:cover; border:1px solid #eee;">
                                            <?php if ($imgCount > 1): ?>
                                                <span style="position:absolute; bottom:0; right:0;
                                                         background:#1a1a1a; color:#d4af7a;
                                                         font-size:9px; padding:1px 4px;">
                                                    +<?php echo $imgCount - 1; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($p['name']); ?></strong>
                                        <br>
                                        <span style="font-size:0.75rem; color:#bbb;">
                                            ID #<?php echo $p['id']; ?>
                                        </span>
                                    </td>

                                    <!-- Quick edit price -->
                                    <td>
                                        <span class="quick-view" id="price-view-<?php echo $p['id']; ?>">
                                            £<?php echo number_format($p['price'], 2); ?>
                                            <button class="btn-quick-edit"
                                                onclick="startQuickEdit(<?php echo $p['id']; ?>)">
                                                ✏️
                                            </button>
                                        </span>
                                        <span class="quick-edit-field"
                                            id="price-edit-<?php echo $p['id']; ?>"
                                            style="display:none;">
                                            <input type="number"
                                                id="price-input-<?php echo $p['id']; ?>"
                                                value="<?php echo $p['price']; ?>"
                                                step="0.01" min="0"
                                                style="width:80px; padding:4px 8px;
                                                   border:1px solid #d4af7a;
                                                   font-family:Georgia,serif;">
                                        </span>
                                    </td>

                                    <!-- Quick edit stock -->
                                    <td>
                                        <span class="quick-view" id="stock-view-<?php echo $p['id']; ?>">
                                            <?php echo $p['stock']; ?>
                                            <?php if ($p['stock'] <= 5 && $p['stock'] > 0): ?>
                                                <span style="color:#f59e0b; font-size:0.72rem;">⚠️ Low</span>
                                            <?php elseif ($p['stock'] == 0): ?>
                                                <span style="color:#e53935; font-size:0.72rem;">✕ Out</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="quick-edit-field"
                                            id="stock-edit-<?php echo $p['id']; ?>"
                                            style="display:none;">
                                            <input type="number"
                                                id="stock-input-<?php echo $p['id']; ?>"
                                                value="<?php echo $p['stock']; ?>"
                                                min="0"
                                                style="width:70px; padding:4px 8px;
                                                   border:1px solid #d4af7a;
                                                   font-family:Georgia,serif;">
                                        </span>
                                    </td>

                                    <!-- Quick edit featured -->
                                    <td>
                                        <span class="quick-view" id="feat-view-<?php echo $p['id']; ?>">
                                            <?php echo $p['featured'] ? '⭐ Yes' : '—'; ?>
                                        </span>
                                        <span class="quick-edit-field"
                                            id="feat-edit-<?php echo $p['id']; ?>"
                                            style="display:none;">
                                            <select id="feat-input-<?php echo $p['id']; ?>"
                                                style="padding:4px 8px; border:1px solid #d4af7a;
                                                   font-family:Georgia,serif; background:#fff;">
                                                <option value="1" <?php echo $p['featured'] ? 'selected' : ''; ?>>⭐ Featured</option>
                                                <option value="0" <?php echo !$p['featured'] ? 'selected' : ''; ?>>— Not featured</option>
                                            </select>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="action-btns">
                                            <!-- Quick save -->
                                            <form method="POST" action=""
                                                id="quick-form-<?php echo $p['id']; ?>"
                                                style="display:none;">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="quick_edit">
                                                <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                                <input type="hidden" name="price" id="qf-price-<?php echo $p['id']; ?>">
                                                <input type="hidden" name="stock" id="qf-stock-<?php echo $p['id']; ?>">
                                                <input type="hidden" name="featured" id="qf-feat-<?php echo $p['id']; ?>">
                                                <button type="submit" class="btn-save-quick">✓ Save</button>
                                            </form>

                                            <button class="btn-edit"
                                                id="edit-btn-<?php echo $p['id']; ?>"
                                                onclick="startQuickEdit(<?php echo $p['id']; ?>)">
                                                Quick Edit
                                            </button>

                                            <button class="btn-edit"
                                                data-id="<?php echo intval($p['id']); ?>"
                                                data-name="<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>"
                                                data-desc="<?php echo htmlspecialchars($p['description'], ENT_QUOTES); ?>"
                                                data-price="<?php echo floatval($p['price']); ?>"
                                                data-stock="<?php echo intval($p['stock']); ?>"
                                                data-featured="<?php echo intval($p['featured']); ?>"
                                                data-weight="<?php echo intval($p['weight_grams'] ?? 0); ?>"
                                                data-length="<?php echo floatval($p['length_cm'] ?? 0); ?>"
                                                data-width="<?php echo floatval($p['width_cm'] ?? 0); ?>"
                                                data-height="<?php echo floatval($p['height_cm'] ?? 0); ?>"
                                                data-discount="<?php echo floatval($p['discount_percent'] ?? 0); ?>"
                                                onclick="openEditFromBtn(this)"
                                                style="background:#3d2b1f;">
                                                Full Edit
                                            </button>

                                            <!-- DELETE via POST -->
                                            <form method="POST" action="" style="display:inline;"
                                                onsubmit="return confirm('Delete <?php echo addslashes(htmlspecialchars($p['name'])); ?>? This cannot be undone.')">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                                <button type="submit" class="btn-delete">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach;
                        else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center; color:#999; padding:40px;">
                                    No products found.
                                    <a href="/admin/add_product.php" style="color:#d4af7a;">
                                        Add your first product →
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>


                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div style="display:flex; justify-content:center;
                                gap:8px; padding:20px;">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1;
                                            echo !empty($search)
                                                ? '&search=' . urlencode($search)
                                                : ''; ?>"
                                class="btn-secondary"
                                style="padding:8px 16px; font-size:0.82rem;">
                                ← Prev
                            </a>
                        <?php endif; ?>

                        <?php for (
                            $i = max(1, $page - 2);
                            $i <= min($totalPages, $page + 2);
                            $i++
                        ): ?>
                            <a href="?page=<?php echo $i;
                                            echo !empty($search)
                                                ? '&search=' . urlencode($search)
                                                : ''; ?>"
                                style="padding:8px 14px; font-size:0.82rem;
                                       background:<?php echo $i === $page
                                                        ? '#d4af7a' : '#fff'; ?>;
                                       color:<?php echo $i === $page
                                                    ? '#1a1a1a' : '#555'; ?>;
                                       border:1px solid #ddd;
                                       text-decoration:none;">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1;
                                            echo !empty($search)
                                                ? '&search=' . urlencode($search)
                                                : ''; ?>"
                                class="btn-secondary"
                                style="padding:8px 16px; font-size:0.82rem;">
                                Next →
                            </a>
                        <?php endif; ?>
                    </div>
                    <p style="text-align:center; color:#999;
                              font-size:0.78rem; padding-bottom:16px;">
                        Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                        (<?php echo $totalProducts; ?> products)
                    </p>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <!-- FULL EDIT MODAL -->
    <div id="editModal" class="modal-overlay" style="display:none;">
        <div class="modal-box">
            <h3>Full Edit Product</h3>
            <form method="POST" action=""
                enctype="multipart/form-data"
                class="product-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="product_id" id="edit_id">

                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description"
                        id="edit_description"
                        style="height:100px;"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Price (£)</label>
                        <input type="number" name="price"
                            id="edit_price" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label>Stock</label>
                        <input type="number" name="stock"
                            id="edit_stock" min="0">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Discount (%)</label>
                        <input type="number" name="discount_percent"
                            id="edit_discount" step="1" min="0" max="90"
                            oninput="calcEditDiscount()">
                    </div>
                    <div class="form-group"
                        style="display:flex; align-items:flex-end; padding-bottom:4px;">
                        <span id="editDiscountPreview"
                            style="font-size:0.88rem; color:#2e7d32; font-weight:bold;">
                        </span>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Weight (grams)</label>
                        <input type="number" name="weight_grams"
                            id="edit_weight" min="1">
                    </div>
                    <div class="form-group">
                        <label>Dimensions L×W×H (cm)</label>
                        <div style="display:grid;
                                    grid-template-columns:1fr 1fr 1fr; gap:8px;">
                            <input type="number" name="length_cm"
                                id="edit_length" placeholder="L" step="0.1" min="1">
                            <input type="number" name="width_cm"
                                id="edit_width" placeholder="W" step="0.1" min="1">
                            <input type="number" name="height_cm"
                                id="edit_height" placeholder="H" step="0.1" min="1">
                        </div>
                    </div>
                </div>

                <div class="form-group checkbox-group">
                    <label>
                        <input type="checkbox" name="featured"
                            id="edit_featured" value="1">
                        Show on Homepage (Featured)
                    </label>
                </div>

                <!-- Current Images -->
                <div class="form-group" id="currentImagesWrap" style="display:none;">
                    <label>Current Images</label>
                    <div id="currentImagesList" class="current-images-grid"></div>
                </div>

                <div class="form-group">
                    <label>Add More Images (optional)</label>
                    <input type="file" name="images[]"
                        accept=".jpg,.jpeg,.png,.webp" multiple class="file-input">
                    <small style="color:#aaa; font-size:0.75rem;">
                        JPG, PNG or WEBP — max 5MB each
                    </small>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-secondary"
                        onclick="closeEdit()">Cancel</button>
                    <button type="submit" class="btn-primary">
                        Save Changes →
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ── Quick Edit ──────────────────────────────────────────
        function startQuickEdit(id) {
            ['price', 'stock', 'feat'].forEach(field => {
                document.getElementById(`${field}-view-${id}`)
                    .style.display = 'none';
                document.getElementById(`${field}-edit-${id}`)
                    .style.display = 'inline-block';
            });
            document.getElementById(`quick-form-${id}`)
                .style.display = 'inline-block';
            document.getElementById(`edit-btn-${id}`)
                .style.display = 'none';

            document.getElementById(`quick-form-${id}`).onsubmit = function() {
                document.getElementById(`qf-price-${id}`).value =
                    document.getElementById(`price-input-${id}`).value;
                document.getElementById(`qf-stock-${id}`).value =
                    document.getElementById(`stock-input-${id}`).value;
                document.getElementById(`qf-feat-${id}`).value =
                    document.getElementById(`feat-input-${id}`).value;
            };
        }

        // ── Full Edit Modal ─────────────────────────────────────
        // Uses json_encode on server side so no escaping issues
        function openEdit(id, name, desc, price,
            stock, featured, weight,
            length, width, height, discount) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_description').value = desc;
            document.getElementById('edit_price').value = price;
            document.getElementById('edit_stock').value = stock;
            document.getElementById('edit_featured').checked = featured == 1;
            document.getElementById('edit_weight').value = weight;
            document.getElementById('edit_length').value = length;
            document.getElementById('edit_width').value = width;
            document.getElementById('edit_height').value = height;
            document.getElementById('edit_discount').value = discount;
            calcEditDiscount();

            fetch('/admin/get_product_images.php?id=' + id)
                .then(r => r.json())
                .then(images => {
                    const wrap = document.getElementById('currentImagesWrap');
                    const list = document.getElementById('currentImagesList');
                    if (images.length > 0) {
                        wrap.style.display = 'block';
                        list.innerHTML = images.map(img => `
                            <div class="current-image-item">
                                <img src="${SHOP_URL}/images/${img.image}"
                                    onerror="this.src='${SHOP_URL}/images/placeholder.jpg'">
                                <div class="current-image-actions">
                                    ${img.is_primary == 1
                                        ? '<span class="primary-badge">⭐ Main</span>'
                                        : `<form method="POST" action="" style="display:inline;">
                                               <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}">
                                               <input type="hidden" name="action" value="set_primary">
                                               <input type="hidden" name="image_id" value="${img.id}">
                                               <input type="hidden" name="pid" value="${img.product_id}">
                                               <button type="submit" class="btn-set-primary">Set Main</button>
                                           </form>`
                                    }
                                    <form method="POST" action="" style="display:inline;"
                                        onsubmit="return confirm('Delete this image?')">
                                        <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}">
                                        <input type="hidden" name="action" value="delete_image">
                                        <input type="hidden" name="image_id" value="${img.id}">
                                        <input type="hidden" name="pid" value="${img.product_id}">
                                        <button type="submit" class="btn-del-img">✕</button>
                                    </form>
                                </div>
                            </div>
                        `).join('');
                    } else {
                        wrap.style.display = 'none';
                    }
                });

            document.getElementById('editModal').style.display = 'flex';
        }

        function openEditFromBtn(btn) {
            const d = btn.dataset;
            openEdit(
                d.id, d.name, d.desc,
                d.price, d.stock, d.featured,
                d.weight, d.length, d.width,
                d.height, d.discount
            );
        }

        function closeEdit() {
            document.getElementById('editModal').style.display = 'none';
        }

        document.getElementById('editModal')
            .addEventListener('click', function(e) {
                if (e.target === this) closeEdit();
            });

        function calcEditDiscount() {
            const base = parseFloat(
                document.getElementById('edit_price').value) || 0;
            const discount = parseFloat(
                document.getElementById('edit_discount').value) || 0;
            const preview = document.getElementById('editDiscountPreview');
            if (base > 0 && discount > 0) {
                const final = base * (1 - discount / 100);
                preview.textContent = 'Sale price: £' + final.toFixed(2);
            } else {
                preview.textContent = 'No discount';
            }
        }
    </script>

    <!-- Pass CSRF token and SHOP_URL to JS -->
    <script>
        const CSRF_TOKEN = <?php echo json_encode(generateCsrfToken()); ?>;
        const SHOP_URL = <?php echo json_encode(SHOP_URL); ?>;
    </script>

    <script>
        const adminMenuToggle = document.querySelector('.admin-menu-toggle');
        const adminSidebar = document.querySelector('.admin-sidebar');

        adminMenuToggle?.addEventListener('click', function() {
            adminSidebar.classList.toggle('open');
            adminMenuToggle.classList.toggle('active');
        });

        document.querySelectorAll('.admin-sidebar a').forEach(link => {
            link.addEventListener('click', function() {
                adminSidebar.classList.remove('open');
                adminMenuToggle.classList.remove('active');
            });
        });

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
