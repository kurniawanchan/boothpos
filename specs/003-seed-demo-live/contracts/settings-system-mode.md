# Contract: System Mode via existing Settings endpoints

No new routes. Two existing endpoints gain a new field / a new accepted key.

## `GET /api/v1/settings/features`

Existing endpoint (`SettingsController::features`), currently returns:

```json
{
  "multi_artist_enabled": true,
  "artist_count": 2,
  "artist_limit_reached": false
}
```

**Change**: add `system_mode`.

```json
{
  "multi_artist_enabled": true,
  "artist_count": 2,
  "artist_limit_reached": false,
  "system_mode": "live"
}
```

- `system_mode`: `"demo" | "live"`. Sourced from `ModeGate::current()`.
- Visible to **every authenticated role** (matches FR-005: status must be
  visible to all users, not just owner/admin) — this endpoint is not
  policy-gated today and stays that way for this field.
- OpenAPI: extend the `FeaturesResponse` schema (or equivalent inline
  schema) in `docs/openapi-pos-mvp.yaml` for this path with the new
  `system_mode` property, `enum: [demo, live]`.

## `PUT /api/v1/settings`

Existing bulk endpoint (`SettingsController::update`, gated by
`SettingPolicy::update` → `canAccessMenu('settings')`). Accepts the new key
like any other:

```json
{
  "settings": [
    { "key": "system_mode", "value": "demo", "type": "string", "group": "system" }
  ]
}
```

**Change**: `UpdateSettingsRequest::rules()` gains one closure-validated
constraint, following the exact pattern already used for
`store_contact_email` in that same file — when `key === 'system_mode'`,
`value` must be one of `demo`/`live`:

```php
if ($key === 'system_mode' && ! in_array($value, ['demo', 'live'], true)) {
    $fail('Mode sistem harus salah satu dari: demo, live.');
}
```

- **Authorization**: unchanged — already `canAccessMenu('settings')`, which
  by default only Owner/Admin roles carry (FR-013). No new Policy method.
- **Side effects on success**: unchanged path already writes an
  `ActivityLogger` entry inside the same DB transaction as the setting
  write (FR-011 — "siapa yang mengubah dan kapan" is exactly what
  `ActivityLogger::log()` already records: `user_id` + `created_at`).
- **Response**: unchanged shape (`{"data": [SettingResource, ...]}`).

## Not built: a dedicated `/system-mode` resource

Rejected — see research.md Decision 5. `system_mode` is a single scalar
setting, structurally identical to `multi_artist_enabled`; giving it its own
CRUD surface would duplicate `SettingsController`/`SettingPolicy` for no
behavioral gain, violating Constitution I's "no duplicated write path"
rule.
