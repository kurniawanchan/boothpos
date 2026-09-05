# Data Model: License Activation Gate

## `license_activations`

Installation-level security data — NOT `HasDataMode`-scoped (research.md R7), same category as `payment_channels`/`activity_logs`. At most one row ever exists (single-tenant installation).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `license_id` | string(64) | Copied from the verified key payload — audit/support reference only, never itself re-checked for validity after activation |
| `issued_to` | string(150) | Copied from the verified payload (client email/store name) — audit/support reference only |
| `machine_fingerprint_hash` | string | `Hash::make()` of the current machine's fingerprint (research.md R3/R7) — one-way, never reversed |
| `activated_at` | timestamp | |
| `created_at`/`updated_at` | timestamps | |

No soft delete, no foreign keys — this table has no relationship to any other entity in the system; it exists purely to answer "is this specific machine currently activated."

## Conceptual entities (from spec.md, not additional tables)

- **License**: Not persisted anywhere in this app — it is a signed, self-contained credential (research.md R2) that exists only as the string the vendor emails and the operator pastes in. Its payload (`license_id`, `issued_to`, `issued_at`) is verified via signature at the moment of submission; only the *result* of that verification (the fields above) is persisted, in `license_activations`.

## Validation rules (from spec.md's functional requirements)

- A submitted key MUST parse into `base64(json).base64(signature)` shape, decode to valid JSON with the three required fields, and its signature MUST verify against the app's baked-in public key (research.md R1) — any failure at any of these steps is a single, generic "invalid license key" rejection (FR-003's "without revealing information that would help someone construct a working key by trial and error" — the failure reason is not more specific than that at the API level, even though the specific internal reason may be logged for the vendor's own troubleshooting).
- `LicenseActivationService::isActivated()` (FR-006, fail-closed): `true` only if a `license_activations` row exists AND `Hash::check(current_machine_fingerprint, $row->machine_fingerprint_hash)` succeeds. Every other condition (no row, a thrown exception while computing the current fingerprint, a hash mismatch) resolves to `false`.
- Activation (`POST /license/activate`) always (re)binds on any successfully-verified key: it upserts the single `license_activations` row to the *current* machine's fingerprint, regardless of whether a row already existed (and regardless of what machine it was previously bound to). This is deliberately simple rather than a special-cased "already activated" branch — it is both what makes re-activation after a legitimate hardware change (spec.md's Edge Cases) self-service with a valid key, and precisely the mechanism research.md R8 documents as the honest limitation (the same code path that enables legitimate recovery is what makes cross-machine key reuse undetectable offline).
