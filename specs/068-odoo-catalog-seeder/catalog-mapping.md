# Tabel pemetaan katalog WJM (usulan)

**Klasifikasi**: CONFIDENTIAL — katalog nyata milik pelanggan (Warkop WJM).

**Status**: Disetujui — semua usulan di tabel ini diterima apa adanya, termasuk harga Nasi Putih Rp0, keenam varian paket Ayam Rp18.000, dan grup "Rasa" yang tidak ditautkan (spec FR-007).

**Disetujui oleh (peran)**: Pemilik · **Tanggal**: 2026-10-25

**Outlet**: Warkop WJM 3 · **Kode usulan**: WJ3, WJM · **Buat outlet**: mode kosong

**Tarik item lain dari menu outlet**: LIVE tidak, DEMO ya

**Stasiun**: Bar = Bar, Dapur Minuman; Dapur = Dapur, Dapur Makanan

Tiga baris di atas ditambahkan 2026-10-25 oleh fitur 069 (katalog WJM 2). Isinya mempertahankan perilaku 068: outlet dicari menurut nama dan dibuat hanya bila mode kosong, item contoh hanya ditarik di DEMO, dan stasiun LIVE yang sudah diganti nama ("Dapur Minuman", "Dapur Makanan") dikenali sebagai peran Bar dan Dapur.

**Stok awal**: 100

Stok awal tiap varian baru di outlet tujuan. Bila diisi angka, pelacakan stok dinyalakan; "—" berarti tanpa pelacakan stok (FR-013). Ditambahkan 2026-10-25 atas permintaan pengembang.

**Perubahan 2026-10-25 (sesudah persetujuan)**: harga 19 varian, nama 6 varian paket Ayam, dan foto Drink Beng-Beng disalin dari data yang disunting di mode DEMO. Lihat `## Harga varian khusus`, `## Nama varian khusus`, dan `## Foto pengganti`.

**Sumber**: tiga ekspor Odoo WJM 3 ([source-analysis.md](./source-analysis.md)). Kolom kategori dan varian di bawah adalah **inferensi** dari nama item, jumlah varian Odoo, dan nama BoM. Inferensi ini belum diverifikasi.

## Ringkasan usulan

| Kategori | Stasiun | Item | Varian |
|---|---|---|---|
| Add on | Dapur | 8 | 9 |
| Coffee | Bar | 8 | 12 |
| Indomie | Dapur | 5 | 14 |
| Mie & Pangsit Level | Dapur | 4 | 4 |
| Nasi | Dapur | 5 | 11 |
| Non Coffee | Bar | 17 | 27 |
| Roti & Pisang | Dapur | 10 | 10 |
| Signature Coffee | Bar | 5 | 6 |
| Snacks | Dapur | 15 | 15 |
| Tea | Bar | 2 | 3 |
| **Total** | | **79** | **111** |

- Total varian 111 sama dengan jumlah yang tersirat di ekspor Odoo (kolom "# Product Variants"), dan jumlah per item juga cocok.
- Atribut yang dipakai: Varian, Varian kopi, variant indomie, Nasi, Ayam, Buah, Paket.
- Atribut yang **dikecualikan** karena tidak dipakai item mana pun: Paket Hemat, Paket Komplit, Paket Kenyang (versi lama dari "Paket"), dan nilai "test".
- Grup pilihan "Rasa" (Hazelnut, Vanilla, Gula Aren): dibuat, **belum ditautkan** ke item mana pun.
- Harga setiap varian = harga item, kecuali pemilik menetapkan lain di bawah.

## Pertanyaan untuk pemilik

