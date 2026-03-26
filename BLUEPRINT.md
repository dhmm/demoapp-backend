# DemoShop Backend — Functional Specification

**Technology**: Laravel 11 (PHP 8.2+)
**Architecture**: REST API, JWT authentication, Celery-style queue (Laravel Horizon)
**Purpose**: E-commerce backend demonstrating scenario generation for ASUP automation tool

---

## 1. Project Overview

DemoShop Backend is a Laravel REST API that powers a full-featured e-commerce platform. It exposes a versioned JSON API consumed by the Symfony frontend and any third-party integrations. The API supports product catalogue management, customer authentication, shopping cart, order fulfilment, and administrative operations.

### Key Design Goals
- Comprehensive API surface for maximum scenario generation coverage
- Real-world business logic representative of production e-commerce systems
- Role-based access control (customer vs. admin)
- Webhook-based integration with payment and logistics providers

---

## 2. Functional Domains

### 2.1 Authentication & Identity

| Feature | Description |
|---------|-------------|
| Registration | Create account with name, email, password; triggers email verification |
| Login | JWT token issuance; detects 2FA requirement |
| Token Refresh | Rotate JWT without re-authentication |
| Logout | Invalidate token (server-side blacklist) |
| Forgot Password | Send reset link to registered email |
| Reset Password | Set new password via signed token |
| Change Password | Authenticated password update; rotates token |
| Email Verification | Time-limited signed token; resend endpoint |
| Two-Factor Auth | TOTP via authenticator app; recovery codes |
| Social Login | Google and Facebook OAuth token exchange |

**Acceptance Criteria (auth):**
- Registration with duplicate email returns 422
- Login with wrong password returns 401
- Accessing protected route without token returns 401 with JSON body
- Banned users receive 403 on all protected routes
- Unverified users receive 403 until email confirmed

---

### 2.2 Product Catalogue

| Feature | Description |
|---------|-------------|
| Browse Products | Paginated, filterable, sortable product list |
| Product Detail | Full product data with images, reviews, variants |
| Search | Full-text search across name, description, brand, SKU, tags |
| Featured Products | Curated homepage spotlight (admin-flagged) |
| Bestsellers | Ranked by all-time sold_count |
| New Arrivals | Products created within the last 30 days |
| On Sale | Products with active sale_price < price |
| Related Products | Same-category recommendations |
| Create Product | Admin: slug auto-generation, image upload |
| Update Product | Admin: partial updates supported |
| Delete Product | Admin: soft delete; restores inventory |
| Publish / Unpublish | Admin: draft ↔ published status toggle |
| Image Management | Admin: multi-image upload, primary designation, delete |
| Bulk Import | Admin: CSV or JSON file, upsert by SKU |
| Bulk Delete | Admin: delete multiple products by ID array |
| Export | Admin: streamed CSV download |

**Filters (public):** category_id, brand, min_price, max_price, in_stock, rating_min, partial name
**Sorts (public):** price, -price, newest, rating, sold_count

---

### 2.3 Categories

| Feature | Description |
|---------|-------------|
| List Categories | Flat list with product counts |
| Category Tree | Hierarchical nested structure |
| Category Detail | Name, description, parent |
| Products in Category | Paginated products scoped to category |
| Subcategories | Children of a given category |
| Create / Update / Delete | Admin CRUD |
| Reorder | Admin: bulk sort_order update |

---

### 2.4 Shopping Cart

| Feature | Description |
|---------|-------------|
| View Cart | Current items, quantities, line totals, subtotal |
| Add Item | Add product (with variant); merge if already present |
| Update Item | Change quantity on a cart item |
| Remove Item | Delete a single line item |
| Clear Cart | Remove all items |
| Apply Coupon | Validate and store discount code |
| Remove Coupon | Detach coupon from session |
| Cart Summary | Subtotal, shipping estimate, discount, tax, grand total |

---

### 2.5 Orders

| Feature | Description |
|---------|-------------|
| Place Order | Convert cart → order; create Stripe payment intent |
| Order History | Customer's paginated order list |
| Order Detail | Full order with items, addresses, payment, shipment |
| Cancel Order | Customer request; restores stock; auto-refunds if paid |
| Return Request | Per-item return; reason required; creates ReturnRequest record |
| Download Invoice | PDF generation; streamed response |
| Shipment Tracking | Live tracking events from logistics provider |
| Admin: List Orders | Filtering by status, user, date range, payment status |
| Admin: Order Detail | Full admin view including internal notes |
| Admin: Update Status | State machine: pending → confirmed → processing → shipped → delivered |
| Admin: Assign Fulfilment | Link to warehouse or fulfilment partner |
| Admin: Process Refund | Full or partial refund via Stripe; updates payment_status |
| Admin: Bulk Export | CSV download with optional filters |

