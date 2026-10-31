**Klasifikasi**: INTERNAL

# Contract: Tetangga pre-order & urutan daftar

## 1. `GET /api/v1/preorders/{id}/neighbors` (BARU)

Mengembalikan pre-order sebelum dan sesudah `{id}` di daftar Pre-order dengan filter dan urutan yang sama seperti `GET /preorders`.

**Auth**: `auth:sanctum`, sama dengan `GET /preorders` dan `GET /preorders/{id}` (tidak ada aturan peran baru). Pre-order dari mode DEMO/LIVE lain → `404` (route-model binding + `DataModeScope`).

**Query** (semua opsional, arti identik dengan `GET /preorders`):
`status[]`, `dispatch_status[]`, `fulfillment[]`, `artist_id[]`, `event_id`, `customer_id`, `search`, `sort_by`, `sort_dir`.
`page`/`per_page` boleh dikirim dan diabaikan.

**200** — pre-order ada di daftar:

```json
{
  "in_list": true,
  "position": 8,
  "total": 41,
  "previous": { "id": 57, "preorder_number": "PO-20260927-0011" },
  "next": { "id": 48, "preorder_number": "PO-20260927-0009" }
}
```

**200** — baris pertama: `"position": 1, "previous": null`; baris terakhir: `"next": null`; satu-satunya baris: keduanya `null`.

**200** — tidak lolos filter aktif:

```json
{ "in_list": false, "position": null, "total": 41, "previous": null, "next": null }
```

**404** — pre-order tidak ada / dihapus / milik mode lain.

**Tidak ada efek samping** (hanya baca; tidak ada `activity_logs`).

**Routing**: `Route::get('/preorders/{preorder}/neighbors', …)` — segmen statis setelah `{preorder}`, tidak bertabrakan dengan `apiResource` (preseden: `/preorders/{preorder}/invoice`).

## 2. `GET /api/v1/preorders` (DIUBAH — perilaku saja)

Bentuk respons tidak berubah. Urutan kini selalu diakhiri `preorders.id` dengan arah yang sama dengan `sort_dir` (bawaan: `created_at desc, id desc`), sehingga baris bernilai sama tampil dalam urutan tetap dan tidak pernah muncul di dua halaman.

## 3. OpenAPI

`docs/openapi-pos-mvp.yaml` diperbarui dalam commit yang sama:
- path baru `/preorders/{id}/neighbors` (tag `Preorder`) dengan parameter di atas dan skema `PreorderNeighbors` (`in_list`, `position` nullable, `total`, `previous`/`next` nullable `{id, preorder_number}`);
- catatan pengikat urutan pada deskripsi `sort_by` di `GET /preorders`.

## 4. Kontrak UI (PreordersView → panel detail)

- Header panel: judul (nomor) + subjudul (pelanggan · fulfillment) seperti sekarang, ditambah kelompok tombol `‹ <nomor sebelumnya>` dan `<nomor berikutnya> ›` di samping tombol tutup.
- `data-testid`: `preorder-nav-prev`, `preorder-nav-next`.
- Tombol nonaktif (tetap terlihat, `BaseTooltip` alasan) bila: tidak ada tetangga (pertama/terakhir), `in_list = false`, tetangga sedang dimuat, atau form pengiriman inline terbuka.
- `aria-label`: "Pre-order sebelumnya: {nomor}" / "Pre-order berikutnya: {nomor}" (dan padanan Inggris).
- `ArrowLeft`/`ArrowRight` di dalam panel (bukan di kolom isian) = klik tombol yang aktif.
- Kunci i18n baru di `preorders.*` pada `en.json` dan `id.json` (dijaga `qa-tests/unit/localeKeys.test.js`): `nav_previous_aria`, `nav_next_aria`, `nav_first`, `nav_last`, `nav_not_in_list`, `nav_busy`, `nav_target_gone`.
