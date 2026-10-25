# Analisis sumber: ekspor Odoo Warkop WJM 3

**Klasifikasi**: CONFIDENTIAL — katalog nyata milik pelanggan (Warkop WJM). Kolom nama staf ("Responsible") sengaja tidak dikutip.

**Tanggal analisis**: 2026-10-24 · **Fitur**: [spec.md](./spec.md)

## Ringkasan

- Tiga berkas terbaca utuh: **10 kategori POS**, **11 atribut dengan 26 nilai**, **80 baris produk** (79 item menu + 1 produk teknis Odoo).
- **Celah utama**: ekspor produk **tidak memuat kategori POS, atribut per produk, maupun harga tambahan per varian**. Kolom "# Product Variants" hanya memberi *jumlah* varian (1, 2, 3, atau 6), bukan atributnya. Tanpa sumber tambahan, item tidak bisa ditaruh di kategori dan variannya tidak bisa dibentuk dengan benar.
- Yang bisa dipakai langsung: nama, harga jual, 61 foto (57 berbeda), daftar kategori, daftar atribut dan nilainya.
- Yang tidak layak dibawa: stok (ada yang negatif), harga pokok (semua 0), struktur kit/BoM (komponennya tidak ada di ekspor), nama staf (data pribadi).

## Berkas 1 — Point of Sale Category (`pos.category`)

| Kolom | Isi |
|---|---|
| Sequence | Semua 0 |
| Display Name | 10 nama, datar (tidak ada induk/anak) |
| Image | Semua kosong |

Kategori (urutan sumber): Add on · Coffee · Indomie · Mie & Pangsit Level · Nasi · Non Coffee · Roti & Pisang · Signature Coffee · Snacks · Tea

## Berkas 2 — Product Attribute (`product.attribute`)

Satu baris per nilai; baris lanjutan mengosongkan kolom atribut (format ekspor Odoo). Semua "Display Type" = Radio.

| Atribut | Variants Creation Mode | Nilai |
|---|---|---|
| Varian | Instantly | Hot, Ice |
| variant indomie | Instantly | SOTO, AYAM BAWANG, KARI AYAM, GORENG ORIGINAL, RENDANG, MIE ACEH |
| Varian kopi | Instantly | 1 shoot, 2 shoot |
| Nasi | Instantly | 1 Porsi, ½ Porsi |
| Rasa | **Never (option)** | Hazelnut, Vanilla, Gula Aren |
| Paket Hemat | Instantly | Nasi + Ayam, **test** |
| Paket Komplit | Instantly | Nasi + Ayam + Tahu, Tempe + Sate Kulit |
| Paket Kenyang | Instantly | Nasi + Ayam + Tahu, Tempe + Sate Kulit + Es Tesh |
| Ayam | Instantly | Penyet, Bakar |
| Buah | Instantly | Jambu, Alpukat |
| Paket | Instantly | Hemat (Nasi + Ayam Penyet / Bakar) · Komplit (Nasi Ayam Penyet / Bakar + Tahu, Tempe + Sate Kulit) · Kenyang (Nasi Ayam Penyet / Bakar + Tahu, Tempe + Sate Kulit + Es Teh) |

- "Instantly" = membentuk varian; "Never (option)" = hanya pilihan pada pesanan, tidak membentuk varian.
- "Paket Hemat: test" adalah data uji.
- **Inferensi (ASSUMPTION)**: "Paket Hemat/Komplit/Kenyang" (satu nilai bermakna masing-masing) tampaknya versi lama yang digantikan atribut "Paket" (3 nilai).

## Berkas 3 — Product Template (`product.template`)

85 baris data: 80 produk + 5 baris lanjutan (BoM ke-2 s.d. ke-6 milik "Ayam Penyet & Bakar Cabe Ijo").

### Kolom dan nasibnya

| Kolom | Temuan | Dibawa? |
|---|---|---|
| Name | 80 nama unik | Ya, persis |
| Sales Price | Rp0–Rp25.000, 16 nilai berbeda; "Nasi Putih" Rp0 | Ya |
| Image | 61 gambar JPEG sah (3,5–17 KB; 57 berbeda), 6 sel berisi pesan batas Odoo, 13 kosong | Ya, bila sah |
| Internal Reference | Hanya "Partial Payment" (`nf_partial_payment`) | Tidak (penanda produk teknis) |
| Responsible | 3 nilai berbeda: **nama staf (data pribadi)** | **Tidak** |
| Cost | Semua 0 | Tidak (tetap 0) |
| Quantity On Hand / Forecasted Quantity | 67 item 0, 12 bukan 0, termasuk −123 dan −41 | Tidak |
| Unit of Measure | Porsi (57), Units (22) | Tidak (tidak ada padanan untuk item menu) |
| # Product Variants | 1 (57 item), 2 (18), 3 (2), 6 (2) → **111 varian** | Hanya sebagai cek jumlah |
| Bill of Materials, # Bill of Material, # of BoM Where is Used, Is Kits | 7 item ber-BoM, 6 kit; komponen tidak diekspor | Tidak |
| Available in POS | Semua True | — |
| Favorite, QR Code, Activity Exception Decoration, Is Combo Product, Is Fixed Price, Is a product variant, Price, Image 1024, Bill of Materials/Action Needed | Kosong, seragam, atau duplikat kolom lain | Tidak |

### Item dengan lebih dari satu varian (22)