**Order Status States:** pending → confirmed → processing → shipped → delivered | cancelled | refunded
**Payment Status States:** pending → paid | failed | refunded | partial_refund

---

### 2.6 Payments

| Feature | Description |
|---------|-------------|
| Checkout | Initiate payment flow; returns client secret |
| Stripe Payment Intent | Create intent for frontend Stripe.js |
| Stripe Confirm | Server-side confirmation after 3DS |
| PayPal Create Order | Create PayPal order object |
| PayPal Capture | Capture approved PayPal payment |
| Saved Payment Methods | List Stripe customer's saved cards |
| Remove Payment Method | Detach card from Stripe customer |
| Stripe Webhook | Signed webhook: payment_intent.succeeded, charge.refunded |
| PayPal Webhook | IPN/webhook for payment status updates |

---

### 2.7 Reviews

| Feature | Description |
|---------|-------------|
| Product Reviews | Public paginated list (approved only); rating breakdown |
| Submit Review | Authenticated; requires verified purchase; pending by default |
| Edit Review | Owner only; resets to pending for re-moderation |
| Delete Review | Owner only |
| Helpful Vote | Toggle helpful/not-helpful; returns updated count |
| Report Review | Flag for spam, offensive content, misleading info |
| My Reviews | Authenticated user's own review list |
| Admin: List Reviews | Filter by status, product, user, reported, rating range |
| Admin: Approve Review | Publish to public display |
| Admin: Reject Review | Hide with optional rejection reason |
| Admin: Delete Review | Hard delete |

---

### 2.8 Wishlist

| Feature | Description |
|---------|-------------|
| View Wishlist | User's saved products |
| Add to Wishlist | Save product; ignore duplicate |
| Remove from Wishlist | Delete by product ID |
| Move to Cart | Transfer item from wishlist to cart |
| Check Wishlist | Boolean: is product in user's wishlist? |

---

### 2.9 Addresses

| Feature | Description |
|---------|-------------|
| List Addresses | All saved delivery/billing addresses |
| Create Address | Add new; optionally set as default |
| Get Address | Single address detail |
| Update Address | Edit any field |
| Delete Address | Remove saved address |
| Set Default | Mark one address as the default |

---

### 2.10 Coupons

| Feature | Description |
|---------|-------------|
| Validate Coupon | Public; returns discount details without authentication |
| Admin: List Coupons | All coupons with usage stats |
| Admin: Create Coupon | Percentage or fixed-amount; usage limit; date range |
| Admin: Get Coupon | Single coupon detail |
| Admin: Update Coupon | Edit any field |
| Admin: Delete Coupon | Remove coupon |
| Admin: Usage Report | Redemption count, user breakdown, revenue impact |

---

### 2.11 Admin Reports & Analytics

| Report | Metrics |
|--------|---------|
| Sales Report | Order count, revenue, AOV by day/week/month |
| Revenue | Gross, net, refunds, tax collected |
| Top Products | By revenue, by units sold |
| Customers | New vs. returning, LTV, cohort data |
| Inventory | Stock levels, low-stock alerts, dead stock |
| Refunds | Refund rate, top refunded products, reasons |
| Dashboard | Summary KPIs: today's revenue, pending orders, low stock alerts |

---

## 3. API Design

### Base URL
```
https://api.demoshop.local/api/v1/
```

### Authentication
All protected endpoints require:
```
Authorization: Bearer <JWT>
```

### Response Format
```json
{
  "data": { ... } | [ ... ],
  "meta": { "current_page": 1, "total": 120, "per_page": 24 },
  "message": "Optional human-readable status"
}
```

### Error Format
```json
{
  "error": true,
  "code": "VALIDATION_ERROR",
  "message": "The given data was invalid.",
  "errors": { "field": ["Reason"] }
}
```

### HTTP Status Codes
| Code | Usage |
|------|-------|
| 200 | Success |
| 201 | Resource created |
| 204 | Success, no content |
| 400 | Bad request (business rule violation) |
| 401 | Unauthenticated |
| 403 | Forbidden (banned, unverified, wrong role) |
| 404 | Resource not found |
| 422 | Validation failure |
| 500 | Internal server error |

---

## 4. Data Models

