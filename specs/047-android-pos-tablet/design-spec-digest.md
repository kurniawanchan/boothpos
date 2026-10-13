INTERNAL

# WJM POS Android design spec digest (v0.2 draft, 2 Oct 2026)

Sources: design/README.md; design/spec/POS Spec 1-4 (HTML text); docs/architecture/restaurant-pos-erp-technical-architecture.md section 3.5, 3.6, 3.11 (quoted because the spec HTML only cites section 3.5 and does not reproduce it). Spec tags: [ASSUMPTION], [OPEN QUESTION], [REC], [CONFLICT].

## 1. Scope
Pilot: Warkop WJM 3, go-live 31 Oct 2026. Hardware: <=2 Android tablets, <=3 thermal printers (interface unknown, OQ-049), 1 standalone BCA EDC, no cash drawer (DEC-013, ASM-070). Native Kotlin + Jetpack Compose + Material 3 (DEC-014, TR-092, ADR-0010, DEC-018, TR-095, ADR-0011). Language Bahasa Indonesia (ASM-024). No customer data in R1 (PRV-026). No hub in R1 (CF-01).

### Screens S01-S35 (primary action; offline; requirement IDs; release)
- S01 Pairing: Blocked offline; DVC-001, DVC-002, DVC-004; Must
- S02 Roster + PIN (also lock screen): IAM-049, IAM-058, IAM-004; Must
- S03 Open shift (float per denomination): POS-056, POS-057, POS-098, PAY-003, VAL-POS-007; Must
- S04 Shell + header: POS-001, POS-002, POS-073, POS-078; Must
- S05 Meja: POS-093, POS-008, VAL-POS-001, BRL-POS-010, EC-POS-003; Must
- S06 Order editor: POS-091, POS-092, POS-017, POS-018, POS-021, POS-025, POS-029, POS-030, POS-041, POS-043, POS-084, PRO-042, SAL-043, BRL-POS-001, VAL-POS-006, EC-POS-012/013; Must
- S07 Modifier sheet: POS-018, PRO-017, PRO-018, PRO-021, PRO-022; Must
- S08 Line actions: POS-021, PRO-021, POS-030; Must
- S09 Void/cancel dialog: POS-030, POS-037, POS-038, PRT-008, IAM-025, CMN-043, VAL-POS-005, BRL-POS-002, EC-POS-008; Must
- S10 Override dialog: IAM-029..IAM-032, IAM-034, BRL-IAM-002, BRL-POS-006; Must
- S11 Tender: POS-043, PAY-011, PAY-013, PAY-014, PAY-019; Must
- S12 EDC sheet: PAY-021, VAL-PAY-002; Must
- S13 QRIS sheet: PAY-024, PAY-026, VAL-PAY-003; Must (blocked offline)
- S14 Payment complete: POS-047, PRT-028, CMN-017; Must
- S15 On-screen receipt: PRT-026; Must
- S16 Order list (Terbuka/Selesai): POS-032, POS-054, SAL-040; Must
- S17 Closed order detail/reprint: POS-051, PRT-007, BRL-POS-007; Must
- S18 Shift hub: POS-061, POS-094; Must
- S19 Cash in/out: POS-094, VAL-POS-008, CMN-043; Must
- S20 X-report: POS-061, PRT-006; Must
- S21 Close shift: POS-062, POS-063, PAY-023, VAL-POS-009, EC-POS-010, BRL-POS-006, BRL-POS-012; Must (blocked offline)
- S22 Shift result + Z: POS-064; Must
- S23 Perangkat & sinkron: POS-073, POS-078, POS-079, POS-080, DVC-009..DVC-011, PRO-048, POS-072; Must
- S24 Event detail (rejected/quarantined): POS-080, DVC-005; Must
- S25 Antrean cetak: PRT-014..PRT-017, POS-082, BRL-PRT-001/002; Must
- S26 Pengaturan (printers read-only, Tes cetak [REC]): PRT-010, PRT-011; Must
- S27 Ubah PIN (online only): IAM-007, endpoint #9; Must
- S28 Blocking states: DVC-005, SAL-043, PRO-048, DVC-007; error codes DEVICE_REVOKED, TAX_RATE_MISSING, CLIENT_VERSION_UNSUPPORTED; Must
- S29 Availability (86): PRO-042, CF-10; Must
- Stretch: S30 Split bill (POS-036, BRL-POS-004); S31 Pindah/gabung (POS-033, 035, 039, 034); S32 Refund (POS-097, SAL-041, PAY-058, PRT-004; online only); S33 Diskon (PRM-001, 002, 005, SAL-042); S34 Order tertahan (POS-031); S35 Safe drop (POS-059, ACC-037).
- Other Stretch: variants (POS-019, PRO-005), cash rounding (POS-045, PAY-020), order note (POS-014), previous-day warning (POS-004), quick user switch (IAM-050), min app version gate (DVC-007), lost/replaced device (DVC-014/015), unlock bill (POS-042), tap budget (POS-083, KPI-001). Stretch is behind remote flags in the release bundle (ADR M-08).
- Future / not designed: reopen bill POS-053, course fire/hold POS-024/026, KDS/hub TR-088/TR-003, e-receipt PRT-019, comp/staff meal POS-050, delivery POS-011/012, handheld POS-087/088, drawer/no-sale POS-046/060 (removed by DEC-013), dynamic QRIS PAY-025, loyalty, customer display, push CMN-038.
- Web-only (not on device): shift force-close POS-066, shift schedule POS-098, EOD POS-067/069/072/095, printer registry, reports RPT-028..030, menu/tax/users/devices.
- Other R1 Must IDs: POS-009/013/029/CMN-020 (counter queue number), POS-010, SAL-038, POS-076/077, CMN-063, POS-078, DVC-009, DVC-010, PRO-048/ADM-042, SAL-043/021/023/024/CMN-062 (calc), PAY-007/009/014/017, CMN-019, POS-065, POS-084/NFR-PERF-001.
- Handoff NN screens map: README table (36 files incl. dark, offline-limited, phone 29-34, tablet8, tablet13).
- Conflicts: CF-01..CF-11 (hub vs R1, phone vs tablet, bill lock vs POS-042 stretch, EOD location, card brand/last4 excluded, QRIS offline blocked, calc authority, drawer as logical container, login PIN lockout assumed, 86 offline, Modernist vs M3 resolved to M3).

