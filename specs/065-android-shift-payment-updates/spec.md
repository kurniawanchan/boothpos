# Feature Specification: Android Shift, Payment and Setup Updates

**Feature Branch**: `065-android-shift-payment-updates`

**Created**: 2026-10-22

**Status**: Draft

**Input**: User description: "update android app: (1) Buka shift screen — fix the money layout, remove the 'Lewati, ambil order saja' link, add a free-text money input. (2) QRIS payment sheet — use the QRIS payment channel's data (QR image already uploaded in the web), remove the 'Jangan terima bukti dari layar ponsel pelanggan' warning, change the instruction text to 'Cek bukti transaksi di aplikasi QRIS, lalu catat kode verifikasi di menu Order', move the title beside the QR icon. (3) 'Siapkan terminal' screen — remove the 'Alamat server' input; use the value chosen in the web Server address menu (local or online), saved by the server, read once by the app and kept on the device until data is cleared. (4) Shift screen — open shifts are not visible in the web; add a menu or integrate into a relevant one, and show open shifts of different users on the web dashboard. (5) Save 'Kas masuk' and 'Kas keluar' data and show it in the web. (6) 'Tutup shift' screen — show sales information, add free-text money input, remove the EDC input and information, and after 'Kirim hitungan' save the data and show it in the web as closed-shift information."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Owner sees every shift, open and closed, in the web (Priority: P1)

An owner or admin opens the web app and sees which cashier shifts are open right now (who, which outlet and terminal, since when, opening cash, sales so far) and a history of closed shifts (who counted what, expected vs counted cash, difference). The web dashboard shows the open shifts of different users at a glance. Today the web "Cashier Session" screen shows only the logged-in user's own shift, so a shift opened on a tablet by another user is invisible.

**Why this priority**: It is the largest gap the user reported ("no open shift data in the web") and the other shift stories (cash in/out, close) are only useful if the web can show them.

**Independent Test**: Open a shift on the tablet as a cashier, then sign in to the web as owner: the shift appears in the open-shifts list and on the dashboard with the cashier's name. Close it on the tablet: it moves to closed shifts with its figures.

**Acceptance Scenarios**:

1. **Given** two cashiers have open shifts on two terminals, **When** an owner opens the web shift screen, **Then** both shifts are listed with cashier, outlet, terminal name, opened time, opening cash and sales so far.
2. **Given** open shifts exist, **When** the owner opens the dashboard, **Then** a panel lists each open shift (one row per user) and links to its detail.
3. **Given** a shift was closed on a tablet, **When** the owner opens closed shifts, **Then** it appears with closing time, who closed it, counted cash, expected cash, difference, and sales totals.
4. **Given** a cashier (not owner/admin) opens the web shift screen, **Then** they see only their own shift(s), never other users' shifts.
6. **Given** a user has an open shift on a tablet, **When** they open a shift on the web, **Then** it is allowed and both appear as separate rows (source: terminal name / web).
5. **Given** the outlet selector is narrowed to one outlet, **When** shifts are listed, **Then** only shifts of that outlet appear.

---

### User Story 2 - Cash in / cash out are recorded and visible in the web (Priority: P1)

Every "Kas masuk" (cash in) and "Kas keluar" (cash out) entered on the tablet (reason, amount, who, when, which shift) is saved on the server and can be viewed in the web, inside the shift detail and as a list across shifts, with totals.

**Why this priority**: Money leaving or entering the drawer must be auditable by the owner outside the tablet.

**Independent Test**: Record a cash out "Belanja bahan Rp 50.000" on the tablet; in the web, open that shift's detail and see the entry with reason, amount, user and time; the shift's cash in/out totals match.

**Acceptance Scenarios**:

1. **Given** a cash in and a cash out were saved on a shift, **When** the owner opens that shift in the web, **Then** both entries show with type, reason, amount, user and time, and totals for in and out.
2. **Given** the tablet shows "Kas masuk / keluar Rp 0 / Rp 0 · 0 catatan" before any entry, **When** one entry is saved, **Then** the tablet figure and the web figure both update to match.
3. **Given** cash movements exist across shifts, **When** the owner opens the cash movements list in the web, **Then** it can be narrowed by date, outlet, user and type.

---

### User Story 3 - Close shift: sales information, typed amount, no EDC (Priority: P1)

On the "Tutup shift" screen the cashier sees the shift's sales information, counts the drawer either by denominations or by typing the total amount directly, and sends the count. The EDC batch input and all EDC wording are gone. After "Kirim hitungan" the shift is closed, the count is saved, and the web shows the closed-shift information.

