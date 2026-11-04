**Classification: INTERNAL**

# Implementation Plan: Transaction Reference — Thumbnails in the Detail and Multiple Images

**Branch**: `091-transaction-reference-images` | **Date**: 2026-11-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/055-transaction-reference-images/spec.md`

## Summary

Feature 054 lets a POS sale carry ONE reference image (two columns on `orders`) and the sale detail only shows the text "image attached". This feature (1) shows every reference image as a thumbnail directly in the sale detail (`TransactionItemsModal`), with a full-size gallery, and (2) allows up to 10 images per sale, added several at a time and removed one by one.

Approach (research.md): images move to a child table `order_reference_images` (no `data_mode`; rides its order via `CloneRegistry` `via`), migrated in-place from the 054 columns, which are then dropped. `OrderReferenceService` stays the ONLY writer: one multipart `PUT` carries `images[]` + `remove_image_ids[]` (delta semantics), applied under the order row lock in one transaction with one activity-log row, so concurrent edits never lose images. Images are served one by one through `GET /orders/{order}/reference/images/{image}` (scoped binding, private disk, `no-store`); thumbnails are the original files shown small (localhost deployment, no generated thumbnails). The duplicated reference presenter (controller + `OrderResource`) becomes one `OrderReferenceService::present()`. The SPA gains `ReferenceThumbnails.vue` and an opt-in gallery mode on the shared `ImageLightbox.vue`.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 13), JavaScript (Vue 3 SFC, Vite)

**Primary Dependencies**: existing only — Laravel Storage (`local` private disk), Pinia, vue-i18n, Tailwind v4 tokens, Phosphor icons. No new package.

**Storage**: MySQL 8 — new table `order_reference_images`, two columns dropped from `orders`; files on `storage/app/private/order-references/` (unchanged location)

**Testing**: PHPUnit feature tests against MySQL `boothpos_test` (`tests/Feature/OrderReferenceTest.php` updated, new `OrderReferenceImagesTest.php`, `CloneRegistryDriftTest`); Vitest (`qa-tests/component/SalesReference.test.js` updated, new `ReferenceThumbnails.test.js`, `ImageLightbox` gallery test, `localeKeys.test.js`); real-browser check per quickstart.md

**Target Platform**: single local machine per store (native or Docker), SPA on `localhost`/booth LAN

**Project Type**: web application (Laravel API + Vue SPA in one repo)

**Performance Goals**: sale detail usable immediately; 10 thumbnails visible ≤ 3 s (SC-004); Sales list `has_reference` without N+1

**Constraints**: ≤ 10 images/sale, ≤ 5 MB each, JPEG/PNG/WebP sniffed from content; one request ≤ 50 MB (PHP: `upload_max_filesize 64M`, `post_max_size 300M`, `max_file_uploads 200` — fits); all-or-nothing saves; images never on a public URL

**Scale/Scope**: a booth event's sales list (hundreds of orders); 1 migration, 1 model, 1 service extended, 4 routes touched, 3 Vue components touched + 1 new

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design — still passing.*

| Principle | Check | Status |
|---|---|---|
| I. Single write path, logic in services, no speculative abstraction | `OrderReferenceService` remains the only writer of the note AND the new table; controllers only validate/delegate. The existing duplicate presenter is collapsed into one (`present()`). No thumbnail pipeline, no configurable limit system — two constants. | PASS |
| II. Verify, don't assume | Feature tests on MySQL for every rule in quickstart §Backend (incl. real temp files for MIME sniffing, rollback leaves no files, scoped-binding 404); Vitest for the components; real-browser checklist with console check before "done". | PASS (planned) |
| III. UX consistency | Token classes only; 422/409/403 mapped by the central handler (409 `reference_image_limit` is a business-rule conflict, 422 shape errors); add/remove controls HIDDEN for users without edit rights; Indonesian comments/commits; en + id locale keys. | PASS |
| IV. Security | Files on the private `local` disk, served only through an authorising endpoint with `no-store`; `{image}` scoped to `{order}`, `{order}` scoped to the DEMO/LIVE mode; MIME from content; random UUID names; permissions enforced server-side (`mayEdit`, `canAccessMenu('sales')`); activity log inside the transaction; no money/stock touched. | PASS |
| V. Performance | `withCount('referenceImages')` on the Sales list query; presenter `loadMissing` (one query per single order); SPA fetches blobs with concurrency 3 and revokes URLs; no new dependency in the bundle. | PASS |
| Docs discipline | OpenAPI updated in the same commit; CLAUDE.md 054 note ("ONE image") gets a dated 055 note; PRD untouched (verified: the PRD never mentions transaction references — 054 added them post-MVP). | PASS (planned) |

No violations → Complexity Tracking left empty.

## Project Structure

### Documentation (this feature)

```text
specs/055-transaction-reference-images/
├── spec.md
├── plan.md              # this file
├── research.md          # Decisions 1-12
├── data-model.md        # table, migration, save algorithm, payload
├── quickstart.md        # automated + real-browser verification
├── contracts/
│   └── order-reference-api.md
├── checklists/
│   └── requirements.md
└── tasks.md             # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
database/migrations/
└── 2026_11_22_000001_create_order_reference_images_table.php   # NEW: create, copy 054 images, drop 2 columns

