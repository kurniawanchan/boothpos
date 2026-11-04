**Classification: INTERNAL**

# Tasks: Transaction Reference — Thumbnails in the Detail and Multiple Images

**Input**: Design documents from `specs/055-transaction-reference-images/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/order-reference-api.md, quickstart.md

**Tests**: INCLUDED — Constitution II requires backend tests under `tests/Feature/` (real MySQL) and frontend tests under `qa-tests/` for every change, plus a real-browser check.

**Test command safety**: inside Docker ALWAYS use `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test …` (CLAUDE.md — the dev DB has been wiped twice). Run one `artisan test` at a time. Frontend: `npx vitest run --exclude '.claude/**' <files>`.

**Conventions**: code comments, UI copy and commit messages in Indonesian; token classes only (no hex); every new locale key in BOTH `resources/js/locales/en.json` and `id.json`.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1 (thumbnails in detail), US2 (several images), US3 (remove one image)

## Why the backend sits in Phase 2

The migration DROPS `orders.reference_image_path|mime`, so every reader AND the single writer (`OrderReferenceService::save()`) must move to the new table in the same step — otherwise the app breaks between phases. Phase 2 therefore delivers the whole backend (schema, writer with `images[]` + `remove_image_ids[]`, per-image serving, presenter, clone, OpenAPI) behind tests. The story phases deliver the UI that makes each story visible, plus story-specific tests.

---

## Phase 1: Setup

**Purpose**: confirm a clean baseline so later failures are attributable to this feature.

- [X] T001 Confirm dev DB has no pending migrations and record the baseline: `docker compose exec app php artisan migrate:status | grep -i pending` (expect none) and note it in `specs/055-transaction-reference-images/quickstart.md` under a new "Baseline" line
- [X] T002 Run the existing reference + clone tests as the baseline and record pass/fail counts: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter='OrderReferenceTest|CloneRegistryDriftTest|LiveToDemoClonerTest'` and `npx vitest run --exclude '.claude/**' qa-tests/component/SalesReference.test.js qa-tests/component/SalesReferenceLink.test.js`

---

## Phase 2: Foundational (blocking — whole backend + API module)

**⚠️ CRITICAL**: no user-story UI work starts until this phase is green.

### Tests first (must FAIL before T008+)

- [X] T003 [P] Write migration test in `tests/Feature/OrderReferenceImagesMigrationTest.php`: seed (via `DB::table`, both `data_mode` values) orders with and without `reference_image_path`, run the new migration's `up()` logic, assert one `order_reference_images` row per imaged order (`position=1`, same path/mime, `uploaded_by = reference_updated_by`), columns `reference_image_path`/`reference_image_mime` no longer exist on `orders`, and `down()` restores the first image (use `Schema::hasColumn`)
- [X] T004 [P] Write writer tests in `tests/Feature/OrderReferenceImagesTest.php` covering data-model.md "Save algorithm": 3 files in one PUT → 3 rows positions 1-3 + ONE activity-log row `images_added: 3`; later add 2 → positions 4-5, first three untouched; stored 9 + 2 → 409 `code=reference_image_limit`, `max=10`, `remaining=1`, no new row and no new file under `order-references/`; 11 files → 422; file #2 of 3 is a renamed text file built as a REAL temp file (not `UploadedFile::fake()`, which reports MIME from the extension) → 422 `errors["images.1"]`, zero rows, zero stray files; remove id of another order's image → ignored, that image intact; remove only image of note-less reference via PUT → 422 `reference_empty`; forced exception after file write (bind a throwing `ActivityLogger` mock) → no rows, no files; non-owner/admin cashier who is not the sale's cashier → 403; voided sale still editable; amounts/stock movements/payments unchanged. Use `Storage::fake('local')` except where a real temp file is needed for sniffing
- [X] T005 [P] Write read/serve tests in `tests/Feature/OrderReferenceImagesTest.php` (same file, separate section): `GET /api/v1/orders/{A}/reference/images/{imageOfB}` → 404 (scoped binding); user without `sales` menu → 403; order of the other DEMO/LIVE mode → 404; removed image → 404; file missing on disk → 404 `reference_image_missing`; 200 carries stored `Content-Type` and `Cache-Control: private, no-store`; `GET /orders/{id}` `reference` block === `GET /orders/{id}/reference` block (keys `note, images, image_count, has_image, max_images, updated_at, updated_by_name, can_edit`); old route `GET /orders/{id}/reference/image` → 404
- [X] T006 [P] Write list-flag test in `tests/Feature/OrderReferenceImagesTest.php`: `GET /api/v1/reports/sales` `transactions[].has_reference` true for image-only reference, false for none; query count with 1 vs 10 referenced orders is equal (warm-up call first — CLAUDE.md 048 note about phantom first-request queries)
- [X] T007 [P] Rewrite `tests/Feature/OrderReferenceTest.php` from the 054 single `image`/`remove_image` contract to `images[]`/`remove_image_ids[]` and the per-image route, keeping every 054 rule it pins (note ≤1000, `''` clears note, empty result 422, DELETE clears all, activity-log actions `order_reference_updated`/`order_reference_removed`, permissions)