1. **Hot/Ice** — Benarkah 14 minuman ini bervarian Hot/Ice, dengan harga Ice sama dengan Hot? ABC Klepon, Chocolatos Coklat, Chocolatos Matcha, Dancow Coklat, Dancow Vanila, Drink Beng-Beng, Good Day Freeze All, Good day All Variant, Indocafe Coffeemix, Lemon Tea, Milo, Nutrisari All Variant, Susu Jahe, Susu Putih.
2. **Americano** — Variannya "1 shoot / 2 shoot" (usulan) atau Hot/Ice? Berapa harga 2 shoot?
3. **Good Day Freeze All** — Apa dua variannya? "Freeze" biasanya disajikan dingin, jadi Hot/Ice diragukan.
4. **Rasa Indomie** — Usulan: Indomie Goreng = Goreng Original, Rendang, Mie Aceh; Indomie Kuah = Soto, Ayam Bawang, Kari Ayam; Indomie Double = keenam rasa. Benar?
5. **Ayam Penyet & Bakar Cabe Ijo** — Berapa harga paket Hemat, Komplit, dan Kenyang? (Odoo hanya memberi Rp18.000 untuk semua.) Apakah nama varian "Penyet Hemat", "Bakar Komplit", dst. (diambil dari nama BoM Odoo) sudah tepat?
6. **Nasi Putih** — Harga 1 porsi dan ½ porsi? Di Odoo harganya Rp0. Masuk kategori Add on (usulan) atau Nasi?
7. **Jus** — Apakah Jambu dan Alpukat sama-sama Rp15.000?
8. **Kategori yang ragu** — Americano dan Cappuchino (Signature Coffee atau Coffee?); Mie Nyemek dan Omelette Mie (Indomie atau Mie & Pangsit Level?); Pangsit Goreng (Snacks atau Mie & Pangsit Level?); Sukro (Snacks atau Add on?); Ayam Penyet / Bakar (Nasi atau Add on?); Air Mineral (Non Coffee atau Add on?); Sate Kulit (Add on atau Snacks?); ABC Klepon dan ABC Susu (Coffee atau Non Coffee?).
9. **Rasa (Hazelnut/Vanilla/Gula Aren)** — Dipakai sebagai pilihan di item mana? Usulan: belum ditautkan.
10. **Es Batu** — Disiapkan di Bar atau Dapur? Kategori Add on mengarah ke Dapur.

Alternatif untuk menjawab 1–4: bila diizinkan, berkas `pos.order` bisa menunjukkan varian yang benar-benar terjual. Data pelanggan di berkas itu harus dimasking dulu.

## Pemetaan per item

Yakin: **T** = tinggi, **S** = sedang, **R** = rendah (perlu keputusan pemilik). "Standard" = satu varian tanpa atribut.

### Signature Coffee (Bar)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| Americano | 10.000 | Varian kopi: 1 shoot, 2 shoot | R | Bisa juga Hot/Ice (P2); kategori ragu (P8) |
| Cappuchino | 15.000 | Standard | S | Kategori ragu (P8); ejaan sesuai sumber |
| Caramel Latte | 17.000 | Standard | T | |
| Hazelnut Latte | 17.000 | Standard | T | |
| Kopi Susu Gula Aren | 15.000 | Standard | S | |

### Coffee (Bar)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| ABC Klepon | 7.000 | Varian: Hot, Ice | S | P1, P8 |
| ABC Susu | 7.000 | Standard | S | P8; foto terpotong |
| Good Day Freeze All | 7.000 | Varian: Hot, Ice | R | P3 |
| Good day All Variant | 7.000 | Varian: Hot, Ice | S | P1 |
| Indocafe Coffeemix | 7.000 | Varian: Hot, Ice | S | P1 |
| Kapal Api | 7.000 | Standard | T | |
| Kopi Liong | 7.000 | Standard | T | |
| Luwak White Coffee | 7.000 | Standard | T | |

### Non Coffee (Bar)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| Air Mineral | 5.000 | Standard | S | P8 |
| Chocolatos Coklat | 7.000 | Varian: Hot, Ice | S | P1 |
| Chocolatos Matcha | 7.000 | Varian: Hot, Ice | S | P1 |
| Dancow Coklat | 8.000 | Varian: Hot, Ice | S | P1 |
| Dancow Vanila | 8.000 | Varian: Hot, Ice | S | P1 |
| Drink Beng-Beng | 7.000 | Varian: Hot, Ice | S | P1; foto terpotong |
| ES JERUK | 12.000 | Standard | S | Foto terpotong |
| Extrajoss | 7.000 | Standard | S | |
| Extrajoss Susu | 10.000 | Standard | S | |
| Jus | 15.000 | Buah: Jambu, Alpukat | T | P7; tanpa foto |
| Kukubima | 7.000 | Standard | S | |
| Kukubima Susu | 10.000 | Standard | S | |
| Milo | 8.000 | Varian: Hot, Ice | S | P1 |
| Nutrisari All Variant | 5.000 | Varian: Hot, Ice | S | P1 |
| Soda Susu | 10.000 | Standard | T | |
| Susu Jahe | 7.000 | Varian: Hot, Ice | S | P1 |
| Susu Putih | 5.000 | Varian: Hot, Ice | S | P1; tanpa foto |

