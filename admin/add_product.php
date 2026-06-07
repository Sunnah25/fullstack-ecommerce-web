<?php
include 'auth.php';
include '../includes/db.php';
require_once '../includes/upload_security.php';
require_once '../includes/csrf.php';

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrfToken();

  $name         = trim($_POST['name']        ?? '');
  $description  = trim($_POST['description'] ?? '');
  $price        = floatval($_POST['price']   ?? 0);
  $stock        = intval($_POST['stock']     ?? 0);
  $featured     = isset($_POST['featured']) ? 1 : 0;
  $weight       = intval($_POST['weight_grams']  ?? 100);
  $length       = floatval($_POST['length_cm']   ?? 10);
  $width        = floatval($_POST['width_cm']    ?? 10);
  $height       = floatval($_POST['height_cm']   ?? 10);
  $categoryId   = intval($_POST['category_id']   ?? 1);
  $discount     = floatval($_POST['discount_percent'] ?? 0);
  if ($discount < 0)  $discount = 0;
  if ($discount > 90) $discount = 90;
  $primaryImage = '';

  // Validate
  if (empty($name))  $error = "Product name is required.";
  if ($price <= 0)   $error = "Please enter a valid price.";
  if ($discount < 0)  $discount = 0;
  if ($discount > 90) $discount = 90;
  if ($weight <= 0)  $error = "Please enter the product weight.";
  if ($length <= 0)  $error = "Please enter the length.";
  if ($width  <= 0)  $error = "Please enter the width.";
  if ($height <= 0)  $error = "Please enter the height.";

  // Handle image uploads
  $uploadedImages = [];
  if (empty($error) && !empty($_FILES['images']['name'][0])) {
    foreach ($_FILES['images']['name'] as $i => $fname) {
      if (empty($fname)) continue;

      $singleFile = [
        'name'     => $_FILES['images']['name'][$i],
        'tmp_name' => $_FILES['images']['tmp_name'][$i],
        'size'     => $_FILES['images']['size'][$i],
        'error'    => $_FILES['images']['error'][$i],
        'type'     => $_FILES['images']['type'][$i],
      ];

      $validation = validateUpload($singleFile, 5, 'image');

      if (isset($validation['error'])) {
        $error = $validation['error'];
        break;
      }

      // Fix 5: cryptographically random filename
      // prevents time-based collisions
      $newName = bin2hex(random_bytes(16))
        . '.' . $validation['extension'];
      $moved   = moveUploadedFileSafe(
        $singleFile['tmp_name'],
        '../images/',
        $newName
      );

      if ($moved) {
        $uploadedImages[] = $newName;
        if ($i === 0) $primaryImage = $newName;
      } else {
        $error = "Failed to save image. Please try again.";
        break;
      }
    }
  }

  if (empty($error)) {

    // Fix 1 & 2: prepared statement + error handling
    $stmt = mysqli_prepare(
      $conn,
      "INSERT INTO products
             (name, description, price,
              discount_percent, stock, featured,
              image, weight_grams, length_cm,
              width_cm, height_cm, category_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
      $error = "Database error. Please try again.";
    } else {
      mysqli_stmt_bind_param(
        $stmt,
        "ssddiisidddi",
        $name,
        $description,
        $price,
        $discount,
        $stock,
        $featured,
        $primaryImage,
        $weight,
        $length,
        $width,
        $height,
        $categoryId
      );

      if (!mysqli_stmt_execute($stmt)) {
        // Fix 3: DB failed — clean up uploaded images
        foreach ($uploadedImages as $img) {
          $path = '../images/' . $img;
          if (file_exists($path)) unlink($path);
        }
        $error = "Failed to save product. Please try again.";
      } else {
        $newId = mysqli_insert_id($conn);

        // Save product images with prepared statements
        foreach ($uploadedImages as $idx => $imgName) {
          $isPrimary = ($idx === 0) ? 1 : 0;

          $imgStmt = mysqli_prepare(
            $conn,
            "INSERT INTO product_images
                         (product_id, image, is_primary, sort_order)
                         VALUES (?, ?, ?, ?)"
          );

          if ($imgStmt) {
            mysqli_stmt_bind_param(
              $imgStmt,
              "isii",
              $newId,
              $imgName,
              $isPrimary,
              $idx
            );
            mysqli_stmt_execute($imgStmt);
            mysqli_stmt_close($imgStmt);
          }
        }

        mysqli_stmt_close($stmt);

        $_SESSION['product_success'] =
          "✅ Product added successfully!";
        header('Location: /admin/add_product.php?added=1');
        exit();
      }

      if ($stmt) mysqli_stmt_close($stmt);
    }
  }
}

