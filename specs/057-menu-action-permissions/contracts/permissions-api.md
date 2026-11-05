# Contract: permissions

## `GET /auth/me` (additive)

```json
{ "...": "existing fields incl. menu_keys",
  "permissions": { "sales": ["view","verify","export"], "pos": ["view","create"] } }
```
Menus the role cannot open are absent. Derived server-side from `Role::allows()`.

## Roles API (additive, backwards compatible)

`POST/PUT /roles` body: existing `name`, `menu_keys` **+ optional `menu_actions`** `{ "sales": ["void"] }`. Omitted ⇒ unchanged on update / full on create. Responses (`RoleResource`) add `menu_actions` (resolved: always explicit per menu) and `is_locked` (Owner).
Errors: 422 unknown menu/action or menu not in `menu_keys`; 422 editing Owner's actions; 409 would leave no role with full actions on `users` and `roles` (existing message family).
`GET /menu-keys` (additive): each item gains `actions: [{key, label, standard: bool}]` so the editor renders the grid without a second hard-coded list.

## Denial

Any mutating (or export) route without the grant: `403 { "message": "<translated>", "code": "action_not_allowed", "menu": "sales", "action": "void" }`. No data changes; not written to the activity log.

## Activity log

Role create/update logs `menu_actions` old/new values in the same transaction (existing logger).

## Import/export

`roles` sheet gains optional column `menu_actions` (see data-model.md); old files unchanged.
