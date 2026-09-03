# Contract: `SakanaFridgeDemoSeeder` CLI invocation

## Invocation

```bash
php artisan db:seed --class=SakanaFridgeDemoSeeder
```

Not wired into `DatabaseSeeder::run()` unconditionally — it is invoked
explicitly (own class), the same way `php artisan db:seed` today only runs
the base accounts/settings/payment-channels seed. Rationale: this is
dummy/demo content, not something every fresh install (including a real
shopkeeper's production install) should get without asking; keeping it a
separate, explicitly-named seeder class matches this codebase's existing
opt-in pattern for anything demo/sample (CLAUDE.md already treats seeded
dev accounts as local-only, "seeded dev accounts... local only").

## Idempotency contract (FR-003)

Running the command twice (or N times) MUST leave the database in the same
state as running it once:

- Store profile (`settings.store_name`), event, artists, categories,
  vendors, materials: upserted by a natural unique key (`code` for
  artists/categories/vendors/materials; event matched by `name` since events
  have no natural unique key in the schema — matched as
  `Event::firstOrCreate(['name' => 'Demo Sakana Fridge Meet & Greet Vol.1', 'data_mode' => 'demo'])`
  scoped to `data_mode = 'demo'` so a same-named LIVE event, if one ever
  exists, is never touched).
- Products/variants: upserted by `code_prefix`/`sku` (already the natural
  keys enforced by unique constraints in `docs/schema-pos-mvp.sql`).
- Customers: upserted by `(name, phone)` pair (customers have no unique
  constraint in the schema; the seeder treats this pair as its own
  idempotency key for seed purposes only — it is not a schema change).
- Sales (orders) and pre-orders are **not** re-created or duplicated on a
  second run — the seeder checks whether any `data_mode = 'demo'` order/
  preorder already exists for the seeded event and skips transaction
  generation entirely if so (an order is a point-in-time transaction record,
  not master data with a natural key to upsert against).

## Preconditions / postconditions

- **Precondition**: migrations have run (`php artisan migrate`), including
  the new `add_data_mode_to_business_tables` migration.
- **Precondition**: base `DatabaseSeeder` has run first (owner/admin/cashier
  accounts and a default `payment_channels` row must exist, since seeded
  orders need a `user_id` cashier and non-cash payments need a `channel_id`).
- **Postcondition**: every row this seeder creates has `data_mode = 'demo'`
  (enforced via `ModeGate::runAs('demo', ...)` — see research.md Decision 2),
  regardless of whatever `system_mode` setting was active before the command
  ran, and regardless of whatever it's left as after (the seeder does not
  itself change `system_mode`; an operator who wants to *see* the seeded
  data still switches to DEMO mode separately via `PUT /settings`).
- **Postcondition**: `php artisan test` (the full existing 214+ test suite)
  still passes unmodified afterward — the seeder must not be a prerequisite
  for, or break, any existing Feature test's assumptions about a clean
  `RefreshDatabase` state.
