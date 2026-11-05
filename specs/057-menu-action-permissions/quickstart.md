# Quickstart / verification: Per-Menu Action Permissions

1. **Back-compat**: before applying, note what Kasir/Inventory/Admin/Owner can do on Sales, Pre-orders, Products; after migrate, repeat — identical (US4).
2. **Read-only role**: Roles → new role "Finance viewer" → Sales: View only → assign to a test user → sign in: Sales opens; no Void / payment / verify / reference-edit / export / receipts buttons; `curl` a void/verify/payment call with that token → 403 `action_not_allowed`.
3. **Partial role**: Sales = View + Void only → Void appears and works; Verify does not.
4. **No widening**: custom role (no `settings`) with every action on Sales → still refused where owner/admin-only (delete payment) (US5).
5. **Editor**: View-only / Full-access shortcuts; unticking a menu clears its actions; saving then reopening shows the same; Owner row locked; lock-out guard 409 when removing the last full `users`+`roles` role.
6. **Import/export**: export roles → re-import (same); import an old menu-only file → full access; bad action → row error.
7. **Live change**: change the role while the user is signed in → their next request obeys; screen refreshes permissions on the 403.
8. Both languages; console clean; real browser (owner + a restricted cashier).
