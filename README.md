# Genova Perfumes — Full-Stack E-Commerce Platform

> A production-oriented PHP e-commerce platform built entirely from scratch — no frameworks, no shortcuts. Handles the full commercial lifecycle from product discovery through payment, fulfilment, and post-sale operations.

**Live Demo:** Currently unavailable (Oracle Cloud Free Tier expired). The complete source code, documentation, screenshots, and architecture are available in this repository.
&nbsp;·&nbsp;
**Built by:** [Sunnah25](https://github.com/Sunnah25)

> ⚠️ **Portfolio Project** — This is a fully functional e-commerce system built for demonstration purposes. Stripe is in test mode. No orders will be fulfilled and no cards will be charged.

---

## Table of Contents

- [Overview](#overview)
- [System Architecture](#system-architecture)
- [Tech Stack](#tech-stack)
- [Feature Set](#feature-set)
- [Security Implementation](#security-implementation)
- [Database Schema](#database-schema)
- [Key Engineering Decisions](#key-engineering-decisions)
- [Challenges & Solutions](#challenges--solutions)
- [API Integrations](#api-integrations)
- [Deployment](#deployment)
- [Local Setup](#local-setup)
- [Lessons Learned](#lessons-learned)

---

## Overview

Genova Perfumes is a complete B2C e-commerce platform for a UK fragrance retailer. The project covers every layer of a real online shop — customer-facing storefront, secure checkout, order management, dispatch workflow, returns and refunds, complaint handling, and an admin control panel.

The architecture is deliberately framework-free. Every component — routing, session management, CSRF protection, rate limiting, database access — is implemented from first principles using PHP 8.2 and MySQLi. This was a conscious engineering choice to demonstrate deep understanding of web fundamentals rather than reliance on abstraction layers.

The system processes a complete order lifecycle:

```
Browse → Cart → Checkout → Stripe Payment → Order Confirmation
  → Admin Dispatch → Tracking → Delivery → Returns/Refunds
```

---

## System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                        Customer Layer                       │
│  index  products  product  cart  checkout  track  returns   │
└──────────────────────┬──────────────────────────────────────┘
                       │ HTTP/HTTPS
┌──────────────────────▼──────────────────────────────────────┐
│                    Apache Web Server                        │
│              .htaccess  ·  mod_rewrite  ·  SSL              │
└──────────────────────┬──────────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────────┐
│                    PHP 8.2 Application                      │
│                                                             │
│  ┌─────────────┐  ┌──────────────┐  ┌────────────────────┐  │
│  │   includes/  │  │   admin/     │  │   webhooks/        │ │
│  │  db.php      │  │  orders      │  │  webhook_stripe    │ │
│  │  mailer.php  │  │  products    │  │  webhook_sendcloud │ │
│  │  csrf.php    │  │  returns     │  │                    │ │
│  │  sendcloud   │  │  dashboard   │  │                    │ │
│  └──────┬───────┘  └──────┬───────┘  └─────────┬──────────┘ │
└─────────┼────────────────┼───────────────────┼─────────────┘
          │                │                   │
┌─────────▼────────────────▼───────────────────▼─────────────┐
│                      MySQL 8.0 Database                    │
│  orders  order_items  products  returns  complaints        │
│  shipments  order_tracking  webhook_logs  payment_logs     │
└────────────────────────────────────────────────────────────┘
          │                │                   │
┌─────────▼──────┐  ┌──────▼──────┐  ┌────────▼───────────┐
│  Stripe API    │  │ Sendcloud   │  │  PHPMailer/SMTP    │
│  Payments      │  │  Shipping   │  │  Transactional     │
│  Refunds       │  │  Labels     │  │  Email             │
└────────────────┘  └─────────────┘  └────────────────────┘
```

### Request Lifecycle

Every request flows through `includes/environment.php` (loaded via `includes/db.php`) which handles:
- Environment detection (development vs production)
- Error handler registration
- Session security configuration
- Security header injection

CSRF tokens are validated on every state-changing POST request. All user input passes through prepared statements before touching the database.

---

## Tech Stack

| Layer | Technology | Reason |
|-------|-----------|--------|
| Language | PHP 8.2 | Production-standard, `match()`, named args, fibers |
| Database | MySQL 8.0 | ACID compliance, foreign keys, `FOR UPDATE` locking |
| Web Server | Apache 2.4 | `.htaccess` flexibility, `mod_rewrite` for clean routing |
| Payments | Stripe Checkout | PCI-compliant, webhook-verified, idempotent |
| Shipping | Sendcloud API v3 | Royal Mail + Evri label generation |
| Email | PHPMailer + SMTP | Reliable transactional delivery |
| Hosting | Oracle Cloud Free Tier | ARM VM, 6GB RAM, Ubuntu 22.04 |
| SSL | Let's Encrypt | Auto-renewing, zero cost |
| Frontend | Vanilla JS + CSS Grid/Flexbox | No build tooling required |

---

## Feature Set

### Storefront
- Product catalogue with category filtering, price range slider, stock filtering and real-time JavaScript search
- Product detail pages with multi-image gallery and thumbnail switching
- Dynamic discount/sale pricing with strikethrough original prices
- Cart with session-persisted locked prices (price at time of add, immune to admin changes mid-session)
- Responsive design supporting iPhone, iPad and desktop

### Checkout & Payments
- Stripe Checkout integration with idempotent session handling
- Duplicate-order prevention using `GET_LOCK()` mutex for concurrent tab protection
- Stock atomically reduced on payment confirmation (`WHERE stock >= qty`)
- Payment intent ID stored at checkout for instant refund processing
- Stripe webhook verification via HMAC-SHA256 signature

### Order Management (Admin)
- Four-tab order dashboard: Awaiting Dispatch · Dispatched · Delivered · All Orders
- Persistent search across all tabs
- Sendcloud API v3 label generation with `shipping_option_code`
- Manual label fallback with carrier detection
- Update Shipments dashboard with visual urgency indicators (green/amber/red by days since dispatch)
- Bulk status updates with checkbox selection

### Returns & Cancellations
- Three distinct workflows:
  - **Pre-dispatch cancellation** → full refund, no label needed
  - **Post-dispatch cancellation** → admin-configurable handling fee, partial refund
  - **Post-delivery return** → prepaid label via Sendcloud or manual upload, refund or replacement
- Stripe refund via stored `payment_intent_id` (no session enumeration)
- Atomic `processing_refund` status prevents double-click race condition
- Stock automatically restored on approved refunds

### Admin Panel
- bcrypt password authentication with session regeneration on login
- 2-hour inactivity timeout with IP logging
- CSRF protection on all state-changing actions
- Rate-limited login (5 attempts per 15 minutes per IP)
- Responsive mobile sidebar with hamburger navigation

### Email System (PHPMailer)
Triggered emails for every customer-facing lifecycle event:
- Order confirmation with itemised receipt
- Dispatch notification with tracking number
- Delivery confirmation
- Return request acknowledgement
- Return label with PDF attachment
- Refund confirmation
- Complaint response

---

## Security Implementation

Security was treated as a first-class concern throughout, not bolted on at the end.

### Input & Query Security
- **Prepared statements** throughout — Uses MySQLi with input validation and query parameterisation where appropriate
- **MySQLi transactions** with explicit rollback on failure for all multi-step DB operations
- **File upload validation** — four independent layers: extension whitelist, MIME type via `finfo_file()`, double-extension detection, `getimagesize()` real image verification
- Cryptographically random filenames via `bin2hex(random_bytes(16))` prevent enumeration

### Session Security
- `session_regenerate_id(true)` on admin login prevents session fixation
- `cookie_httponly`, `cookie_samesite=Strict`, `use_strict_mode` enforced
- `cookie_secure` activated automatically when HTTPS is detected (not environment flag)
- 1-hour `gc_maxlifetime` prevents indefinitely valid sessions

### CSRF & Rate Limiting
- All POST forms include cryptographically generated CSRF tokens
- Token stored in session, verified before any state change
- Rate limiting on contact form (3/10min), admin login (5/15min) — IP-based not session-based

### Infrastructure
- `.env` blocked by `.htaccess` (`Require all denied`)
- `vendor/`, `labels/`, `logs/`, `includes/` all blocked from web access
- Webhook endpoints restricted to POST only via `.htaccess `
- Stripe webhook HMAC-SHA256 signature verification
- Security headers: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `Content-Security-Policy`
- Error display disabled in production; all errors logged to protected file

### Race Condition Protection
| Scenario | Solution |
|----------|----------|
| Two browsers buying last item | Atomic `UPDATE ... WHERE stock >= qty` |
| Multiple tabs submitting checkout | `GET_LOCK()` mutex per session |
| Admin double-clicking refund | Atomic `processing_refund` status claim |
| Admin double-dispatching | `SELECT ... FOR UPDATE` inside transaction |
| Duplicate payment webhook | Idempotent `WHERE status = 'pending_payment'` |

---

## Database Schema

Core tables and their relationships:

```
orders
  id, customer_name, customer_email, customer_phone
  customer_address, total_amount, status
  stripe_session_id, payment_intent_id, session_id
  dispatched_at, shipped_at, delivered_at, cancelled_at
  tracking_number, tracking_url, shipping_method

order_items
  id, order_id → orders.id, product_id → products.id
  quantity, price (locked at checkout time)

products
  id, name, description, price, discount_percent
  stock, featured, image
  weight_grams, length_cm, width_cm, height_cm
  category_id

product_images
  id, product_id, image, is_primary, sort_order

returns
  id, order_id, type, status, reason
  resolution_choice, refund_amount, refund_id
  return_label_url, return_label_file, return_label_type
  return_tracking, item_received, item_received_at
  resolved_at, admin_notes

complaints
  id, order_id, customer_email, type
  description, status, admin_response, resolved_at

shipments
  id, order_id, sendcloud_shipment_id
  carrier, service_name, tracking_number, tracking_url
  sendcloud_label_url, label_method, status
  parcel_weight, parcel_length, parcel_width, parcel_height

order_tracking
  id, order_id, tracking_number, carrier
  service_name, label_type (original/replacement)

payment_logs
  id, order_id, event_type, status, amount
  stripe_id, ip_address, notes

webhook_logs
  id, source, event_type, payload, processed, error_msg
```

---

## Key Engineering Decisions

### 1. No Framework
**Decision:** Build without Laravel, Symfony, or any MVC framework.

**Rationale:** Frameworks abstract away the foundations. Understanding what happens at the raw PHP level — how sessions work, how prepared statements prevent injection, how CSRF tokens flow through a request — requires building it yourself. Every abstraction in this project is mine to maintain, which meant deeply understanding each component.

**Trade-off:** More code to write and maintain. The benefit: complete transparency into every security boundary.

### 2. Locked Cart Prices
**Decision:** Store the sale price at the time of cart addition, not recalculate from the database at checkout.

**Rationale:** Admin could change a price or remove a discount between a customer adding an item and completing payment. Recalculating at checkout would charge a different amount than what was shown. The cart session stores `['qty' => x, 'price' => y]` and the Stripe line item uses `charge_price` from the session, not from the database.

### 3. payment_intent_id Storage
**Decision:** Store the Stripe `payment_intent_id` at payment time rather than fetching sessions to find it at refund time.

**Rationale:** The naive approach fetches up to 100 Stripe sessions to find the right payment intent. With `payment_intent_id` stored directly on the order at `payment_success.php`, a refund is a single API call: `Stripe\Refund::create(['payment_intent' => $id])`.

### 4. Manual Tracking Workflow
**Decision:** Use a manual "Update Shipments" dashboard rather than polling carrier APIs.

**Rationale:** Royal Mail and Evri consumer APIs require business accounts and paid plans. AfterShip webhooks require $119/month. For a sub-100-order-per-month shop, a well-designed admin dashboard that takes 2 minutes daily is more economical and reliable than a paid API integration. The dashboard sorts by oldest dispatch first with colour-coded urgency, making the daily task efficient.

### 5. Idempotent Webhook Processing
**Decision:** All webhook handlers check current state before processing.

**Rationale:** Webhooks can arrive multiple times (Stripe retries on non-2xx responses). The `stripe_session_id` unique-per-order check means the second delivery of the same event finds `status != 'pending_payment'` and exits cleanly without re-processing.

---

## Challenges & Solutions

### Challenge 1: Stripe CSP Blocking the Redirect
After implementing Content Security Policy headers, the Pay Now button would show "Redirecting..." but never open Stripe. The browser was blocking the form submission to an external domain.

**Root cause:** `form-action 'self'` in the CSP header blocked the server-side `header('Location: https://checkout.stripe.com/...')` redirect from being followed.

**Solution:** Added `https://checkout.stripe.com` to the `form-action` directive. This is the correct approach — `form-action` controls where form data can be submitted, and Stripe Checkout requires the browser to navigate to their domain.

**Lesson:** CSP is extremely sensitive. Every external domain your app touches must be explicitly whitelisted in the correct directive.

---

### Challenge 2: Sendcloud API v2 vs v3 Inconsistencies
The Sendcloud documentation described using their v2 parcels API to create shipments, but the endpoint was returning 403. Weight values from the shipping methods endpoint were arriving in kilograms despite documentation suggesting grams.

**Root cause:** Sendcloud migrated label creation to their v3 API. v3 requires a `shipping_option_code` obtained from a separate `fetch-shipping-options` POST endpoint rather than a shipping method ID. v2 remained for other endpoints but silently rejected parcel creation.

**Solution:** Built a two-step flow — first POST to `fetch-shipping-options` with parcel dimensions to get the `shipping_option_code`, then POST to v3 `shipments` with `ship_with.type = 'shipping_option_code'`. Added a `SENDCLOUD_DEV_MODE` flag (auto-detected from `ENVIRONMENT`) for local testing without a connected Sendcloud account.

**Lesson:** Third-party API documentation frequently lags behind actual behaviour. When a documented endpoint returns unexpected errors, check for API versioning issues before debugging application code.

---

### Challenge 3: Cart Session Format Migration
Mid-development, the cart session structure changed from a flat integer (`$_SESSION['cart'][$id] = 3`) to an array (`$_SESSION['cart'][$id] = ['qty' => 3, 'price' => 29.99]`) to support locked prices. Existing sessions in development contained the old format.

**Root cause:** `intval(['qty' => 2, 'price' => 29.99])` returns `1` in PHP, not `2`. Checkout was silently getting wrong quantities from old-format sessions.

**Solution:** Added format detection throughout the cart loading code — any handler that reads from `$_SESSION['cart']` checks `is_array($entry)` first and handles both formats. The legacy format rebuilds itself with the current database price on first access, ensuring a smooth migration path.

**Lesson:** When changing session data structures in a running application, you must handle both old and new formats simultaneously rather than assuming all sessions are fresh.

---

### Challenge 4: Race Condition on Checkout
A customer with slow internet connection who double-clicked Pay Now could create two separate orders before either completed, resulting in two Stripe sessions for the same basket.

**Root cause:** The check-then-insert sequence (`SELECT existing order → if none, INSERT`) is not atomic. Two concurrent requests can both reach the SELECT before either INSERT completes, both finding no existing order and both creating new ones.

**Solution:** Wrapped the critical section in a MySQL advisory lock: `GET_LOCK('checkout_{session_id}', 3)`. Only one PHP process can hold the lock at a time. The second request waits, then finds the existing pending order and reuses it rather than creating a duplicate. Lock is released after the order ID is obtained.

**Lesson:** Optimistic locking (`IF NOT EXISTS`) is not sufficient for concurrent writes. Advisory locks or `INSERT ... ON DUPLICATE KEY` are required for true atomicity without a unique constraint.

---

### Challenge 5: Oracle Cloud Firewall Layers
After deploying to Oracle Cloud, the site was unreachable despite Apache running correctly. The standard Ubuntu `ufw` commands had no effect.

**Root cause:** Oracle Cloud uses two independent firewall layers — the Oracle Virtual Cloud Network Security List (cloud-level) and the OS-level `iptables`. Ubuntu's `ufw` is a frontend for `iptables`, but Oracle's default `iptables` rules take precedence and must be modified directly. Additionally, `ufw` was inactive by default on the Oracle image.

**Solution:** Added ingress rules directly to `iptables` (`iptables -I INPUT 1 -p tcp --dport 80 -j ACCEPT`) and saved with `netfilter-persistent`. Also added port 80 and 443 ingress rules in the Oracle Cloud Console Security List. Both layers must be open simultaneously.

**Lesson:** Cloud providers often add their own networking layer independent of OS-level firewalls. Always verify reachability at both layers.

---

## API Integrations

### Stripe
- **Checkout Session** — creates hosted payment page with line items from cart
- **Webhook** — `checkout.session.completed` with HMAC-SHA256 signature verification
- **Refund API** — instant partial or full refund via stored `payment_intent_id`
- **Idempotency keys** — prevent duplicate sessions on form resubmission

### Sendcloud
- **v2 `shipping_methods`** — fetch available services for a given weight
- **v3 `fetch-shipping-options`** — get `shipping_option_code` for specific parcel dimensions
- **v3 `shipments`** — create label with `ship_with.type = 'shipping_option_code'`
- **Webhook** — parcel status updates (signed, IP-verified)
- **Dev mode** — automatic fake success response when `ENVIRONMENT != production`

### PHPMailer
- SMTP with STARTTLS
- HTML + plain text multipart
- PDF label attachments for return emails
- Centralised `sendEmail()` and `sendEmailWithAttachment()` to avoid SMTP config duplication

---

## Deployment

**Platform:** Oracle Cloud Always Free — ARM VM.Standard.A1.Flex (1 OCPU, 6GB RAM)
**OS:** Ubuntu 22.04 LTS
**Stack:** Apache 2.4 · PHP 8.2 · MySQL 8.0
**SSL:** Let's Encrypt via Certbot (auto-renewing)
**Domain:** DDNS via No-IP

### Infrastructure Notes
- `.env` stored outside version control, permissions `640`
- `logs/` directory permissions `750` — world-unreadable
- Cron job runs `cron/cleanup_orders.php` every 30 minutes (abandoned order GC moved out of `db.php` bootstrap)
- Daily database backup via `mysqldump` with 30-day rotation
- Security headers set in `.htaccess` — `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `CSP`, `Permissions-Policy`
- HSTS enabled post-SSL-verification

---

## Local Setup

### Requirements
- XAMPP (PHP 8.2, MySQL 8.0, Apache 2.4)
- Composer

### Steps

```bash
# 1. Clone the repository
git clone https://github.com/Sunnah25/fullstack-ecommerce-web.git
cd fullstack-ecommerce-web

# 2. Install PHP dependencies
composer install

# 3. Create environment file
cp .env.example .env
# Edit .env with your local credentials

# 4. Import database
mysql -u root perfumeshop < database/schema.sql
mysql -u root perfumeshop < database/seed.sql

# 5. Configure virtual host in XAMPP
# Add to httpd-vhosts.conf:
# <VirtualHost *:80>
#   DocumentRoot "C:/xampp/htdocs/genova-perfumes"
#   ServerName perfumeshop.local
# </VirtualHost>

# 6. Add to hosts file
# 127.0.0.1 perfumeshop.local

# 7. Visit http://perfumeshop.local
```

### Environment Variables

```ini
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=perfumeshop

STRIPE_PUBLIC_KEY=pk_test_...
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...

SHOP_URL=http://perfumeshop.local
SHOP_NAME=Genova Perfumes
SHOP_EMAIL=hello@genovaperfumes.com
SHOP_CURRENCY=gbp

SENDCLOUD_PUBLIC_KEY=
SENDCLOUD_SECRET_KEY=
SENDCLOUD_SENDER_ID=

MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=

ENVIRONMENT=development
```

---

## Lessons Learned

**Frameworks exist for good reasons.** Building routing, CSRF protection, rate limiting, and session handling from scratch is educational, but in a production team context the time cost is rarely justified. The value here was in understanding what frameworks do, not in avoiding them permanently.

**Security cannot be retrofitted.** Every security feature in this project that was added after initial implementation — CSRF tokens, prepared statements, idempotency checks — created more work than if it had been designed in from the start. The `checkout.php` race condition fix required changes to the database schema, the PHP handler, and the JavaScript layer simultaneously.

**API documentation is aspirational.** Sendcloud's v2/v3 migration, the Stripe CSP requirement, and Oracle Cloud's dual-firewall architecture were all discovered through debugging rather than documentation. Building in a generous debugging mode (`SENDCLOUD_DEV_MODE`, detailed error logging) significantly shortened these debugging cycles.

**Session state is hard.** The cart format migration mid-project created subtle bugs that only appeared under specific conditions — existing sessions with the old format. Any time persistent state changes structure, forward compatibility must be explicitly coded, not assumed.

**Operational UX matters as much as customer UX.** The dispatch dashboard — sorting by days since dispatch with colour-coded urgency — was designed after thinking carefully about the admin's daily workflow. A technically correct system that is operationally painful will be used incorrectly. The two-minute daily tracking check is only achievable because the UI surfaces the right information in the right order.

---

## Project Stats

| Metric | Value |
|--------|-------|
| PHP files | 40+ |
| Lines of PHP | ~6,000 |
| Lines of CSS | ~3,500 |
| Database tables | 14 |
| Email templates | 10 |
| API integrations | 3 (Stripe, Sendcloud, PHPMailer) |
| Build time | ~3 months |

---

## Author

Built by **[Sunnah25](https://github.com/Sunnah25)** as a full-stack portfolio project demonstrating production-grade PHP engineering, security hardening, third-party API integration, and cloud deployment.

---

*This project is intentionally framework-free. All architectural decisions were made to demonstrate understanding of web fundamentals at the implementation level.*