## 2. Roles, PIN, override
- Roles: Cashier CSH, Waiter WTR, Supervisor SPV, Outlet Manager OMG (device: approver only). OWN/SYS web.
- CSH: create/update orders O; cancel, void, refund M; reprint O/M (L1); tender; QRIS verify M; 86 M; discount L1 (stretch); approve own variance never. Landing Counter (ASM-M3).
- WTR: create orders, update own (S); cancel/void M; pre-bill; NO payment rights (Bayar hidden, pre-bill primary); landing Meja; tables owned by other device blocked offline.
- SPV: O for all POS, approve void/refund L2, QRIS verify O, 86 O, variance tolerance L1, offline override up to online SPV limit; landing Order; can act as cashier.
- OMG: void L3, variance L2, offline-limit override (POS-079), pairing/EOD/force-close in web.
- Limit values pending OQ-023; placeholders: discount limits per role, cash-out override threshold Rp 200.000, variance tolerance Rp 5.000, tax rate 10% tenant-entered.
- PIN: 6 digits, unique per outlet (IAM-004, ASM-032); roster pick then PIN; 6th digit auto-submits; verify against cached verifier offline; wrong PIN shows remaining tries; 5 wrong -> 15 min lock (login: ASM-M1 assumption; override: IAM-032, emits override.blocked, notifies SPV/OMG); lockout uses monotonic clock; verifier expiry = offline limit 4 h (IAM-058, NFR-OFF-007); expired verifier blocks new logins, not active session (ASM-M6); idle auto-lock 5 min configurable (ASM-M4); PIN change online only (POST /auth/pin-changes). Offline verifiers re-wrapped with non-exportable Keystore key.
- Override: dialog bound to ONE action + payload on same device (IAM-029/030); shows action, amount, reason, requester; approver picker filtered to those whose role/limit covers; approval does not raise requester session; approver != requester for shift variance (BRL-POS-006); online POST /overrides (one-time token bound to payload hash); offline embeds record {approver_user_id, reason_code, action, payload_hash, verified_at}; offline limit <= online (BRL-IAM-002), else message "Nominal melebihi batas persetujuan offline"; server flags revoked_user_offline. Lock glyph shown where override will be needed.
- Needs override: void fired line, cancel order, reprint over limit, cash-out over threshold, QRIS verify (CSH), variance over tolerance, 86 (CSH), discount over limit, refund, new order when offline limited.

