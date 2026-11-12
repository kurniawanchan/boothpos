# Implementation Prompt — BoothPOS Customer Commerce Android App

Copy the prompt below into the coding agent that will implement this work.

---

You are implementing the approved **BoothPOS Customer Commerce Android App** in this repository. Work incrementally, keep existing BoothPOS staff/POS behaviour intact, and leave the worktree cleaner than you found it. Read [`docs/android-customer-commerce/design.md`](./design.md) before changing code; it is the approved product and architecture decision record.

## Outcome

Deliver a native Android app for customers (roughly 15+ audience) to register, sign in, browse a store catalogue, manage a cart, place a pickup or courier order, and follow its fulfilment/shipment timeline. Build the supporting Laravel 13 + MySQL API and runnable Bruno documentation in the same repository.

The product must be fast, conventional, accessible, secure, and visually restrained. It must feel like a small Android storefront, not a POS dashboard, admin panel, social feed, or an AI-generated collage of gradients, cards, and carousels.

## Non-negotiable boundaries

1. Do **not** expose, loosen, or reuse the existing staff/POS endpoints for the customer app. In particular, do not use staff `/api/v1/auth/*`, `/api/v1/catalog`, or `POST /api/v1/orders` as customer API endpoints.
2. Do **not** use `users` or staff tokens for customer identity. Existing `customers` rows are contact records and do not authenticate. Introduce a dedicated customer account model/table that is an authenticatable Sanctum token subject and is linked one-to-one with `customers`.
3. Do **not** repurpose existing POS `orders` (which require event, cashier session, and staff user) or existing `shipments` (which belong to preorders). Use the approved customer-commerce schema and services.
4. Never trust the app for item price, stock, subtotal/total, address ownership, customer identity, status, or tracking URL. All authority belongs to Laravel.
5. Do not collect or log raw passwords, tokens, card data, or unnecessary PII. Do not add analytics/ads/tracking SDKs.
6. Preserve MySQL compatibility and run Laravel tests using the repository’s real MySQL test configuration; do not substitute SQLite where the project forbids it.
7. Do not begin payment-gateway capture, automatic shipping-rate calculation, carrier webhooks, push notifications, reviews, guest checkout, or social login. Keep those explicitly out of this delivery.

## Deliverables

### Laravel and MySQL

Create a separate customer/storefront API namespace under `/api/v1`:

- Public, read-only storefront catalogue:
  - `GET /storefront/products`
  - `GET /storefront/products/{slug}`
  - Only published, non-deleted, eligible, in-stock variants; paginated and bounded allow-listed filtering/search.
- Customer account endpoints:
  - `POST /customer/auth/register`
  - `POST /customer/auth/login`
  - `POST /customer/auth/logout`
  - `GET /customer/me`
  - `PUT /customer/me`
- Customer-owned address CRUD at `/customer/addresses`.
- Customer-owned orders:
  - `POST /customer/orders`
  - `GET /customer/orders`
  - `GET /customer/orders/{publicId}`
  - `GET /customer/orders/{publicId}/tracking`
- Staff-only customer-order fulfilment actions, protected by an explicit staff ability. They must allow confirmation, packing, pickup-ready, picked-up, dispatch, delivered, and cancellation, but must never accept a customer token.

Implement migrations, relationships, factories/fixtures as appropriate, Form Requests, Policies, Resources, Services, and feature tests for:

| Table | Minimum role |
| --- | --- |
| `customer_accounts` | One-to-one customer identity; normalized unique email, Laravel password hash, `email_verified_at`, `disabled_at`, timestamps. |
| `customer_addresses` | Customer-owned delivery address with a single default address maintained transactionally. |
| `customer_orders` | Public order number, customer, `pickup`/`courier` fulfilment, order contact/address snapshots, money totals, status, timestamps. |
| `customer_order_items` | Variant and immutable SKU/name/unit-price snapshots, quantity, line total. |
| `customer_order_shipments` | Customer order shipment with courier/service, optional tracking number, dispatch/delivery timestamps, shipping-cost snapshot. |
| `customer_order_status_events` | Append-only, customer-visible fulfilment history with status, occurred time, safe note, and staff/system audit metadata. |

