---

description: "Task list for 056 — Sales payment proof visibility & list column refinements"
---

# Tasks: Sales Payment Proof Visibility & List Column Refinements

**Classification**: INTERNAL

**Input**: Design documents from `/specs/056-sales-payment-proof-column/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/sales-list-row.md, quickstart.md

**Tests**: INCLUDED — constitution II requires tests for every change plus a real-browser check for user-facing screens.

**Organization**: grouped by user story (spec.md). Comments, docs and UI copy follow the project convention (Indonesian code comments; UI copy via en + id locale keys).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: different files, no dependency on an incomplete task
- `SalesView.vue` is edited by US2–US6 → those edits are sequential (no [P] between them)
- Backend tests inside the `app` container ONLY with `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test …` (never bare — it wipes the dev DB)

## Phase 1: Setup

- [X] T001 Confirm baseline is green before touching code: `npx vitest run --exclude '.claude/**' qa-tests/component/Sales* qa-tests/component/PaymentHistoryList.test.js qa-tests/component/TransactionItemsModal.test.js` and the backend `--filter=SalesTransactionsTest` / `SalesPageDataTest` (container, with the `-e` overrides above); note any pre-existing failure so it is not blamed on this feature
- [X] T002 [P] Confirm `sales_reference.link` is used only by `resources/js/views/SalesView.vue` (grep `resources/js` and `qa-tests`) and list the existing tests that assert the old "Reference"/"Referensi" text (expected: `qa-tests/component/SalesReferenceLink.test.js`, `SalesReference.test.js`, `SalesReferenceAndMethod.test.js`) so US3 updates them

## Phase 2: Foundational (blocks US1 and US2)

**Purpose**: the pieces both proof stories share.

- [X] T003 [P] Create `resources/js/components/payment/PaymentProofThumbnail.vue`: props `proofId` (required); fetches `getPaymentProofBlobUrl(proofId)` from `resources/js/api/payments.js`; shows a loading skeleton, then a small `<img>` button that emits `open`; on failure shows a neutral "unavailable" placeholder (no broken image); revokes the object URL on unmount and when `proofId` changes (refetch); drops a stale response if `proofId` changed mid-flight (same pattern as `ReferenceThumbnails.vue`); token classes only, no hex
- [X] T004 [P] Add locale keys in BOTH `resources/js/locales/en.json` and `resources/js/locales/id.json` (the `localeKeys.test.js` guard fails otherwise): `payment_proof.link` ("Payment Proof"), `payment_proof.title`, `payment_proof.unavailable`, `payment_proof.loading`, `payment_proof.load_failed`, `payment_proof.none`, `payment_proof.thumb_alt` (with method/amount interpolation as needed); reuse `common.retry`
- [X] T005 [P] Write `qa-tests/component/PaymentProofThumbnail.test.js` (mock `api/payments`): renders image after load; click emits `open`; failed load shows placeholder and no `<img>`; `proofId` change refetches and revokes the previous URL; unmount revokes the URL

**Checkpoint**: shared thumbnail + copy ready.

## Phase 3: User Story 1 — Payment proof thumbnail in the transaction detail (P1) 🎯 MVP

**Goal**: each non-cash payment with a viewable current proof shows a thumbnail in the sale detail; click opens the existing full-size viewer.

**Independent Test**: open a sale with a non-cash payment + proof → thumbnail present, click opens full size; no-proof payment keeps "No proof yet"; cash shows nothing; unviewable proof shows nothing.

- [X] T006 [P] [US1] Extend `qa-tests/component/PaymentHistoryList.test.js`: with `proofThumbnails` true + `showProof` true, an entry with `proof_id` and `can_view_proof !== false` renders a thumbnail; click emits `view-proof` with the payment; entry with `can_view_proof: false`, no `proof_id`, or cash renders none; "No proof yet" pill unchanged; with the prop OFF (default, Pre-orders) nothing new renders
- [X] T007 [P] [US1] Extend `qa-tests/component/TransactionItemsModal.test.js`: the sale detail passes thumbnails on, clicking a thumbnail opens the existing proof lightbox (`proofLightboxSrc` flow), a failed thumbnail does not break the rest of the modal
- [X] T008 [US1] Edit `resources/js/components/payment/PaymentHistoryList.vue`: add opt-in prop `proofThumbnails` (Boolean, default false); when set and `showProof && canViewProof(p)`, render `PaymentProofThumbnail` keyed by `p.proof_id` inside the entry (wrapping layout, no overflow with many payments); its `open` emits the existing `view-proof`; keep the "View proof" text link; update the file's header comment (why opt-in: shared with Pre-orders, out of scope)
- [X] T009 [US1] Edit `resources/js/components/sales/TransactionItemsModal.vue`: pass `:proof-thumbnails="true"` to `PaymentHistoryList` (line ~338, next to `:show-proof="true"`)

**Checkpoint**: US1 complete and demonstrable on its own.

## Phase 4: User Story 2 — "Payment Proof" link in the Sales list (P1)

**Goal**: sales with a viewable proof show "Payment Proof" under the transaction number; it opens a dialog with every viewable proof; the list loads no images.

**Independent Test**: list row with `payment_proof_count > 0` shows the link; 0/absent shows none; clicking lists each proof (method + amount) with thumbnails and a gallery.

- [X] T010 [P] [US2] Write `tests/Feature/SalesPaymentProofListTest.php` (RED first): `payment_proof_count` on `GET /reports/sales` rows — owner sees count of non-cash payments with a current proof; the cashier who recorded sees it; an unrelated cashier gets 0 for proofs they may not view; superseded proof excluded (replaced proof counts once); cash payment excluded; voided sale still counted; two proofs → 2; sale without payments → 0; the field is also present in `detail` build used by the export but the export columns are unchanged (`SalesTransactionsExportTest` stays green); query count does not grow with row count (warm-up call first, then compare 3 vs. 10 sales). Never name a helper `put`/`seed`/`get`/`call`
- [X] T011 [US2] Edit `app/Services/SalesTransactionsService.php`: add `'payments.proofs'` to the `with([...])` in `build()` (line ~48) and add `'payment_proof_count'` to `orderRow()`, computed with `$order->payments`, `method !== 'cash'`, `currentProof()`, `proofViewableBy($user, $current)`; `orderRow()` therefore needs the `User` (pass it from `build()`); one comment explaining why it is permission-aware and count-only (no ids/urls in the list)
- [X] T012 [P] [US2] Update `docs/openapi-pos-mvp.yaml`: add `payment_proof_count` (integer, minimum 0, "viewable current payment proofs for the requesting user") to the Sales transaction row schema, same commit as T011
- [X] T013 [P] [US2] Write `qa-tests/component/PaymentProofsModal.test.js` (mock `api/orders.getOrder`, `api/payments`): opens → loads the sale; lists only payments with `proof_id` and `can_view_proof`, each labelled with method and amount; thumbnail click opens the gallery (`ImageLightbox` with `images` + `v-model:index`); load failure shows message + Retry (no blank dialog); closes and revokes URLs; stale response after reopen for another sale is dropped
- [X] T014 [US2] Create `resources/js/components/sales/PaymentProofsModal.vue`: props `open`, `orderId`; emits `close`; on open calls `getOrder(orderId)` from `resources/js/api/orders.js`, filters `payments` to non-cash with `proof_id` and `can_view_proof`, renders one `PaymentProofThumbnail` per proof with `paymentMethodLabel` + `formatIDR(amount)`, gallery via `ImageLightbox` (blob URLs from `getPaymentProofBlobUrl`, revoked on close), `loadError` + Retry, empty state `payment_proof.none`; focus trap/Escape like the other modals; a request counter drops stale responses
- [X] T015 [US2] Edit `resources/js/views/SalesView.vue`: in `#cell-order_number` add, next to the Sale Reference button, `<button v-if="row.payment_proof_count > 0" data-testid="payment-proof-link">` with a `ph-receipt`-style duotone icon + `t('payment_proof.link')` (same classes as the reference link, stacked, no overlap in narrow columns); add `paymentProofOrderId` ref and mount `<PaymentProofsModal>` beside `OrderReferenceModal`
- [X] T016 [P] [US2] Write `qa-tests/component/SalesPaymentProofLink.test.js`: link shown only when `payment_proof_count > 0`, hidden for 0/undefined; both links present and distinguishable when a row has a reference and a proof; click opens the modal for that row's id; the list itself never calls `getPaymentProofBlobUrl` (SC-003)

