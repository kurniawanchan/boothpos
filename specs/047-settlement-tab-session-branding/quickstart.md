**Classification: INTERNAL**

# Quickstart: verifying feature 076 end to end

Prereq: the dev stack is up (`docker compose up`), migrations applied, demo data present (`php artisan db:seed --class=SakanaFridgeDemoSeeder`), and you are signed in as `owner` / `password123` (local dev only).

## Safe test commands (read CLAUDE.md first)

```bash
# Backend — INSIDE the container the -e overrides are mandatory (the dev DB has been wiped twice without them)
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=SellerSettlement
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=CloneRegistryDriftTest
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test   # full suite, includes report parity
npm test                                                                                   # Vitest (no backend needed)
npm run build                                                                              # confirm the signature pad / document code are split chunks
```

After pulling this feature on a running dev stack, apply the new migrations (`docker compose exec app php artisan migrate`) — the app never migrates from a web request.

## A. New-tab session (US1)

1. Sign in in tab A. Middle-click **Sales** in the sidebar → tab B opens on Sales, signed in, no login screen. Repeat with Ctrl/Cmd-click and "Open link in new tab".
2. In tab B click the user menu → log out. Within a moment tab A is on `/login`; reloading it stays on `/login`.
3. Sign in again, **close every browser window**, reopen the app → login screen (shared-terminal rule preserved).
4. Signed out, open `/sales?cashier=2` directly → login; after signing in you land on `/sales?cashier=2`. Try `?redirect=//evil.example` and `?redirect=/login` by hand → you land on the dashboard.
5. Browser devtools → Application: no token in `localStorage` or cookies at any point.

## B. Branding (US4)

1. Settings → set store name "Demo Store" and upload a logo. With no reload: tab title reads `Demo Store · BoothPOS`, sidebar shows the logo and the name above the tier label, favicon is the logo (square-fitted).
2. Clear the logo / break its URL → sidebar shows the storefront glyph and the favicon the default; no broken-image icon. Clear the store name → title is just `BoothPOS`.
3. Switch to DEMO mode → the title uses the DEMO store name. Login screen shows the default title/icon.

## C. Settlement (US2, US3)

1. Sign in as `owner`. Sidebar → **Settlement** (visible). Sign in as `kasir01` → no item; `GET /api/v1/settlements` with that token → `403`.
2. New settlement → pick an event and a seller with POS sales **and** a partly paid pre-order. The draft lists one row per order/pre-order; revenue = POS + collected pre-order; the reconciliation block shows `difference 0.00`.
3. Compare with Reports → Seller Cost for the same event: POS exact; pre-order within 1 cent per pre-order row (the statement says "rounded per transaction").
4. Add a **deduction** (e.g. "Booth fee share", basis "40% by sales share"). Net drops by that amount; no commission line exists anywhere.
5. **Finalize** → a number `STL-…` appears; figures are read-only. Void one of the underlying orders in Sales → the settlement detail shows a "source changed" notice with the delta; the frozen figures do not move.
6. **Acknowledge** → draw a signature, enter signer name → saved with date/time. Try "typed" and "recorded outside the system" too.
7. **Add payment**: partial amount → status `partially_paid`, balance = net − paid; try an amount above the balance → `422` naming the maximum; double-click Save → one row (idempotent).
8. Print/download the **settlement proof** (PDF and PNG): store logo/name, seller, event, statement, reconciliation, acknowledgement block with the signature image. Cost/profit absent unless "include cost & profit" is ticked. No customer names/phones anywhere.
9. Download the **payment proof**: same number, amount, method, reference, balance after.
10. Make the deductions exceed revenue on another seller → net negative, wording reads "seller pays store"; recording payment works the same way.
11. Reopen (no payments) → back to draft, acknowledgement marked superseded; delete draft → gone; audit log shows every step (Activity Log screen).
12. Run **Clone LIVE → DEMO** (`php artisan app:clone-live-to-demo`) on a copy: settlements, payments and the two private files appear in DEMO with `-DM` numbers.

## D. Regression spots

- Reports → Seller Recap, Cost & Profit, Seller Cost and Pre-order show unchanged numbers for an event (the extraction in `PreorderRecognizedRevenue` is behaviour-preserving).
- Deleting an event or seller that has a settlement → `409`.
- Browser console clean on every screen above (constitution II).
