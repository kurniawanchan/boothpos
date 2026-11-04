**Classification: INTERNAL**

# Research: Transaction Reference — Thumbnails and Multiple Images (055)

All findings below come from reading the code on `develop` at `9d6f969a` (feature 054 merged). Nothing here is assumed from the spec alone.

## What exists today (054, US8)

| Concern | Where | Shape today |
|---|---|---|
| Storage | `orders.reference_note`, `reference_image_path`, `reference_image_mime`, `reference_updated_by`, `reference_updated_at` (migration `2026_11_21_000001_add_reference_to_orders_table.php`) | ONE image per sale, as two columns on `orders` |
| Writer | `App\Services\OrderReferenceService::save()/delete()` | Upload = REPLACE (old file deleted after commit); `remove_image` flag; row lock; activity log `order_reference_updated` / `order_reference_removed` inside the transaction; new file deleted if the transaction throws |
| Rule | `OrderReferenceService::mayEdit()` | owner/admin or the sale's cashier; view = `canAccessMenu('sales')` |
| Validation | `UpsertOrderReferenceRequest` | `note ≤1000`, `image` `mimes:jpeg,png,webp` `max:5120`, `remove_image` bool; MIME re-sniffed from CONTENT in the service |
| Routes | `routes/api.php:239-242` | `GET/PUT/DELETE /orders/{order}/reference`, `GET /orders/{order}/reference/image` |
| Presenters | `OrderController::referenceBlock()` AND an inline copy in `OrderResource` (lines 30-35) | `{note, has_image, updated_at, updated_by_name, can_edit}` — **two copies of one shape** |
| List flag | `SalesTransactionsService` row `has_reference` ← `Order::hasReference()` (note or path) | column read, no query |
| SPA | `TransactionItemsModal.vue:324-329` shows the note or the TEXT "image attached" + a link; `OrderReferenceModal.vue` (single `<input type=file>`, one preview, blob URL via `getReferenceImageUrl`) | no thumbnail in the detail |
| Lightbox | `components/ui/ImageLightbox.vue` (`open`, `src`, `alt`), 11 callers | single image, no prev/next |
| Clone | `CloneRegistry` `orders.files = ['reference_image_path' => 'local']` | file copied, never shared |
| Backup | `BackupService::archivePaymentProofs()` archives only `storage/app/private/payment-proofs` | **`order-references/` is NOT in the backup today** (see Decision 10) |
| PHP limits | `docker/php/uploads.ini`: `upload_max_filesize 64M`, `post_max_size 300M`, `max_file_uploads 200` | 10 × 5 MB = 50 MB fits in one request |

## Decision 1 — Images move to their own child table `order_reference_images`

- **Decision**: New table `order_reference_images` (one row per image: `order_id`, `path`, `mime`, `size_bytes`, `position`, `uploaded_by`, timestamps). The note and the "last changed by/at" stay on `orders`. `orders.reference_image_path` / `reference_image_mime` are moved into the new table by the migration and then dropped.
- **Rationale**: An ordered, individually removable list is a one-to-many relation; a JSON column would make "remove image #7 without touching the others while another user adds one" a read-modify-write on a blob, and the clone/drift machinery (`CloneRegistry` `files`, `required_files`, `via`) already understands child tables with file columns (`payment_proofs`, `seller_settlement_acknowledgements`). Dropping the old columns leaves ONE place where a reference image can live, so no reader can look in the wrong place.
- **Alternatives rejected**: (a) keep `reference_image_path` as "image #1" plus a child table for the rest — two shapes for one concept, every reader must merge them; (b) JSON array of paths on `orders` — no per-row identity for authorised serving, concurrent edits clobber each other, the clone file-copier only knows scalar file columns.

## Decision 2 — No `data_mode` on the child table; it rides its order (`via`)

- **Decision**: `order_reference_images` does NOT use `HasDataMode`. Every read goes through a route-bound `{order}` (which IS `HasDataMode`-scoped, so a cross-mode order 404s) and a scoped child lookup (`$order->referenceImages()->whereKey($id)`). In `CloneRegistry` it is registered with `'via' => 'orders.order_id'`, `'files' => ['path' => 'local']`, `'required_files' => ['path']`, right after `orders`.
- **Rationale**: Same pattern as the four `seller_settlement_*` children (076). The image has no meaning outside its order, and a scoped binding is a stronger guarantee than a second global scope. `CloneRegistryDriftTest` will fail if the table is not registered (FK into a registered table not declared), which is the safety net.
- **Alternative rejected**: `HasDataMode` on the child (like `payment_proofs`) — needed there because proofs are uploaded BEFORE the payment exists; reference images are always created through their order, so a second mode column adds a way for parent and child to disagree and nothing else.