Add publication controls for products/variants (recommended: `published_at`). The storefront must use a distinct public Resource projection; never serialize staff-only fields, cost, stock internals, private notes, or other customers’ data.

Use a dedicated customer-order transition service as the only writer of customer-order state/events. Enforce valid state progression:

```
awaiting_confirmation → confirmed → packing → ready_for_pickup → picked_up
                                      └──────→ shipped → delivered
any pre-completion state → cancelled (subject to documented policy)
```

Rules:

- A pickup order cannot receive a courier shipment or transition to `shipped`.
- A courier order cannot transition to `shipped` without valid dispatch information.
- Tracking-number changes and delivered transitions append status events; they do not rewrite history.
- Customer accounts can only read their own orders, addresses, and tracking data, including predictable-public-ID attempts.
- Staff transitions must be auditable using established project practices.
- Create checkout inside a database transaction. Recalculate canonical product price and total, validate availability with locking/the existing stock service, and use a required persisted `Idempotency-Key` per customer request. Duplicate requests/taps must return the original result rather than create an extra order.
- Follow the approved business boundary: no stock movement on order request; design the later staff confirmation/fulfilment stock transition explicitly with the existing stock mechanism so inventory cannot be oversold. Do not write stock columns directly.

Use narrow Sanctum abilities for customer tokens, separate customer middleware/guard resolution from staff auth, rate-limit registration and login by IP and normalized identifier, and return generic invalid-credential failures. Use stable machine-readable errors with field errors and a correlation/request ID; use appropriate `201`, `401`, `403`, `404`, `409`, `422`, and `429` responses. Never make a public error reveal account existence or another customer’s order.

Tracking in this release is **staff-maintained**, not claimed to be live carrier telemetry. Return carrier/service, tracking number, dispatch/delivery timestamps, and the status history. If adding an optional external carrier tracking link, construct it server-side from an allow-listed carrier template. Do not store or render arbitrary URLs.

### Bruno documentation

Add a `bruno/12-CustomerCommerce/` collection section and extend the local environment with the minimal variables needed (for example `customerToken`, `customerEmail`, `customerOrderId`, `idempotencyKey`). Follow existing collection conventions.

Include runnable examples for:

- successful registration, login, authenticated profile, logout;
- rate-limit/invalid credentials/duplicate email negative cases;
- public catalogue and proof unpublished/out-of-stock data does not leak;
- address ownership negative case;
- successful idempotent checkout and duplicate-key retry;
- customer order list/detail/tracking;
- cross-account order/tracking access denied/not found;
- staff fulfilment progression and invalid transition negatives;
- proof a customer token cannot call staff/POS APIs or staff fulfilment actions.

### Android app

Do not modify the existing `android/` Laravel installer into this customer app. Create an isolated native Android project/module with Kotlin and a current supported Android toolchain (minimum SDK 26 unless repository constraints require higher). Use current compatible stable versions at implementation time and centralize them in a Gradle version catalog/BOMs.

Use these libraries/patterns unless a concrete compatibility issue is documented:

- Kotlin, coroutines, Flow, and structured concurrency;
- Jetpack Compose + Material 3 + Navigation Compose;
- ViewModel, lifecycle-aware collection, and `StateFlow` UI state;
- Hilt for dependency injection;
- Retrofit + OkHttp + Kotlin serialization, with a single mapped API-error model;
- Room for a bounded cache of catalogue data and local cart;
- DataStore for non-sensitive preferences;
- Android Keystore-protected encrypted storage for the short-lived customer token;
- Coil for product images;
- WorkManager only for deferrable/retryable tasks, not normal screen loading.

Use feature-first clean architecture and keep Android implementation details out of domain types:

```
app/                    composition root, navigation, DI
core/{ui,network,data}/  shared UI, HTTP/error handling, persistence
feature/{auth,catalog,cart,checkout,orders,account}/
  data/ domain/ presentation/
```

Repositories expose domain models and `suspend`/`Flow` APIs. ViewModels own screen state and one-time events. Composables are stateless where practical, testable, and do not contain business rules or HTTP calls.

Implement these screens/states:

1. Onboarding/auth choice, register, sign in, logout, profile/session expiry.
2. Storefront product list with search/filter, pagination, image placeholders, loading, empty, offline, and retry states.
3. Product detail with variants, price, stock availability, quantity selector, and add-to-cart.
4. Cart with editable quantities/removal and a persistent clear total; preserve cart locally but validate it afresh at checkout.
5. Checkout with pickup/courier selection, address selection/entry, contact confirmation, transparent total, and an explicit final `Place order` action. Disable duplicate submission while in flight and pass a generated idempotency key.
6. Orders list and order detail. The detail page shows current status first, then order number, items, pickup/delivery instructions, address snapshot, courier/tracking number if available, and the timestamped status timeline.

UX requirements:

- Follow Android Material 3 and platform back/navigation behaviour. Use a simple bottom navigation: Shop, Cart, Orders; Account is a clear top-bar/profile action.
- Use one primary action per screen, familiar form fields, inline validation, clear status language, and plain Bahasa Indonesia strings organized for later localization.
- Target small phones first; support larger layouts without becoming a dashboard.
- Minimum 48 dp touch targets, scalable text, high contrast, semantic labels, logical TalkBack order, and status conveyed with text/icon/timestamp rather than colour alone.
- No dark patterns, artificial countdowns, surprise fees, autoplay, decorative overload, excessive rounded cards, or unnecessary animations.
- Tracking must say `Diperbarui oleh toko` until carrier integration exists. Manual refresh is adequate. A `Track parcel` action appears only for a safe server-generated URL, opens externally, and clearly communicates the handoff.

Android security/performance requirements:

- HTTPS only via Network Security Config; no cleartext production traffic.
- Respect TLS/certificate validation, use connection/read/write timeouts, and avoid automatic unsafe retries for checkout.
- Redact authorization/PII from logs. Use network logging only in debug, and never log request bodies that contain credentials or addresses.
- Clear encrypted token/session state on logout, account disablement, and unrecoverable `401`.
- Do not export Android components without a demonstrated need. Avoid a WebView.
- Bound image/cache size, avoid preloading the whole catalogue, and handle low-bandwidth/offline conditions gracefully.

## Verification and quality gates

Work in small vertical slices and run the relevant checks after each. Do not declare completion until all applicable checks pass.

- Laravel feature/unit tests covering authentication, rate limiting, public resource redaction, authorization/ownership, validation, published catalogue rules, idempotency, concurrent stock validation, state transitions, tracking history, and staff/POS regression cases.
- Run the repository’s normal PHP/Laravel formatter/static analysis/test commands and report their exact results.
- Android unit tests for use cases/ViewModels and Compose tests for register/login, cart/checkout duplicate prevention, order list/detail, and tracking timeline/error states.
- Run Android lint/static checks and a release build.
- Execute the new Bruno calls against the configured test/staging API and keep them runnable without manual body editing beyond documented environment values.
- Manually check TalkBack labels/traversal, font scaling, compact-screen layout, loading/offline/error/retry states, and no sensitive data in logs.

## Delivery discipline

Before coding, inspect the existing models, services, migrations, routes, test conventions, API Resources, RBAC/ability middleware, Android installer, and Bruno files. Reuse safe existing project conventions; do not duplicate business logic. Explain any unavoidable deviation in code documentation and the implementation summary.

Do not commit unrelated formatting or revert user changes. For each completed slice, report files changed, tests run, test results, remaining risks, and any decision that needs product-owner input. Stop and ask before making a decision that changes approved scope—especially payment, carrier-provider integration, fulfilment semantics, or reconciliation with existing POS orders/preorders.