Atribut tiap item **tidak ada di ekspor**. Kolom "Atribut (inferensi)" adalah dugaan dari nama, jumlah varian, dan nama BoM — **ASSUMPTION, belum diverifikasi**.

| Item | Varian | Atribut (inferensi) | Keyakinan |
|---|---|---|---|
| Ayam Penyet & Bakar Cabe Ijo | 6 | Paket × Ayam (nama BoM: "Bakar Komplit", "Penyet Kenyang", …) | Tinggi |
| Indomie Double | 6 | variant indomie (6 nilai) | Tinggi |
| Indomie Goreng | 3 | variant indomie, 3 dari 6 nilai — yang mana tidak diketahui | Rendah |
| Indomie Kuah | 3 | variant indomie, 3 dari 6 nilai — yang mana tidak diketahui | Rendah |
| Ayam Penyet / Bakar | 2 | Ayam | Tinggi |
| Jus | 2 | Buah | Tinggi |
| Nasi Putih | 2 | Nasi | Tinggi |
| Americano | 2 | Varian (Hot/Ice) **atau** Varian kopi (1/2 shoot) | Rendah |
| ABC Klepon, Chocolatos Coklat, Chocolatos Matcha, Dancow Coklat, Dancow Vanila, Drink Beng-Beng, Good Day Freeze All, Good day All Variant, Indocafe Coffeemix, Lemon Tea, Milo, Nutrisari All Variant, Susu Jahe, Susu Putih | 2 masing-masing | Varian (Hot/Ice) | Sedang |

- Atribut "Rasa" (opsi) tidak menambah jumlah varian, jadi item pemakainya tidak bisa diduga dari ekspor ini.
- Harga tambahan per varian (mis. Paket Kenyang lebih mahal dari Hemat) tidak ada di ekspor. Semua varian "Ayam Penyet & Bakar Cabe Ijo" akan berharga Rp18.000 bila tidak ada sumber lain.

### Foto

- **6 sel berisi pesan Odoo, bukan gambar**: *"The content of this cell is too long for an XLSX file (more than 32767 characters). Please use the CSV format for this export."* Item: ABC Susu, DIMSUM GORENG KEJU LUMER, DIMSUM GORENG KEJU LUMER SAUS MENTAI, Drink Beng-Beng, ES JERUK, MARTABAK TELUR KORNET. Ekspor ulang dalam CSV akan memulihkannya.
- **12 item menu tanpa foto**: Ayam Penyet & Bakar Cabe Ijo, Ayam Penyet / Bakar, Es Teh, Jus, Kerupuk, Kol Goreng, Mie Nyemek, Nasi Putih, Sambal Cabe Ijo, Sate Kulit (Tusuk), Sukro, Susu Putih.
- **Foto dipakai bersama** (4 pasang): Chicken Ricebowl Cabe Hijau = Sambal Bawang; Indomie Double = Indomie Kuah; Mie Level 1-2 = 3-5; Pangsit Chillie Oil lvl 1-2 = 3-5.

### Kit dan BoM (tidak dibawa)

- Kit (6): Ayam Penyet & Bakar Cabe Ijo, Bakso Aci + Telor, Kornet, Mie Nyemek, Nasi Goreng Spesial, Omelette Mie. BoM non-kit: Indomie Kuah.
- Dipakai sebagai komponen BoM: Ayam Penyet / Bakar (6), Nasi Putih (6), Sate Kulit (Tusuk) (4), Es Teh (2) — semuanya komponen paket ayam.
- Di BoothPOS, BoM menautkan **bahan baku**, bukan item menu lain; komponen juga tidak ada di ekspor. Kit dijual sebagai item biasa dengan harganya sendiri.

### Catatan mutu data

- Nama berhuruf kapital semua: DIMSUM GORENG KEJU LUMER, DIMSUM GORENG KEJU LUMER SAUS MENTAI, ES JERUK, MARTABAK TELUR KORNET.
- Kemungkinan salah ketik: "Cappuchino", "1 shoot / 2 shoot", "Es Tesh" (nilai Paket Kenyang).
- Stok negatif ("Ayam Penyet & Bakar Cabe Ijo" −123, "Ayam Penyet / Bakar" −41): Odoo menjual tanpa saldo, jadi angka stok tidak bisa dipercaya sebagai saldo awal.

## Padanan konsep Odoo → BoothPOS

| Odoo | BoothPOS |
|---|---|
| POS Category | Kategori, ditambah stasiun persiapan (Bar/Dapur) |
| Product Template | Item menu (produk) milik outlet tujuan |
| Atribut "Instantly" + nilainya | Varian item (nama varian = nilai; kombinasi bila >1 atribut) |
| Atribut "Never (option)" | Grup pilihan (modifier), satu pilihan, tanpa tambahan harga |
| Sales Price | Harga jual varian |
| Image | Foto item |
| Kit/BoM, stok, satuan, harga pokok, Responsible | Tidak dibawa |

## Di luar analisis

- `Point of Sale Orders (pos.order).xlsx` ada di folder yang sama tetapi **tidak dibuka**. Berkas ini tidak diminta, kemungkinan memuat data pelanggan, dan impor transaksi penjualan dipotong dari cakupan (PRD F15.9).
- Metode: berkas dibaca dengan pembaca XLSX berbasis pustaka standar di direktori terisolasi; tidak ada data sumber yang diubah.
