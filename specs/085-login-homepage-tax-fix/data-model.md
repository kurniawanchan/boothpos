# Data Model: 085

**Klasifikasi**: INTERNAL

**Tidak ada perubahan skema database dan tidak ada migrasi.** Fitur ini memakai data yang sudah ada.

## Entitas yang dibaca

| Entitas | Dipakai untuk | Sumber |
|---------|---------------|--------|
| Pengaturan pajak (`settings`: `tax_rate`, `tax_label`, `tax_show`) | Tarif, label, dan tampil/sembunyi baris pajak | Ditulis `PUT /settings` (owner/admin); dibaca `PosSettings` (satu-satunya pembaca) |
| Pesanan (`orders.tax_amount`, `orders.tax_rate_snapshot`, `orders.total_amount`) | Snapshot pajak per pesanan; tidak berubah bila tarif berganti | `TaxCalculator::compute` lewat `OrderService::create` dan `TabService::recalculate` |
| Konfigurasi tablet (`store.config`: nama outlet, nama terminal) | Bagian Info Outlet | Penyiapan terminal (064/073) |
| Branding tanpa token (`GET /pos-setup/branding`: mode, nama toko, logo) | Logo, nama, lencana mode di beranda | 077 |
| Roster staf (`GET /staff/roster`: id, nama, peran, `has_pin`, `photo_url`) | Kartu pengguna | 073/078 |

## Aturan yang tidak berubah

- Pajak = `round_half_up(max(0, subtotal − diskon) × tarif ÷ 100)` per pesanan, rupiah penuh (`TaxCalculator`).
- `tax_show` hanya menentukan tampil/tidaknya baris; tagihan tetap memuat pajak.
- Pesanan lama tidak pernah dihitung ulang karena tarif berubah (snapshot).

## Model sisi klien baru (Android, bukan basis data)

- `PinLayout` kehilangan nilai `THREE_COLUMN`; tersisa `TWO_COLUMN` dan `STACKED`.
- `StaffRow` tetap (`user`, `expanded`, `selectable`); `expanded` berarti "terpilih", tidak lagi mengubah ukuran baris.