**Checkpoint**: US2 complete (needs T003–T004 from Foundational).

## Phase 5: User Story 3 — Rename "Reference" to "Sale Reference" (P2)

**Goal**: the list link reads "Sale Reference" in en and id, behaviour unchanged.

**Independent Test**: row with a reference shows "Sale Reference" / Indonesian equivalent; click still opens the reference dialog.

- [X] T017 [US3] Change `sales_reference.link` to "Sale Reference" in `resources/js/locales/en.json` and the Indonesian equivalent ("Referensi Penjualan") in `resources/js/locales/id.json`
- [X] T018 [US3] Update the existing assertions that expect the old label (found in T002), e.g. in `qa-tests/component/SalesReferenceLink.test.js`, to the new text; add one assertion per locale that the link text is "Sale Reference" / "Referensi Penjualan"

## Phase 6: User Story 4 — "Verified" status in the Payment column (P2)

**Goal**: a positive "Verified" pill when all of a sale's non-cash payments are verified.

**Independent Test**: all-verified non-cash sale → "Verified"; any pending → "Not verified" only; any rejected → rejected only; cash-only/unpaid → no verification pill.

- [X] T019 [P] [US4] Write `qa-tests/component/SalesVerifiedStatus.test.js`: rows `{payment_state:'verified', payment_methods:['qris']}` → Verified pill (`data-testid="payment-verified"`); `['cash','qris']` + verified → Verified; `['cash']` + verified → none; `pending` → only Not verified; `rejected` → only rejected; `none`/unpaid → none; voided rows unaffected; the existing `pstate` filter and bulk "Mark verified" behaviour unchanged
- [X] T020 [US4] Edit `resources/js/views/SalesView.vue` `#cell-payment_methods` (~line 542): add `<StatusPill v-else-if="row.payment_state === 'verified' && hasNonCash(row)" variant="mint" data-testid="payment-verified">` using the existing `reports.paystate_verified`-style copy (reuse `payment_ledger.verification_verified` if it reads "Verified"; add a key to both locales only if not); define `hasNonCash(row)` (`(row.payment_methods ?? []).some(m => m !== 'cash')`) once in the script block with a comment on why state alone is not enough (cash is born verified)

