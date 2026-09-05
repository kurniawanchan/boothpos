# Contract: New API Endpoints

All endpoints below sit under `/api/v1`, require `auth:sanctum`, and are additionally gated on the new `companies` menu key (`$user->canAccessMenu('companies')`) via each resource's Policy — owner/admin only (FR-012, research.md R1). `docs/openapi-pos-mvp.yaml` must be updated with these in the same commit that adds them (Constitution's Documentation & Change Discipline rule).

Response envelope, status-code convention (`422`/`409`/`403`), and pagination shape (`{"data": [...], "meta": {...}}`) all follow this codebase's existing conventions unchanged.

## Business Types

```
GET    /business-types              list (paginated, ?is_active=, ?search=)
POST   /business-types               create
GET    /business-types/{id}          show
PUT    /business-types/{id}          update
DELETE /business-types/{id}          delete — 409 if referenced by any Company
```

## Packages

```
GET    /packages                     list (paginated, ?is_active=, ?license_tier=)
POST   /packages                     create
GET    /packages/{id}                show
PUT    /packages/{id}                update
DELETE /packages/{id}                delete — 409 if referenced by any Company
```

## Companies

```
GET    /companies                              list (paginated, ?status=, ?business_type_id=, ?package_id=, ?search=)
POST   /companies                               onboard — creates Company (status: pending_activation) +
                                                 inactive owner User + sends activation code
GET    /companies/{id}                          show (full detail incl. status, business type, package, contact)
POST   /companies/{id}/resend-activation        invalidates the previous code, issues + sends a new one
                                                 — 409 if company is already active
POST   /companies/{id}/activate                 body: {"code": "123456"} — throttle:10,1 (research.md R5)
                                                 — 200 + activated company on success
                                                 — 422 wrong code / expired / already active (distinguishable
                                                   error per FR-008)
```

### `POST /companies` request shape

```json
{
  "business_type_id": 1,
  "package_id": 1,
  "name": "Toko Contoh",
  "address": "Jl. Merdeka No. 1, Jakarta",
  "contact_name": "Budi Santoso",
  "contact_email": "budi@contoh.com",
  "contact_phone": "0812-3456-7890",
  "owner_username": "owner_tokocontoh",
  "owner_password": "a-strong-password"
}
```

### `POST /companies/{id}/activate` request/response shape

Request: `{"code": "123456"}`

Success (200):
```json
{
  "id": 1,
  "status": "active",
  "activated_at": "2026-09-05T12:00:00Z",
  "...": "rest of CompanyResource fields"
}
```

Failure (422): `{"message": "...", "errors": {"code": ["Kode aktivasi tidak valid." | "Kode aktivasi sudah kedaluwarsa." | "Perusahaan ini sudah aktif."]}}`