### User
Fields: id, name, email, password, avatar_url, role (customer|admin|manager), status (active|banned|pending), two_factor_enabled, two_factor_secret, last_login_at, email_verified_at, created_at, deleted_at

### Product
Fields: id, name, slug, description, short_description, price, sale_price, stock_quantity, status (draft|published|archived), category_id, brand, sku, weight_kg, dimensions, is_featured, sold_count, meta_title, meta_description, created_at, deleted_at

### Order
Fields: id, order_number, user_id, shipping_address_id, billing_address_id, subtotal, shipping_cost, discount_amount, tax_amount, total_amount, status, payment_status, payment_intent_id, coupon_code, notes, created_at

### Review
Fields: id, product_id, user_id, order_item_id, rating (1-5), title, body, status (pending|approved|rejected), helpful_count, reports_count, verified_purchase, rejection_reason, created_at

---

## 5. Security & Non-Functionals

- JWT tokens expire in 60 minutes; refresh tokens last 2 weeks
- Role-based access: customer routes and admin routes are fully separated
- Passwords hashed with bcrypt (cost factor 12)
- Coupon codes are case-insensitive and stripped of whitespace
- File uploads restricted to image types; max 5 MB per image
- Webhook endpoints verify Stripe-Signature / PayPal headers
- Rate limiting: 60 requests/minute on public routes, 120 on authenticated routes
- All financial values stored as DECIMAL(10,2) in EUR

---

