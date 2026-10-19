# Data Model: 054 Server Address and Connection State

## Settings rows (existing `settings` table, no migration)

| key | type | group | value | default when row absent |
|---|---|---|---|---|
| `server_address_local` | string | `server` | absolute `http`/`https` URL with host, or null | null |
| `server_address_online` | string | `server` | absolute `http`/`https` URL with host, or null | null |
| `server_address_selected` | string | `server` | `local` or `online` | `local` |

**Rules** (enforced in `UpdateServerAddressRequest` and `App\Support\ServerAddress`):
- A URL, when present, must parse with a scheme and host; no path other than `/` is required, and a trailing slash is normalised away.
- `server_address_selected = online` requires `server_address_online` to be set; otherwise 422 on the field `selected`.
- `server_address_selected = local` requires `server_address_local` to be set **or** null (null means "keep the tablet's paired address"). Recorded here so the empty case is deliberate, not accidental.
- Writing the three values is one transaction that also writes one `activity_logs` row (action `server_address_updated`, with the before and after values of the three keys; no credential is involved).

## Resolution (what a tablet uses)

```
selectedUrl(selected, local, online):
  if selected == "online": return online       # may be null only if validation was bypassed; treated as null
  if selected == "local":  return local        # null → keep paired address
```

The device endpoint returns `{selected, url}` where `url` is the result above. It never returns `local` and `online` together.

## Android state

### `Conn` (existing enum, one new value)

| Value | Label (Indonesian) | Meaning |
|---|---|---|
| `UNKNOWN` (new, initial) | Memeriksa koneksi… | No check has succeeded or failed yet |
| `ONLINE` | Online | Last sign-in-screen or signed-in call succeeded |
| `OFFLINE` | Offline | Last call failed with a network error |
| `OFFLINE_LIMITED` | Offline terbatas | Existing, unchanged |

**Transitions** (single writer `PosStore`):
- Start → `UNKNOWN`.
- Roster success or heartbeat success → `ONLINE`.
- Roster or heartbeat `ApiError.Network` → `OFFLINE`.
- Any non-network error (for example 401, 423, 5xx) → unchanged. FR-006: a non-connection error must not set `OFFLINE`.

### `TerminalConfig` (existing, unchanged shape)

`serverUrl` is the paired address. It is overwritten only when the device endpoint returns a non-null `url` that differs from the stored value (option A, after a successful contact).

## Validation summary for the admin endpoint

| Field | Rule | Failure |
|---|---|---|
| `local` | nullable, `http`/`https` URL with host | 422 on `local` |
| `online` | nullable, `http`/`https` URL with host | 422 on `online` |
| `selected` | required, in `local`, `online` | 422 on `selected` |
| `selected` = `online` and `online` null | refused | 422 on `selected` |