// Show flash success after redirect
$success = '';
if (isset($_SESSION['product_success'])) {
  $success = $_SESSION['product_success'];
  unset($_SESSION['product_success']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add Product — Admin</title>
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

      <a href="/admin/products"
        class="btn-back">← Back to Products</a>
      <h1>Add New Product</h1>
      <p class="admin-subtitle">
        Fill in the details below to add a new fragrance.
      </p>

      <?php if ($success): ?>
        <div class="alert alert-success">
          <?php echo $success; ?>
          <a href="/admin/products"
            style="color:#2e7d32; margin-left:12px;">
            ← Back to Products
          </a>
          &nbsp;|&nbsp;
          <a href="/admin/add_product.php"
            style="color:#2e7d32; margin-left:8px;">
            Add Another
          </a>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-error">
          ⚠️ <?php echo $error; ?>
        </div>
      <?php endif; ?>

      <div class="add-product-layout">

        <!-- LEFT: Form -->
        <div class="add-product-main">
          <form method="POST" action=""
            enctype="multipart/form-data"
            class="product-form">
            <?php echo csrfField(); ?>

            <!-- Basic Info -->
            <div class="detail-box"
              style="margin-bottom:20px;">
              <h3>Basic Information</h3>

              <div class="form-group">
                <label>Product Name *</label>
                <input type="text" name="name"
                  placeholder="e.g. Rose Oud"
                  value="<?php echo isset($_POST['name'])
                            ? htmlspecialchars($_POST['name'])
                            : ''; ?>"
                  required>
              </div>

              <div class="form-group">
                <label>Description</label>
                <textarea name="description"
                  style="height:140px;"
                  placeholder="Describe the scent — notes, mood, who it is for..."><?php
                                                                                    echo isset($_POST['description'])
                                                                                      ? htmlspecialchars($_POST['description'])
                                                                                      : ''; ?></textarea>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label>Price (£) *</label>
                  <input type="number" name="price"
                    id="basePrice"
                    step="0.01" min="0"
                    placeholder="49.99"
                    value="<?php echo isset($_POST['price'])
                              ? floatval($_POST['price']) : ''; ?>"
                    oninput="calcDiscount()"
                    required>
                </div>
                <div class="form-group">
                  <label>Discount (%)</label>
                  <div style="display:flex; align-items:center;
               gap:12px;">
                    <input type="number" name="discount_percent"
                      id="discountInput"
                      step="1" min="0" max="90"
                      placeholder="0"
                      value="<?php echo isset($_POST['discount_percent'])
                                ? intval($_POST['discount_percent']) : '0'; ?>"
                      oninput="calcDiscount()"
                      style="width:100px;">
                    <span id="discountPreview"
                      style="font-size:0.88rem; color:#2e7d32;
                 font-weight:bold; min-width:140px;">
                    </span>
                  </div>
                  <small style="color:#aaa; font-size:0.75rem;
                margin-top:4px; display:block;">
                    Leave 0 for no discount. Max 90%.
                  </small>
                </div>
                <div class="form-group">
                  <label>Stock Quantity</label>
                  <input type="number" name="stock"
                    min="0" placeholder="25"
                    value="<?php echo isset($_POST['stock'])
                              ? intval($_POST['stock'])
                              : '0'; ?>">
                </div>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label>Category</label>
                  <select name="category_id"
                    style="width:100%;
                               padding:10px;
                               border:1px solid #ddd;
                               font-family:Georgia,serif;
                               background:#fff;">
                    <?php
                    $cats = mysqli_query(
                      $conn,
                      "SELECT * FROM categories
                       WHERE slug != 'all'
                       ORDER BY sort_order"
                    );
                    while ($cat = mysqli_fetch_assoc($cats)):
                    ?>
                      <option value="<?php echo $cat['id']; ?>"
                        <?php echo (isset($_POST['category_id'])
                          && $_POST['category_id'] == $cat['id'])
                          ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                      </option>
                    <?php endwhile; ?>
                  </select>
                </div>
                <div class="form-group"
                  style="display:flex;
                          align-items:flex-end;
                          padding-bottom:4px;">
                  <label style="display:flex;
                               align-items:center;
                               gap:8px; cursor:pointer;
                               font-size:0.88rem;">
                    <input type="checkbox"
                      name="featured" value="1"
                      <?php echo (isset($_POST['featured']))
                        ? 'checked' : ''; ?>
                      style="accent-color:#d4af7a;
                                width:16px; height:16px;">
                    Show on Homepage (Featured)
                  </label>
                </div>
              </div>
            </div>

            <!-- Images -->
            <div class="detail-box"
              style="margin-bottom:20px;">
              <h3>Product Images</h3>
              <div class="form-group">

                <div id="imageDropArea"
                  style="border:2px dashed #ddd;
                          padding:20px;
                          text-align:center;
                          cursor:pointer;
                          background:#fafafa;
                          transition:border-color 0.3s;"
                  onclick="document.getElementById(
                     'productImages').click()"
                  ondragover="event.preventDefault();
                     this.style.borderColor='#d4af7a';"
                  ondragleave="this.style.borderColor='#ddd';"
                  ondrop="handleDrop(event)">
                  <p style="color:#999; margin:0 0 6px;
                           font-size:0.88rem;">
                    📎 Click to select images or drag and drop
                  </p>
                  <small style="color:#ccc;">
                    JPG, PNG or WEBP — max 5MB each
                  </small>
                </div>

                <input type="file"
                  id="productImages"
                  accept=".jpg,.jpeg,.png,.webp"
                  multiple
                  style="display:none;">

                <div id="fileInputsContainer"></div>

                <div id="imageError"
                  style="color:#e53935;
                          font-size:0.82rem;
                          margin-top:8px;
                          display:none;">
                </div>

                <div id="imagePreviews"
                  style="display:flex;
                          flex-wrap:wrap;
                          gap:10px;
                          margin-top:14px;">
                </div>

              </div>
            </div>

            <!-- Shipping -->
            <div class="detail-box"
              style="margin-bottom:20px;">
              <h3>Shipping Details</h3>
              <div class="form-row">
                <div class="form-group">
                  <label>Weight (grams) *</label>
                  <input type="number"
                    name="weight_grams"
                    placeholder="e.g. 250"
                    min="1"
                    value="<?php echo isset($_POST['weight_grams'])
                              ? intval($_POST['weight_grams'])
                              : '100'; ?>"
                    required>
                </div>
                <div class="form-group">
                  <label>Dimensions L × W × H (cm) *</label>
                  <div style="display:grid;
                            grid-template-columns:1fr 1fr 1fr;
                            gap:8px;">
                    <input type="number" name="length_cm"
                      placeholder="L" step="0.1"
                      min="1"
                      value="<?php echo isset($_POST['length_cm'])
                                ? floatval($_POST['length_cm'])
                                : '10'; ?>"
                      required>
                    <input type="number" name="width_cm"
                      placeholder="W" step="0.1"
                      min="1"
                      value="<?php echo isset($_POST['width_cm'])
                                ? floatval($_POST['width_cm'])
                                : '10'; ?>"
                      required>
                    <input type="number" name="height_cm"
                      placeholder="H" step="0.1"
                      min="1"
                      value="<?php echo isset($_POST['height_cm'])
                                ? floatval($_POST['height_cm'])
                                : '10'; ?>"
                      required>
                  </div>
                </div>
              </div>
            </div>

            <!-- Submit -->
            <div style="display:flex; gap:12px;">
              <button type="submit" class="btn-primary">
                ＋ Add Product
              </button>
              <a href="/admin/products"
                class="btn-secondary">Cancel</a>
            </div>

          </form>
        </div>

        <!-- RIGHT: Tips -->
        <div class="add-product-tips">
          <div class="detail-box">
            <h3>💡 Tips</h3>
            <div class="tip-item">
              <strong>Great product names</strong>
              <p>Keep it short and memorable.
                e.g. "Rose Oud" or "Cedar &amp; Amber"</p>
            </div>
            <div class="tip-item">
              <strong>Great descriptions</strong>
              <p>Mention the top, middle and base notes.
                Describe the mood and occasion.</p>
            </div>
            <div class="tip-item">
              <strong>Images</strong>
              <p>Use square photos for best results.
                First image is the main photo.</p>
            </div>
            <div class="tip-item">
              <strong>Weight</strong>
              <p>Include bottle and packaging weight
                for accurate shipping calculations.</p>
            </div>
            <div class="tip-item">
              <strong>Featured</strong>
              <p>Tick "Show on Homepage" to display
                this product in the featured section.</p>
            </div>
          </div>
        </div>

      </div>
    </main>
  </div>

  <script>
    let selectedFiles = [];

    document.getElementById('productImages')
      .addEventListener('change', function() {
        addFiles(this.files);
        this.value = '';
      });

    function handleDrop(event) {
      event.preventDefault();
      document.getElementById('imageDropArea')
        .style.borderColor = '#ddd';
      addFiles(event.dataTransfer.files);
    }

    function addFiles(fileList) {
      const maxBytes = 5 * 1024 * 1024;
      const errorDiv = document.getElementById('imageError');
      const allowed = ['image/jpeg', 'image/png', 'image/webp'];
      let hasError = false;

      Array.from(fileList).forEach(file => {
        if (!allowed.includes(file.type)) {
          errorDiv.textContent =
            '⚠️ Only JPG, PNG and WEBP allowed.';
          errorDiv.style.display = 'block';
          hasError = true;
          return;
        }
        if (file.size > maxBytes) {
          errorDiv.textContent =
            '⚠️ "' + file.name +
            '" exceeds 5MB. Please choose a smaller file.';
          errorDiv.style.display = 'block';
          hasError = true;
          return;
        }
        const alreadyAdded = selectedFiles.some(
          f => f.name === file.name && f.size === file.size);
        if (alreadyAdded) return;
        selectedFiles.push(file);
      });

      if (!hasError) errorDiv.style.display = 'none';
      updatePreviews();
      updateHiddenInputs();
    }

    function removeFile(index) {
      selectedFiles.splice(index, 1);
      updatePreviews();
      updateHiddenInputs();
    }

    function updatePreviews() {
      const container = document.getElementById('imagePreviews');
      container.innerHTML = '';

      selectedFiles.forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = function(e) {
          const div = document.createElement('div');
          div.style.cssText =
            'position:relative;width:90px;height:90px;' +
            'border:1px solid #ddd;overflow:hidden;';

          const img = document.createElement('img');
          img.src = e.target.result;
          img.style.cssText =
            'width:100%;height:100%;object-fit:cover;';

          if (index === 0) {
            const badge = document.createElement('div');
            badge.textContent = 'Primary';
            badge.style.cssText =
              'position:absolute;bottom:0;left:0;right:0;' +
              'background:rgba(0,0,0,0.6);color:#d4af7a;' +
              'font-size:0.65rem;text-align:center;' +
              'padding:2px;letter-spacing:1px;';
            div.appendChild(badge);
          }

          const btn = document.createElement('button');
          btn.textContent = '✕';
          btn.type = 'button';
          btn.style.cssText =
            'position:absolute;top:2px;right:2px;' +
            'background:rgba(229,57,53,0.85);' +
            'color:#fff;border:none;width:20px;height:20px;' +
            'cursor:pointer;font-size:0.75rem;' +
            'line-height:1;padding:0;';
          btn.onclick = () => removeFile(index);

          div.appendChild(img);
          div.appendChild(btn);
          container.appendChild(div);
        };
        reader.readAsDataURL(file);
      });

      const dropArea = document.getElementById('imageDropArea');
      const p = dropArea.querySelector('p');
      if (selectedFiles.length > 0) {
        p.textContent = '✅ ' + selectedFiles.length +
          ' image(s) selected. Click to add more.';
        p.style.color = '#2e7d32';
        dropArea.style.borderColor = '#4caf50';
      } else {
        p.textContent = '📎 Click to select images or drag and drop';
        p.style.color = '#999';
        dropArea.style.borderColor = '#ddd';
      }
    }

    function updateHiddenInputs() {
      const container = document.getElementById(
        'fileInputsContainer');
      container.innerHTML = '';

      // Check DataTransfer support
      if (typeof DataTransfer !== 'undefined' &&
        DataTransfer.prototype.hasOwnProperty('items')) {
        // Modern browsers
        const dt = new DataTransfer();
        selectedFiles.forEach(file =>
          dt.items.add(file));
        const input = document.createElement('input');
        input.type = 'file';
        input.name = 'images[]';
        input.multiple = true;
        input.style.display = 'none';
        try {
          input.files = dt.files;
          container.appendChild(input);
        } catch (e) {
          // Fallback for browsers that don't allow
          // setting input.files
          showManualFallback();
        }
      } else {
        showManualFallback();
      }
    }

    function showManualFallback() {
      // Safari fallback: just show a normal
      // multi-file input
      const container = document.getElementById(
        'fileInputsContainer');
      const input = document.createElement('input');
      input.type = 'file';
      input.name = 'images[]';
      input.multiple = true;
      input.accept = '.jpg,.jpeg,.png,.webp';
      input.style.cssText =
        'display:block; margin-top:10px;' +
        'font-family:Georgia,serif;' +
        'font-size:0.82rem;';

      const label = document.createElement('p');
      label.textContent =
        'Your browser requires standard file selection:';
      label.style.cssText =
        'font-size:0.78rem; color:#888; margin:8px 0 4px;';

      container.appendChild(label);
      container.appendChild(input);
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


    function calcDiscount() {
      const base = parseFloat(
        document.getElementById('basePrice').value) || 0;
      const discount = parseFloat(
        document.getElementById('discountInput').value) || 0;
      const preview = document.getElementById(
        'discountPreview');

      if (base > 0 && discount > 0) {
        const final = base * (1 - discount / 100);
        preview.textContent =
          '→ Sale price: £' + final.toFixed(2);
        preview.style.color = '#2e7d32';
      } else {
        preview.textContent = '';
      }
    }
    // Run on load to populate if editing
    calcDiscount();
  </script>

</body>

</html>