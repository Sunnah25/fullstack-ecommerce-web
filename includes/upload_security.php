<?php
// ============================================================
// SECURE FILE UPLOAD HANDLER
// Prevents malicious file uploads
// ============================================================

function validateUpload($file, $maxSizeMB = 5,
                          $type = 'image') {

    $errors = [];

    // ── 1. Check upload errors ───────────────────
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   =>
                'File too large (server limit).',
            UPLOAD_ERR_FORM_SIZE  =>
                'File too large (form limit).',
            UPLOAD_ERR_PARTIAL    =>
                'File only partially uploaded.',
            UPLOAD_ERR_NO_FILE    =>
                'No file uploaded.',
            UPLOAD_ERR_NO_TMP_DIR =>
                'Missing temp folder.',
            UPLOAD_ERR_CANT_WRITE =>
                'Failed to write file.',
            UPLOAD_ERR_EXTENSION  =>
                'Upload blocked by extension.',
        ];
        return ['error' => $uploadErrors[
            $file['error']]
            ?? 'Unknown upload error.'];
    }

    // ── 2. Check file size ───────────────────────
    $maxBytes = $maxSizeMB * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        return ['error' =>
            "File too large. Max {$maxSizeMB}MB."];
    }

    // ── 3. Allowed types by upload type ─────────
    $allowedTypes = [
        'image' => [
            'extensions' => ['jpg','jpeg',
                              'png','webp'],
            'mimes'      => ['image/jpeg',
                              'image/png',
                              'image/webp'],
        ],
        'label' => [
            'extensions' => ['pdf','jpg',
                              'jpeg','png'],
            'mimes'      => ['application/pdf',
                              'image/jpeg',
                              'image/png'],
        ],
    ];

    $allowed = $allowedTypes[$type]
            ?? $allowedTypes['image'];

    // ── 4. Check extension ───────────────────────
    $originalName = $file['name'];
    $ext = strtolower(pathinfo(
        $originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed['extensions'])) {
        return ['error' =>
            "File type not allowed.
             Allowed: "
            . implode(', ',
              $allowed['extensions'])];
    }

    // ── 5. CRITICAL: Check for double extensions
    //    Blocks: image.php.jpg shell.jpg.php ─────
    $nameParts = explode('.', $originalName);
    if (count($nameParts) > 2) {
        // Multiple dots — check all extensions
        array_shift($nameParts); // Remove filename
        $dangerousExts = [
            'php','php3','php4','php5',
            'phtml','phar','exe','sh',
            'py','pl','rb','asp','aspx',
            'js','cgi','htaccess',
        ];
        foreach ($nameParts as $part) {
            if (in_array(strtolower($part),
                $dangerousExts)) {
                return ['error' =>
                    "Suspicious filename detected.
                     Upload rejected."];
            }
        }
    }

    // ── 6. Verify MIME type matches extension ────
    // Uses finfo to check actual file content
    // not just the filename
    if (function_exists('finfo_open')) {
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo,
                    $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType,
            $allowed['mimes'])) {
            return ['error' =>
                "File content does not match
                 its extension. Upload rejected."];
        }
    }

    // ── 7. For images: verify it's a real image ──
    if ($type === 'image') {
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return ['error' =>
                "File is not a valid image."];
        }
    }

    // ── 8. Generate safe filename ────────────────
    // Never use original filename directly
    $safeExt      = $ext;
    $safeFilename = bin2hex(random_bytes(16))
                  . '.' . $safeExt;

    return [
        'success'   => true,
        'filename'  => $safeFilename,
        'extension' => $safeExt,
        'mime'      => $mimeType ?? '',
        'size'      => $file['size'],
    ];
}

// ── Move uploaded file safely ─────────────────────
function moveUploadedFileSafe($tmpFile,
                               $destination,
                               $filename) {
    $filename = basename($filename);                            
    $fullPath = rtrim($destination, '/')
              . '/' . $filename;

    if (!move_uploaded_file($tmpFile, $fullPath)) {
        return false;
    }

    // Set restrictive permissions
    chmod($fullPath, 0644);

    return $fullPath;
}
?>