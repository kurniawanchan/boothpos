# Kontrak: GET /api/v1/settings/features (perubahan aditif)

Auth: `auth:sanctum`, semua peran (tidak berubah).

Field baru:

| Field | Tipe | Keterangan |
|---|---|---|
| `store_logo_url` | string \| null | URL logo toko dari `store_logo_path` (disk `public`, diselesaikan `ImageUploadService::url`); `null` bila belum diunggah |

Field lain tidak berubah (`store_name` sudah mengikuti mode, `app_name` sudah ada). `docs/openapi-pos-mvp.yaml` diperbarui pada commit yang sama.