## Phase 7: User Story 6 — Sale Reference filter (P2)

**Goal**: filter the list by has / no Sale Reference; combines with other filters; URL-synced.

**Independent Test**: choose Has → only rows with `has_reference`; None → only rows without; All → everything; reload restores from `?ref=`; invalid value ignored.

- [X] T021 [P] [US6] Write `qa-tests/unit/useSalesFilters.reference.test.js` (pattern of `useSalesFilters.payment.test.js`): `has`/`none`/`''` filtering on `has_reference` (missing = none); AND with seller and payment-state filters; `activeCount` +1 when set and `reset()` clears it; `toQuery()` emits `ref=has|none` only when set; `applyQuery({ref:'bogus'})` ignored; page resets to 1 when the filter changes; `kpis`, `summary` and `filtered` all follow (SC-008)
- [X] T022 [US6] Edit `resources/js/composables/useSalesFilters.js`: constant `REFERENCE_FILTERS = ['has','none']`; add `reference: ''` to `filters` (reactive init and `reset()`), predicate in the filter function (`has` ⇒ `tx.has_reference === true`, `none` ⇒ not true), include `f.reference !== ''` in `activeCount`, `put('ref', f.reference)` in `toQuery()`, `filters.reference = oneOf(query.ref, REFERENCE_FILTERS)` in `applyQuery()`; keep the sync watcher so the page resets
- [X] T023 [US6] Edit `resources/js/views/SalesView.vue`: add `'ref'` to `OUR_QUERY_KEYS` (line ~92); `referenceOptions` computed (has / none) and a `<BaseSelect v-model="filters.reference" data-testid="filter-reference" :placeholder="t('sales_reference.filter_all')" :options="referenceOptions" />` beside the other single selects (line ~417)
- [X] T024 [P] [US6] Add filter locale keys to BOTH locale files: `sales_reference.filter_all` ("All sale references"), `sales_reference.filter_has` ("Has Sale Reference"), `sales_reference.filter_none` ("No Sale Reference") + Indonesian
- [X] T025 [P] [US6] Extend `qa-tests/component/SalesViewAdvanced.test.js` (or a new `SalesReferenceFilter.test.js`): selecting the control narrows the rendered rows, the "Showing X of Y" line equals the filtered rows, the address query gets `ref`, mounting with `?ref=has` restores it

