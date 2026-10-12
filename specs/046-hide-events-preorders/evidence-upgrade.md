**Classification**: INTERNAL

# Bukti pemutakhiran 046 (gladi pada salinan, 2026-10-13)

`sh specs/046-hide-events-preorders/rehearse.sh` — salinan database dev (1 event, 2 pre-order, 3 pembayaran pre-order, 5 order,
44 pergerakan stok, 3 shift) dimundurkan ke skema pra-046, satu peran diberi kunci menu `events`/`preorders`, lalu dimigrasikan maju.

| Pemeriksaan | Hasil |
|---|---|
| `event_id` pada `cashier_sessions`, `orders`, `outlet_settlements` | nullable (`YES`) |
| Kunci menu peran Owner | `events` dan `preorders` hilang, kunci lain utuh |
| `tools/totals.sh` sebelum vs sesudah (jumlah baris `events`/`preorders`/`preorder_items`/`payments`/`stock_movements`, Σ pembayaran, Σ order per tanggal bisnis, Σ pembayaran per metode, kas shift) | **identik** (diff kosong) |
| `outlets:reconcile --compare` | **REKONSILIASI OK — tidak ada selisih** (I1/I2 stok, kolom outlet) |
| Dev DB (`warkop_wjm`) setelah migrasi sungguhan | total sama dengan `baseline.md` |

Catatan: dump `app:backup` tidak dapat dimuat ke MySQL 8 (kolom turunan `orders.open_table_key`, kekurangan 045 yang sudah tercatat),
sehingga gladi memakai `mysqldump --complete-insert` dari database dev.
