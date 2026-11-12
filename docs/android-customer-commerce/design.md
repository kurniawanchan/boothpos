# Customer Commerce Android App

**Status:** Approved<br>
**Author:** Codex  **Date:** 2026-11-11

## Summary

Build a separate, native Android storefront for customers to register, sign in, browse the shop catalogue, manage a cart, submit an order, and follow its fulfilment/shipping progress. It will use Kotlin and Android-standard patterns, and a deliberately separate Laravel customer API backed by the existing MySQL installation. The existing BoothPOS staff API and POS `orders` flow must not be exposed to customers or repurposed as a public checkout API: staff authentication, permissions, cashier sessions, and event-bound sales are fundamentally different concerns.

The first release should make an order request for fulfilment and confirmation by shop staff, with inventory validated server-side. It supports pickup and manually maintained courier shipment/tracking. Online payment-gateway capture, automated carrier-status ingestion, promotion engines, reviews, social sign-in, and push notifications are not in this release.

## Goals

- Give customers aged approximately 15 and above a fast, predictable mobile path: register/sign in, catalogue, product details, cart, checkout, and their orders.
- Keep the Android app responsive on ordinary Android devices and unreliable connections.
- Preserve the existing POS functionality, data, authorization boundaries, and stock invariants.
- Use maintainable Kotlin, narrowly scoped dependencies, server-side validation, MySQL migrations, automated tests, and runnable Bruno API documentation.
- Make privacy and account security default behaviour rather than a UI afterthought.

## Non-goals

- Replacing the existing POS/tablet installer, Vue staff UI, or staff-facing API.
- Reusing staff credentials, staff tokens, `GET /catalog`, or POS `POST /orders` from the mobile app.
- Guest checkout, payment gateway integration, vouchers, delivery-rate calculation, automatic carrier-status ingestion, or a social/feed-style experience in v1.
- Collecting date of birth or using manipulative engagement patterns. The 15+ target informs readability and plain language; legal/consent requirements remain a product-owner decision.

## Constraints and discovered repository facts

- The project already has Laravel 13, Sanctum, MySQL, staff `/api/v1` routes, a `customers` table, and a Bruno collection at `bruno/`.
- `customers` is a staff-managed contact record only: it has no password or authenticatable identity. Existing `orders` require `event_id`, `session_id`, and a staff `user_id`; they represent completed POS sales. Reusing either for customer authentication or checkout would weaken authorization and corrupt the POS workflow.
- The existing `shipments` table is a one-to-one child of `preorders`; it cannot represent a customer-commerce shipment without a deliberately incompatible schema change. Customer orders therefore need their own shipment and status-history records in v1.
- Catalogue routes are inside the Sanctum staff group and protected by product abilities. They cannot be made public by merely relaxing middleware.
- Keep the current MySQL test database requirement; new migrations and tests must run there.

## Proposed design

### Product and fulfilment boundary

The customer selects in-stock, **published** variants and submits a checkout after choosing pickup or courier delivery and confirming contact/address data. The server creates a `customer_orders` record in `awaiting_confirmation`, snapshots item name/SKU/unit price, and atomically rechecks available stock. Staff may confirm it, mark it ready for pickup, or create a courier shipment; the customer can then see a small, human-readable status timeline. No stock movement occurs when the request is submitted; the later fulfilment action is the only place that may reserve/deduct stock and it must be designed transactionally with the existing stock service.

This conservative boundary avoids declaring an unpaid request to be a completed POS sale and gives the business an explicit place to settle payment and fulfilment decisions. A payment gateway, if approved later, becomes a separate state machine and webhook integration, not a client-side extension of checkout.

### Data model

Add these tables through Laravel migrations, with foreign keys, indexes, decimal money values, and immutable order snapshots:

| Table | Purpose |
| --- | --- |
| `customer_accounts` | One-to-one with `customers`; normalized unique email, password hash, verified-at and disabled-at timestamps. It is an authenticatable Sanctum token subject, not a `users` row. |
| `customer_addresses` | Customer-owned saved addresses; one default delivery address at most, enforced in application transaction logic. |
| `customer_orders` | Customer, public order number, fulfilment method, address snapshot, contact snapshot, monetary totals, status, notes, timestamps. |
| `customer_order_items` | Customer order, variant, SKU/name/price snapshots, quantity, line total. |
| `customer_order_shipments` | One customer order shipment, courier/service names, optional tracking number, dispatch/delivery timestamps, and shipping-cost snapshot. |
| `customer_order_status_events` | Append-only customer-visible status history: status, occurred time, allowed plain-language note, and staff/system actor metadata for audit. |

Use an explicit transition service as the sole writer for the order state and status events. Valid v1 states are `awaiting_confirmation`, `confirmed`, `packing`, `ready_for_pickup`, `shipped`, `delivered`, `picked_up`, and `cancelled`; prohibit impossible changes and never let the customer mutate status. A courier order cannot become `shipped` without the dispatch information, and pickup orders cannot have a courier shipment. Tracking-number changes and delivery confirmation create new status events rather than overwriting the history.