## Phase 8: User Story 5 — Total before Margin (P3)

**Goal**: owner/admin column order …, Payment, Total, Margin, actions.

**Independent Test**: header order for owner/admin; cashier has no Margin and Total is the last data column; both columns still sort correctly.

- [X] T026 [US5] Edit the `columns` computed in `resources/js/views/SalesView.vue` (~line 168–181): move the `total_amount` entry before the conditional `margin_amount` spread; adjust the explanatory comment
- [X] T027 [P] [US5] Write/extend `qa-tests/component/SalesColumnOrder.test.js`: with margin rows the header texts read `… Payment, Total, Margin, ''`; without margin `… Payment, Total, ''`; clicking each sortable header still sorts by its own data (rows `margin_amount` / `total_amount` fixtures where the orders differ)

## Phase 9: Polish & cross-cutting

- [X] T028 Run the full affected suites: backend `--filter=Sales` (container with `-e` overrides, one run at a time) incl. `SalesTransactionsExportTest`; frontend `npx vitest run --exclude '.claude/**'` (whole suite — `localeKeys.test.js` catches any missing key); fix regressions
- [X] T029 `npm run build` and confirm no new dependency and no initial-bundle growth (no new chunk expected)
- [ ] T030 (OPEN — Playwright and Chrome DevTools MCP failed to connect in the implementing session; run by hand) Real-browser verification per `specs/056-sales-payment-proof-column/quickstart.md` (owner and a non-recording cashier; console clean; en and id; compare the visual form against the screenshots from the request, which were not viewable at spec time); record what was verified, what was not
- [X] T031 [P] Add the dated feature note to `CLAUDE.md` (a short "Sales payment proof, reference filter (feature 056, 2026-11-04)" section: `payment_proof_count` is permission-aware and count-only, Verified pill derivation, opt-in `proofThumbnails`, `ref` filter key) and tick the Active-plan pointer; keep it factual and concise

## Phase 10: User Story 9 — Seller filter statistics count only that seller's items (P1)

**Goal**: with a seller filter on, units / Gross / Net (cards, summary line, export) count only the selected sellers' items. **Independent test**: a sale with item A Rp 50.000 + item B Rp 70.000, filter seller A → gross/net 50.000.
**Note**: `part()` in `useSalesFilters.js` already does this (feature 054) yet the requester still sees whole-transaction figures — reproduce first, do not assume.