## 3. Domain rules
- Order types: dine-in (table + pax 1-99), takeaway (name optional, no PII), counter (daily queue number, terminal letter, e.g. A-024, Arch A8). Tables: Tersedia/Terisi/Bill tercetak/Dikelola perangkat lain (offline), no floor plan.
- Lines: one-tap add <=200 ms P95; required modifier groups open sheet, Tambah disabled until min/max met; line note <=100 chars, control bytes stripped; unfired lines +/- and delete no reason; fired lines: decrease = void (reason code CMN-043, stock decision Sudah dibuat/Belum dibuat, partial qty, BATAL slip, override above role limit); paid order cannot be voided (BRL-POS-002); existing lines keep price (EC-POS-012); discontinued item stays payable (EC-POS-013); no price override in R1.
- Kirim: "Kirim n item", prints KOT per station (only new lines), no confirm; disabled when none unfired; counter auto-fire. Cancel unpaid order POS-038. Pre-bill (PRT-002, BELUM LUNAS marker) with bill lock (POS-041/ADM-043; CF-03 recommends lock off unless POS-042 ships).
- Tax: prices exclude tax, tenant-entered rate, NO service charge, order cannot close/Bayar without active rate (SAL-043). Totals: Subtotal, Pajak, Pembulatan, Total. Calc engine pure JVM core-calc shared vectors with PHP (contracts/calc-vectors). Device computes own total; server calc_variance flag (CF-07, AQ-2 open).
- Discount: Stretch (S33), presets with reason, CSH L1 / SPV L2.
- Split bill: Stretch (per item / rata N / per nominal; VAL-POS-004 sum mismatch); R1 workaround = multiple tenders.
- Tender: multiple tenders (PAY-011); non-cash <= remaining (PAY-013); change only cash; order auto-closes at Sisa 0; receipt number gapless terminal sequence (CMN-017/019/063).
  - Cash: Uang pas + 2-3 round-ups; Kembalian display size.
  - EDC BCA standalone: show amount to key in; approval code 6-12 alnum auto-upper (VAL-PAY-002); no card brand/last4 (CF-05); batch total per EDC at shift close (PAY-023).
  - QRIS static GoPay: record Pending verifikasi -> verify with merchant-app reference by SPV (or CSH with override); never from customer screenshot (PAY-026); Tandai gagal; disabled offline; pending blocks other non-cash tenders (PAY-014) and shift close.
  - States: Initiated, Pending, Succeeded (Lunas), Failed, Requires verification, Recorded locally, Synchronized.
  - Duplicate protection: 600 ms debounce, shared idempotency key (EC-POS-001), same method+amount within 2 min asks confirm.
  - Rounding on tender: Stretch. Refund: Stretch, online, full, current business date, override.
