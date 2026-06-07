<?php
// Load environment variables
require_once __DIR__ . '/includes/env.php';

// ── Database ─────────────────────────────────
// No fallbacks for sensitive credentials
// If these are missing the site should not run
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: '');

// ── Stripe ───────────────────────────────────
define('STRIPE_PUBLIC_KEY', getenv('STRIPE_PUBLIC_KEY') ?: '');
define('STRIPE_SECRET_KEY', getenv('STRIPE_SECRET_KEY') ?: '');

// ── Shop Details ─────────────────────────────
define('SHOP_NAME',     getenv('SHOP_NAME')     ?: 'Genova Perfumes');
define('SHOP_EMAIL',    getenv('SHOP_EMAIL')     ?: '');
define('SHOP_PHONE',    getenv('SHOP_PHONE')     ?: '');
define('SHOP_ADDRESS',  getenv('SHOP_ADDRESS')   ?: '');
define('SHOP_CITY',     getenv('SHOP_CITY')      ?: '');
define('SHOP_POSTCODE', getenv('SHOP_POSTCODE')  ?: '');
define('SHOP_COUNTRY',  getenv('SHOP_COUNTRY')   ?: 'GB');
define('SHOP_CURRENCY', getenv('SHOP_CURRENCY')  ?: 'gbp');
define('SHOP_URL', getenv('SHOP_URL') ?: '');

define('BASE_PATH',
    getenv('BASE_PATH') ?: '');



// ── Sendcloud ─────────────────────────────
define(
    'SENDCLOUD_PUBLIC_KEY',
    getenv('SENDCLOUD_PUBLIC_KEY') ?: ''
);
define(
    'SENDCLOUD_SECRET_KEY',
    getenv('SENDCLOUD_SECRET_KEY') ?: ''
);
define(
    'SENDCLOUD_API_URL',
    'https://panel.sendcloud.sc/api/v2/'
);
define(
    'SENDCLOUD_SENDER_ID',
    getenv('SENDCLOUD_SENDER_ID') ?: ''
);




// ── Email ─────────────────────────────────────────
define('MAIL_HOST',       getenv('MAIL_HOST')       ?: 'smtp.gmail.com');
define('MAIL_PORT',       (int)getenv('MAIL_PORT')       ?: 587);
define('MAIL_USERNAME',   getenv('MAIL_USERNAME')   ?: '');
define('MAIL_PASSWORD',   getenv('MAIL_PASSWORD')   ?: '');
define('MAIL_FROM_NAME',  getenv('MAIL_FROM_NAME')  ?: SHOP_NAME);
define('MAIL_FROM_EMAIL', getenv('MAIL_FROM_EMAIL') ?: SHOP_EMAIL);


//admin
define('ADMIN_PASSWORD_HASH',
    getenv('ADMIN_PASSWORD_HASH') ?: '');



// Calculate sale price from base price and discount
function getSalePrice($price, $discountPercent) {
    if ($discountPercent <= 0) return null;
    return round($price * (1 - $discountPercent / 100), 2);
}

function isOnSale($discountPercent) {
    return $discountPercent > 0;
}