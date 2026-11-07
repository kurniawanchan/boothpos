# Perubahan Kontrak API: 092

**Klasifikasi**: INTERNAL

Semua perubahan HARUS masuk `docs/openapi-pos-mvp.yaml` di commit yang sama (PRD §9.5). Tidak ada rute baru, tidak ada izin baru, tidak ada perubahan skema database. 422 bentuk/validasi, 409 aturan bisnis, 403 peran (konvensi).

## 1. Bidang varian di payload menu POS

Berlaku di `GET /products?pos=1&with_variants=1` dan `GET /variants/lookup` (bentuk varian yang sama dengan `ProductVariantResource` mode `pos` dan `ProductController` baris 619–622).

Untuk varian **beresep** (punya resep aktif):

| Bidang | Nilai | Catatan |
|---|---|---|
| `track_stock` | `true` | bila ada bahan pembatas aktif; `false` bila tak ada (stok tak ber-angka, tanpa batas qty) |
| `outlet_stock` | integer ≥ 0 | **stok menu** = kapasitas bahan penghambat dikurangi tahanan tab terbuka, di outlet pemesanan (outlet shift pengguna) |
| `stock_basis` | `"recipe"` | **baru**, tambahan. Varian tanpa resep: `"variant"` |
| `stock_limiting` | `{material_id, name, unit, on_hand, per_portion}` atau `null` | **baru**, tambahan. `on_hand` = saldo SETELAH tahanan tab terbuka (yang masih bisa dipakai); `on_hand` dan `per_portion` string tiga desimal. `null` bila stok menu tak dibatasi atau bukan beresep |

Untuk varian **tanpa resep** semua bidang lama persis seperti sekarang; hanya `stock_basis: "variant"` ditambahkan.

Kompatibilitas: APK lama dan web lama hanya membaca `track_stock` dan `outlet_stock` (F6), jadi menampilkan stok menu dan menahan qty tanpa perubahan klien. Bidang baru diabaikan (`ignoreUnknownKeys`).

Payload pengelolaan (tanpa `pos=1`): `current_stock` tetap mentah; `stock_tracked` memakai aturan baru (varian beresep = `false`); `stock_basis` ikut dikirim.

## 2. Penolakan pesanan

Berlaku di `POST /orders`, `POST /tabs/{id}/items`, `PATCH /tabs/{id}/items/{item}` (hanya bila qty naik atau pilihan diganti), `POST /tabs/{id}/payments` (saat melunasi), `POST /tabs/{id}/split`, dan `POST /self-service/...` saat dispatch (lewat `TabService::addLine`).

```
409
{
  "message": "Stok bahan tidak cukup untuk <menu>: <bahan> tersisa <on_hand> <unit>, dibutuhkan <required>.",
  "code": "insufficient_recipe_stock",
  "limiting": { "material_id": 12, "name": "Telur", "unit": "pcs", "on_hand": "3.000", "required": "4.000", "available_portions": 3 }
}
```

- `required` dan `on_hand` adalah jumlah SETELAH tahanan tab lain.
- Seluruh transaksi digulung: tidak ada baris, tidak ada pergerakan bahan, tidak ada pembayaran.
- Hanya kebutuhan yang berasal dari **resep** yang ditolak. Bahan dari pilihan modifier tetap memicu `material_warnings` (087), bukan 409.
- Permintaan TANPA pengguna login (rute publik pesan mandiri, 090) memakai kode yang sama tetapi `limiting` hanya `{available_portions}`, tanpa nama/id/jumlah bahan, karena itu detail internal toko. Kiriman publik melebihi stok ditolak SEBELUM pelanggan membayar (penyaring awal; penentu tetap `assertCanFulfil` saat staf mengirim ke dapur). Menu beresep yang bahannya habis tampil `sold_out` di `GET /self-service/{token}/menu`.
- Pesanan retry (`local_ref`/`client_ref`/`submission_ref` sama) tetap dikembalikan lebih awal sebelum penjagaan dan tidak memotong lagi.

## 3. Yang TIDAK berubah

- `GET /menu/availability` (tanda 86/diskontinu/versi) dan ETag-nya.
- `POST /orders` bentuk tubuh dan idempotensi.
- `GET /materials`, `GET /materials/movements`, `restoreOrder` (void/refund), `wasteLine`.
- Koreksi stok varian untuk varian beresep ditolak 409 `stock_source_modifier` (kode yang sudah ada), konsisten dengan sumber `modifier`.