- Shift: open requires scheduled shift suggestion + float counted per denomination (zero float allowed with confirm, ASM-M5; differing from handover flagged not blocked); only one open shift per terminal (POS-057); tender needs open shift on terminal, order taking does not (CF-08); cash in/out with reason code, cash-out above recorded cash rejected (VAL-POS-008), above threshold override; X-report (own for CSH; SPV all; offline footnote "Angka perangkat, belum tersinkron"); close gates: online, outbox empty, no pending QRIS -> blind count per denomination (expected never shown) + EDC batch total -> confirm irreversible -> reveal expected/counted/variance (Lebih/Kurang) -> variance over tolerance needs SPV/OMG excluding cashier -> Z-report auto-printed -> back to S02. Multiple shifts per day/handover POS-065. Business date from server offset; locked day -> "Hari bisnis ... sudah ditutup", late events quarantined.
- Offline: allowed = orders (own tables), fire, cash, EDC, reprint, open shift, cash in/out, X-report, 86 (ASM-M2, device-local, OQ-M3), overrides w/ cached verifier. Blocked = static QRIS, QRIS verify, close shift, refund, PIN change, transfer/merge, tables of other device. Limits 4 h offline or Rp 3.000.000 unsynced cash per device (working values, OQ-039, POS-096/079, DVC-011, ASM-064); warning at 50% cash exposure; at limit header "Offline terbatas": new orders need OMG override, new cash blocked, EDC still allowed. Offline duration on monotonic clock. Clock skew >5 min banner (DVC-010).
- Reprint: first copy free(CSH O/M), later copies need override per limit (BRL-POS-007), always "SALINAN n"; retry from print queue is not a SALINAN (PRT-017, BRL-PRT-002).
- Printing: PrinterPort direct from tablet; KOT/BATAL 3 retries -> fallback printer -> queued Gagal + persistent header chip + line tag "KOT belum tercetak"; receipt failure -> on-screen receipt (PRT-026); templates 58/80 mm fixed, no QR (PRT-029/023); printers assigned in web.
- Performance: menu/search <=300 ms; local action <=200 ms P95; cold start <=2 s (assumption); PIN unlock <=500 ms; backlog 5,000 events <=15 min (NFR-OFF-005); counter flow 6 taps (budget 8, POS-083).
- UX: touch >=48dp, primary 56dp, keypad 72dp (64 phone), no swipe actions, in-app keypad for money (UXP-19), long-press 550 ms on tile -> 86 sheet. Adaptive: Compact <600 (nav bar, order bar), Medium 600-839 (rail, 320dp pane), Expanded 840-1199 (pilot, 380dp pane, 4 cols), Large >=1200 (420dp, 5 cols). M3 seed #EC3013; success/warning custom roles; kiosk lock-task + landscape lock (M-09).

