**Classification**: INTERNAL

# API contract — customers/loyalty, reports, users/roles/audit (Phase 7)

Legend: **R** reason code required · **A** approval path.

## Members and loyalty

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /members?q=&tier=&favourite_outlet=` | list with tier, points, visits, lifetime spend, last visit | `crm.member.view` | phone/email masked without `prv.pii.reveal` (server-side masking, not CSS) |
| `GET /members/summary` | active members, today's member visits, points outstanding (and liability estimate), average rating | `crm.member.view` | |
| `POST/PUT /members` | create/update incl. `birth_date`, consent choices | `crm.member.manage` | consent per channel/purpose required before promos/e-receipts |
| `POST /members/{id}/points` | manual adjustment (`delta`, `reason_code_id`) | `crm.points.adjust` | **R**; ledger append-only |
| `POST /members/{id}/anonymise` | data-subject deletion | `prv.pii.delete` | **R**; keeps transaction totals; audited |
| `POST /members/export` | export | `prv.pii.reveal` | **R**; goes through export history |
| `POST /members/bulk-voucher` | issue vouchers to selected members | `crm.voucher.issue` | per-row result |
| `GET/POST/PUT /loyalty-tiers` | thresholds, earn rate, perks, redemption, expiry | `crm.tier.manage` | changes apply from the effective date; audited |
| `GET /feedback` · `POST /feedback/{id}/follow-up` | list; mark followed up | `crm.feedback.view/manage` | |

## Report catalogue

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /report-catalog` | 22 reports: id (REP-nnn), group, description, frequency, formats | `rpt.view` | groups: Penjualan, Kas & shift, Kontrol & audit, Inventori & biaya, Pembelian |
| `GET /reports/{id}/preview?…` | preview rows (limited) with data-freshness time and date basis | `rpt.view` | states "business date, cut-off HH:MM" and pending-sync warning |
| `POST /reports/{id}/export` | export `csv|xlsx|pdf` | `rpt.export` | queued into export history; personal-data reports need **R** |
| `GET/POST/DELETE /report-schedules` | schedule (frequency, outlets, format, recipients) | `rpt.schedule` | recipients validated; runs from the scheduler |
| `GET /report-exports` · `GET …/{id}/download` | history with status (processing/ready/expired/failed) and expiry | `rpt.view` | private disk; authorised download; expired files return `410` |

All reports read one shared sales source and the outlet filter helper, so totals equal Dashboard/Penjualan for the same business date and outlet set. Existing endpoints (`/reports/sales`, `/profit`, `/purchases`, …) remain and are re-expressed through the same runners.

## Users, roles, audit

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /users?q=&role=&scope=&status=` | users with role, outlet scope, POS PIN state, MFA, last active | `iam.user.view` | |
| `POST /users/invite` | invite (name, email, role, outlets, POS access, MFA requirement) | `iam.user.manage` | invitation state until accepted; PIN for POS set by an outlet manager |
| `PUT /users/{id}` | edit access | `iam.user.manage` | audited; cannot demote the last owner |
| `POST /users/{id}/unlock` · `/reset-pin` | account unlock, PIN reset | `iam.user.manage` | audited |
| `POST /users/{id}/deactivate` | deactivate | `iam.user.manage` | **R** |
| `POST /auth/approver-pin/verify` | approver PIN for overrides | signed-in | 5 wrong → account locked, override blocked 15 min; response never reveals which part failed |
| `GET /users/summary` | active users (back office / POS), pending invitations, locked accounts, custom roles | `iam.user.view` | |
| `GET /audit?actor=&action=&object=&outlet=&from=&to=` | audit trail with before/after | `iam.audit.view` | read-only for auditor role; PII fields in values masked without privilege |
| `GET /roles` · `PUT /roles/{id}` | see foundation (matrix) | `iam.role.manage` | |

MFA enrolment/verification endpoints are part of Phase 7 and follow the same throttling as login; their exact shape is fixed in that phase's contract once the MFA method is chosen (UNVERIFIED: method and any library not yet selected).
