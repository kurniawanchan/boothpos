# Contract: Server Address (054)

**Constitution**: `docs/openapi-pos-mvp.yaml` must receive these three operations in the same commit as the routes. This file is the working contract until that edit lands.

All responses are JSON. Errors follow the store convention: `422` field validation, `403` role denial, `401` no session.

## 1. Admin: read the server address

`GET /api/v1/settings/server-address`

- **Auth**: signed-in user with the `settings` menu (owner/admin). Other roles → `403`.
- **200**

```json
{
  "local": "http://192.168.1.10:8010",
  "online": "https://pos.example.com",
  "selected": "online"
}
```

Null fields are returned as `null`. Nothing else is included.

## 2. Admin: change the server address

`PUT /api/v1/settings/server-address`

- **Auth**: same as above; checked by the policy before validation runs.
- **Body**

```json
{
  "local": "http://192.168.1.10:8010",
  "online": "https://pos.example.com",
  "selected": "online"
}
```

- **200**: the same shape as the GET.
- **422** examples:
  - `{"errors": {"online": ["Alamat server online harus berupa URL http atau https yang lengkap."]}}`
  - `{"errors": {"selected": ["Pilih online hanya jika alamat online sudah diisi."]}}`
- **Side effects**: one transaction writes the three settings rows and one `activity_logs` row (`server_address_updated`). No row is written on a 422.

## 3. Device: which server address to use

`GET /api/v1/devices/server-address`

- **Auth**: the tablet's admin token from setup (`auth:sanctum`, same as the staff roster), plus header `X-Device-Id: <device id>`. The device must exist and not be revoked, using the same check as `POST /devices/heartbeat` (`DeviceController::activeDevice`). No staff PIN needed; the tablet calls this after the roster succeeds, before sign-in.
- **200**

```json
{
  "selected": "online",
  "url": "https://pos.example.com"
}
```

- `url` is `null` when the selected address is not set (for example `local` with no local address); the tablet then keeps its paired address.
- **Refused**: missing, unknown, or revoked device → `422` with field `device_id` (same as heartbeat).
- **Never returned**: the other address, the admin's identity, or any token.

## Android client behaviour (for the implementer)

1. On PIN screen entry and every 10 s while it is shown: silent roster request (`GET` staff list). Success → `Conn.ONLINE`; then call (3). Network error → `Conn.OFFLINE`.
2. Call (3) only after a successful roster request. If `url` is non-null and differs from the stored `serverUrl`, store it and rebuild the API client.
3. A change of address never resets an open shift; the next signed-in call simply uses the new base URL.
