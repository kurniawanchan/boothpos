---

description: "Task list for Pre-order Invoice/Shipping Progress, Print Menu & List Refinements"

---

# Tasks: Pre-order Invoice/Shipping Progress, Print Menu & List Refinements

**Input**: Design documents from `/specs/025-preorder-dispatch-status-list-refinements/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Included — Constitution II. Backend tests run via `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=<Test>` (never without the `-e` flags — see CLAUDE.md).

**Note**: Written after implementation (the work was requested and built in the same session), so tasks are recorded as delivered; the single open item is real-browser verification.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Foundational (blocking)

- [X] T001 Migration `database/migrations/2026_10_28_000001_add_dispatch_status_to_preorders_table.php` — `dispatch_status` enum, default `pending`.
- [X] T002 Migration `database/migrations/2026_10_29_000001_add_dispatch_timestamps_to_preorders_table.php` — `invoice_sent_at`, `shipping_at`.
- [X] T003 `app/Models/Preorder.php` — fillable, `datetime` casts, `DISPATCH_STATUSES`, `$attributes` default (research.md Decision 2).
- [X] T004 Apply both migrations to the dev database (`php artisan migrate --force` in the `app` container).

---

## Phase 2: US1 + US2 — Manual marker, filter, Mail Order rule (P1) 🎯 MVP

- [X] T005 [US1] `PATCH /preorders/{preorder}/dispatch-status` — route in `routes/api.php`; `PreorderController::updateDispatchStatus()` validating against `DISPATCH_STATUSES`, 409 for cancelled, response via `present()` with `show()`'s eager-loads.
- [X] T006 [US2] Same action: 409 for `shipping` on a non-courier pre-order.
- [X] T007 [US2] `PreorderService::update()` — edit off courier downgrades `shipping` → `invoice_sent` and clears `shipping_at`.
- [X] T008 [US1] `applyFilters()` — repeatable `dispatch_status` filter; `present()` and `index()` rows expose `dispatch_status`.
- [X] T009 [P] [US1] Backend messages `dispatch_status_cancelled`, `dispatch_status_shipping_mail_order_only` in `lang/{en,id}/preorders.php`.
- [X] T010 [P] [US1] `tests/Feature/PreorderDispatchStatusTest.php` — default `pending` (incl. the create response), forward/back without touching status/stock, relations still present, 422 on bad value, 409 cancelled, filter (one/many), 409 shipping on pickup, edit-to-pickup downgrade, list rows expose the field.
- [X] T011 [P] [US1] `docs/openapi-pos-mvp.yaml` — new path, `dispatch_status` list filter, behaviour notes.
- [X] T012 [US1] `resources/js/api/preorders.js` — `updatePreorderDispatchStatus()`.
- [X] T013 [US1] `PreordersView.vue` — `DISPATCH_LABEL/VARIANT`, `dispatchFilter` + `BaseMultiSelect`, list column + cell (fallback `pending`), detail card with a button group and `setDispatchStatus()` (refreshes the list).
- [X] T014 [US2] `PreordersView.vue` — `detailDispatchOptions` hides `shipping` unless the pre-order is Mail Order; buttons disabled for cancelled.
- [X] T015 [P] [US1] Locale keys in `resources/js/locales/{en,id}.json` (status labels, title, note, filter, column, toasts).
- [X] T016 [P] [US1] Vitest (`qa-tests/component/PreordersView.test.js`) — mark as sent + list refresh, no call on re-click, cancelled disabled, Mail Order only, list filter call.

**Checkpoint**: marker works end to end.

---

## Phase 3: US3 — Print menu (P2)

- [X] T017 [US3] `resources/js/components/preorder/PreorderPrintMenu.vue` — dropdown, click-outside/Escape, `hasPayment` disables the payment item.
- [X] T018 [US3] `PreordersView.vue` — place it in the status card header; wire `openInvoice(detail)` and `openPaymentReceipt(lastPaymentId)`.
- [X] T019 [P] [US3] Vitest — both items shown, payment item disabled/enabled by payments.

---

## Phase 4: US4 — List row fixes and Actions column (P2)

- [X] T020 [US4] Customer name button gets `text-left` (a `<button>` centers text by default).
- [X] T021 [US4] `openPaymentReceipt(paymentId, preorderId = detail.value?.id)` fix (research.md Decision 6).
- [X] T022 [US4] `resources/js/components/preorder/PreorderRowActions.vue` — inline ≤ 3, otherwise `Detail` + teleported "More" menu.
- [X] T023 [US4] `PreordersView.vue` — `rowActions(row)` / `onRowAction(row, key)`; "Actions" column title; disabled payment item without payment.
- [X] T024 [US4] Update existing edit/delete row-action tests to open the menu; add tests (inline vs menu, Detail stays visible, headers, left alignment, list-row payment invoice for that row's id).

---

## Phase 5: US5 + US6 — Dates (P3 / P2)

- [X] T025 [US6] `updateDispatchStatus()` derives `invoice_sent_at` / `shipping_at` from the target state (research.md Decision 4); client dates ignored.
- [X] T026 [US5] `updated_at` in `index()`/`present()`; `created_at`/`updated_at` added to `applySort()`.
- [X] T027 [P] [US6] Backend tests — dates follow the target state and ignore client input, re-marking keeps the date, edit-to-pickup clears the shipping date, list/detail expose all four dates and sort by them.
- [X] T028 [US5][US6] `PreordersView.vue` — "Created"/"Updated" columns and cells; dates inside the status cell; detail shows created/updated and the two marker dates.
- [X] T029 [P] Locale keys (`col_actions`, `col_created`, `col_updated`, `row_more_actions`, `detail_created_label`, `detail_updated_label`, `dispatch_invoice_sent_on`, `dispatch_shipping_on`) — after a duplicate-key scan (research.md Decision 8).
- [X] T030 [P] Vitest — created/updated cells, dates inside the status cell, detail shows all dates.

---

## Phase 6: US7 — Export / import (P2)

- [X] T031 [US7] `PreorderExportImportService` — shared columns + `EXPORT_ONLY`, ISO 8601 export, template row.
- [X] T032 [US7] Export filters accept scalar or array for `status` / `fulfillment` / `dispatch_status`; controller forwards `dispatch_status`.
- [X] T033 [US7] Import validation + fill rules + `parseDispatchDate()` (ISO or Excel serial, normalised to the app timezone); create with the three fields.
- [X] T034 [P] [US7] Messages `import_dispatch_*` in `lang/{en,id}/preorders.php`.
- [X] T035 [P] [US7] Tests in `PreorderExportImportTest.php` — columns present and ordered, template excludes read-only columns, array/scalar filters (incl. HTTP), import sets status and dates with offset conversion, blank = pending, fill-with-now, six rejection cases, case/space tolerant status, full-export round trip.
- [X] T036 [P] [US7] `docs/openapi-pos-mvp.yaml` — export/import description and filter types.

---

## Phase 7: Polish & verification

- [X] T037 Full frontend suite (`npx vitest run`) — 291 passed, 2 skipped (pre-existing).
- [X] T038 Backend `--filter='Preorder|Shipment|Report'` — 210 passed.
- [X] T039 `npm run build`.
- [ ] T040 Run quickstart.md in a real browser (Constitution II) — **open**.
- [ ] T041 Commit on a `025-preorder-dispatch-status-list-refinements` branch — **open** (nothing is committed; the tree also holds earlier uncommitted work from feature 024 follow-ups).

---

## Dependencies & Execution Order

- Phase 1 blocks everything. Phases 2–6 then follow the stories; US6's dates (T025) depend on T005; US7 (Phase 6) depends on Phases 1 and 5 (the fields it exports).
- Within a phase, tasks marked [P] touch different files.

## Notes

- Known gaps, deliberately out of scope: the export still ignores the list's seller (`artist_id`) filter; exported timestamps are UTC (no store-timezone setting exists).