### Implementation

- [X] T008 Create migration `database/migrations/2026_11_22_000001_create_order_reference_images_table.php` per data-model.md (table, `(order_id, position)` index, FK `cascadeOnDelete`, `uploaded_by` `nullOnDelete`; copy existing images with `DB::table()` across BOTH modes; drop the two columns; reversible `down()` with an Indonesian comment that extra images are lost on rollback and files are never deleted)
- [X] T009 [P] Create model `app/Models/OrderReferenceImage.php` (no `HasDataMode` — docblock: mode follows the order, see CloneRegistry `via`; `$fillable` path/mime/size_bytes/position/uploaded_by; `belongsTo` `order` and `uploader`)
- [X] T010 Update `app/Models/Order.php`: remove `reference_image_path`/`reference_image_mime` from `$fillable`; add `referenceImages()` (`hasMany`, `orderBy('position')->orderBy('id')`); add `referenceImageCount()` (aggregate attribute `reference_images_count` → loaded relation → one `count()`); `hasReference()` = note not null OR count > 0
- [X] T011 Rewrite `app/Services/OrderReferenceService.php` per data-model.md "Save algorithm": constants `MAX_IMAGES = 10`, `MAX_IMAGE_KILOBYTES = 5120`; `save(Order, array $input, array $images, User)` with `remove_image_ids` delta under `Order::lockForUpdate()`, 409 via a typed exception or `ValidationException::status(409)` carrying `code/max/remaining`, per-file MIME sniff → 422 `images.N`, positions `max+1…`, one `ActivityLogger` row (`old: {note, image_count}`, `new: {note, image_count, images_added, images_removed}`), removed files deleted AFTER commit, new files deleted on any exception; `delete()` removes all image rows + files (after commit); new `present(Order, ?User): array` with `loadMissing(['referenceImages','referenceEditor'])` returning the contract block incl. `max_images`. Update the class docblock (055: multiple images, delta semantics, why no stored thumbnails)
- [X] T012 Update `app/Http/Requests/UpsertOrderReferenceRequest.php`: `note` unchanged; `images` `array|max:{MAX_IMAGES}`; `images.*` `file|mimes:jpeg,png,webp|max:{MAX_IMAGE_KILOBYTES}`; `remove_image_ids` `array`; `remove_image_ids.*` `integer`; read limits from `OrderReferenceService` constants; remove `image`/`remove_image`
- [X] T013 Update `app/Http/Controllers/Api/OrderController.php`: `referenceBlock()` delegates to `OrderReferenceService::present()`; `saveReference()` passes `$request->file('images', [])` and `remove_image_ids`; map the limit conflict to 409 `{message, code, max, remaining}`; replace `referenceImage()` with `referenceImage(Request, Order, OrderReferenceImage $image)` (same `canAccessMenu('sales')` gate, 404 when file missing, stored mime, `private, no-store`)
- [X] T014 Update `routes/api.php` (around line 240): replace `GET /orders/{order}/reference/image` with `Route::get('/orders/{order}/reference/images/{image}', …)->scopeBindings()`
- [X] T015 [P] Update `app/Http/Resources/OrderResource.php`: `reference` ← `OrderReferenceService::present($this->resource, $request->user())` (delete the inline duplicate block and its comment)
- [X] T016 [P] Update `app/Services/SalesTransactionsService.php` (query near line 43): add `->withCount('referenceImages')` so `has_reference` costs no per-row query
- [X] T017 [P] Update `app/Services/DemoClone/CloneRegistry.php`: remove `'files' => ['reference_image_path' => 'local']` from `orders` (and its 054 comment); add `order_reference_images` right after `orders` with `fks => ['order_id' => 'orders']`, `files => ['path' => 'local']`, `required_files => ['path']`, `via => 'orders.order_id'` and an Indonesian comment (055 — copied, never shared)
- [X] T018 [P] Add the limit message to `lang/en/orders_payments.php` and `lang/id/orders_payments.php` (`reference_image_limit` with `:max` and `:remaining`; per-file invalid message reused/extended for `images.N`)
- [X] T019 [P] Add a clone test to `tests/Feature/DemoClone/LiveToDemoClonerTest.php`: a LIVE order with 2 reference images → DEMO order has 2 rows whose `path`s are NEW files with identical bytes, never the LIVE paths; a LIVE row whose file is missing is skipped (`required_files`)
- [X] T020 Update `resources/js/api/orders.js`: `getReferenceImageUrl(orderId, imageId)` → `/orders/${orderId}/reference/images/${imageId}` blob URL; `saveReference(orderId, { note, images = [], removeImageIds = [] })` appends `images[]` and `remove_image_ids[]` (keep POST + `_method=PUT`); update the JSDoc (Indonesian)
- [X] T021 Update `docs/openapi-pos-mvp.yaml` (`/orders/{id}/reference` ~line 2046 and `/orders/{id}/reference/image` ~line 2079) per `contracts/order-reference-api.md`: new request fields, 409 `reference_image_limit`, block shape with `images[]`, new path `/orders/{id}/reference/images/{image}`, old path removed
- [X] T022 Run Phase 2 tests and fix until green: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter='OrderReference|DemoClone'` (incl. `CloneRegistryDriftTest`); then `docker compose exec app php artisan migrate` on the dev DB and confirm an existing 054 reference still opens

**Checkpoint**: backend + API module complete; the existing single-image UI still renders (the block keeps `has_image`).

---

## Phase 3: User Story 1 — Thumbnails in the transaction detail (P1) 🎯 MVP

**Goal**: the sale detail shows every reference image as a thumbnail; click opens a full-size gallery.

**Independent test**: a sale migrated from 054 with one image shows one thumbnail in `TransactionItemsModal` with no extra click; clicking opens it full size (quickstart browser checks 1-2, 7-8).

### Tests

- [X] T023 [P] [US1] Write `qa-tests/component/ImageLightbox.test.js`: with `src` only → no prev/next, behaves as before; with `images` (3) + `index` → shows `2 / 3`, next/prev buttons and ←/→ update `update:index`, wraps or stops at the ends (pick one and assert it), Escape/close emits `close`; single-item `images` → no navigation
- [X] T024 [P] [US1] Write `qa-tests/component/ReferenceThumbnails.test.js` (mock `getReferenceImageUrl`): renders one `data-testid="reference-thumbnail"` per image in order; never more than 3 fetches in flight; a rejected fetch shows the placeholder (`sales_reference.image_unavailable`) while others render; click opens the lightbox at that index; blob URLs revoked on unmount and when `images` changes
- [X] T025 [P] [US1] Update `qa-tests/component/SalesReferenceLink.test.js` (and any `TransactionItemsModal` test) so a reference with `images` shows thumbnails inside the reference card, a note-only reference shows no thumbnail area, and no reference keeps today's "Add reference" behaviour

### Implementation

- [X] T026 [US1] Extend `resources/js/components/ui/ImageLightbox.vue` with opt-in gallery: props `images: Array` (`{src, alt}`), `index: Number` (`v-model:index`); prev/next buttons (Phosphor `ph-caret-left/right`, `aria-label` via `common.previous`/`common.next`), `n / total` text, ←/→ on `window` only while open; existing `src`/`alt` path unchanged (11 callers); update the docblock (055)
- [X] T027 [US1] Create `resources/js/components/sales/ReferenceThumbnails.vue` per contract UI section: props `orderId`, `images`, `removable` (default false), `markedForRemoval` (Set); fixed-size square thumbnails (`object-cover`, token classes, `flex-wrap`), placeholder icon on failure, fetch queue concurrency 3, revoke on unmount/change, opens `ImageLightbox` gallery; when `removable`, a per-image remove/undo toggle emitting `toggle-remove(id)` (used by US3)
- [X] T028 [US1] Update `resources/js/components/sales/TransactionItemsModal.vue` (reference card ~line 324): render the note as today plus `<ReferenceThumbnails :order-id :images>` when `order.reference.image_count > 0`; remove the text-only "image attached" line; keep the View/Add button; after `handleReferenceSaved` the thumbnails reflect the new `images`
- [X] T029 [P] [US1] Add locale keys to `resources/js/locales/en.json` and `id.json`: `sales_reference.images_title`, `sales_reference.image_unavailable`, `common.previous`, `common.next` (only if missing — check first); run `npx vitest run --exclude '.claude/**' qa-tests/unit/localeKeys.test.js`
- [X] T030 [US1] Run US1 tests green: `npx vitest run --exclude '.claude/**' qa-tests/component/ImageLightbox.test.js qa-tests/component/ReferenceThumbnails.test.js qa-tests/component/SalesReferenceLink.test.js`

**Checkpoint**: US1 deliverable on its own (existing single images now visible as thumbnails).

---

## Phase 4: User Story 2 — Attach several images to one sale (P1)

**Goal**: select several images at once, see which are refused and why, save them in one go up to the limit.

**Independent test**: on a sale without a reference, pick 3 valid images in one selection, save → 3 thumbnails; add a 4th later → 4 thumbnails, first three unchanged (quickstart browser checks 1, 3, 4, 6).

### Tests

- [X] T031 [P] [US2] Update `qa-tests/component/SalesReference.test.js` for `OrderReferenceModal`: input has `multiple` and the three accepted types; picking 3 valid files shows 3 previews and Save calls `saveReference` ONCE with `images` length 3; a `.pdf` and an oversized JPEG among them are listed by name as refused while the valid ones stay (use `userEvent.setup({ applyAccept: false })` — CLAUDE.md 054 trap); remaining-slots text uses `max_images` from the payload; picking more than the remaining slots keeps only what fits and shows `limit_reached`; a 409 `reference_image_limit` response shows its message and keeps the dialog open; edit controls hidden when `can_edit` is false
- [X] T032 [P] [US2] Add a concurrency-shaped backend test to `tests/Feature/OrderReferenceImagesTest.php`: two sequential PUTs built from the same stale GET (user A adds 1, user B adds 1) → both images stored (delta semantics, FR-016); with 9 stored, A adds 1 then B adds 1 → B gets 409 `remaining=0`

### Implementation

- [X] T033 [US2] Update `resources/js/components/sales/OrderReferenceModal.vue`: replace single `pickedFile/pickedPreview/removeImage` state with `pickedFiles[]` (+ preview URLs, revoked on remove/close/unmount); `<input type="file" multiple accept="image/jpeg,image/png,image/webp">`; per-file client check (type/size, `MAX_BYTES` stays 5 MB) collecting refused names into one message (`sales_reference.file_refused`); cap by `max_images − (image_count − marked + picked)` with `sales_reference.remaining_slots` / `limit_reached`; show stored images via `ReferenceThumbnails` (read-only in this story) and picked previews beside them with a per-file "remove from selection"; Save sends ONE `saveReference({ note?, images })`; map 409 `reference_image_limit` through the central error handler (message shown, dialog stays open); `canSave`/`dirty`/`somethingLeft` recomputed for arrays; emit `saved` with the returned block
- [X] T034 [P] [US2] Add locale keys to `resources/js/locales/en.json` and `id.json`: `sales_reference.add_images`, `sales_reference.remaining_slots` (with `{count}`), `sales_reference.limit_reached` (with `{max}`), `sales_reference.file_refused` (with `{names}`); remove keys that became unused (e.g. a single-image "replace" label) only if no other caller uses them; run `qa-tests/unit/localeKeys.test.js`
- [X] T035 [US2] Run US2 tests green: `npx vitest run --exclude '.claude/**' qa-tests/component/SalesReference.test.js qa-tests/unit/localeKeys.test.js` and `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=OrderReferenceImagesTest`

**Checkpoint**: US1 + US2 work together; images can be added several at a time.

---

## Phase 5: User Story 3 — Remove one image without losing the others (P2)

**Goal**: mark specific stored images for removal (undoable until Save); save removes only those.

**Independent test**: on a sale with 3 images, remove the 2nd, save → images 1 and 3 remain in order, note unchanged; close without saving → nothing removed (quickstart browser check 5).

### Tests

- [X] T036 [P] [US3] Extend `qa-tests/component/SalesReference.test.js`: toggling remove on image #2 marks it (visual state + undo), closing without save sends nothing; Save sends `removeImageIds: [id2]` together with any new `images` in ONE call; marking the last image of a note-less reference (and no picked files, empty note) routes Save to the existing delete confirmation (`deleteReference`) instead of PUT; remaining-slots count goes up when an image is marked
- [X] T037 [P] [US3] Add backend assertions to `tests/Feature/OrderReferenceImagesTest.php` (if not already covered by T004): remove #2 of 3 → rows #1,#3 keep positions 1 and 3 (gaps, never renumbered), file #2 gone after the response, file #1/#3 intact; activity log `images_removed: 1`; removing an already-removed id alongside a new image → 200, only the new image added

### Implementation

- [X] T038 [US3] Update `resources/js/components/sales/OrderReferenceModal.vue`: pass `removable` + `markedForRemoval` (Set of ids) to `ReferenceThumbnails`, handle `toggle-remove`; include `removeImageIds` in the single save; "nothing left" (no note, no remaining stored image, no picked file) opens the existing delete `ConfirmDialog` instead of saving; marks reset on close/reload
- [X] T039 [P] [US3] Add locale keys `sales_reference.remove_image`, `sales_reference.undo_remove` to `resources/js/locales/en.json` and `id.json`; run `qa-tests/unit/localeKeys.test.js`
- [X] T040 [US3] Run US3 tests green: `npx vitest run --exclude '.claude/**' qa-tests/component/SalesReference.test.js qa-tests/component/ReferenceThumbnails.test.js` and `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=OrderReference`

**Checkpoint**: all three stories complete.

---

## Phase 6: Polish & Cross-Cutting

- [X] T041 Add a dated 055 note to `CLAUDE.md` inside the "POS layout, Sales upgrades…(feature 054)" section's "Transaction reference" bullet: now up to 10 images in `order_reference_images` (no data_mode, CloneRegistry `via`), delta PUT (`images[]`/`remove_image_ids[]`), 409 `reference_image_limit`, per-image route with `scopeBindings`, ONE presenter `OrderReferenceService::present()`, the old columns are gone, reference images are still NOT in backups
- [ ] T042 (PARTIAL: frontend full suite green; backend full run incomplete — see quickstart Results) Run the full suites one at a time and record results in `specs/055-transaction-reference-images/quickstart.md`: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test` and `npx vitest run --exclude '.claude/**'` (known unrelated failures from earlier sessions, e.g. `Code128Test`, must be named, not hidden)
- [X] T043 `npm run build` and confirm no new chunk/size regression in the build output for the Sales view (no new dependency expected)
- [ ] T044 (NOT RUN: no browser tool available) Real-browser verification per `quickstart.md` "Real-browser checks" 1-10 against the running app (`kasir01`/`kasir02`, local dev only), DevTools console clean, Network shows per-image URLs with `Cache-Control: private, no-store` and ≤ 3 concurrent image requests; record outcomes in `quickstart.md`
- [X] T045 Confirm the Android POS client (083) does not call `/orders/{id}/reference*` (research Decision 4 ASSUMPTION); if it does, accept `image` as an alias of `images[0]` in `app/Http/Requests/UpsertOrderReferenceRequest.php` and record the outcome in `research.md`
- [X] T046 Security self-review of the diff (access control: scoped binding + `canAccessMenu('sales')` + `mayEdit`; file handling: private disk, content sniffing, UUID names, cleanup on rollback; no PII or file names in activity log) and note findings in `specs/055-transaction-reference-images/plan.md` "Known gaps"

