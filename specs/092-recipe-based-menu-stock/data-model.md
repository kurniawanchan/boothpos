# Data Model: Stok Menu Berbasis Resep

**Klasifikasi**: INTERNAL

## Ringkasan

**Tidak ada tabel atau kolom baru, tidak ada migrasi.** Stok menu beresep adalah nilai TURUNAN yang dihitung saat daftar dimuat dari data yang sudah ada (research D1, D4). Tidak ada angka stok baru yang disimpan, jadi tidak ada yang bisa menyimpang dari sumbernya.

## Entitas yang dibaca (sudah ada)

| Entitas | Dipakai untuk | Catatan |
|---|---|---|
| `recipes` (`archived_at`, `effective_from`, `yield`) | menentukan varian beresep dan Portions | aktif = `Recipe::active()` |
| `recipe_variants` | himpunan varian tiap resep | menentukan "menu beresep" |
| `recipe_blocks` (`variant_id`, `modifier_option_id`) | blok DASAR (opsi null) menentukan kapasitas; blok bermodifier hanya ikut penjaga saat pilihannya dipesan | |
| `recipe_lines` (`material_id`, `quantity`, `shrinkage_pct`) | kebutuhan per porsi | rumus tunggal (D2) |
| `materials` (`is_active`, soft delete) | bahan nonaktif/terhapus dilewati, sama seperti pemotongan | |
| `material_outlet_stock` (`current_stock` decimal 14,3) | saldo outlet = sumber kebenaran | dikunci `FOR UPDATE` saat penjagaan |
| `material_stock_movements` | jejak pemotongan (`sale`), pembalikan (`return`) | tidak berubah |
| `orders` (`status = open`, `outlet_id`) dan `order_items` (`variant_id`, `qty`, `modifiers`, `stock_source`) | `tertahan` oleh tab terbuka | tidak ada tabel reservasi |

## Nilai turunan (tidak disimpan)

### MenuStock (per varian beresep per outlet)

| Bidang | Arti | Aturan |
|---|---|---|
| `portions` | stok menu | `min` atas bahan dari `floor((saldo − tertahan + 0,0005) ÷ kebutuhanPerPorsi)`, ≥ 0 |
| `basis` | `recipe` | varian punya resep aktif |
| `limiting` | bahan penghambat | `{material_id, name, unit, on_hand, per_portion}`; seri → id terkecil |
| `unbounded` | true bila tak ada bahan pembatas aktif | stok null, tanpa batas qty |

### Kebutuhan bahan (per bahan, dari resep)

`kebutuhanPerPorsi(bahan) = Σ_resepAktif Σ_barisBlokDasar quantity ÷ (1 − susut%/100) ÷ yield`. Bahan nonaktif/terhapus dilewati. Rumus ini satu fungsi bersama pemotongan (D2).

### Tertahan (per bahan per outlet)

`tertahan(bahan, outlet) = Σ kebutuhan RESEP seluruh baris pada pesanan 'open' di outlet itu`, dikecualikan pesanan yang sedang diperiksa.

## Perubahan aturan pada entitas yang ada

| Aturan | Sebelum | Sesudah |
|---|---|---|
| `ProductVariant::tracksOwnStock()` | `track_stock` DAN `stock_source = variant` | + varian TIDAK punya resep aktif (D6) |
| `order_items.stock_source` baris baru | `variant` bila `tracksOwnStock()` | tak berubah; menu beresep kini otomatis `modifier` |
| Penjualan menu beresep dengan bahan kurang | diterima, saldo minus + peringatan | DITOLAK 409 `insufficient_recipe_stock` (kebutuhan resep saja); kebutuhan pilihan modifier tetap peringatan |

## Transisi / siklus hidup

1. Resep dibuat/diaktifkan (atau `effective_from` tiba) → varian menjadi beresep: stok varian berhenti bergerak untuk baris BARU; baris tab lama bersnapshot `variant` tetap menutup ke varian.
2. Resep diarsipkan/dihapus → varian kembali memakai stok varian (angka lama dilanjutkan; tidak ada penulisan ulang).
3. Baris masuk tab → tahan bahan (turunan, tanpa tulis). Baris dihapus/diubah turun/tab batal → tahanan lepas.
4. Pesanan selesai → `consumeOrder` memotong bahan (satu `sale` per bahan per baris) dan `tertahan` pesanan itu hilang karena statusnya bukan `open` lagi, di transaksi yang sama.
5. Void/refund → `restoreOrder` membaca buku besar (tak berubah).

## Invarian yang dijaga

- Saldo bahan tidak pernah minus karena kebutuhan RESEP (penjaga + kunci baris, D5). Minus lama (warisan) bertahan dan dibaca sebagai kapasitas 0.
- Stok varian menu beresep tidak bergerak untuk baris baru.
- Satu pemotongan per pesanan selesai (sabuk pengaman buku besar di `consumeOrder` dipertahankan).
