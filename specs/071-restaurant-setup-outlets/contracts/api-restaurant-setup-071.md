# Kontrak API: 071

**Klasifikasi**: INTERNAL

Perubahan tambahan-saja. `docs/openapi-pos-mvp.yaml` diperbarui dalam komit yang sama.

## GET /dining-tables, GET /stations

Parameter baru (opsional): `outlet_id[]` — array integer. Dipotong dengan outlet yang boleh dilihat pengguna (dan dengan header `X-Outlet-Id` bila ada); hasil kosong bila tak satu pun boleh. Nilai bukan integer → 422. Bentuk respons tidak berubah (`outlet_id` sudah ada pada tiap baris).

## PUT /dining-tables/{id}, PUT /stations/{id}

Bidang baru (opsional): `outlet_id` (integer). Sama dengan outlet saat ini → diabaikan.

| Kondisi | Hasil |
|---|---|
| outlet tujuan tidak boleh dilihat pengguna | 403 `{code: outlet_forbidden}` |
| outlet tujuan tidak ada | 422 `outlet_id` |
| nama sudah dipakai di outlet tujuan | 422 `name` |
| meja punya order `open` | 409 `{message}` (`restaurant.table_move_open_tab`) |
| stasiun dipakai kategori/item menu | 409 (`restaurant.station_move_in_use`) |
| stasiun punya tiket dapur belum selesai | 409 (`restaurant.station_move_open_tickets`) |
| berhasil | 200, resource dengan `outlet_id` baru; log aktivitas `moved` (lama → baru) |

Perubahan lain (nama, area, kursi, urutan, aktif) pada permintaan yang sama berlaku bersama pemindahan atau tidak sama sekali (satu transaksi).
