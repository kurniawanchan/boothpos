**Classification**: INTERNAL

# Evidence — Stage 0: constitution amendment 2.0.0 (2026-10-11)

**Approval**: the product owner replied "approved" to the amendment text in `constitution-amendment.md` on 2026-10-11, the same day as the clarifications Q1 = C, Q2 = B and stock/transfers per outlet.

**Applied to**:
- `.specify/memory/constitution.md` — Sync Impact Report prepended (1.0.0 → 2.0.0); Stack paragraph replaced; Principle IV paragraph (server-derived outlet, narrowing only, one shared access mechanism) and Principle V sentence (cache keys and computed-at time) added; `Version 2.0.0`, `Last Amended 2026-10-11`.
- `CLAUDE.md` — dated note in "What this is" and a dated bullet in the environment-constraints list; the original sentences are kept.
- `docs/PRD-POS-Event-Multivendor.md` — dated notes before §2 and before §10.2 (seller → outlet meaning; scope cut not reversed for consignment sales).
- `docs/RUNBOOK.md` — §12 outlet migration, rehearsal, backup and recovery.

**Verification**: `grep` confirms the version line, the new Stack paragraph and both principle additions; no PHP was changed in this stage.

**Owner's decisions** (the three questions in the draft): wording approved as drafted; licence rule Pro = one outlet, Master = several (already the seller limit); shape (b) permitted, not built in 045.