## 6. Endpoint Summary

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | /auth/register | Public | Register |
| POST | /auth/login | Public | Login |
| POST | /auth/logout | JWT | Logout |
| POST | /auth/refresh | Public | Refresh token |
| GET | /auth/me | JWT | Current user |
| POST | /auth/forgot-password | Public | Request reset link |
| POST | /auth/reset-password | Public | Reset password |
| PUT | /auth/change-password | JWT | Change password |
| POST | /auth/verify-email/{token} | Public | Verify email |
| POST | /auth/resend-verification | Public | Resend verification |
| POST | /auth/two-factor/enable | JWT | Enable 2FA |
| POST | /auth/two-factor/disable | JWT | Disable 2FA |
| POST | /auth/two-factor/verify | JWT | Complete 2FA login |
| POST | /auth/social/google | Public | Google OAuth |
| POST | /auth/social/facebook | Public | Facebook OAuth |
| GET | /products | Public | Browse products |
| GET | /products/featured | Public | Featured list |
| GET | /products/bestsellers | Public | Bestsellers |
| GET | /products/new-arrivals | Public | New arrivals |
| GET | /products/on-sale | Public | On sale |
| GET | /products/search | Public | Search |
| GET | /products/{id} | Public | Product detail |
| GET | /products/{id}/related | Public | Related products |
| GET | /products/{id}/reviews | Public | Product reviews |
| GET | /categories | Public | Category list |
| GET | /categories/tree | Public | Category tree |
| GET | /categories/{id} | Public | Category detail |
| GET | /categories/{id}/products | Public | Products by category |
| GET | /categories/{id}/subcategories | Public | Subcategories |
| POST | /coupons/validate | Public | Validate coupon |
| GET | /users/profile | JWT | Own profile |
| PUT | /users/profile | JWT | Update profile |
| PUT | /users/avatar | JWT | Update avatar |
| DELETE | /users/account | JWT | Delete account |
| GET | /users/order-history | JWT | Order history |
| GET | /users/purchase-summary | JWT | Purchase summary |
| GET | /users/notifications | JWT | Notifications |
| PUT | /users/notifications/{id}/read | JWT | Mark notification read |
| PUT | /users/notifications/read-all | JWT | Mark all read |
| GET | /addresses | JWT | List addresses |
| POST | /addresses | JWT | Create address |
| GET | /addresses/{id} | JWT | Get address |
| PUT | /addresses/{id} | JWT | Update address |
| DELETE | /addresses/{id} | JWT | Delete address |
| PUT | /addresses/{id}/set-default | JWT | Set default address |
| GET | /cart | JWT | View cart |
| POST | /cart/items | JWT | Add to cart |
| PUT | /cart/items/{id} | JWT | Update cart item |
| DELETE | /cart/items/{id} | JWT | Remove cart item |
| DELETE | /cart | JWT | Clear cart |
| POST | /cart/apply-coupon | JWT | Apply coupon |
| DELETE | /cart/remove-coupon | JWT | Remove coupon |
| GET | /cart/summary | JWT | Cart totals |
| GET | /orders | JWT | Order list |
| POST | /orders | JWT | Place order |
| GET | /orders/{id} | JWT | Order detail |
| POST | /orders/{id}/cancel | JWT | Cancel order |
| POST | /orders/{id}/return | JWT | Request return |
| GET | /orders/{id}/invoice | JWT | Download invoice |
| GET | /orders/{id}/tracking | JWT | Shipment tracking |
| POST | /payments/checkout | JWT | Initiate checkout |
| POST | /payments/stripe/intent | JWT | Create Stripe intent |
| POST | /payments/stripe/confirm | JWT | Confirm Stripe payment |
| POST | /payments/paypal/create | JWT | Create PayPal order |
| POST | /payments/paypal/capture | JWT | Capture PayPal |
| GET | /payments/methods | JWT | Saved payment methods |
| DELETE | /payments/methods/{id} | JWT | Remove payment method |
| POST | /reviews | JWT | Submit review |
| PUT | /reviews/{id} | JWT | Edit review |
| DELETE | /reviews/{id} | JWT | Delete review |
| POST | /reviews/{id}/helpful | JWT | Mark helpful |
| POST | /reviews/{id}/report | JWT | Report review |
| GET | /reviews/my-reviews | JWT | My reviews |
| GET | /wishlist | JWT | View wishlist |
| POST | /wishlist/items | JWT | Add to wishlist |
| DELETE | /wishlist/items/{productId} | JWT | Remove from wishlist |
| POST | /wishlist/move-to-cart | JWT | Move to cart |
| GET | /wishlist/check/{productId} | JWT | Check wishlist |
| GET | /admin/users | Admin | List users |
| GET | /admin/users/{id} | Admin | User detail |
| PUT | /admin/users/{id} | Admin | Update user |
| PUT | /admin/users/{id}/ban | Admin | Ban user |
| PUT | /admin/users/{id}/unban | Admin | Unban user |
| DELETE | /admin/users/{id} | Admin | Delete user |
| POST | /admin/products | Admin | Create product |
| PUT | /admin/products/{id} | Admin | Update product |
| DELETE | /admin/products/{id} | Admin | Delete product |
| POST | /admin/products/{id}/images | Admin | Upload images |
| DELETE | /admin/products/{id}/images/{imgId} | Admin | Delete image |
| PUT | /admin/products/{id}/publish | Admin | Publish |
| PUT | /admin/products/{id}/unpublish | Admin | Unpublish |
| POST | /admin/products/bulk-import | Admin | Bulk import |
| POST | /admin/products/bulk-delete | Admin | Bulk delete |
| GET | /admin/products/export | Admin | Export CSV |
| POST | /admin/categories | Admin | Create category |
| PUT | /admin/categories/{id} | Admin | Update category |
| DELETE | /admin/categories/{id} | Admin | Delete category |
| PUT | /admin/categories/reorder | Admin | Reorder |
| GET | /admin/orders | Admin | All orders |
| GET | /admin/orders/{id} | Admin | Order detail |
| PUT | /admin/orders/{id}/status | Admin | Update status |
| PUT | /admin/orders/{id}/assign | Admin | Assign fulfilment |
| POST | /admin/orders/{id}/refund | Admin | Process refund |
| POST | /admin/orders/bulk-export | Admin | Export orders |
| GET | /admin/coupons | Admin | List coupons |
| POST | /admin/coupons | Admin | Create coupon |
| GET | /admin/coupons/{id} | Admin | Coupon detail |
| PUT | /admin/coupons/{id} | Admin | Update coupon |
| DELETE | /admin/coupons/{id} | Admin | Delete coupon |
| GET | /admin/coupons/{id}/usage | Admin | Usage report |
| GET | /admin/reports/sales | Admin | Sales report |
| GET | /admin/reports/revenue | Admin | Revenue report |
| GET | /admin/reports/top-products | Admin | Top products |
| GET | /admin/reports/customers | Admin | Customer report |
| GET | /admin/reports/inventory | Admin | Inventory report |
| GET | /admin/reports/refunds | Admin | Refunds report |
| GET | /admin/reports/dashboard | Admin | Dashboard KPIs |
| GET | /admin/reviews | Admin | All reviews |
| PUT | /admin/reviews/{id}/approve | Admin | Approve review |
| PUT | /admin/reviews/{id}/reject | Admin | Reject review |
| DELETE | /admin/reviews/{id} | Admin | Delete review |
| POST | /webhooks/stripe | Signed | Stripe webhook |
| POST | /webhooks/paypal | Signed | PayPal webhook |
| POST | /webhooks/shipment | Signed | Shipment webhook |

Total: **85+ endpoints**
