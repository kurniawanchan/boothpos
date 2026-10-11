**Classification**: INTERNAL

# Cashier-flow baseline (SC-010 / FR-065)

**Rule**: the cashier flow must take no more steps than before the redesign. A *step* is one deliberate user action (click, type, select).

## Procedure (retail POS, as `kasir01`, DEMO mode, local dev stack)

Start on the Dashboard with no open shift, then:

| # | Action |
|---|---|
| 1 | open "Cashier Session" from the navigation |
| 2 | type the opening cash (Rp 100.000) |
| 3 | "Open session" (the active event is preselected) |
| 4 | open "POS" from the navigation |
| 5–6 | add item 1: tap the product, tap its variant in the picker |
| 7–8 | add item 2: product, variant |
| 9–10 | add item 3: product, variant |
| 11 | "Pay" |
| 12 | "Confirm & save transaction" (cash is the default method; exact amount preselected) |
| 13 | dismiss the receipt ("Transaksi berikutnya") |
| 14 | open "Cashier Session" |
| 15 | type the counted cash (Rp 200.000) |
| 16 | "Close session" |

The restaurant (floor/tab/kitchen) leg is **not** measured here: the seeded `Kasir` role reaches it only through the "Restaurant" navigation group (2 clicks in both the old and the new frame, group + item), and its screens are not changed until US14. Measure it with the same method before US14 starts.

## Results (2026-10-11)

| | Steps | Outcome checked in the database |
|---|---|---|
| **BEFORE** — old frame (`AppSidebar`/`AppTopbar`), built from commit `d426ef6` in a separate worktree and served by the same backend | **16** | session closed, expected 200.000 = counted 200.000, difference 0.00 |
| **AFTER** — new frame (rail, top bar) + page kit, cashier screens untouched | **16** | session closed, expected 200.000 = counted 200.000, difference 0.00 |

**Verdict: no increase (16 → 16).** Navigation to both screens stays one click (the rail items are top-level links, as the old sidebar items were).

Elapsed wall time was also recorded (BEFORE 144 s, AFTER 56 s) but is **not comparable**: the BEFORE run included diagnostic page inspections between steps and one mistaken click that I excluded from the step count (a click on the picker's close button). Use steps as the metric. Repeat both runs with the same script before claiming any time result.

## Notes

- The only console errors in both runs are `404 GET /api/v1/sessions/current`, returned when no shift is open (existing behaviour, not a regression).
- US14 (restyle of the cashier screens) must re-run this procedure and keep the step count at 16 or below.
