<?php
function loadEnv($path)
{
    if (!file_exists($path)) {
        error_log(".env file not found: $path");
        http_response_code(500);
        exit('Application configuration error.');
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) continue;

        // Split on first = only
        if (strpos($line, '=') === false) continue;

        list($key, $value) = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Remove surrounding quotes if present
        $value = trim($value, '"\'');

        if (!empty($key)) {
            $_ENV[$key]  = $value;
            putenv("$key=$value");
        }
    }
}

// Load the .env file
loadEnv(__DIR__ . '/../.env');