## 4. Architecture
- Layers: presentation (Compose, ViewModel StateFlow<UiState> + Channel<UiEvent>) -> use case -> domain (pure Kotlin; calc engine, state machines, OfflinePolicy.check -> Allowed | Blocked(reason) | NeedsOverride(role, limit)) -> repository ports -> data (Room/encrypted DB + outbox, API client, sync engine, printer adapters, Keystore secure store). Modules: app, core-calc (pure JVM), optional core-escpos. Ports: OrderRepository, ShiftRepository, ReleaseRepository, RosterRepository, OutboxRepository, PrinterPort, PaymentPort, SecureStore, Clock, Connectivity. PaymentPort: CashTender, ManualEdcTender, StaticQrisTender (future IntegratedEdc, DynamicQris).
- Write path: every intent -> use case -> ONE local transaction (domain rows + outbox row + counters + receipt number) via TransactionRunner; UI confirms after commit; sync drains later.
- Local DB: "Room over SQLCipher (or equivalent encrypted SQLite), WAL" [REC library]; TR-093, NFR-OFF-006. Secure storage: Keystore + EncryptedFile/DataStore with Tink [REC]; backup disabled. Printer: PrinterPort -> EscPosRenderer (core-escpos) -> LAN socket / Bluetooth SPP / USB host / vendor SDK (TR-094).
- Background sync: WorkManager (unique periodic + expedited one-shot) + foreground coroutine loop while visible; triggers commit/reconnect/timer/manual; batches <=50 events (<=512 KB, assumption); backoff 5 s to 5 min; ack deletes outbox rows.
- Connectivity state: Online | Syncing(pending) | Offline(since, pending) | OfflineLimited(reason).
- Heartbeat: POST /devices/heartbeat (telemetry, clock offset, release and version signals); device health S23: connectivity, last sync, outbox count+oldest age, release version, clock skew, printers, offline cash exposure, app version. Revoked device: refused everywhere except drain of POST /sync/batches (seq <= last heartbeat seq and occurred_at not after revocation, else quarantined).
- Auth/token model (Arch 3.6): device credential 256-bit secret (hash server-side, Keystore-wrapped on tablet, valid until revoked); user token opaque access 15 min + rotating single-use refresh 12 h absolute (ASSUMPTION values), reuse revokes family; opaque DB-validated tokens not JWT (ADR 0003); sync authenticates the DEVICE not user (each event names actor_user_id, server checks assignment/permission/limit at ingest). Pairing code valid 15 min, one terminal binding.
- Endpoints (Arch 3.5, base `https://api.<DOMAIN>/api/v1`, JSON, decimal-string money, Idempotency-Key on every POST, cursor pagination, error body {code,message,details,correlation_id}):
  1 POST /sync/batches; 2 GET /sync/snapshots/menu (release bundle: menu, prices, tax, rounding, rules, routing, printers, tables); 3 GET /sync/snapshots/staff (roster + PIN verifiers); 4 POST /devices/pairings; 5 POST /devices/heartbeat; 6 POST /auth/pin-sessions; 7 POST /auth/token-refresh; 8 DELETE /auth/sessions/current; 9 POST /auth/pin-changes; 10 POST /overrides; 11-13 reports/jobs (web); 14 POST /platform/tenants; 15 GET /health/live|ready.
- Sync event types (exact): order.opened; order.line.added; order.line.removed; order.fired; order.line.voided; order.cancelled; order.prebill.printed; payment.recorded; payment.cancelled; payment.verified (online only); order.closed; receipt.reprinted; shift.opened; shift.closed; cash.recorded; availability.set; print.reported; override.blocked. Not events in R1: force-close, EOD, back-office 86, refund, split, merge, transfer.
- Batch request fields: batch_id, device_time, events[]{event_id, seq, type, occurred_at, actor_user_id, release_version, payload}. order.opened payload: order_id, order_type, service_mode, table_id, pax, customer_label, queue_number. order.line.added payload: order_id, line_id, item_id, quantity (string), modifiers[{option_id, price_delta}], unit_price, note. Response: data{batch_id, ack_seq, results[{event_id, seq, status, flags[]}], server_state{latest_release_version, last_closed_business_date, server_time}}.
- Result statuses: accepted (flags calc_variance, clock_skew_suspect, release_stale, revoked_user_offline), rejected (final, reason+Bahasa message), quarantined (final, OMG decides in web), deferred (seq gap/transient, resend). ack_seq = highest seq below which all events final.
- Conflict rules: duplicate event_id returns stored result (idempotent); no merge, device keeps its record, VERSION_CONFLICT -> reload server state for that order; table with two orders after sync -> OMG merges in web (EC-POS-003); BUSINESS_DAY_CLOSED -> quarantine; menu release applied whole between orders (PRO-048); AQ-6 table status between tablets open.
- Error codes: 401 UNAUTHENTICATED, TOKEN_EXPIRED, DEVICE_REVOKED, PIN_INVALID; 403 FORBIDDEN, OUT_OF_SCOPE, OVERRIDE_REQUIRED, DEVICE_SUSPENDED, TENANT_SUSPENDED, ACCOUNT_LOCKED; 404 NOT_FOUND; 409 VERSION_CONFLICT, IDEMPOTENCY_PAYLOAD_MISMATCH, REQUEST_IN_PROGRESS, BUSINESS_DAY_CLOSED, SHIFT_NOT_OPEN, TAX_RATE_MISSING, CLIENT_VERSION_UNSUPPORTED, INVALID_STATE_TRANSITION; 422 VALIDATION_FAILED; 429 RATE_LIMITED, OVERRIDE_BLOCKED.
- Navigation: roots Pairing, Roster+PIN, Shell; nested Meja, Counter, Order, Shift, plus Order editor, Tender, Payment complete, Closed order detail, X-report, Close shift, Device & sync, Print queue, Settings, Ubah PIN; sheets (Modifier, Line actions, Pax, EDC, QRIS, Cash in/out, Availability); dialogs (Void/cancel, Override stackable, Duplicate payment, Zero float, Submit count). No deep links in R1. Back from order editor never discards.
- Suggested UiState: OrderEditorUiState, TenderUiState (Ready, EnteringEdc, QrisPending, ConfirmDuplicate, Completed, Blocked), CloseShiftUiState (Gate, Counting, NeedsApproval, Closed).

