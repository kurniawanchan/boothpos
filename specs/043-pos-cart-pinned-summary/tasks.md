# Tasks: POS Cart Summary Stays Visible

**Input**: `specs/043-pos-cart-pinned-summary/` (plan.md, spec.md, research.md, data-model.md, quickstart.md)

**Tests**: INCLUDED where they are cheap and automatic: a shell component test and a cart layout test (Vitest). A real browser check at booth sizes is required by Constitution Principle II, because the POS screen is interacted with; those tasks are marked manual.

**Test commands**: `npm test` (Vitest, `qa-tests/`). Browser checks follow `quickstart.md` §2 (run `npm run build` first, use DEMO mode).

**Organization**: by user story, P1 first (US1, US2), then P2 (US3). The route flag is Foundational because the shell and the POS screen both depend on it.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: different files, no dependency on an incomplete task
- **[Story]**: US1–US3 map to spec.md user stories

---

## Phase 1: Setup

- [X] T001 Baseline: run `npm test` before any change and record the pass count (`qa-tests/component/AppShell.test.js`, `qa-tests/`)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: one place declares that the POS screen needs a fixed height (plan Design §1). Blocks US1 and US2.

- [X] T002 Add `fullHeight: true` to the POS route `meta` (`meta: { menuKey: 'pos', fullHeight: true }`) in `resources/js/router/index.js`; no other route changes

**Checkpoint**: the flag exists and only the POS route carries it.

---

## Phase 3: User Story 1 — Pay button always in view (Priority: P1) 🎯 MVP

**Goal**: on the POS route, the app shell runs at viewport height and `<main>` is the only scroll area, so the cart summary stays on screen.
**Independent Test**: with the POS route active, the shell's outer element has the fixed-height class and the content column can shrink; other routes keep the previous classes.

- [X] T003 [US1] In `resources/js/components/layout/AppShell.vue`, add `const fullHeight = computed(() => route.meta.fullHeight === true)` (import `useRoute` and `computed` if not already present)
- [X] T004 [US1] In `resources/js/components/layout/AppShell.vue`, the outer `div` uses `h-dvh` when `fullHeight` and keeps `min-h-screen` otherwise (`:class` binding)
- [X] T005 [US1] In `resources/js/components/layout/AppShell.vue`, the content column (`flex min-w-0 flex-1 flex-col`) adds `min-h-0` when `fullHeight`; `<main class="min-h-0 flex-1 overflow-auto">` keeps its classes
- [X] T006 [US1] Test in `qa-tests/component/AppShell.test.js`: with a route whose `meta.fullHeight` is true, the outer element has `h-dvh`; with a route without the flag, it has `min-h-screen` and not `h-dvh`
- [X] T007 [US1] Verify `resources/js/views/PosView.vue` root (`flex h-full`) fills `main` at runtime; change nothing unless it does not stretch (then fix only that class)

**Checkpoint**: US1 layout verified in the test; the browser check is US3.

---

## Phase 4: User Story 2 — Product list and cart scroll on their own (Priority: P1)

**Goal**: the product grid and the cart item list scroll independently; the cart summary does not move.
**Independent Test**: the cart item list has `overflow-auto` and `flex-1`; the summary block has `flex-none`.

- [X] T008 [US2] Verify in `resources/js/components/pos/PosCartPanel.vue` that the summary block (discount, subtotal, total, Pay button, status message) is a non-shrinking sibling below the item list (`flex-none` or equivalent); add `flex-none` to that block only if it is missing
- [X] T009 [P] [US2] Add test `qa-tests/component/PosCartPanel.test.js`: the item list container has `overflow-auto` and `flex-1`, and the summary container has `flex-none`, when the panel renders with one cart line

**Checkpoint**: US2 testable on its own.

---

## Phase 5: User Story 3 — Works on the booth devices (Priority: P2)

**Goal**: at tablet-landscape and laptop sizes with many products, the Pay button is visible without page scroll and there is no horizontal scroll; other screens still scroll the page.
**Independent Test**: quickstart §2 steps 1–8.

- [X] T010 [US3] Manual browser check: `npm run build`; open POS in DEMO mode at `1180×820` and `1366×768` with 50+ products (or a shorter window); confirm discount, subtotal, total, Pay button and status message are visible without page scroll (quickstart §2.1–3)
- [X] T011 [US3] Manual browser check: scroll the product grid and then 10+ cart lines; the summary does not move (quickstart §2.4–5)
- [X] T012 [US3] Manual browser check: no horizontal scroll and no document-level vertical scroll on POS; on the Products screen (`/products`) the page still scrolls as before (quickstart §2.6–7)
- [X] T013 [US3] Manual check: the sidebar collapse/expand animation and the top bar's "Show sidebar" button still work on POS (`resources/js/components/layout/AppShell.vue`)
- [X] T014 [US3] Manual check: payment modal (`resources/js/components/payment/PosPaymentModal.vue`) and the variant picker (`resources/js/components/pos/ProductVariantPickerModal.vue`) still open centred over the fixed shell and close correctly

---

## Phase 6: Polish & cross-cutting concerns

- [X] T015 Run full `npm test`; compare with T001's baseline; all pass including T006 and T009
- [X] T016 Run `npm run build`; confirm it succeeds
- [X] T017 Manual: browser console is clean (no errors) on POS and on Products after the change (quickstart §2.8)
- [X] T018 Confirm `CLAUDE.md` SPECKIT block points to `specs/043-pos-cart-pinned-summary/plan.md` (done in the plan step; verify only)

---

## Dependencies & Execution Order

- **Setup (T001)** → **Foundational (T002)** → US1 (T003–T007) and US2 (T008–T009).
- **US1**: T003 → T004 → T005 (same file, sequential); T006 after T003–T005; T007 after T002.
- **US2**: T008 then T009 (T009 `[P]` relative to US1 because it is a different file).
- **US3 (T010–T014)**: after US1 and US2 (needs the working layout); manual.
- **Polish (T015–T018)**: last.

### Parallel opportunities
- T009 (cart test, `qa-tests/component/PosCartPanel.test.js`) can run alongside US1 work (different file).
- T006 and T009 can be written together, then run once.

---

## Implementation Strategy

### MVP (this feature)
1. T001 → T002 → T003–T006 (US1): the POS shell is viewport-height, with a test.
2. T008–T009 (US2): verify the cart summary is pinned.
3. US3 manual checks (T010–T014) before merge. Do not skip them: the change is layout-only, so only a browser shows whether the total and Pay button are visible.

### Totals
- 18 tasks: 1 done ([X] T018 verify-only), 17 open.
- Per story: Setup 1, Foundational 1, US1 5, US2 2, US3 5, Polish 4.

---

## Notes
- Every task names an exact file path.
- The top bar stays pinned on POS (research Decision 4); confirm during T012.
- Commit only when asked. The branch is stacked on `050-product-segment-1-3`.