### Tea (Bar)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| Es Teh | 3.000 | Standard | T | Tanpa foto |
| Lemon Tea | 7.000 | Varian: Hot, Ice | S | P1 |

### Indomie (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| Indomie Double | 15.000 | variant indomie: SOTO, AYAM BAWANG, KARI AYAM, GORENG ORIGINAL, RENDANG, MIE ACEH | T | Foto sama dengan Indomie Kuah |
| Indomie Goreng | 8.000 | variant indomie: GORENG ORIGINAL, RENDANG, MIE ACEH | S | P4 |
| Indomie Kuah | 8.000 | variant indomie: SOTO, AYAM BAWANG, KARI AYAM | S | P4; ber-BoM |
| Mie Nyemek | 15.000 | Standard | R | P8; kit; tanpa foto |
| Omelette Mie | 15.000 | Standard | R | P8; kit |

### Mie & Pangsit Level (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| Mie Level 1-2 | 15.000 | Standard | T | Foto sama dengan Mie Level 3-5 |
| Mie Level 3-5 | 17.000 | Standard | T | |
| Pangsit Chillie Oil lvl 1-2 | 15.000 | Standard | T | Foto sama dengan lvl 3-5 |
| Pangsit Chillie Oil lvl 3-5 | 17.000 | Standard | T | |

### Nasi (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| Ayam Penyet & Bakar Cabe Ijo | 18.000 | Ayam × Paket → Penyet Hemat, Penyet Komplit, Penyet Kenyang, Bakar Hemat, Bakar Komplit, Bakar Kenyang | T (varian), S (kategori) | P5; kit; tanpa foto |
| Ayam Penyet / Bakar | 15.000 | Ayam: Penyet, Bakar | S | P8; tanpa foto |
| Chicken Ricebowl Cabe Hijau | 22.000 | Standard | T | Foto sama dengan Sambal Bawang |
| Chicken Ricebowl Sambal Bawang | 22.000 | Standard | T | |
| Nasi Goreng Spesial | 15.000 | Standard | T | Kit |

### Roti & Pisang (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| Pisang Bakar Coklat | 13.000 | Standard | T | |
| Pisang Bakar Coklat Keju | 15.000 | Standard | T | |
| Pisang Bakar Keju | 13.000 | Standard | T | |
| Roti Bakar Blueberry | 13.000 | Standard | T | |
| Roti Bakar Choco Crunchy | 15.000 | Standard | T | |
| Roti Bakar Coklat | 13.000 | Standard | T | |
| Roti Bakar Coklat Keju | 15.000 | Standard | T | |
| Roti Bakar Keju | 13.000 | Standard | T | |
| Roti Bakar Strawberry | 13.000 | Standard | T | |
| Roti Bakar Tiramisu Crunchy | 15.000 | Standard | T | |

### Snacks (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| Bakso Aci + Telor | 15.000 | Standard | S | Kit |
| Basreng | 6.000 | Standard | T | |
| Cireng Isi Ayam Pedas | 15.000 | Standard | T | |
| Cireng Rujak | 13.000 | Standard | T | |
| DIMSUM GORENG KEJU LUMER | 20.000 | Standard | T | Foto terpotong |
| DIMSUM GORENG KEJU LUMER SAUS MENTAI | 25.000 | Standard | T | Foto terpotong |
| Dimsum Isi 4 | 20.000 | Standard | T | |
| Dimsum Mentai Isi 4 | 25.000 | Standard | T | |
| Kentang Goreng | 13.000 | Standard | T | |
| MARTABAK TELUR KORNET | 15.000 | Standard | S | Foto terpotong |
| Mix Platter (Sosis, Otak² & Kentang) | 20.000 | Standard | T | |
| Otak-otak Goreng | 13.000 | Standard | T | |
| Pangsit Goreng | 15.000 | Standard | R | P8 |
| Sosis Bakar | 13.000 | Standard | T | |
| Sukro | 2.000 | Standard | R | P8; tanpa foto |