Add a publishability field to products/variants (recommended: `published_at` on products and variants) and never disclose unpublished, deleted, disabled, or out-of-stock variants through the storefront. Keep price and stock authority on the server. Do not store card data, payment credentials, access tokens, or a raw password in application tables or logs.

### API boundary

Create a new, versioned public/customer namespace such as `/api/v1/storefront` and `/api/v1/customer`. Keep routes, Form Requests, Resources, Policies, services, and rate limits separate from staff equivalents. Do not add customer conditionals to the staff `AuthController` or `OrderController`.

| Area | Endpoint shape | Policy |
| --- | --- | --- |
| Catalogue | `GET /storefront/products`, `GET /storefront/products/{slug}` | Unauthenticated; only the public resource projection and published items. Paginate/filter/search with allow-listed values and bounded page sizes. |
| Account | `POST /customer/auth/register`, `login`, `logout`; `GET /customer/me`; `PUT /customer/me` | Register/login throttled by IP and account identifier; generic credential errors; Sanctum customer-token ability only. |
| Addresses | `/customer/addresses` CRUD | Token account may access only its own rows. |
| Checkout/orders | `POST /customer/orders`; `GET /customer/orders`; `GET /customer/orders/{publicId}`; `GET /customer/orders/{publicId}/tracking` | Token account owns the order; checkout uses a transaction and `lockForUpdate`/the stock service to defeat overselling. An `Idempotency-Key` is required and persisted per customer/request. Order and tracking resources return only the account's shipping/contact snapshots and status timeline. |
| Staff fulfilment | Staff-only `/customer-orders/{id}` actions to confirm, pack, mark pickup-ready/picked-up, dispatch, deliver, or cancel | Explicit staff ability; all state changes use the transition service, append an event, are audited, and can never be invoked with a customer token. |

The initial tracking endpoint reports records maintained by staff, not a claim of live courier telemetry. It returns carrier/service, tracking number, dispatch/delivery timestamps, and the status timeline. If a later carrier integration supplies an external tracking URL, generate it server-side from an allow-listed courier template; do not accept an arbitrary URL from staff or render untrusted web content in the app.

Use RFC 9457-style, localized-safe JSON errors (stable machine `code`, field errors, request/correlation ID) and `201`/`409`/`422` semantics. The API never trusts a client subtotal, price, availability, customer ID, order status, or ownership field. Add API-versioning and a deprecation policy before any public release.

### Android architecture and libraries

Create a separate native Android app module/project rather than extending `android/`, which is a local Laravel/POS installer. Use Kotlin, Jetpack Compose, Material 3, adaptive layouts, Navigation Compose, ViewModel + `StateFlow`, coroutines, Hilt, Retrofit/OkHttp with Kotlin serialization, Room for cached catalogue and local cart, DataStore for non-sensitive preferences, Coil for images, and WorkManager only for deferrable/retriable work. Pin versions through Gradle version catalogs and Android's BOMs; use the current stable compatible versions at implementation time rather than copying stale versions into this document.

Organize as feature-first clean architecture:

```
app/                    composition root, navigation, dependency injection
core/{ui,network,data}/  shared design system, HTTP/error handling, persistence
feature/{auth,catalog,cart,checkout,orders,account}/
  data/ domain/ presentation/
```

Repositories expose domain models and suspend/Flow APIs; ViewModels hold presentation state and emit one-off UI events; composables remain stateless where practical. The cart remains useful offline but checkout requires a fresh server validation. Use a remote-image cache with size limits and placeholders; do not preload the entire catalogue.

Store the short-lived customer token only in encrypted application storage whose key is protected by Android Keystore; clear it on logout, account disablement, and unrecoverable `401`. Enforce HTTPS with Network Security Config (no cleartext), certificate validation, request timeouts, redacted HTTP logs only in debug, no authorization headers or PII in logs/crash reports, and no exported components unless necessary. Add a server-controlled kill/maintenance response before public rollout.

### UX and accessibility

Follow Android Material 3 and platform navigation conventions: bottom navigation has Shop, Cart, and Orders; Account is an obvious action from the top bar or profile area. Register and sign-in are short single-column forms with password visibility toggle, inline field errors, clear recovery help, and a single primary action. Checkout is a small sequence with visible totals and an explicit final “Place order” confirmation; there are no surprise fees, countdowns, dark patterns, overloaded carousels, or decorative complexity.

The order-detail screen shows a concise timeline with the current state first, then order number, pickup/delivery instructions, carrier and tracking number when available, and item/address snapshots. It must clearly say “Updated by the store” until a carrier integration exists; a manual refresh is sufficient for v1. Use status text plus icon and timestamp, never color alone. “Track parcel” only appears for a safe server-generated external link and opens outside the app with a clear handoff notice.

Use 48 dp minimum touch targets, scalable text, semantic labels, high contrast, TalkBack traversal, loading/empty/error/offline states, and plain Bahasa Indonesia copy (with a localization-ready resource structure). Product cards prioritize image, name, price, stock availability, and one clear action. Test compact phones first and support larger screens without turning the primary flow into a tablet dashboard.

## Implementation plan and acceptance gates