**Why this priority**: Closing is the control point for cash; the user wants it faster (typed amount) and simpler (no EDC) and visible to the owner.

**Independent Test**: Close a shift by typing an amount; confirm the screen shows no EDC field or text; in the web, the shift appears under closed shifts with the typed counted amount.

**Acceptance Scenarios**:

1. **Given** an open shift with sales, **When** the cashier opens "Tutup shift", **Then** the screen shows the shift's full sales information — receipts, sales total, payment-method split, cash in/out, voids and the expected drawer cash — before the cashier counts (decision Q2 = B; the blind count of feature 048 is dropped).
2. **Given** the close screen, **When** the cashier types a total amount instead of using the denomination steppers, **Then** that amount is the submitted count and the denomination steppers are not required.
3. **Given** the close screen, **When** it is displayed, **Then** there is no EDC input, no EDC helper text, and no EDC figure anywhere in the close flow or its result.
4. **Given** the cashier taps "Kirim hitungan", **When** the server accepts it, **Then** the shift is closed and saved, and it shows in the web closed-shift list with counted, expected and difference.
5. **Given** the connection drops while sending, **When** the cashier retries, **Then** the shift is closed once and the count is not duplicated.

---

### User Story 4 - Open shift screen: tidy money layout, typed amount, no skip link (Priority: P2)

On "Buka shift" the denomination rows are readable (labels not cut off, e.g. "Rp 100." today), the cashier can type the opening cash total directly, and the "Lewati, ambil order saja" link is gone, so a shift is always opened before taking orders.

**Why this priority**: Visible defects and a faster entry method, but nothing blocks sales.

**Independent Test**: Open "Buka shift" on the tablet in landscape: every denomination label is fully readable; type "100000" in the free amount field; "Buka shift" opens a shift with opening cash Rp 100.000; the skip link does not exist.

**Acceptance Scenarios**:

1. **Given** the open-shift screen on a tablet, **When** it is shown, **Then** all denomination labels (Rp 100.000 … Koin Rp 500), steppers and per-row subtotals are fully visible and do not overlap.
2. **Given** the screen, **When** the cashier types a total amount, **Then** the "Total modal" equals the typed amount and "Buka shift" is enabled.
3. **Given** the screen, **When** it is shown, **Then** there is no "Lewati, ambil order saja" link or any other way to reach orders without an open shift.
4. **Given** both denominations and a typed amount are entered, **Then** the screen never submits two conflicting totals (see Assumptions).

---

### User Story 5 - QRIS sheet uses the payment channel's data and clearer text (Priority: P2)

On the QRIS payment sheet the QR image comes from the QRIS payment channel configured in the web (the one that already has a QR uploaded), the title sits beside the QR icon, the customer-phone warning is removed, and the instruction reads "Cek bukti transaksi di aplikasi QRIS, lalu catat kode verifikasi di menu Order".

**Why this priority**: The sheet currently says "QR belum diatur" although the web channel has a QR; the text change reflects the real verification process.

**Independent Test**: With a QRIS channel that has a QR image in the web, open the QRIS sheet: the QR is displayed; the title (provider + account name) is on the same row as the QR icon; the warning is absent; the new instruction text is shown.

**Acceptance Scenarios**:

1. **Given** the web QRIS payment channel has a QR image, **When** the cashier opens the QRIS sheet, **Then** that image is shown, not "QR belum diatur".
2. **Given** the QRIS channel has no QR image, **Then** the sheet says the QR is not set up (and, for users allowed to manage it, offers the existing upload).
3. **Given** the sheet, **Then** the title appears beside the QR icon on one row, the warning "Jangan terima bukti dari layar ponsel pelanggan" is not shown, and the instruction text is exactly "Cek bukti transaksi di aplikasi QRIS, lalu catat kode verifikasi di menu Order".
4. **Given** the cashier taps "Bayar", **Then** the payment is still recorded as awaiting verification, as today.

---

### User Story 6 - Server address comes from the web setting, not typed on the tablet (Priority: P2)

On "Siapkan terminal" the "Alamat server" input is removed. The admin's choice in the web "Server address" setting (local or online) decides the address: local → the local address, online → the online address. The server stores it; the tablet reads it during setup, keeps it on the device, and uses that stored address for every request. Only if the stored data is cleared does the tablet read the address from the server again.

**Why this priority**: Removes a typo-prone step for staff, and lets the admin move all tablets between local and online without touching them.

**Independent Test**: Choose "Local" in the web with a local address; set up a fresh tablet: no address field appears; setup completes and requests go to the local address. Clear the app's data and set up again: the address is fetched again.

