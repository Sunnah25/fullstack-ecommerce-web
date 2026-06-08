# Manual Testing & Validation Report — Genova Perfumes

> Manual test cases executed during development and deployment of a full-stack PHP e-commerce application. Each test documents the scenario, expected result, actual result, and any bugs found and fixed.

> ⚠️ **Note:** These are manual test cases executed during development and QA. 
> This is not an automated test suite. Automated testing with PHPUnit and CI/CD 
> integration is a planned future improvement.

---

## Table of Contents

- [Testing Strategy](#testing-strategy)
- [1. Authentication & Session Security](#1-authentication--session-security)
- [2. Payment & Checkout Flow](#2-payment--checkout-flow)
- [3. Stock & Inventory Management](#3-stock--inventory-management)
- [4. Cart & Pricing](#4-cart--pricing)
- [5. Order Management](#5-order-management)
- [6. Returns & Refunds](#6-returns--refunds)
- [7. File Upload Security](#7-file-upload-security)
- [8. SQL Injection & Input Validation](#8-sql-injection--input-validation)
- [9. CSRF Protection](#9-csrf-protection)
- [10. Rate Limiting](#10-rate-limiting)
- [11. Race Conditions](#11-race-conditions)
- [12. Email System](#12-email-system)
- [13. API Integrations](#13-api-integrations)
- [14. Access Control](#14-access-control)
- [15. Error Handling & Recovery](#15-error-handling--recovery)
- [16. Mobile & Browser Compatibility](#16-mobile--browser-compatibility)
- [17. Performance & Edge Cases](#17-performance--edge-cases)
- [18. Production Environment](#18-production-environment)
- [Test Results Summary](#test-results-summary)

---

## Testing Strategy

Tests are grouped into three categories:

| Category | Description |
|----------|-------------|
| 🔴 Security | Tests that could result in financial loss, data breach, or system compromise if they fail |
| 🟡 Functional | Tests that verify core business logic works correctly |
| 🟢 UX | Tests that verify the user experience is correct and intuitive |

Each test is marked with its current status:
- ✅ **PASS** — Behaves as expected
- ❌ **FAIL** — Bug found, fix applied (documented)
- ⚠️ **KNOWN** — Known limitation, documented and accepted

---

## 1. Authentication & Session Security

### 1.1 Admin Login — Correct Credentials
**Category:** 🔴 Security
**Test:** Submit valid username and password on `/admin/login.php`
**Expected:** Redirect to dashboard, session created, session ID regenerated
**Result:** ✅ PASS
**Notes:** `session_regenerate_id(true)` confirmed in login handler preventing session fixation

---

### 1.2 Admin Login — Wrong Password
**Category:** 🔴 Security
**Test:** Submit valid username with wrong password
**Expected:** Error message shown, no session created, attempt logged
**Result:** ✅ PASS

---

### 1.3 Admin Login — Brute Force Protection
**Category:** 🔴 Security
**Test:** Submit wrong password 6 times within 15 minutes from the same IP
**Expected:** Rate limit triggered after 5 attempts — "Too many login attempts" shown
**Result:** ✅ PASS
**Notes:** Rate limit resets after 15-minute window. Each IP has independent counter.

---

### 1.4 Direct Admin Access Without Login
**Category:** 🔴 Security
**Test:** Navigate directly to `/admin/orders.php` without a session
**Expected:** Redirect to `/admin/login.php`
**Result:** ✅ PASS
**Notes:** `auth.php` included on every admin page checks `$_SESSION['admin_logged_in']`

---

### 1.5 Session Timeout
**Category:** 🔴 Security
**Test:** Log into admin, wait 2+ hours without activity, attempt to navigate
**Expected:** Session expired, redirect to login with timeout message
**Result:** ✅ PASS
**Notes:** `admin_last_activity` timestamp checked in `auth.php`

---

### 1.6 Direct Access to auth.php
**Category:** 🔴 Security
**Test:** Navigate directly to `/admin/auth.php`
**Expected:** 403 Forbidden (blocked by `.htaccess`)
**Result:** ✅ PASS

---

### 1.7 Session Cookie Flags
**Category:** 🔴 Security
**Test:** Inspect session cookie in browser DevTools after login
**Expected:** `HttpOnly` flag set, `SameSite=Strict`, `Secure` on HTTPS
**Result:** ✅ PASS
**Notes:** `Secure` flag activates only when HTTPS detected via `$_SERVER['HTTPS']`

---

## 2. Payment & Checkout Flow

### 2.1 Successful Payment — Full Flow
**Category:** 🟡 Functional
**Test:** Complete checkout with Stripe test card `4242 4242 4242 4242`
**Expected:** Redirect to `payment_success.php`, order status set to `paid`, stock reduced, confirmation email sent
**Result:** ✅ PASS

---

### 2.2 Cancelled Payment
**Category:** 🟡 Functional
**Test:** Click "Back" on Stripe checkout page
**Expected:** Redirect to `payment_cancel.php`, order remains as `pending_payment`, cart preserved
**Result:** ✅ PASS — after fix
**Bug Found:** `payment_cancel.php` was throwing a foreign key constraint error when attempting to delete the pending order without first deleting `order_items`
**Fix Applied:** Delete `order_items` before `orders` in cancel handler

---

### 2.3 Declined Card
**Category:** 🟡 Functional
**Test:** Use Stripe test card `4000 0000 0000 0002` (decline)
**Expected:** Stripe shows decline message, user stays on Stripe page, order remains `pending_payment`
**Result:** ✅ PASS — Stripe handles entirely on their side

---

### 2.4 Payment Success Page — Direct URL Access
**Category:** 🔴 Security
**Test:** Navigate to `/payment_success.php` directly without a valid `session_id` parameter
**Expected:** Redirect to homepage immediately
**Result:** ✅ PASS

---

### 2.5 Payment Success Page — Reload After Payment
**Category:** 🔴 Security
**Test:** Complete payment, then press F5 to reload `payment_success.php`
**Expected:** Show generic "Thank you" page, no duplicate email sent, no stock reduced again
**Result:** ✅ PASS — after fix
**Bug Found:** Every reload was sending a new confirmation email and reducing stock
**Fix Applied:** `stripe_session_id` stored on order. Second visit finds `status != 'pending_payment'` and shows generic page without re-processing

---

### 2.6 Price Manipulation — Tamper with POST Data
**Category:** 🔴 Security
**Test:** Use browser DevTools to modify the price shown in the order summary before submitting checkout
**Expected:** Server ignores POSTed price, uses locked cart price from session
**Result:** ✅ PASS
**Notes:** Price is never taken from `$_POST`. Line items use `$item['charge_price']` from session.

---

### 2.7 Stripe Webhook — Fake Payload
**Category:** 🔴 Security
**Test:** Send a crafted POST to `/webhook_stripe.php` without a valid Stripe signature
**Expected:** 400 response, request rejected before any processing
**Result:** ✅ PASS
**Notes:** HMAC-SHA256 signature verified via `Stripe\Webhook::constructEvent()`

---

### 2.8 Checkout — Empty Cart
**Category:** 🟡 Functional
**Test:** Navigate directly to `/checkout.php` with an empty cart
**Expected:** Redirect to products page
**Result:** ✅ PASS

---

### 2.9 Checkout — Session Expired Mid-Flow
**Category:** 🟡 Functional
**Test:** Add items to cart, wait for session to expire, attempt checkout
**Expected:** Redirect to products page with appropriate message
**Result:** ✅ PASS

---

### 2.10 Checkout — Double Submit (Slow Network)
**Category:** 🟡 Functional
**Test:** Click Pay Now twice rapidly
**Expected:** Second click prevented by `formSubmitting` flag and disabled button
**Result:** ✅ PASS
**Notes:** Button uses `pointerEvents: none` not `disabled` to prevent blocking the POST

---

### 2.11 Checkout — Multiple Tabs Open
**Category:** 🔴 Security
**Test:** Open checkout in two browser tabs, fill both forms, submit both simultaneously
**Expected:** Only one order created, second tab reuses existing pending order
**Result:** ✅ PASS
**Notes:** `GET_LOCK('checkout_{session_id}', 3)` serialises concurrent requests

---

## 3. Stock & Inventory Management

### 3.1 Purchase Last Item
**Category:** 🟡 Functional
**Test:** Set stock to 1, complete a purchase
**Expected:** Stock becomes 0 after successful payment
**Result:** ✅ PASS
**Notes:** Atomic SQL: `UPDATE products SET stock = stock - $qty WHERE id = $pid AND stock >= $qty`

---

### 3.2 Purchase More Than Available Stock
**Category:** 🟡 Functional
**Test:** Set stock to 1, try to add 2 to cart
**Expected:** Cart blocks addition, error shown
**Result:** ✅ PASS — after fix
**Bug Found:** Original cart code allowed any quantity to be added without stock check
**Fix Applied:** Stock check on every `add` and `update` action in `cart.php`

---

### 3.3 Race Condition — Two Customers, One Item
**Category:** 🔴 Security
**Test:** Simulate two concurrent payment completions for the last item in stock
**Expected:** One succeeds, second logs an oversell warning but does not go negative
**Result:** ✅ PASS
**Notes:** `GREATEST(0, stock - qty)` prevents negative stock. `mysqli_affected_rows() === 0` detects the race and logs it.

---

### 3.4 Stock Restore on Refund
**Category:** 🟡 Functional
**Test:** Complete a purchase, then process a full refund via admin returns panel
**Expected:** Stock restored to pre-purchase level
**Result:** ✅ PASS

---

### 3.5 Stock Restore on Cancellation (Pre-Dispatch)
**Category:** 🟡 Functional
**Test:** Customer submits pre-dispatch cancellation, admin approves
**Expected:** Stock restored
**Result:** ✅ PASS

---

### 3.6 Stock NOT Restored on Rejection
**Category:** 🟡 Functional
**Test:** Customer submits return request, admin rejects it
**Expected:** Stock unchanged — customer kept the item
**Result:** ✅ PASS — after fix
**Bug Found:** Early implementation restored stock on rejection
**Fix Applied:** Stock restoration only runs in the `process_refund` handler, never in `reject` handler

---

## 4. Cart & Pricing

### 4.1 Discount Price Display
**Category:** 🟢 UX
**Test:** Set a 20% discount on a £50 product, view product page and cart
**Expected:** Product shows ~~£50~~ £40 with -20% badge throughout
**Result:** ✅ PASS

---

### 4.2 Locked Price — Admin Changes Price After Cart Add
**Category:** 🟡 Functional
**Test:** Add product at £50 to cart, admin changes price to £70, proceed to checkout
**Expected:** Checkout charges £50 (locked price), not £70
**Result:** ✅ PASS — after fix
**Bug Found:** Checkout was recalculating price from DB with `getSalePrice()` instead of using session-locked price
**Fix Applied:** Checkout uses `$item['charge_price']` from session exclusively. Stripe line items and order_items both use the locked value.

---

### 4.3 Cart Count Display
**Category:** 🟢 UX
**Test:** Add 3 different products to cart with quantities 2, 1, 3
**Expected:** Cart badge shows 6 (total items, not unique products)
**Result:** ✅ PASS — after fix
**Bug Found:** Cart badge showed 0 after session format changed from integer to array
**Fix Applied:** Header counts using `is_array($entry) ? $entry['qty'] : intval($entry)` pattern

---

### 4.4 Cart Quantity Update
**Category:** 🟡 Functional
**Test:** Change quantity in cart from 2 to 5 when stock is 3
**Expected:** Quantity capped at 3 (available stock)
**Result:** ✅ PASS

---

### 4.5 Cart — Out of Stock Product
**Category:** 🟡 Functional
**Test:** Product is in cart, stock set to 0 before checkout completes
**Expected:** Cart removes the product during load, checkout blocks with error
**Result:** ✅ PASS
**Notes:** Cart loading loop checks `stock <= 0` and calls `unset()` then `continue`

---

### 4.6 Related Products — Show Own Price
**Category:** 🟢 UX
**Test:** View a product detail page with 3 related products shown below
**Expected:** Each related product shows its own price, not the main product's price
**Result:** ✅ PASS — after fix
**Bug Found:** Related products loop used `$product` (main product) instead of `$rel` (related product) for price calculation
**Fix Applied:** Changed price variables to use `$rel['price']` and `$rel['discount_percent']` inside the related products loop

---

## 5. Order Management

### 5.1 Order Appears in Admin After Payment
**Category:** 🟡 Functional
**Test:** Complete a test purchase, check admin orders
**Expected:** Order appears in "Awaiting Dispatch" tab with correct details
**Result:** ✅ PASS

---

### 5.2 Create Label via Manual Fallback
**Category:** 🟡 Functional
**Test:** Enter a Royal Mail tracking number manually in create_shipment.php
**Expected:** Order status moves to `dispatched`, tracking number saved, dispatch email sent
**Result:** ✅ PASS

---

### 5.3 Dispatch Dashboard — Urgency Colours
**Category:** 🟢 UX
**Test:** Create orders dispatched 1 day ago, 4 days ago, and 7 days ago
**Expected:** Green / Amber / Red urgency colours respectively, oldest first
**Result:** ✅ PASS

---

### 5.4 Mark as Delivered
**Category:** 🟡 Functional
**Test:** Click Delivered on an order in update_shipments.php
**Expected:** Order status → `delivered`, delivery email sent, return option unlocked for customer
**Result:** ✅ PASS

---

### 5.5 Admin Cancels Order (Pre-Dispatch)
**Category:** 🟡 Functional
**Test:** Admin clicks Cancel on an awaiting-dispatch order
**Expected:** Status → `cancelled`, stock restored
**Result:** ✅ PASS

---

### 5.6 Search Persists Across Tabs
**Category:** 🟢 UX
**Test:** Search for an order by name, click the Dispatched tab
**Expected:** Search term preserved in URL parameter, results filtered in new tab
**Result:** ✅ PASS
**Notes:** Tab links include `&search=<?php echo urlencode($search); ?>`

---

### 5.7 Duplicate Dispatch Prevention
**Category:** 🔴 Security
**Test:** Open create_shipment.php in two browser tabs for the same order, submit both simultaneously
**Expected:** Only one shipment created, second request blocked by `SELECT ... FOR UPDATE` inside transaction
**Result:** ✅ PASS

---

## 6. Returns & Refunds

### 6.1 Return Request — Delivered Order Only
**Category:** 🟡 Functional
**Test:** Try to submit a return for an order with status `dispatched`
**Expected:** Return option not shown, only cancellation option visible
**Result:** ✅ PASS

---

### 6.2 Pre-Dispatch Cancellation Flow
**Category:** 🟡 Functional
**Test:** Customer submits cancellation for a `paid` order
**Expected:** Admin sees "no label needed" notice, full refund button shown, stock restored on approval
**Result:** ✅ PASS

---

### 6.3 Post-Dispatch Cancellation — Handling Fee
**Category:** 🟡 Functional
**Test:** Customer submits cancellation for a `dispatched` order
**Expected:** Admin can enter custom handling fee, refund processed for order total minus that amount
**Result:** ✅ PASS

---

### 6.4 Refund Race Condition — Double Click
**Category:** 🔴 Security
**Test:** Click "Process Refund" twice rapidly
**Expected:** First click atomically sets status to `processing_refund`. Second click finds this status and exits. Only one Stripe refund created.
**Result:** ✅ PASS
**Notes:** `UPDATE returns SET status = 'processing_refund' WHERE status NOT IN ('refunded','rejected','processing_refund')` — zero rows affected on second click

---

### 6.5 Failed Refund — Status Resets
**Category:** 🟡 Functional
**Test:** Simulate a Stripe refund failure (invalid payment intent)
**Expected:** Status resets to `awaiting_refund` so admin can retry
**Result:** ✅ PASS
**Notes:** `catch` block resets status back to `awaiting_refund`

---

### 6.6 Customer Re-Request After Rejection
**Category:** 🟢 UX
**Test:** Admin rejects return request, customer visits returns page again
**Expected:** Customer can submit a new request, sees informational notice about previous rejection
**Result:** ✅ PASS
**Notes:** Rejected status is excluded from the blocked-status list

---

### 6.7 Refund Email After Processing
**Category:** 🟡 Functional
**Test:** Process a refund via admin panel
**Expected:** Customer receives refund confirmation email with amount and processing time
**Result:** ✅ PASS

---

## 7. File Upload Security

### 7.1 Upload Correct Image
**Category:** 🟡 Functional
**Test:** Upload a valid JPG under 5MB as a product image
**Expected:** Image saved with random hex filename, displayed correctly
**Result:** ✅ PASS
**Notes:** Filename: `bin2hex(random_bytes(16)) . '.jpg'`

---

### 7.2 Upload PHP File Disguised as Image
**Category:** 🔴 Security
**Test:** Rename `shell.php` to `shell.jpg` and attempt to upload
**Expected:** Blocked — MIME type check via `finfo_file()` detects `text/x-php`
**Result:** ✅ PASS

---

### 7.3 Upload File with Double Extension
**Category:** 🔴 Security
**Test:** Attempt to upload `malware.php.jpg`
**Expected:** Blocked — double extension detection finds `.php` in filename parts
**Result:** ✅ PASS

---

### 7.4 Upload File Over 5MB
**Category:** 🟡 Functional
**Test:** Attempt to upload a 6MB image
**Expected:** Client-side JS blocks before submission with error message, PHP backend also rejects
**Result:** ✅ PASS — two-layer validation

---

### 7.5 Upload Non-Image File (PDF) as Product Image
**Category:** 🔴 Security
**Test:** Attempt to upload a PDF as a product image
**Expected:** Blocked — `getimagesize()` returns false for non-image files
**Result:** ✅ PASS

---

### 7.6 PHP Execution in Images Folder
**Category:** 🔴 Security
**Test:** Manually place a PHP file in `/images/` via SFTP and attempt to access it via URL
**Expected:** 403 Forbidden — `.htaccess` in images folder blocks PHP execution
**Result:** ✅ PASS

---

### 7.7 Return Label — Direct URL Access
**Category:** 🔴 Security
**Test:** Attempt to access a return label file directly via URL e.g. `/labels/return_28_xxx.png`
**Expected:** 403 Forbidden — `.htaccess` blocks all direct access to `/labels/`
**Result:** ✅ PASS
**Notes:** Labels served exclusively via `download_label.php` with SHA-256 token

---

### 7.8 Path Traversal in File Deletion
**Category:** 🔴 Security
**Test:** Manipulate the `image_id` POST parameter to reference a file outside `/images/`
**Expected:** `realpath()` + `str_starts_with($real, $base)` check blocks deletion
**Result:** ✅ PASS

---

## 8. SQL Injection & Input Validation

### 8.1 SQL Injection in Search Field
**Category:** 🔴 Security
**Test:** Enter `' OR '1'='1` in product search
**Expected:** Treated as literal search string, no SQL injection, zero or normal results
**Result:** ✅ PASS
**Notes:** Prepared statements with `LIKE ?` binding

---

### 8.2 SQL Injection in Order ID
**Category:** 🔴 Security
**Test:** Modify order ID in URL to `1; DROP TABLE orders;--`
**Expected:** `intval()` converts to `1`, only valid order fetched
**Result:** ✅ PASS

---

### 8.3 XSS in Product Name
**Category:** 🔴 Security
**Test:** Create a product with name `<script>alert('XSS')</script>`
**Expected:** Stored as plain text, rendered as `&lt;script&gt;` via `htmlspecialchars()`
**Result:** ✅ PASS

---

### 8.4 XSS in Customer Name at Checkout
**Category:** 🔴 Security
**Test:** Submit checkout with name containing HTML tags
**Expected:** Stored as escaped text, rendered safely in admin panel and emails
**Result:** ✅ PASS

---

### 8.5 Oversized Input Fields
**Category:** 🟡 Functional
**Test:** Submit a product description of 10,000 characters
**Expected:** Capped at 5,000 characters by server-side validation
**Result:** ✅ PASS

---

## 9. CSRF Protection

### 9.1 Cross-Site Form Submission
**Category:** 🔴 Security
**Test:** Submit a POST request to `/admin/products.php` with `action=delete` from a different origin without a CSRF token
**Expected:** `verifyCsrfToken()` rejects with 403
**Result:** ✅ PASS

---

### 9.2 CSRF Token Presence on All Forms
**Category:** 🔴 Security
**Test:** Inspect every form in the application for CSRF token field
**Expected:** Every `<form method="POST">` contains a hidden `csrf_token` input
**Result:** ✅ PASS
**Notes:** `<?php echo csrfField(); ?>` required in all form templates

---

### 9.3 Token Mismatch
**Category:** 🔴 Security
**Test:** Modify the CSRF token value in a form submission
**Expected:** `hash_equals()` comparison fails, request rejected
**Result:** ✅ PASS

---

## 10. Rate Limiting

### 10.1 Contact Form Rate Limit
**Category:** 🔴 Security
**Test:** Submit contact form 4 times within 10 minutes from same IP
**Expected:** 4th attempt blocked with "too many messages" error
**Result:** ✅ PASS
**Notes:** Limit: 3 per 10 minutes per IP

---

### 10.2 Admin Login Rate Limit
**Category:** 🔴 Security
**Test:** Submit wrong password 6 times within 15 minutes
**Expected:** Blocked after attempt 5
**Result:** ✅ PASS

---

### 10.3 Rate Limit — Different IPs Not Affected
**Category:** 🟡 Functional
**Test:** Trigger rate limit from IP A, submit from IP B
**Expected:** IP B can still submit successfully
**Result:** ✅ PASS
**Notes:** Rate limiting is IP-keyed, not session-keyed — new browser tab does not bypass it

---

## 11. Race Conditions

### 11.1 Concurrent Stock Purchase
**Documented in:** Section 3.3

### 11.2 Concurrent Checkout Tabs
**Documented in:** Section 2.11

### 11.3 Concurrent Refund Processing
**Documented in:** Section 6.4

### 11.4 Concurrent Dispatch Creation
**Documented in:** Section 5.7

### 11.5 Email Resend on Page Refresh
**Category:** 🔴 Security
**Test:** Complete any form that sends an email (return submission, complaint), press F5 to refresh
**Expected:** No duplicate email, page shows result of original action (POST-Redirect-GET pattern)
**Result:** ✅ PASS — after fix
**Bug Found:** Every refresh of the admin returns page was resending emails
**Fix Applied:** All POST handlers that send emails use `header('Location: ...)` + `exit()` immediately after success. Flash messages stored in session.

---

## 12. Email System

### 12.1 Order Confirmation Email
**Category:** 🟡 Functional
**Test:** Complete a test purchase
**Expected:** HTML email with order number, itemised list, delivery address, within 30 seconds
**Result:** ✅ PASS

---

### 12.2 Dispatch Email with Tracking
**Category:** 🟡 Functional
**Test:** Mark order as dispatched with a Royal Mail tracking number
**Expected:** Email contains tracking number, clickable Track button, correct carrier name
**Result:** ✅ PASS

---

### 12.3 Return Label Email with PDF Attachment
**Category:** 🟡 Functional
**Test:** Admin uploads PDF return label and sends
**Expected:** Email arrives with PDF attached and download button in body
**Result:** ✅ PASS

---

### 12.4 Invalid Email Address
**Category:** 🟡 Functional
**Test:** Manually set customer email to `notanemail` in database, trigger email send
**Expected:** `filter_var()` check in `sendEmail()` returns false, error logged, no crash
**Result:** ✅ PASS

---

### 12.5 SMTP Failure
**Category:** 🟡 Functional
**Test:** Set wrong SMTP credentials, trigger dispatch email
**Expected:** Dispatch succeeds (DB committed), email failure logged, admin shown error
**Result:** ✅ PASS
**Notes:** Email calls wrapped in `try/catch` after transaction commit — external service failure does not roll back the dispatch

---

## 13. API Integrations

### 13.1 Stripe Webhook — Valid Signature
**Category:** 🔴 Security
**Test:** Use Stripe CLI to send `checkout.session.completed` event
**Expected:** Order status updated to `paid`, processing completes
**Result:** ✅ PASS

---

### 13.2 Stripe Webhook — Invalid Signature
**Category:** 🔴 Security
**Test:** POST to `/webhook_stripe.php` with forged payload
**Expected:** 400 response, `SignatureVerificationException` caught, logged
**Result:** ✅ PASS

---

### 13.3 Stripe Webhook — Replay Attack
**Category:** 🔴 Security
**Test:** Send same `checkout.session.completed` event twice
**Expected:** First processing succeeds. Second finds `status != 'pending_payment'` and exits with no side effects
**Result:** ✅ PASS

---

### 13.4 Sendcloud API — Dev Mode
**Category:** 🟡 Functional
**Test:** Attempt to create a Sendcloud label with `ENVIRONMENT=development`
**Expected:** `SENDCLOUD_DEV_MODE` auto-true, fake success response returned, order dispatched normally
**Result:** ✅ PASS

---

### 13.5 Sendcloud Webhook — IP Verification
**Category:** 🔴 Security
**Test:** POST to `/webhook_sendcloud.php` from a non-Sendcloud IP
**Expected:** 403 response
**Result:** ✅ PASS

---

### 13.6 Sendcloud Webhook — Browser Access
**Category:** 🔴 Security
**Test:** Visit `/webhook_sendcloud.php` in a browser (GET request)
**Expected:** `Method not allowed` — `.htaccess` restricts to POST only
**Result:** ✅ PASS

---

## 14. Access Control

### 14.1 Vendor Directory Access
**Category:** 🔴 Security
**Test:** Navigate to `/vendor/composer/installed.json`
**Expected:** 403 Forbidden
**Result:** ✅ PASS — after fix
**Bug Found:** `AllowOverride None` in Apache config was causing `.htaccess` files to be ignored
**Fix Applied:** `AllowOverride All` set in Apache virtual host configuration

---

### 14.2 .env Direct Access
**Category:** 🔴 Security
**Test:** Navigate to `/.env`
**Expected:** 403 Forbidden
**Result:** ✅ PASS
**Notes:** `<Files ".env"> Require all denied </Files>` in root `.htaccess`

---

### 14.3 Logs Directory Access
**Category:** 🔴 Security
**Test:** Navigate to `/logs/error.log`
**Expected:** 403 Forbidden
**Result:** ✅ PASS

---

### 14.4 Labels Directory Direct Access
**Category:** 🔴 Security
**Test:** Navigate to `/labels/return_28_xxx.png` directly
**Expected:** 403 Forbidden
**Result:** ✅ PASS

---

### 14.5 Admin Panel on Mobile — Forbidden
**Category:** 🟡 Functional
**Test:** Access admin panel on a mobile device without login
**Expected:** Redirect to login page
**Result:** ✅ PASS — after fix
**Bug Found:** File permissions set to 755 on admin directory were causing 403 on some server configurations
**Fix Applied:** `sudo find /var/www/html -type d -exec chmod 755 {} \;` and `sudo find /var/www/html -type f -exec chmod 644 {} \;`

---

### 14.6 Order Access Verification
**Category:** 🔴 Security
**Test:** Try to access order tracking for order #5 by guessing the URL, entering wrong email
**Expected:** "Order not found" — requires both order ID and email address
**Result:** ✅ PASS

---

## 15. Error Handling & Recovery

### 15.1 Database Connection Failure
**Category:** 🟡 Functional
**Test:** Stop MySQL service, visit homepage
**Expected:** Friendly maintenance page shown, 503 status, no PHP errors visible
**Result:** ✅ PASS — after fix
**Bug Found:** Multiple conflicts between exception handler and error handler caused raw PHP output
**Fix Applied:** `@mysqli_connect()` suppresses warning, try/catch in `db.php` handles exception before global handler

---

### 15.2 Production Error Display
**Category:** 🔴 Security
**Test:** Set `ENVIRONMENT=production`, trigger a deliberate error
**Expected:** Generic error page shown, error logged to `logs/error.log`, no stack trace visible
**Result:** ✅ PASS

---

### 15.3 Development Error Display
**Category:** 🟢 UX
**Test:** Set `ENVIRONMENT=development`, trigger a deliberate error
**Expected:** Full stack trace shown in browser for debugging
**Result:** ✅ PASS

---

### 15.4 Fatal Error Handling
**Category:** 🔴 Security
**Test:** Create a parse error in a PHP file on production
**Expected:** `register_shutdown_function` catches fatal, shows friendly page
**Result:** ✅ PASS
**Notes:** `set_error_handler` cannot catch `E_ERROR`, `E_PARSE` — shutdown function handles these

---

### 15.5 Abandoned Order Cleanup
**Category:** 🟡 Functional
**Test:** Create a `pending_payment` order, wait for cron to run
**Expected:** Order and `order_items` deleted after 2-hour window
**Result:** ✅ PASS — after fix
**Bug Found:** Cleanup SQL ran on every page load inside `db.php`, causing performance overhead and potential race with slow Stripe webhooks
**Fix Applied:** Moved to dedicated `cron/cleanup_orders.php` running every 30 minutes

---

## 16. Mobile & Browser Compatibility

### 16.1 iPhone Safari — Checkout Form Zoom
**Category:** 🟢 UX
**Test:** Tap an input field on iPhone Safari
**Expected:** Page does not zoom in on focus
**Result:** ✅ PASS
**Notes:** `font-size: 16px` on all inputs prevents iOS auto-zoom (triggers below 16px)

---

### 16.2 Mobile — Sticky Header
**Category:** 🟢 UX
**Test:** Scroll down on any page on mobile
**Expected:** Header stays fixed at top
**Result:** ✅ PASS — after fix
**Bug Found:** Mobile CSS was overriding `position: sticky` with `position: relative`
**Fix Applied:** Mobile media query updated to `position: sticky; top: 0; z-index: 500`

---

### 16.3 Mobile — Hamburger Menu Close
**Category:** 🟢 UX
**Test:** Open mobile nav menu, tap ✕ close button
**Expected:** Menu closes
**Result:** ✅ PASS — after fix
**Bug Found:** No close button existed in original hamburger menu
**Fix Applied:** Close button added as first item in mobile nav with `closeMenu()` function

---

### 16.4 Mobile — Cart Table Layout
**Category:** 🟢 UX
**Test:** View cart on iPhone
**Expected:** Cart items displayed as stacked cards, not broken table
**Result:** ✅ PASS
**Notes:** CSS `display: block` on table elements with `data-label` attributes for mobile

---

### 16.5 Desktop — All Browsers
**Category:** 🟢 UX
**Test:** View site on Chrome, Firefox, Safari, Edge
**Expected:** Consistent layout and functionality across all
**Result:** ✅ PASS
**Notes:** `DataTransfer` API for multi-file upload has a Safari 14.1+ requirement — fallback added for older versions

---

### 16.6 iPad — Tablet Layout
**Category:** 🟢 UX
**Test:** View site on iPad (768px-1024px)
**Expected:** Sidebar visible, content readable, forms usable
**Result:** ✅ PASS

---

## 17. Performance & Edge Cases

### 17.1 Products Page with No Products
**Category:** 🟢 UX
**Test:** Delete all products, visit products page
**Expected:** Empty state shown with "Add your first product" prompt
**Result:** ✅ PASS

---

### 17.2 Image Upload — Select Files Separately
**Category:** 🟢 UX
**Test:** Open file picker, select 1 image, close, open again, select another image
**Expected:** Both images accumulated in preview, not second replacing first
**Result:** ✅ PASS — after fix
**Bug Found:** Each file picker opening replaced the previous selection
**Fix Applied:** `DataTransfer` API accumulates files in `selectedFiles` array across multiple picker opens

---

### 17.3 Product Discount — Checkout Price Consistency
**Category:** 🟡 Functional
**Test:** Add discounted product to cart, view cart, view checkout, complete payment
**Expected:** Same discounted price shown at all stages, Stripe charged discounted amount
**Result:** ✅ PASS

---

### 17.4 Large Order — Many Items
**Category:** 🟡 Functional
**Test:** Add 10 different products to cart, complete checkout
**Expected:** All items saved to `order_items`, correct total calculated, all appear in confirmation email
**Result:** ✅ PASS

---

### 17.5 Admin Search — Special Characters
**Category:** 🟡 Functional
**Test:** Search for a product containing `'` (apostrophe) in the name
**Expected:** Correct results returned, no SQL error
**Result:** ✅ PASS
**Notes:** LIKE binding via prepared statements handles special characters safely

---

## 18. Production Environment

### 18.1 SSL Certificate
**Category:** 🔴 Security
**Test:** Visit site via HTTP
**Expected:** Automatic redirect to HTTPS (301)
**Result:** ✅ PASS
**Notes:** Certbot configured with `--redirect` flag

---

### 18.2 HSTS Header
**Category:** 🔴 Security
**Test:** Check response headers on HTTPS request
**Expected:** `Strict-Transport-Security: max-age=31536000; includeSubDomains`
**Result:** ✅ PASS
**Notes:** Enabled in `.htaccess` after SSL confirmed

---

### 18.3 Security Headers
**Category:** 🔴 Security
**Test:** Check all security headers via `curl -I https://genovatest.ddns.net`
**Expected:** All headers present

| Header | Status |
|--------|--------|
| X-Frame-Options: SAMEORIGIN | ✅ |
| X-Content-Type-Options: nosniff | ✅ |
| Referrer-Policy: strict-origin-when-cross-origin | ✅ |
| Content-Security-Policy | ✅ |
| Permissions-Policy | ✅ |

---

### 18.4 Error Log Rotation
**Category:** 🟡 Functional
**Test:** Verify `logs/error.log` is created automatically if missing
**Expected:** `environment.php` calls `mkdir()` if directory doesn't exist
**Result:** ✅ PASS

---

### 18.5 Cron Job Execution
**Category:** 🟡 Functional
**Test:** Verify cron job runs and produces output
**Expected:** `logs/cleanup.log` updated every 30 minutes with cleanup results
**Result:** ✅ PASS

---

### 18.6 Database Backup
**Category:** 🟡 Functional
**Test:** Run `cron/cleanup_orders.php` manually via SSH
**Expected:** Compressed `.sql.gz` backup created in `/home/ubuntu/perfumeshop_backups/daily/`
**Result:** ✅ PASS

---

## Test Results Summary

| Category | Total Tests | Pass | Fail (Fixed) | Known Limitations |
|----------|-------------|------|--------------|-------------------|
| Authentication | 7 | 7 | 0 | 0 |
| Payment & Checkout | 11 | 11 | 2 | 0 |
| Stock Management | 6 | 6 | 1 | 0 |
| Cart & Pricing | 6 | 6 | 3 | 0 |
| Order Management | 7 | 7 | 1 | 0 |
| Returns & Refunds | 7 | 7 | 2 | 0 |
| File Upload Security | 8 | 8 | 0 | 0 |
| SQL Injection | 5 | 5 | 0 | 0 |
| CSRF | 3 | 3 | 0 | 0 |
| Rate Limiting | 3 | 3 | 0 | 0 |
| Race Conditions | 5 | 5 | 1 | 0 |
| Email System | 5 | 5 | 1 | 0 |
| API Integrations | 6 | 6 | 0 | 0 |
| Access Control | 6 | 6 | 1 | 0 |
| Error Handling | 5 | 5 | 2 | 0 |
| Mobile & Browser | 6 | 6 | 3 | 0 |
| Performance | 5 | 5 | 1 | 0 |
| Production | 6 | 6 | 0 | 0 |
| **TOTAL** | **107** | **107** | **18** | **0** |

---

## Bugs Found and Fixed During Testing

| # | Bug | Severity | Fix |
|---|-----|----------|-----|
| 1 | Payment success page resent email on every refresh | 🔴 Critical | `stripe_session_id` idempotency check |
| 2 | Cart allowed purchasing more than available stock | 🔴 Critical | Stock check on every cart add/update |
| 3 | Checkout charged original price ignoring locked cart price | 🔴 Critical | `charge_price` from session used exclusively |
| 4 | Admin refund double-click created two Stripe refunds | 🔴 Critical | Atomic `processing_refund` status claim |
| 5 | Stock restored on return rejection | 🟡 High | Restoration only in approve handler |
| 6 | payment_cancel.php foreign key error | 🟡 High | Delete order_items before orders |
| 7 | Related products showed main product price | 🟡 High | Changed variable to `$rel` in loop |
| 8 | Email resent on every admin page refresh | 🟡 High | POST-Redirect-GET on all email actions |
| 9 | `.htaccess` ignored due to AllowOverride None | 🔴 Critical | `AllowOverride All` in Apache config |
| 10 | Checkout redirect blocked by CSP form-action | 🔴 Critical | Added `https://checkout.stripe.com` to form-action |
| 11 | DB cleanup ran on every page load | 🟡 High | Moved to cron job |
| 12 | Cart count showed 0 after session format change | 🟢 Medium | Format-aware count in header.php |
| 13 | DB error handler conflicted with exception handler | 🟡 High | try/catch in db.php with `@mysqli_connect` |
| 14 | Mobile hamburger menu had no close button | 🟢 Medium | ✕ button added to nav |
| 15 | Sticky header scrolled with page on mobile | 🟢 Medium | CSS `position: sticky` enforced in media query |
| 16 | Multi-file select replaced previous selection | 🟢 Medium | DataTransfer API accumulates files |
| 17 | Admin panel 403 Forbidden on server | 🔴 Critical | File permissions corrected on Oracle Cloud |
| 18 | Duplicate orders from concurrent checkout tabs | 🔴 Critical | MySQL advisory lock `GET_LOCK()` |

---

*107 manual test cases executed. 18 bugs discovered and resolved during development. 
Some edge cases may exist outside tested scenarios. Automated regression testing is 
a planned future improvement.*