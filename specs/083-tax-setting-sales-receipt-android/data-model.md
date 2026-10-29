# Data Model: Pengaturan Pajak (083)

Tidak ada tabel atau kolom baru. Satu baris `settings` baru; kolom order yang sudah ada dipakai ulang.

## Pengaturan pajak (tabel `settings`, grup `pos`)

| Key | Tipe | Bawaan | Aturan |
|---|---|---|---|
| `tax_rate` | decimal | 0 | Angka 0–100, maksimal 2 desimal. Di luar itu ditolak (422) saat disimpan; `PosSettings::taxRate()` tetap jatuh ke 0 untuk nilai rusak yang sudah ada. |
| `tax_label` | string | `Pajak` | Dipangkas; kosong = bawaan; maksimal 30 karakter (lebih ditolak 422). |
| `tax_show` | boolean | **true** | Hanya mengatur TAMPILAN baris pajak. Dibaca lewat `filter_var(..., FILTER_VALIDATE_BOOLEAN)`. Tidak pernah memengaruhi total. |

- Satu set untuk seluruh instalasi, dipakai bersama mode DEMO dan LIVE (research D8).
- Perubahan tercatat di log aktivitas per kunci (jalur `PUT /settings` yang sudah ada), berisi nilai lama dan baru.

## Catatan pajak per order (SUDAH ADA, tidak diubah)

| Kolom `orders` | Arti |
|---|---|
| `tax_amount` | Pajak yang ditagihkan, rupiah penuh, dihitung server saat order dibuat. |
| `tax_rate_snapshot` | Tarif (persen) yang berlaku saat order dibuat. |
| `total_amount` | `subtotal − diskon + tax_amount`. |

Aturan tampil (bukan data): baris pajak tampil hanya bila `tax_show` aktif DAN `tax_amount > 0`. Order lama bertarif 0 tidak menampilkan baris.

## Transisi dan invarian

- Mengubah `tax_rate` hanya memengaruhi order yang dibuat sesudahnya; order tersimpan tidak pernah dihitung ulang.
- `tax_show = false` tidak mengubah satu angka pun; hanya menyembunyikan baris.
- Tarif 0 menghasilkan `tax_amount = 0` dan tidak ada baris, berapa pun `tax_show`.