**Acceptance Scenarios**:

1. **Given** the web setting is "Local" with an address, **When** a tablet starts "Siapkan terminal", **Then** it shows no server address input and connects using the local address.
2. **Given** the setting is "Online" with an address, **Then** the tablet uses the online address instead.
3. **Given** a tablet already set up, **When** the admin later changes the web choice, **Then** the tablet keeps using its stored address until its data is cleared. [Assumption — see below; differs from feature 054, where the tablet adopts a changed choice on its next check.]
4. **Given** app data is cleared, **When** the tablet is set up again, **Then** it reads the address from the server again.
5. **Given** the chosen option has no address saved, **When** setup starts, **Then** the tablet shows a clear message that the admin must set the address in the web.

---

### Edge Cases

- A cashier closes a shift with open tabs or pending QRIS payments: existing blocking rules (open tabs, pending payments) still apply; the close screen explains the block.
- Typed amount of 0, empty, negative, or with separators ("1.000.000"): empty/negative rejected with a clear message; thousand separators accepted and shown formatted; decimals not accepted (whole rupiah).
- A shift is opened on the web (not the tablet): it also appears in the same open-shifts list (terminal shown as none).
- A very old shift left open (forgotten): shows an "open since" age so the owner can spot it.
- Two terminals of the same outlet, two users: the dashboard shows both rows, one per shift.
- A user with an app shift and a web shift: each side's payments and cash movements stay in its own shift; the web "my shift" card shows only the web shift.
- QR image URL unreachable from the tablet (e.g. address of the server unreachable): the sheet shows "QR belum diatur / tidak dapat dimuat" with a retry, never a blank box.
- Existing closed shifts that recorded an EDC batch total: the old value stays in history but is no longer collected or shown in the app; the web may omit it.

## Requirements *(mandatory)*

### Functional Requirements

**Web visibility of shifts (US1)**
- **FR-001**: The web MUST provide a shift list for owner/admin showing ALL shifts across users in the permitted outlets, split into open shifts and closed shifts, with cashier, outlet, terminal name, opened time, opening cash and sales so far (open) or closed time, counted cash, expected cash and difference (closed).
- **FR-002**: Each shift MUST have a detail view in the web with sales information, payment-method breakdown, cash in/out entries, and (if closed) the count and difference.
- **FR-003**: The web dashboard MUST show a panel of currently open shifts, one row per shift/user, each linking to its detail.
- **FR-004**: Users who are not owner/admin MUST only see their own shifts in the web; shifts of other users and other outlets MUST NOT be visible or retrievable.
- **FR-005**: Shift lists and the dashboard panel MUST respect the selected outlet and the DEMO/LIVE mode, like other web screens.

**Cash in / cash out (US2)**
- **FR-006**: Each cash in/out entry saved on the tablet MUST be stored with type, reason, amount, user, time and shift, and MUST appear in the shift detail and in a cash movements list in the web.
- **FR-007**: The tablet's "Kas masuk / keluar" figure and the web's figure MUST agree for the same shift.
- **FR-008**: Cash movements MUST be filterable in the web by date, outlet, user and type, with in/out totals for the filtered set.

**Close shift (US3)**
- **FR-009**: The close screen MUST show the shift's full sales information, including the expected drawer cash, before the cashier counts (Q2 = B). A cash difference above the tolerance still needs the existing approval.
- **FR-010**: The cashier MUST be able to enter the counted cash as a typed total amount, as an alternative to denomination counting.
- **FR-011**: The close screen, its result and the closed-shift data MUST contain no EDC input, text or figure.
- **FR-012**: "Kirim hitungan" MUST close the shift and save the count; the closed shift MUST then be visible in the web (FR-001/FR-002). Retrying after a failure MUST NOT create a second close or duplicate data.

**Open shift (US4)**
- **FR-013**: The open-shift screen MUST display all denomination labels, steppers and subtotals without truncation or overlap at tablet landscape size.
- **FR-014**: The open-shift screen MUST accept a typed total opening amount; the stored opening cash MUST equal the typed or the counted total, never an inconsistent mix.
- **FR-015**: The "Lewati, ambil order saja" link MUST be removed; orders require an open shift.

**QRIS (US5)**
- **FR-016**: The QRIS sheet MUST show the QR image, provider and account name of the QRIS payment channel configured in the web; "QR belum diatur" is shown only when the channel truly has no QR image or it cannot be loaded.
- **FR-017**: The QRIS sheet MUST NOT show the warning "Jangan terima bukti dari layar ponsel pelanggan".
- **FR-018**: The instruction text MUST read exactly "Cek bukti transaksi di aplikasi QRIS, lalu catat kode verifikasi di menu Order".
- **FR-019**: The sheet title MUST be placed on the same row as, and beside, the QR icon.