---

## Dependencies & Execution Order

```
Phase 1 (T001-T002)
   └─▶ Phase 2 tests (T003-T007, parallel) ─▶ Phase 2 impl (T008 → T009/T010 → T011 → T012-T014; T015-T021 parallel after T011) ─▶ T022
          └─▶ US1 (T023-T030) ─▶ US2 (T031-T035) ─▶ US3 (T036-T040) ─▶ Polish (T041-T046)
```

- **US1** depends only on Phase 2.
- **US2** depends on Phase 2; it reuses `ReferenceThumbnails` from US1 (T027) for stored images — if US2 is built first, T033 must create that component.
- **US3** depends on US2's array state in `OrderReferenceModal.vue` (T033) and the `removable` mode of `ReferenceThumbnails` (T027).
- Within Phase 2: T008 before every other implementation task; T011 before T012/T013/T015; T020/T021 can start once T011's contract is fixed.

## Parallel Opportunities

- Phase 2 tests: T003, T004/T005/T006 (same file — write as one task per section by one person, or split files), T007 in parallel.
- Phase 2 impl after T011: T015, T016, T017, T018, T019, T020, T021 touch different files → parallel.
- US1: T023, T024, T025, T029 in parallel; T026 and T027 can be parallel (T027 imports T026's props — agree the API first).
- Each story's locale task ([P]) runs alongside its component work.

### Parallel example (US1)

```text
Task: "T023 [US1] ImageLightbox gallery test in qa-tests/component/ImageLightbox.test.js"
Task: "T024 [US1] ReferenceThumbnails test in qa-tests/component/ReferenceThumbnails.test.js"
Task: "T025 [US1] TransactionItemsModal reference card test in qa-tests/component/SalesReferenceLink.test.js"
Task: "T029 [US1] locale keys in resources/js/locales/en.json + id.json"
```

## Implementation Strategy

- **MVP = Phase 1 + Phase 2 + US1**: after the migration, every existing 054 image shows as a thumbnail in the sale detail with a full-size view. The existing single-file upload path in `OrderReferenceModal` must at least keep working against the new API during this stage (T020 sends one file as `images[0]`).
- **Increment 2 = US2**: multiple selection, per-file refusal, limit.
- **Increment 3 = US3**: per-image removal.
- **Then Polish**: CLAUDE.md note, full suites, build, real-browser check, Android confirmation, security self-review. Do not report "done" before T042 and T044 have evidence from the current session (Constitution II).