## 5. Tech decisions / ADRs
- Fixed: Kotlin native, three layers (ADR-0010); Material 3 on Compose, seed #EC3013, Roboto Flex (DEC-018, ADR-0011) -- versions unverified; spec-first openapi.yaml DTOs (ADR-0009); opaque tokens (ADR-0003).
- [REC], all unversioned: Navigation-Compose type-safe routes; Room over SQLCipher (WAL); WorkManager; OkHttp+Retrofit or Ktor, kotlinx.serialization; Hilt; Timber (release tree strips tokens/PIN); Tink; Material Symbols Rounded; lock-task kiosk; crash reporting optional (OQ-M5).
- Mobile ADRs proposed: M-01 Compose+WindowSizeClass; M-02 three-pane editor on Expanded/chips on Medium/grid+order bar on Compact; M-03 custom success/warning roles; M-04 connectivity once in header, sync outcomes only on rejection; M-05 OfflinePolicy drives enforcement and disabled copy; M-06 in-app numeric keypad; M-07 destructive confirm = filled error button with icon + verb; M-08 Stretch behind flags in release bundle; M-09 kiosk + landscape lock.
- Repo ADR files: docs/adr/0001-0011 (0010 local-first Android client, 0011 design system).

## 6. Open questions / placeholders
- OQ-M1 phone class at pilot?; OQ-M2 bill-lock setting (ADM-043) vs POS-042 Stretch; OQ-M3 86 offline; OQ-M4 runbook for DB corruption with unsynced events; OQ-M5 crash reporting vendor/location (also OQ-026); OQ-M6 QRIS amount on customer display. Existing: OQ-049 device/printer model; OQ-039 offline limits; OQ-023 limit values; AQ-2 authoritative total; AQ-3 EOD in web; AQ-6 table status between tablets; AQ-1 single write channel; AQ-4 locked-day quarantine; AQ-7 revoked drain.
- Assumptions: ASM-M1 login lockout 5/15; ASM-M2 86 offline local; ASM-M3 landing destinations; ASM-M4 idle lock 5 min; ASM-M5 zero float with confirm; ASM-M6 expired verifier keeps session; A17 no dynamic color; A18 color values approximate.
- Placeholders: 10% tax, discount limits, cash-out threshold Rp 200.000, variance tolerance Rp 5.000, sample menu/photos, QRIS standee drawn placeholder (real one physical), dark mode auto 18:00-06:00 is a recommendation.
- Note: the Spec HTML files do not reproduce Arch 3.5 text; section 4 endpoints/events were taken from docs/architecture. Spec Part 3 section 10 / ADR M-03/M-07 revised to M3 on 2 Oct (Arch doc C17 says older Modernist wording may remain).