- [X] T032 [US9] Reproduce against real/dev data: in the LIVE dev DB (counts only, no personal data) find a sale with items of 2 sellers; load `GET /reports/sales` rows and check `seller_items` keys vs `artist_names` vs the names in `filters.sellers`, for ties such as two sellers with the same name, a blank/deleted seller, rows lacking `seller_items`; record the root cause in `research.md` (Decision 9)
- [X] T033 [P] [US9] Write `qa-tests/unit/useSalesFilters.seller.test.js` (red first): mixed-seller fixture (A 50.000 + B 70.000, plus a sale with only B) — seller A ⇒ `kpis.units/gross/net` = A's only, `summary.units/amount` = A's only, the B-only sale not listed, two sellers selected ⇒ sum with nothing counted twice, no filter ⇒ whole sale, voided sale excluded, `kpis.count` = sales containing A; plus the case found in T032
- [X] T034 [US9] Fix the root cause in `resources/js/composables/useSalesFilters.js` (and `SalesTransactionsService::orderRow()` `seller_items` if the data is wrong); keep Total/cash/non-cash whole-sale; update the comment block; make T033 green
- [X] T035 [P] [US9] Extend `qa-tests/component/SalesViewAdvanced.test.js` (or new `SalesSellerStats.test.js`): selecting a seller changes the four cards and the summary line to the seller-scoped values

## Phase 11: User Story 8 — Full Excel export with images (P1)

**Goal**: one row per sale, every list column for this viewer, reference note + reference images + permitted proof images embedded. **Independent test**: export a selection with a reference sale and a proof sale; the workbook shows both images in their rows.

