# Research: Android POS Phase 4 (057)

**Verified** = read in code this session. **TO VERIFY** = confirm during implementation (listed, not assumed).

## R1. Discount storage

- **Verified**: `DiscountService::apply` computes `amount = base × percent ÷ 100` on the server, writes `order_items.discount_amount` / `line_total` for line scope, and `TabService::recalculate($order, $amount)` for order scope. The percentage and reason go only to the activity log (lines ~56, ~81). `clearOrderDiscount` sets the order amount to 0 and logs it.
- **Decision**: add nullable `discount_percent decimal(5,2)` and `discount_reason_code varchar(40)` to `orders` (order scope) and to `order_items` (line scope). Write them in the same branch as the amount; clear them when the order discount is removed; keep the activity log as it is.
- **Rationale**: the product owner decided the data belongs on the order. Columns on the existing rows keep reads cheap and avoid a second table.
- **Alternatives rejected**: a `discounts` table (more joins for one value per row); reading the percent back from the activity log (not a reliable store, and reports cannot use it).

## R2. Historical discounts

- **Decision**: no back-fill (FR-013). The percentage and reason stay null for older discounts; the payload has null and the tablet shows the amount only.
- **Rationale**: the activity log has the values, but guessing a percentage from an amount is unreliable.

## R3. Reprint copy number

- **Verified**: `ReprintService` expects `copy_no == receipt_copies + 2` (the original is copy 1; the first reprint is copy 2) and returns 409 `reprint_copy_changed` with the expected number. `ReprintRequest` requires `copy_no` (integer, min 2). The tablet's `ReprintRequestDto` carries only `override_token`, and `PosStore.reprint` counts copies on the tablet (`reprintCopies[orderId] + 1`), which starts at 1 and does not know about earlier reprints.
- **Decision**: the order payload carries the server's `receipt_copies` (mapped as the reprint count). The tablet sends `copy_no = receipt_copies + 2` from that value; on 409 it refreshes the order and asks again once.
- **Rationale**: the server owns the copy count (constitution IV); the tablet only sends what the server last reported.

## R4. Closed-order time

- **Verified**: the closed list is `GET /orders?per_page` (`PosApi.closedOrders`), mapped by `ClosedOrderDto`, whose only time field is `created_at`. The dialog prints `hhmm(createdAt)`; the screenshot shows "ditutup null" and "Counter A-006 · null", so the value is absent.
- **TO VERIFY**: which timestamp `OrderResource` returns for a closed order (candidates: `completed_at`, `closed_at`, `updated_at`). The fix uses the server's closing timestamp; if none is returned, the time text is left out (FR-007).

## R5. Reconnect and the expired PIN session

- **Verified**: a PIN session token lasts `PinAuthService::TOKEN_HOURS = 12`. The tablet's roster call uses `sessionToken ?: adminToken` (`PosStore`/`RemoteBackend`). The session is cleared only on logout or reset, and `hasValidSession` only checks the stored expiry. The sign-in screen's error (`Unauthenticated`) comes from a 401 that `ApiErrorMapper` maps to `ApiError.Unauthorized`; "Coba lagi" calls `loadRoster()` again with the same token.
- **Decision**: on a 401 while the session token is in use, drop the session token (and its expiry) and retry the staff list once with the admin token. If that also gives 401, show the sign-in / setup message with Coba lagi. Never retry in a loop.
- **Rationale**: the server refuses the expired token, so retrying it can never work; the fallback is the same credential the tablet already holds from setup.
- **TO VERIFY on the tablet**: that the admin token is still valid after the offline period (the spec's device check).

## R6. Sold-out reasons never loading

- **Verified**: `loadSoldOutReasons` sets `Load.Loading` and calls `reasonCodes("sold_out")` under `retryNetwork`. The dev database has four active `sold_out` reason rows. The same 401 path as R5 applies; the request ends in `Load.Failed` only when it returns, so an unanswered or retried call keeps the spinner.
- **Decision**: apply the R5 fallback to this call; the dialog shows the failure text and Coba lagi, and an empty list shows "Tidak ada alasan" (spec edge case).
- **TO VERIFY**: whether `retryNetwork` retries 401 responses (it should not).

## R7. Layout items

- **Verified**: `TenderScreen.kt` line ~51 renders the pending QRIS tile in the left column; the word "Verifikasi" wraps one letter per line in the screenshot, so the tile's width is too small for its label. `Sheets.kt` `QrisVerifyDialog` (line ~608) puts Tutup, Tidak ditemukan and Verifikasi in one row. `OrdersScreen.kt` (lines ~84–86) renders the detail buttons; the third one (Refund) has no room for its label.
- **Decision**: TenderScreen: give the QRIS tile a minimum width and let its label use the fitted-label helper with no wrap per letter; Verifikasi: its own full-width row below Tutup and Tidak ditemukan; Refund: fitted label and a row that wraps instead of squeezing the label.
- **Alternatives rejected**: a separate screen for each fix (more navigation for the cashier).

## Open items

- R4: confirm the closing timestamp field in `OrderResource`.
- R5: confirm on the tablet that the admin token works after the offline period.
- Device checks for every layout change (US1–US4).