### Add on (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| Es Batu | 2.000 | Standard | T | P10 |
| Keju | 5.000 | Standard | T | |
| Kerupuk | 3.000 | Standard | T | Tanpa foto |
| Kol Goreng | 5.000 | Standard | T | Tanpa foto |
| Kornet | 5.000 | Standard | T | Kit |
| Nasi Putih | 0 | Nasi: 1 Porsi, ½ Porsi | S | P6; tanpa foto |
| Sambal Cabe Ijo | 2.000 | Standard | T | Tanpa foto |
| Sate Kulit (Tusuk) | 3.000 | Standard | S | P8; tanpa foto |

## Harga varian khusus

Varian yang harganya berbeda dari harga item (jawaban P2, P5, P6, P7). Tanpa baris berarti semua varian memakai harga item.

| Item | Varian | Harga (Rp) |
|---|---|---|
| ABC Klepon | Ice | 8.000 |
| Americano | 2 shoot | 12.000 |
| Ayam Penyet & Bakar Cabe Ijo | Penyet Komplit | 23.000 |
| Ayam Penyet & Bakar Cabe Ijo | Penyet Kenyang | 28.000 |
| Ayam Penyet & Bakar Cabe Ijo | Bakar Komplit | 23.000 |
| Ayam Penyet & Bakar Cabe Ijo | Bakar Kenyang | 28.000 |
| Chocolatos Coklat | Ice | 8.000 |
| Chocolatos Matcha | Ice | 8.000 |
| Dancow Coklat | Ice | 10.000 |
| Dancow Vanila | Ice | 10.000 |
| Drink Beng-Beng | Ice | 8.000 |
| Good Day Freeze All | Ice | 8.000 |
| Good day All Variant | Ice | 8.000 |
| Indocafe Coffeemix | Ice | 8.000 |
| Lemon Tea | Ice | 8.000 |
| Milo | Ice | 10.000 |
| Nutrisari All Variant | Ice | 7.000 |
| Susu Jahe | Ice | 8.000 |
| Susu Putih | Ice | 7.000 |

## Nama varian khusus

Varian yang namanya diganti dari nama hasil hitungan atribut. Kolom "Varian" memakai nama hasil hitungan, sama seperti tabel harga di atas.

| Item | Varian | Nama |
|---|---|---|
| Ayam Penyet & Bakar Cabe Ijo | Penyet Hemat | Penyet Hemat (Nasi + Ayam Penyet) |
| Ayam Penyet & Bakar Cabe Ijo | Penyet Komplit | Penyet Komplit (Nasi Ayam Penyet + Tahu, Tempe + Sate Kulit) |
| Ayam Penyet & Bakar Cabe Ijo | Penyet Kenyang | Penyet Kenyang (Nasi Ayam Penyet + Tahu, Tempe + Sate Kulit + Es Teh) |
| Ayam Penyet & Bakar Cabe Ijo | Bakar Hemat | Bakar Hemat (Nasi + Ayam Bakar) |
| Ayam Penyet & Bakar Cabe Ijo | Bakar Komplit | Bakar Komplit (Nasi Ayam Bakar + Tahu, Tempe + Sate Kulit) |
| Ayam Penyet & Bakar Cabe Ijo | Bakar Kenyang | Bakar Kenyang (Nasi Ayam Bakar + Tahu, Tempe + Sate Kulit + Es Teh) |

## Foto pengganti

Foto untuk item yang fotonya terpotong atau kosong di ekspor XLSX. Jalurnya relatif terhadap folder tabel ini.

| Item | Berkas |
|---|---|
| Drink Beng-Beng | photo-overrides/drink-beng-beng.jpg |

## Grup pilihan

Item yang memakai grup pilihan (jawaban P9). Tanpa baris berarti grup dibuat tanpa tautan.

| Grup | Item |
|---|---|

## Dikecualikan

| Baris sumber | Alasan |
|---|---|
| Partial Payment (`nf_partial_payment`, Rp0) | Produk teknis Odoo, bukan menu |
| Atribut Paket Hemat, Paket Komplit, Paket Kenyang | Tidak dipakai item mana pun; digantikan "Paket" |
| Nilai "Paket Hemat: test" | Data uji |