**Server address (US6)**
- **FR-020**: The "Siapkan terminal" screen MUST NOT contain an "Alamat server" input.
- **FR-021**: The address used MUST be the web "Server address" setting's chosen option (local → local address, online → online address), saved on the server.
- **FR-022**: The tablet MUST read the address during setup, store it on the device, and use the stored address for all requests; it MUST fetch it from the server again only when its stored data has been cleared.
- **FR-023**: If the chosen option has no address saved, setup MUST show an actionable message instead of failing silently.
- **FR-024**: The app MUST ship with default local and online server addresses (set at build time) and use them only to ask the server which address the admin chose (Q1 = A). If neither default answers, setup MUST show a clear "server unreachable" message with retry; it MUST NOT fall back to an address input.
- **FR-025**: Shifts opened on the tablet app and shifts opened on the web MUST be separate: a user may hold one open shift in the app and one on the web at the same time; orders, payments, refunds and cash movements made in one place go to that place's shift and never to the other; closing one does not close the other.
- **FR-026**: The Cashier Session page in the web MUST show the user's own web shift as today AND, for owner/admin, the list of all shifts from both sources, each labelled with its source (app terminal name, or web).

### Key Entities

- **Shift**: A cashier's working session on a terminal at an outlet: opener, opened/closed time, opening cash, sales, cash in/out, count and difference at close. Visible in the web for owner/admin (all) and cashier (own).
- **Cash movement**: A cash-in or cash-out entry on a shift: type, reason, amount, user, time.
- **Shift count**: What the cashier submits at close: typed total or denomination counts, saved with the shift (no EDC).
- **Payment channel (QRIS)**: Configured in the web; provides provider name, account name and QR image used by the tablet's QRIS sheet.
- **Server address setting**: Admin's choice (local/online) and the two addresses, saved on the server; read by a tablet at setup and then kept on the device.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of shifts opened on tablets appear in the web open-shifts list within 30 seconds, with the correct cashier name.
- **SC-002**: An owner can see every currently open shift (all users) on the dashboard without opening any other screen.
- **SC-003**: 100% of saved cash in/out entries are visible in the web with the same totals as the tablet.
- **SC-004**: A shift can be closed by typing one amount in under 30 seconds, with 0 EDC fields or EDC text in the close flow.
- **SC-005**: 100% of closed shifts appear in the web closed-shift list with counted, expected and difference figures.
- **SC-006**: On the open-shift screen, 0 denomination labels are truncated at standard tablet landscape resolution; the skip link is absent.
- **SC-007**: With a QR image configured on the web QRIS channel, the QRIS sheet shows it in 100% of attempts (barring a network error, which shows a retry).
- **SC-008**: A new tablet is set up without any server address typed by staff, in under 2 minutes.

## Assumptions

- "Free text for money input" means a typed whole-rupiah total (digits, thousand separators optional) offered **alongside** the denomination steppers; entering a typed total replaces the denomination counts as the source, and starting to use the steppers clears the typed total, so only one total is ever submitted. Denomination counts remain available.
- Web visibility is added by **integrating into the existing "Cashier Session" menu** (owner/admin get a list of all open and closed shifts; others keep seeing only their own) plus a **dashboard panel** for open shifts, rather than a new top-level menu. Cash in/out appear inside the shift detail and as a filterable list under the same menu.
- The existing close rules (blocked while tabs are open or QRIS payments are pending) and the "difference above tolerance needs approval" rule are unchanged. Showing the expected cash before counting (Q2 = B) is an owner decision that supersedes the blind count of feature 048.
- Server addresses: the build-time defaults are supplied by whoever builds the APK (e.g. the outlet's LAN address and the online address); they are not user-visible.
- The QRIS payment is still recorded as "awaiting verification"; verification and the verification code entry continue in the existing Order menu.
- The QR sheet failing today with "QR belum diatur" while the web channel has a QR is treated as a defect to fix as part of FR-016, not as a missing configuration.
- Server address: the tablet keeps its stored address until data is cleared (the user's rule). This replaces feature 054's behaviour in which a tablet adopted a changed admin choice on its next check; if the admin changes local/online later, tablets must have their data cleared (or be re-set up) to follow.
- Historic EDC batch totals already stored on closed shifts are kept as history but no longer collected or displayed in the app.
- Out of scope: new payment methods, changing receipt layouts, offline support, and any change to how stock or reports are computed.
