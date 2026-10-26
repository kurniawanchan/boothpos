**Classification: INTERNAL**

# Research: New-Tab Session, Seller Settlement Module, Store Branding

Branch `076-settlement-tab-session-branding` · spec dir `047-…`. Every "fact" below was read in this repository during planning; anything else is labelled ASSUMPTION.

## Part A — New-tab session

### Root cause (fact)

`resources/js/utils/storage.js` keeps the Sanctum bearer token in `sessionStorage`. Browsers scope `sessionStorage` per tab, and a link opened with middle-click / Ctrl-click (an `<a>` is `noopener` by default) does not inherit it. So the new tab has no token, `auth.restore()` finds none, and the router guard sends it to `/login`. The sidebar already renders real `<RouterLink>` anchors (`AppSidebar.vue`), so FR-005 is already true; only authentication is broken. The file's own comment records that `sessionStorage` was chosen on purpose: memory-only forces re-login on refresh, `localStorage` "silently persists across browser restarts on a shared terminal".

### Decision A1 — hand the token over between live tabs (BroadcastChannel); keep it in `sessionStorage`

A new module `resources/js/utils/tabSession.js`:

- On boot, if this tab has no token, it posts `{type:'request'}` on a same-origin `BroadcastChannel('boothpos-session')` and waits up to ~250 ms for `{type:'token', token}`. Any already-signed-in tab answers from its own `sessionStorage`. The new tab stores the token in **its own** `sessionStorage` and continues.
- `auth.restore()` is the single entry point (it is already cached and awaited both by `main.js` and by the router guard), so the handoff goes there, before the `getToken()` check. No second code path.
- Logout posts `{type:'logout'}`; every other tab clears its token, empties `auth.user` and hard-redirects to `/login` (same mechanism the 401 interceptor uses).
- No `BroadcastChannel` (old browser): the function resolves `null` immediately and behaviour is exactly today's. Not an error.

**Why it satisfies FR-003:** the token only ever exists in `sessionStorage` + live memory. Close every window and it is gone, so the shared-terminal protection is unchanged (SC-002). A token can only be handed over by a tab that is currently open and signed in.

**Security (risk area: session management / secrets management):** `BroadcastChannel` is same-origin only; the token never touches `localStorage`, a cookie, or the URL. A stale token handed over is rejected by `GET /auth/me` → 401 → cleared → login, so a revoked session cannot be resurrected. Logout already revokes the token server-side, so even a missed broadcast ends the session on the next request from another tab.

**Alternatives rejected**

| Alternative | Why rejected |
|---|---|
| `localStorage` token | Survives browser restarts → breaks FR-003 and the documented shared-terminal rule. |
| HttpOnly cookie session (Sanctum SPA mode) | Rewrites the whole auth model (CSRF, cookie domain, every test); far larger than the defect. |
| `localStorage` + "clear on browser close" heuristics | No reliable browser-close event. |
| `window.open` with opener (copies `sessionStorage`) | Only covers links we open ourselves, not middle-click / "Open in new tab". |

### Decision A2 — return to the requested page after login (FR-004)

Fact: `LoginView.vue` always `router.push({name:'dashboard'})` and the guard returns plain `{name:'login'}`; there is no return-to-requested-page today. Fix: the guard returns `{name:'login', query:{redirect: to.fullPath}}`; the 401 interceptor appends the current path as `redirect`; `LoginView` navigates to a **validated** redirect (must start with a single `/`, not `//`, not `/login`, must resolve to a known route) else dashboard. The validator is one function in `utils/safeRedirect.js` (open-redirect guard, input-validation risk area). `LoginView` stays Indonesian-only with no `t()` (feature 002 FR-001).

## Part B — Branding (title, sidebar, favicon)

Facts: `resources/views/app.blade.php` hard-codes `<title>BoothPOS</title>`; `favicon.ico` is static in `public/`; `AppSidebar.vue` shows a fixed storefront icon + `settings.appName`; `GET /settings/features` already returns `store_name` (per mode: `store_name` / `store_name_demo`) and `app_name`, but **not** the logo URL — that is only on the owner/admin `GET /settings`. `SettingsView.vue` keeps its own local `storeLogoUrl` and never refreshes the shared settings store after saving a name or logo.

### Decision B1 — one composable owns title + favicon

`composables/useBranding.js`, mounted once in `App.vue` (so it runs on every route, including login where it simply sees the defaults):

