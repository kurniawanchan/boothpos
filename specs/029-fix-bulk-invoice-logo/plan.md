# Implementation Plan: Correct Images on Downloaded Invoices

**Branch**: `029-fix-bulk-invoice-logo` | **Date**: 2026-10-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/029-fix-bulk-invoice-logo/spec.md`

## Summary

The bulk-downloaded pre-order invoice PDFs show the payment QR image where the store logo belongs. Root cause (confirmed in code and in html2canvas 1.4.1's source): `captureElementCanvas()` in `resources/js/utils/pdfCapture.js` pre-fetches every `<img>` **inside the target element** into an array, then in html2canvas's `onclone` callback assigns that array **by index to `clonedDoc.querySelectorAll('img')`** — but `clonedDoc` is a clone of the **whole page**, not just the target. Any picture earlier in the page (header avatar, product thumbnails, an open panel's images) shifts every index: the logo slot receives the QR's data, and the QR keeps its own URL — exactly what the supplied PDF shows. The bulk flows append their offscreen container at the end of `<body>`, so they are always shifted when the page has any other `<img>`.

Fix: key the swap by **image identity (the image's own `src`)**, not by position, and look only inside the cloned reference element. One change in the shared helper fixes the pre-order invoice, payment invoice, shipping slip, POS receipt, purchase order and billing invoice, individually and in bulk. No backend, API, schema or UI change.

## Technical Context

**Language/Version**: JavaScript (Vue 3 SPA, Vite); no backend change

**Primary Dependencies**: `html2canvas` 1.4.1 (existing), `jspdf`, `jszip` — none added or upgraded

**Storage**: N/A

**Testing**: Vitest (`qa-tests/unit/pdfCapture.test.js`, component tests that exercise the download flows); a real-browser end-to-end check (canvas inspected visually) per Constitution II

**Target Platform**: Browser SPA served by the local Laravel app

**Project Type**: Web application (frontend-only fix)

**Performance Goals**: A bulk download of 10 invoices stays within +10% of today's time (SC-003) — the fix must not add fetches; it should remove duplicate ones

**Constraints**: Keep the existing taint protection (images converted to `data:` URIs inside html2canvas's own clone, live DOM untouched); never show a different picture for an image that fails to load; no visual/layout change to any document

**Scale/Scope**: One shared helper (~15 lines) + its tests; 8 call sites inherit the fix

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1 design — still passes.*

| Principle | Assessment |
|---|---|
| I. Clean code / single implementation | **Pass.** The bug lives in the ONE shared helper used by every download; fixing it there (not per call site) is exactly the principle. No per-screen workaround. |
| II. Testing | **Pass (planned).** The existing unit test hid the bug because its simulated cloned document contained *only* the target element; the new test clones a page with decoy images before and inside the element, plus a bulk-shaped case. A real-browser check inspects the actual rendered canvas. |
| III. UX consistency | **Pass.** No UI/copy change; no new strings. |
| IV. Security | **Pass.** No new network surface: the same URLs the document already references are fetched, once each. Taint protection preserved. |
| V. Performance | **Pass.** Fetches are de-duplicated per capture; the bulk loop is unchanged (sequential). |
| Documentation discipline | No route/response change, so `openapi` untouched. A short CLAUDE.md note records the identity-not-position rule so it is not reintroduced. |

No violations → Complexity Tracking not required.

## Project Structure

### Documentation (this feature)

```text
specs/029-fix-bulk-invoice-logo/
├── plan.md
├── research.md          # root-cause evidence, fix decision, alternatives
├── data-model.md        # (no data changes — stated explicitly)
├── quickstart.md        # automated + end-to-end verification
├── checklists/requirements.md
└── tasks.md             # created later by /speckit-tasks
```

(No `contracts/`: the fix has no external interface.)

### Source Code (repository root)

```text
resources/js/utils/pdfCapture.js          # captureElementCanvas(): identity-keyed swap, scoped to the clone
qa-tests/unit/pdfCapture.test.js          # decoy-image regression tests (replace the clone-of-only-el simulation)
qa-tests/component/PreordersView.test.js  # bulk download flow: each invoice captured separately (guard)
CLAUDE.md                                 # one-line rule + plan pointer
```

**Structure Decision**: Frontend-only change in the existing shared utility; no new files in `resources/js/`.

## Complexity Tracking

No constitution violations to justify.