## Decision 3 — Migrating existing single images (FR-014)

- **Decision**: One migration: create the table; `INSERT … SELECT` every order with a non-null `reference_image_path` as `position = 1`, `uploaded_by = reference_updated_by`, `created_at = reference_updated_at`, `size_bytes` NULL (unknown, files are not opened in a migration); then drop the two columns. `down()` re-adds the columns and copies back the LOWEST-position image per order (others are lost on rollback — documented in the migration comment).
- **Rationale**: Files stay where they are (`order-references/<uuid>.<ext>`); only the pointer moves, so the migration is fast and cannot lose a file. Uses `DB::table()` deliberately — it must migrate BOTH data modes (the Eloquent scope would only see the active one).
- **Alternative rejected**: a separate artisan backfill command — leaves a window where the code reads the new table but the data is still in the old columns; the entrypoint migrates on container start, so an in-migration copy is the only way the upgrade is invisible to the shop.

## Decision 4 — Write API: one atomic multipart save with `images[]` and `remove_image_ids[]`

- **Decision**: `PUT /orders/{order}/reference` (still POST + `_method=PUT`) accepts `note` (unchanged semantics), `images[]` (0..10 files) and `remove_image_ids[]` (ints). `OrderReferenceService::save()` does, under `Order::lockForUpdate()`: drop removal ids that do not belong to this order or are already gone (silently — spec edge case), check `stored − removed + new ≤ MAX_IMAGES`, store new files, insert rows at `max(position)+1…`, delete removed rows, refuse an empty result, write ONE activity-log row, commit; delete removed files after commit; delete new files if anything throws. The old `image` / `remove_image` keys are removed (the SPA is the only caller in this repo and changes in the same commit). **Verified 2026-11-04 (T045):** the Android POS client (083, worktree `.claude/worktrees/083-android-pos-app`, `core/network/BoothApi.kt`) calls only `server-info`, `auth/login|me|logout`, `settings/features`, `products`, `sessions/current`, `POST orders`, `orders/{id}/receipt` — never `/orders/{id}/reference*`, so no alias for `image` is needed.
- **Rationale**: FR-007 (all-or-nothing) and FR-016 (no lost concurrent additions) both fall out of "one request, one lock, delta semantics" — the client sends what it ADDS and what it REMOVES, never the full list, so a concurrent save by another user is never overwritten. This is the same lock-then-validate-then-write shape the service already has.
- **Status codes**: >10 files in one request, wrong type/size of any file → **422** (`errors["images.N"]`, request-shape); stored + new over the limit → **409** `{code: 'reference_image_limit', max, remaining}` (depends on stored state, the codebase's 409 convention); empty result → 422 as today; not allowed → 403 as today.
- **Alternatives rejected**: (a) one `POST /reference/images` per file + `DELETE /reference/images/{id}` — simpler endpoints but a three-image upload becomes three transactions, breaking FR-007 and producing three activity-log rows; (b) send the full desired list — last writer wins, violating FR-016.

## Decision 5 — Serving: per-image authorised endpoint with scoped binding; originals, no stored thumbnails

- **Decision**: `GET /orders/{order}/reference/images/{image}` with `->scopeBindings()` (an image id of another order 404s), same gate as today (`canAccessMenu('sales')`), `Cache-Control: private, no-store`, `Content-Type` from the stored `mime`. Thumbnails are the ORIGINAL file displayed small (`object-cover`); no thumbnail files are generated. `GET /orders/{order}/reference/image` (singular) is removed.
- **Rationale**: BoothPOS runs on `localhost` (or a booth LAN); 10 × ≤5 MB over loopback is well inside SC-004's 3 s. Generated thumbnails would double the files the clone, backup, and delete paths must handle, for no felt gain on this deployment (Constitution I: no speculative work). The SPA fetches with limited concurrency (3) so ten images never block the rest of the detail (FR-003, US1-6).
- **Alternative rejected**: GD thumbnail at upload (`CatalogImageThumbnail` exists) — revisit only if a real booth on slow LAN tablets shows a problem; noted as a follow-up, not built.

## Decision 6 — ONE reference presenter (removes the existing duplicate)

- **Decision**: `OrderReferenceService::present(Order, User): array` returns `{note, images:[{id, mime, size_bytes, position, created_at}], image_count, has_image, updated_at, updated_by_name, can_edit}` and is used by BOTH `OrderController::referenceBlock()` and `OrderResource`. It calls `loadMissing(['referenceImages', 'referenceEditor'])` itself, so a caller that forgets to eager-load gets one extra query, never a silently missing field.
- **Rationale**: Today the block exists twice (controller + resource). Adding `images` to two copies is exactly the drift CLAUDE.md warns about (the `relationLoaded()` trap). `has_image` is kept as a derived convenience so existing SPA checks keep working.
- **Alternative rejected**: `images` only `whenLoaded` — repeats the trap that already hid fields from pre-order responses.

## Decision 7 — `has_reference` in the Sales list without N+1

- **Decision**: `SalesTransactionsService` adds `->withCount('referenceImages')` to its existing query; `Order::hasReference()` = `reference_note !== null || $this->referenceImageCount() > 0`, where `referenceImageCount()` reads `reference_images_count` if the aggregate was selected, else the loaded relation, else one `count()` query.
- **Rationale**: One sub-select on a list already loaded in one query (Constitution V). `withCount` keeps Eloquent semantics; the child has no mode column, and the parent query is already mode-scoped.

## Decision 8 — Lightbox gallery is an opt-in extension of `ImageLightbox.vue`

- **Decision**: `ImageLightbox` gains optional `images` (array of `{src, alt}`) and `index` (`v-model:index`). With ≥2 images it shows previous/next buttons, `n / total`, and ←/→ keys; with `src` only it behaves exactly as today. A new `components/sales/ReferenceThumbnails.vue` (fetch blobs → grid of fixed-size thumbnails, placeholder on failure, revoke URLs on unmount/change, click opens the gallery) is used by `TransactionItemsModal` (read-only) and `OrderReferenceModal` (with per-image remove toggles).
- **Rationale**: One popup mechanism (the component's own docblock forbids duplicating it per screen). The 11 existing callers pass `src` and are untouched.
- **Alternative rejected**: a second "gallery" component — two lightboxes with different keyboard/close behaviour.

## Decision 9 — Limits live in one place

- **Decision**: `OrderReferenceService::MAX_IMAGES = 10`, `MAX_IMAGE_KILOBYTES = 5120`; `UpsertOrderReferenceRequest` reads them; the reference payload carries `max_images` so the SPA's "N more allowed" text never hard-codes 10. The SPA's per-file pre-check (type/size) remains a UX convenience; the server re-checks every file, sniffing content.
- **Rationale**: FR-005/FR-006; a single constant prevents client and server from disagreeing on the limit.

## Decision 10 — Backup: unchanged, gap recorded

- **Finding**: `BackupService` archives only `payment-proofs/`. Reference images (054) were never part of a backup; this feature does not change that. The spec's edge case "follow the same backup behaviour" is therefore literally true but the behaviour is "not backed up".
- **Decision**: Out of scope here (the user asked for thumbnails + multiple upload). Recorded as a known gap in `plan.md` and in the CLAUDE.md note so it is a conscious follow-up, not a surprise. Adding `order-references/` to the archive is a small, separate change (and must follow the "proofs are backed up but never restored by the UI" rule).

## Decision 11 — Activity log

- **Decision**: Keep the two actions. `order_reference_updated` with `old_values: {note, image_count}`, `new_values: {note, image_count, images_added, images_removed}`; `order_reference_removed` with `old_values: {note, image_count}`. Written inside the transaction. No file names or contents (FR-012). A save that changes nothing writes nothing (as today).

## Decision 12 — Ordering

- **Decision**: `position` is assigned as `max(position)+1` per new image under the row lock, in the order the files appear in the request; removals leave gaps (never renumbered). Reads order by `position, id`.
- **Rationale**: Gaps are harmless and renumbering would be a write to rows the user did not touch. No user re-ordering (spec Assumptions).