- Title = `storeName ? "<storeName> · <appName>" : appName` (`appName` already defaults via `resolveAppName`). Reactive, so a Settings save updates it without reload (spec US4-5).
- Favicon = the store logo when set, else the default. The logo is drawn onto a 64×64 canvas ("contain" fit, same-origin so the canvas is not tainted) and used as a PNG data URL, so a non-square logo is not stretched. If the image fails to load (or canvas fails) it falls back to the default icon — never a broken icon (FR-027). The static `<title>`/`favicon.ico` in the Blade view stay as the pre-JS/login default.

### Decision B2 — expose the logo URL on `features`

Add `store_logo_url` (resolved with `ImageUploadService::url()`, like every other image in the product) to `SettingsController::features()`. It is not sensitive (it is already printed on every receipt and invoice) and `features` is already open to every signed-in role. Login screen keeps defaults because settings need a session (spec Assumption). `GET /settings`'s existing `store_logo_url` stays.

### Decision B3 — sidebar brand and Settings write-through

Sidebar brand block: logo `<img>` (32 px, `object-contain`, `@error` → default storefront icon) + store name (truncated, full name in `title`) over the tier label; blank store name falls back to the app name. `SettingsView` calls two new store actions after saving (`settings.setStoreName`, `settings.setStoreLogoUrl`) so the title/sidebar/favicon change instantly. `receipt_show_logo` is a receipt-only flag and is deliberately **not** consulted.

## Part C — Seller settlement module

### Facts that constrain the design

1. `SettlementService` + `artist_settlements` already exist but are the POS-only **Seller Recap** cache (040). Its payable/paid/status columns are vestigial; the recap must not change. New work gets new tables and a new service; names avoid the clash (`SellerSettlement*`).
2. Revenue rules already exist and must not be re-derived a second time: POS = completed `order_items.line_total` per `orders.event_id`/`artist_id`; pre-order = `preorder_items.line_total × (collected / preorders.subtotal)` where `collected` = Σ non-rejected `payments.amount`, cancelled pre-orders excluded (`ReportController::preorderRecognizedRevenueBase()` + `PREORDER_FRACTION_EXPR`, private to the controller). Hand-rolled `DB::table()` queries must filter `data_mode` explicitly.
3. Money is `decimal(14,2)` and returned as strings; `ReportSplit::cents()/money()` already do cent-exact sums.
4. Single write paths are constitutional: `ActivityLogger` inside the same transaction; `PaymentRecorder` is the only creator of **customer** `payments` rows (its table has order/preorder/PO CHECK constraints).
5. Menu keys are a registry (`MenuKeys::ALL`) plus a data migration that appends the key to existing default roles (`…add_purchase_orders_menu_key_to_default_roles.php` is the template).
6. Documents are built in the browser (`utils/pdfCapture.js`, images swapped by `src`, never by index — feature 029).
7. Payment proofs live on the private `local` disk and are streamed through an authorizing endpoint (`PaymentProofController`).
8. Every `HasDataMode` table must be registered in `CloneRegistry::tables()` (FK order) or the LIVE→DEMO clone silently skips it; `CloneRegistryDriftTest` fails on drift.

### Decision C1 — scope is ONE event per seller (spec amended)

The first spec draft said "event or period". A free date range can overlap an event settlement and let the same sale be settled twice — fatal for "the amount balances". Settlement = `(seller, event)`; uniqueness is enforced by the database (see data-model `active_slot`). Period/multi-event settlement is a later feature. Spec Assumptions and FR-006/013/019/021 were edited to match.

### Decision C2 — one revenue source, extracted not copied

Extract the pre-order query and fraction expression from `ReportController` into `App\Support\PreorderRecognizedRevenue` (static query builder taking plain filters). `ReportController::preorderRecognizedRevenueBase()` delegates to it with **identical** SQL, so Sales/Profit/Seller Cost/Pre-order reports are behaviour-unchanged. The settlement builder uses the same class. A parity test asserts a seller's settlement revenue equals `artistProfit()` `sales_pos`/`sales_preorder` for the same event (SC-006). Rejected: copying the SQL (violates the "never add a second formula" rule already written in CLAUDE.md, feature 033).

### Decision C3 — snapshot at row level, rounded once per source row

The builder produces one **source row** per transaction per seller: an order (units, Σ line totals) or a pre-order (units, Σ `line_total × fraction`), each rounded to cents **once** (half-up via `ReportSplit`). Totals are sums of those rounded rows. Consequence: the statement always adds up exactly, and may differ from a report that rounds only the aggregate by at most 1 cent per pre-order row. The statement states this ("rounded per transaction") and the parity test allows ≤ 1 cent × number of pre-order rows. Rejected: rounding only the total (then lines would not add to the total — the exact failure the user asked us to avoid).

### Decision C4 — lifecycle and derived payment state