- [X] T036 [P] [US8] Write `tests/Feature/SalesTransactionsExportTest.php` additions (red first; read the file's current helpers): columns equal the on-screen set in order (Total before Margin, `verification` present); cost/margin only for owner/admin; no phone/email; reference note in the file; with `seller_ids`/`sellers` filter the units/gross/net columns are seller-scoped (US9 parity); a cashier who may not view a proof gets no proof drawing and no trace; unreadable/PDF proof ⇒ export still 200 and the cell says "could not embed"; over the limit ⇒ 422 with the limit, not a partial file
- [X] T037 [US8] Extract a `SalesExportRows` (or extend `SalesTransactionsService`) so list and export share ONE row builder: add `verification` (client rule: all-verified AND a non-cash method), `reference_note`, and per sale the reference image paths and the viewer-permitted current-proof paths (via `Payment::proofViewableBy()`, `payments.proofs` already eager-loaded); never expose paths in the JSON list
- [X] T038 [US8] Create `app/Exports/SalesTransactionsExport.php` modelled on `app/Exports/ProductCatalogExport.php` (read it first): headings from locale (`lang/*`), money as numbers, one row per sale, images placed in the sale's row via drawings with a fixed row height; reuse `CatalogImageThumbnail` (GD ≤160 px, pixel guard) for downscaling; temp files by path with `cleanup()`; non-image/missing ⇒ cell text "could not embed"; no phone/email
- [X] T039 [US8] Edit `ReportController::exportSalesTransactions()` (`app/Http/Controllers/Api/ReportController.php` ~line 296): accept optional `sellers[]`, build through the shared rows, `Excel::raw()` + `cleanup()`; hard limit (config key, e.g. `sales.export_max_images`, default 200 from the measured run — see research D11) ⇒ 422 with the limit; keep `keys` regex and the ignore-keys-outside-filters rule
- [X] T040 [P] [US8] Edit `docs/openapi-pos-mvp.yaml` for `POST /reports/sales/transactions/export` (new `sellers[]` request field, 422 limit response, new columns note); validate the YAML
- [X] T041 [US8] Edit the export call in the SPA (`resources/js/api/reports.js` + the export handler in `resources/js/views/SalesView.vue`): send the active seller names; show the limit message from the 422; add the locale keys (en + id) for new headings/messages; extend the component test that covers export
- [X] T042 [US8] Measure: export 100/300 sales with images in the dev DB (time, peak memory) and write the result + chosen limit in `research.md`; adjust T039's default

## Phase 12: User Story 7 — Show/hide all Sale Reference and Payment Proof in the list (P2)

**Goal**: one toggle expands reference + proof thumbnails on every row of the page. **Independent test**: off ⇒ zero images loaded; on ⇒ page rows show them; off ⇒ collapse.

- [X] T043 [P] [US7] Write `qa-tests/component/SalesExpandAttachments.test.js` (red first): default off and no thumbnail fetch; on ⇒ rows with a reference show note + thumbnails, rows with proofs show proof thumbnails, rows with neither show nothing; off collapses; paging fetches only on-screen rows; a failed image shows the placeholder; a viewer without proof permission never sees a proof; toggle persists across mount via `localStorage` (guarded by try/catch, renders without it)
- [X] T044 [US7] Decide the data path (record in `research.md`, Decision 10) — prefer reusing `GET /orders/{id}/reference` + `GET /orders/{id}` per on-screen row with a small concurrency cap (as `ReferenceThumbnails.vue` does, ≤ 3 in flight) over adding image data to the list payload; only rows with `has_reference` / `payment_proof_count > 0` are fetched
- [X] T045 [US7] Create `resources/js/components/sales/RowAttachments.vue` reusing `ReferenceThumbnails.vue`, `PaymentProofThumbnail.vue` and `ImageLightbox` gallery (single gallery per sale); lazy: loads only when mounted visible
- [X] T046 [US7] Edit `resources/js/views/SalesView.vue`: toggle control beside the filters (`data-testid="toggle-attachments"`, default off, remembered in `localStorage` under a per-viewer key with try/catch), render `RowAttachments` under the transaction number cell only when on; locale keys (en + id) for the label and tooltip

## Phase 13: Polish for US7–US9

- [X] T047 Run the affected suites again (frontend whole suite excluding `.claude/**`; backend `--filter=Sales` in the container with the `-e` overrides, one run at a time); `npm run build`
- [X] T048 Update `CLAUDE.md` section 056 (seller-scoped statistics root cause, export design and limit, toggle data path) and tick the plan pointer
- [X] T049 Fold the new checks into `quickstart.md` (done) — the T030 browser check stays OPEN and now also covers (toggle, export opened in Excel with images, seller filter figures)

## Dependencies & Execution Order

- **Phase 1 → Phase 2 → stories.** Foundational (T003–T005) blocks US1 and US2 only; US3–US6 do not use the thumbnail.
- **US1** and **US2** (both P1) are independent of each other after Phase 2; US2's backend (T010–T012) can run in parallel with all frontend work.
- **US3, US4, US5, US6** are independent of US1/US2 but all edit `SalesView.vue` → do those edits one after another (T015 → T017… order below), not in parallel.
- Suggested `SalesView.vue` edit order: T015 (US2) → T020 (US4) → T023 (US6) → T026 (US5); locale edits T004/T017/T024 are separate files from those but touch the same two locale JSONs → apply sequentially or in one pass.
- TDD inside each story: write the test task first (red), then implement.

- **US9 first** (P1, smallest, and it unblocks US8's seller-scoped export columns): T032 → T033 → T034; T035 parallel.
- **US8** needs T037 before T038/T039; T036 and T040 parallel; T041 after T039.
- **US7** is frontend only and independent of US8/US9, but T046 edits `SalesView.vue` — apply after T041 (also `SalesView.vue`), not in parallel.
- Plan gap: `plan.md` and `research.md` do not yet cover US7–US9; T032, T042 and T044 write those decisions. Run `/speckit-plan` first if a full design pass is wanted.

### Parallel opportunities

- After Phase 1: T003, T004, T005 together.
- US1: T006, T007 together (tests), then T008 → T009.
- US2: T010, T012, T013, T016 together (different files), then T011 → T014 → T015.
- US4 T019, US6 T021/T024/T025, US5 T027 are different test files and can be written in parallel.

## Implementation Strategy

- **MVP = Phase 1 + 2 + US1**: the sale detail thumbnail (frontend only) already delivers the core ask and can ship alone.
- **Increment 2**: US2 (the only backend change, one additive field) completes the proof story.
- **Increment 3**: US3 + US4 + US6 (+ US5) — small label/derivation/filter/layout changes, each independently shippable.
- Stop after any checkpoint to verify in the browser (T030 items per story).

## Notes

- Total tasks: 49 (31 original — 30 done, T030 open — plus US9 4, US8 7, US7 4, Polish 3).
- No migration, no new route, no new dependency (the export reuses the existing Maatwebsite/GD stack).
- Permission is decided server-side only (`Payment::proofViewableBy()`); the SPA never infers it from the role.
