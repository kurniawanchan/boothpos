**Classification**: INTERNAL

# Evidence — Stage 4 (orders use the outlet's data) — DONE except browser check

**Date**: 2026-10-12. **Branch**: `045-outlets-per-outlet-menu`. Nothing committed or pushed.

## What changed

- **Ordering outlet**: `OutletContext::shiftOutletId()` (the user's open shift, read without `OutletScope`) and `orderingId()` (shift outlet, else the single effective outlet). The server still prices/permits orders from the shift outlet (Stage 3); this only makes the screens show what will be charged.
- **`pos=1`** on `GET /products` (legacy shape), `GET /variants/lookup` and `GET /menu/availability`: menu of the shift outlet — items not carried there are not offered, variants carry that outlet's `outlet_price`, 86 marks are the shift outlet's (not the header's). Without `pos`, nothing changes. POS, tab screen, POS search and both polls send it.
- **Pre-orders**: items not carried at the creating outlet are refused (422 `items`), on create (duplicate wraps create) and on edit-added lines; pre-orders still price from the base `sell_price`. A **split** pre-order now keeps the original's outlet (it used to take the request context's outlet).
- **Visibility of the selling outlet**: Sales list column "Dijual di" (`sold_at_name`/`sold_at_code`, several outlets only), order detail line "Dijual di {outlet}" (`order.outlet`), receipt line (Stage 2).
- **Status codes**: the existing contract is kept — a refused order line answers 409 (`OrderController` maps service validation errors to 409), a refused pre-order 422. The planned `item_unavailable_at_outlet` code was not introduced (the 043 characterization tests pin the current shape); the reason text names "not sold at this outlet" / "sold out (86)".

## Verification

| Check | Result |
|---|---|
| New backend tests | `OrderingScreensOutletTest` 9 (POS list filter + prices, shift outlet beats header, lookup, poll, sale at outlet price + snapshot unchanged, forged not-carried order, 86 at one outlet only, pre-order not carried, split keeps outlet), `ShiftOutletPayloadTest` extended |
| New frontend tests | `PosView` pos=1, `useMenuAvailability` pos, `TransactionItemsModal` outlet line; existing PosView/useMenuAvailability expectations updated for the new args |
| Backend full suite | **1808 passed, 1 skipped, exit 0** (8584 assertions) |
| Frontend full suite + build | **187 files, 1752 passed, 2 skipped, exit 0; build passes** |
| Dev app (API) | `owner` and `kasir01`: `/products?pos=1`, `/menu/availability?pos=1`, `/variants/lookup?pos=1` all 200 |

## Open

- **T069 browser check** (two cashiers at two outlets, 86 at WJ1, outlet price at WJ2, receipts) — the Playwright browser profile is still locked by an unrelated long-running Chrome (not killed); the server behaviour is covered by the tests above.
- The tab screen's `?pos=1` list and the POS "greyed 86" use the same server answers; no separate tab-specific test beyond the existing tab outlet-price test.
- Pre-orders are not outlet-priced (documented since Stage 3).