Stored `status`: `draft → finalized → cancelled(deleted)`; acknowledgement and payments are separate records. Everything the spec calls "acknowledged / partially paid / settled" is **derived** (never stored, like `PaymentSummary`): `payment_status` = `net_zero | unpaid | partially_paid | settled`; `acknowledged` = a current (non-superseded) acknowledgement exists; display status = first match of `draft`, `finalized`, `acknowledged`, `partially_paid`, `settled`. `paid_amount` is a cache rewritten from the payments in the same transaction.

- **Draft**: sources can be refreshed from live data; adjustments freely added/edited/removed; deletable.
- **Finalize**: one transaction, row-locked: refresh sources, run the reconciliation, refuse if the difference ≠ 0 (409 `reconciliation_mismatch`) or if there is nothing to settle (no source rows and no adjustments: 409 `nothing_to_settle`), freeze totals, assign number, write the audit row.
- **Finalized / acknowledged**: figures frozen. A correction is either **Reopen** (only with no payments; back to draft; current acknowledgement superseded) or a **post-finalization adjustment** (adds a signed line with a reason, supersedes the acknowledgement so it must be re-signed, refused when the new net would fall below what is already paid, or flip the direction while payments exist).
- **Source changed** is a read-time cue (one extra aggregate query on the detail view): live revenue vs frozen revenue; shows the deltas, never rewrites.

### Decision C5 — direction and payments

Net ≥ 0 → "store pays seller"; net < 0 → "seller pays store". Payments are always stored as positive amounts against `|net|`; the document wording follows the direction. Overpayment refused (422 naming the maximum; already-settled 409), idempotent through a client `client_ref` UUID (same ref + same settlement → 200 replay; ref on another settlement → 422), row-locked, audit row in the same transaction.

**Why a new payments table, not `PaymentRecorder`:** `payments` is the customer-receipts ledger with CHECK constraints tying each row to an order, pre-order or PO; a payout to a seller is a different business fact. Reusing it would force NULL-FK hacks and pollute shift-cash and reports. A dedicated `seller_settlement_payments` table with **one** writer (`SellerSettlementService::addPayment()`/`deletePayment()`) keeps the single-write-path rule. Recorded in Complexity Tracking.

### Decision C6 — acknowledgement ("digital signature")

Three methods, one record shape: `signature` (drawn on a canvas, uploaded as PNG to the private `local` disk), `typed` (signer name + timestamp), `external` (owner records that the seller acknowledged outside the system, with a note, labelled as such on the proof). Append-only: a new one supersedes the old (`superseded_at`), nothing deleted. Not a certified e-signature and no third-party service (spec Assumption). The signature image is served only through an authorizing endpoint; the browser turns it into a data URL before rendering the PDF (pdfCapture swaps images by `src`).

### Decision C7 — documents

Two printable documents reuse the existing browser pipeline: `SettlementProofDocument.vue` (statement + reconciliation + acknowledgement block) and `SettlementPaymentProofDocument.vue` (one payment, balance after). Data comes from `GET /settlements/{id}/document` (store identity, "Powered by" via `AppName::current()` and `BuildsInvoiceDocument`-style assembly). Cost of goods and gross profit are shown on screen as memo lines and **omitted from the printed proof unless the owner ticks "include cost & profit"** (they are the store's margin; the proof goes to the seller). Customer names/phone/email are never in the document: source rows carry only order/pre-order numbers (FR-023, privacy / data minimisation).

### Decision C8 — authorization

New menu key `settlements` (`MenuKeys::ALL`, label "Settlement") + a data migration appending it to the default **Owner** and **Admin** roles only. Enforcement is server-side in two of the three existing mechanisms: `FormRequest::authorize()` on every write request (one shared base request) and an inline `canAccessMenu('settlements')` guard on the read endpoints; sidebar hides the item for others (cosmetic). Signature and payment-proof streaming endpoints use the same check.

### Decision C9 — DEMO/LIVE and clone

All five new tables use `HasDataMode`. They are appended to `CloneRegistry::tables()` after `artist_settlements` (FKs: `event_id`, `artist_id`, `settlement_id`; `number` suffix `-DM`; `client_ref` UUID regenerated; `signature_path`/`proof_path` in `files` on the `local` disk so demo gets its own copies). `CloneRegistryDriftTest` extended rather than allow-listed. Settlement `number` and `client_ref` are unique across modes (counted with `withoutGlobalScope`, same as `PreorderService::generateNumber()`).

### Open items

None blocking. Items the product owner may revisit later (logged, not asked): multi-event/period settlement; certificate-based e-signature; commission (explicitly removed); whether adjustments after acknowledgement should require a second user.