1. **Foundation and contract** — create the Android project, CI quality checks, app configuration, Laravel customer models/migrations, separate guard/token setup, public resources, and Bruno `12-CustomerCommerce` environment variables. Prove staff APIs remain inaccessible with a customer token.
2. **Account** — registration/login/logout/profile and account test coverage, then Android auth/session screens with secure persistence and expiry handling. Prove rate limiting, duplicate-email protection, password hashing, token revocation, and account ownership.
3. **Storefront** — published-catalog endpoint, product detail endpoint, pagination/filter/search, Room cache, and catalogue UI. Prove unpublished/non-stock items never leak.
4. **Cart and checkout** — cart behaviour, address management, idempotent customer-order submission, item/price/address snapshots, concurrency-safe stock validation, customer order list/detail UI, and all success/error recovery states. Prove duplicate taps/requests create one order and simultaneous checkout cannot oversell.
5. **Fulfilment and tracking** — staff review, state-transition and shipment services, customer-visible tracking endpoint/timeline, staff shipment controls, audit records, and all transition/ownership tests. Prove no customer can read or mutate another order, no invalid transition is accepted, pickup cannot be marked shipped, and shipment updates become visible only to the owning customer.
6. **Hardening and rollout** — observability, integration/UI/accessibility/performance testing, staging rollout, and release checklist. A payment-gateway or carrier-webhook workstream may begin only after its provider and legal/commercial contract are approved.

Every API endpoint gets Bruno examples for success and key negative cases, with environment variables such as `baseUrl`, `customerToken`, `customerEmail`, and `customerOrderId`. Bruno remains executable documentation, not a static export. Laravel feature tests cover authorization, validation, rate limits, idempotency, inventory concurrency, resource redaction, and regressions; Android tests cover ViewModels/use cases and critical Compose flows. CI must run Laravel tests, static analysis/formatting, Android unit tests, and a release build.

## Alternatives and tradeoffs

- **Use existing staff API/order entities:** rejected. Its privileged token model and required cashier/event fields describe another business process.
- **Put customer credentials on `customers`:** rejected. A dedicated authenticatable identity separates contact records from security concerns and supports lifecycle controls cleanly.
- **Allow guest checkout:** deferred. It reduces friction but complicates ownership, recovery, fraud control, and order history; it conflicts with the requested registration/login journey.
- **Use a multi-platform UI:** rejected for v1. Kotlin/Compose is requested and removes an extra runtime/abstraction while preserving Android platform behaviour.
- **Create a flashy custom interface:** rejected. Standard components, predictable flow, and accessibility better serve the target audience and performance goal.

## Risks and mitigations

- **Overselling:** Treat client availability as informational; revalidate inside one database transaction and make staff fulfilment use the existing stock service.
- **Account takeover or enumeration:** Hash passwords with Laravel's current password driver, rate-limit endpoints, use generic login failures, short-lived/revocable tokens, and add verified email/password reset only after mail delivery is operational.
- **PII exposure:** Separate public Resources from staff Resources, enforce owner policies, avoid logging PII/tokens, snapshot only the fulfilment data required.
- **Misleading tracking:** Label v1 tracking as store-updated and retain timestamped status events; do not present estimated/live carrier updates until the integration is contracted and monitored.
- **Scope creep from payments/carrier integrations:** Freeze v1 at order request + staff-managed fulfilment/tracking until provider, fees, delivery zones, returns rules, and webhook guarantees are decided.
- **Mixed data modes/seller scope:** Customer APIs must apply the same isolation rules as the active BoothPOS installation; add explicit multi-seller tests before exposing a multi-artist catalogue.

## Rollout

Ship behind a server feature flag disabled by default. Deploy migrations and API first, seed only test/demo data, and run Bruno + automated suites against a staging MySQL database. Internal-test a signed Android build with a staging API, enable one store/artist at a time, monitor registration/order failures and stock conflicts, then enable production. Backout disables the flag and blocks new checkout while preserving submitted customer orders; never roll back by deleting customer data.

## Open questions

1. Is v1 pickup only, delivery only, or both? The recommendation is pickup + courier delivery with staff-managed tracking and no automatic shipping-rate calculation.
2. Is payment captured online, paid on pickup, or confirmed manually? The recommendation is payment on pickup/manual confirmation in v1.
3. What storefront identity should a customer see in a multi-artist installation: one store, one selected artist, or a marketplace? The recommendation is one store catalogue first.
4. Which email/SMS provider, verified-email policy, password-reset flow, privacy notice, and data-retention period have been approved?
5. Which couriers/services, tracking-number format, delivery proof, cancellation/return policy, and notification channel are approved? The recommendation is no proof upload and no push notification in v1; staff update the order timeline manually.
6. Should a staff-confirmed customer order create an existing POS `order`, a `preorder`, or complete as its own record? This requires an explicit reconciliation design before staff handoff is built.

## Decision

Approved by product owner on 2026-11-11. Build a separate Kotlin/Jetpack Compose customer-commerce app and a separate Laravel customer/storefront API. V1 supports registration/login, a published catalogue, cart, pickup or courier checkout, staff-managed fulfilment, and customer-visible shipment tracking. It excludes payment-gateway capture and automated carrier-status ingestion.