app/
├── Models/
│   ├── Order.php                       # referenceImages(), hasReference(), referenceImageCount(); fillable minus 2 columns
│   └── OrderReferenceImage.php         # NEW (no HasDataMode — rides its order)
├── Services/
│   ├── OrderReferenceService.php       # save() delta + limit + rollback cleanup; delete() all images; present(); MAX_* constants
│   ├── SalesTransactionsService.php    # withCount('referenceImages')
│   └── DemoClone/CloneRegistry.php     # drop orders.files entry; register order_reference_images (via, files, required_files)
├── Http/
│   ├── Controllers/Api/OrderController.php   # referenceBlock() → service present(); referenceImage(order, image)
│   ├── Requests/UpsertOrderReferenceRequest.php  # images[] / remove_image_ids[] rules from service constants
│   └── Resources/OrderResource.php     # reference ← OrderReferenceService::present()
routes/api.php                          # GET /orders/{order}/reference/images/{image} ->scopeBindings(); remove /reference/image
lang/{en,id}/orders_payments.php        # reference_image_limit message

resources/js/
├── api/orders.js                       # getReferenceImageUrl(orderId, imageId); saveReference({note, images, removeImageIds})
├── components/ui/ImageLightbox.vue     # opt-in gallery (images, v-model:index, prev/next, ←/→)
├── components/sales/ReferenceThumbnails.vue   # NEW
├── components/sales/OrderReferenceModal.vue   # multiple picker, per-file refusal, remaining slots, remove toggles
├── components/sales/TransactionItemsModal.vue # thumbnails in the reference card
└── locales/{en,id}.json                # sales_reference.* keys

tests/Feature/
├── OrderReferenceTest.php              # updated to images[] / per-image route
├── OrderReferenceImagesTest.php        # NEW: limit, delta, concurrency-shaped, rollback, scoped binding, migration
└── CloneRegistryDriftTest.php          # must stay green (no edit expected)

qa-tests/
├── component/SalesReference.test.js    # updated
├── component/ReferenceThumbnails.test.js  # NEW
└── component/ImageLightbox.test.js     # NEW or extended: gallery mode + single-image unchanged

docs/openapi-pos-mvp.yaml               # /orders/{id}/reference*, new images/{image} path
CLAUDE.md                               # dated 055 note under the 054 section
```

**Structure Decision**: existing single-repo Laravel + Vue layout; no new top-level directories. Backend logic stays in `app/Services/OrderReferenceService.php`; frontend pieces live beside the existing reference components in `resources/js/components/sales/`.

## Known gaps / follow-ups (not in this feature)

- **Reference images are not in backups** (`BackupService` archives only `payment-proofs/`) — true since 054, unchanged here (research Decision 10). Separate small change.
- **Android POS client (083)**: verified it does not call `/orders/{id}/reference*` (research Decision 4) — no compatibility alias needed.

### Security self-review (T046, 2026-11-04) — no blocking finding

| Risk area | Checked | Result |
|---|---|---|
| Access control (read) | `GET …/reference/images/{referenceImage}`: `canAccessMenu('sales')`, `{order}` resolved through the DEMO/LIVE-scoped model, `{referenceImage}` via `scopeBindings()` | Cross-sale id → 404, other mode → 404, no `sales` menu → 403, unauthenticated → 401 (all tested) |
| Access control (write) | `OrderReferenceService::mayEdit()` checked before any lock/write; `remove_image_ids` intersected with THIS order's stored rows | A foreign image id can never be deleted through another sale (tested) |
| File handling | MIME sniffed from content for every file BEFORE any write; server-generated UUID names under `order-references/`; private `local` disk; `private, no-store` | Renamed text file refused, no stray files after 422 or exception (tested) |
| Integrity / concurrency | One transaction under `Order::lockForUpdate()`; limit counted against stored rows; delta semantics | Two stale saves keep both images; second save over the limit → 409 (tested) |
| Audit / privacy | One `ActivityLogger` row inside the transaction; only counts and the note — no file names or contents | Matches FR-012 |
| Resource limits | ≤ 10 files × ≤ 5 MB per request (FormRequest + service); PHP limits 64M/file, 300M/post, 200 files | Fits; nothing unbounded |
| Output | Note rendered with text interpolation (no `v-html`); blob URLs revoked on change/unmount | No XSS path found |

Residual (accepted, unchanged from 054): reference images are not in backups.
- Generated thumbnails, captions, user re-ordering, thumbnails in the Sales list — out of scope (spec Assumptions).

## Complexity Tracking

> No Constitution violations — nothing to justify.
