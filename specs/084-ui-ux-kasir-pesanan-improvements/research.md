# Research: 084 — Perbaikan UI/UX Kasir, Pesanan, Shift, dan Koneksi

Label: INTERNAL. Semua temuan berasal dari pembacaan kode pada 2026-10-30 (cabang `084-ui-ux-kasir-pesanan-improvements`); yang belum diverifikasi di perangkat/printer ditandai **ASUMSI**.

## Temuan penentu cakupan

| # | Temuan | Bukti |
|---|--------|-------|
| F1 | Hampir semua butir permintaan ada di **aplikasi Android** (`android-pos/`), bukan SPA web. "Counter", "Shift dibuka", "Online", "Perangkat", "Tunai bersih", "Transfer", "Tahan kartu menu", "Cek bukti transaksi… menu Order" ada di Kotlin; tak satu pun ada di `resources/js`. | `ui/Shell.kt`, `ui/screens/EditorScreen.kt`, `ShiftScreens.kt`, `ui/QrisTexts.kt` |
| F2 | Modifier **sudah ada di Android** (`Sheet.Modifier`, `MenuMapper` → `ModGroup`) dan struk Android sudah mencetak `line.mods`. | `ui/Sheets.kt:150-197`, `print/Documents.kt:123` |
| F3 | Di web, modifier hanya ada di alur **Tab restoran** (`TabView`, `ModifierPickerModal`, `modifierRules.js`). `PosView` (Kasir web) tidak punya, dan `POST /orders` tidak menerima modifier; hanya `TabService` memakai `ModifierResolver`. | `StoreOrderRequest.php`, `OrderService.php`, `TabService.php:144-237` |
| F4 | Penyimpanan dan tampilan modifier di sisi server/web **sudah ada**: `order_items.modifiers` (JSON snapshot), `OrderResource` mengeluarkannya, `ReceiptModal.vue` dan `OpenOrderDetail.vue` menampilkannya. | `OrderResource.php:61`, `ReceiptModal.vue:141` |
| F5 | Cetak X-report dan Z-report **belum dibangun**: tombolnya hanya menampilkan "Cetak belum tersedia: printer belum tersambung." Tidak ada `Documents.xReport/zReport`. "Fix" = membangun dokumennya dan menyambungkannya ke antrean cetak. | `ShiftScreens.kt:187`, `:296`; `print/Documents.kt` |
| F6 | Penyebab "?" di struk: encoder mengganti semua karakter di luar `0x20..0x7E` dengan `?`. | `print/EscPos.kt:47` |
| F7 | Jam statis: jam hanya ada di layar PIN dan dibuat dengan `remember { SimpleDateFormat(...).format(Date()) }` (dihitung sekali, tak pernah berdetak). Header aplikasi tidak punya jam. | `ui/screens/PinScreen.kt:211-231`, `ui/Shell.kt` |
| F8 | Kursor lompat: `OutlinedTextField(value = input.typedDisplay)` memberi `String` berformat titik, sehingga Compose menaruh kursor di awal setelah nilai ditulis ulang. | `ui/CountEntry.kt:58`, `state/CountInput.kt` |
| F9 | `TabService::move()` hanya untuk tab **terbuka** (`lockOpen`) dan menolak meja terisi/nonaktif/beda outlet. Ubah meja untuk pesanan Selesai butuh jalur baru. Android belum punya pemanggil `move`. | `TabService.php:549-580`, `PosBackend.kt` |
| F10 | QRIS dicatat `pending`; penutupan shift ditolak (`pending_payments`) bila ada QRIS pending; Android memblokir pembayaran non-tunai lain. | `CashierSessionController.php:263-273`, `TenderScreen.kt:57-108`, `PosStore.kt:1189-1233` |
| F11 | Tombol hamburger di rail hanyalah `Icon(Icons.Filled.Menu)` tanpa `clickable`. Mode compact memakai BottomNav (tanpa rail/hamburger) dan BottomNav tidak memuat Perangkat. | `ui/Shell.kt:56`, `:70-90` |
| F12 | Status printer hanya `NotConfigured / Disconnected / Connected / Error`; tidak ada `Connecting`. | `print/PrinterService.kt:32-37` |

## Keputusan

### D1 — Cakupan per aplikasi
- **Decision**: Android untuk semua butir kecuali modifier; **web `PosView` hanya untuk modifier** (jawaban pemilik 2026-10-30). Android tidak diubah untuk modifier selain verifikasi tampilannya.
- **Rationale**: Sesuai F1–F3 dan pilihan pemilik. Spesifikasi awal menyebut "web" untuk semuanya; dikoreksi di `spec.md` (Asumsi).
- **Alternatives**: modifier di keduanya (ditolak pemilik); modifier hanya di Android (ditolak pemilik).

### D2 — Modifier di web POS lewat `POST /orders`
- **Decision**: `POST /orders` menerima `items.*.modifier_option_ids` (opsional). `OrderService` memanggil `ModifierResolver::resolve()` (satu-satunya aturan modifier), memakai `unit_price` hasilnya sebagai `sell_price` baris, dan menyimpan `snapshot` ke `order_items.modifiers`. Klien tidak pernah mengirim harga. `PosView` memuat `GET /products?with_modifiers=1`, membuka `ModifierPickerModal` untuk produk bergrup, dan kunci baris keranjang = `variant_id` + opsi terurut (dua pilihan berbeda = dua baris).
- **Rationale**: Konstitusi I (satu implementasi per konsep) dan IV (server menghitung ulang harga). Memindahkan `PosView` ke alur Tab terlalu besar dan mengubah semantik pembayaran.
- **Alternatives**: PosView membuat Tab lalu membayar (ditolak: ubah alur dan stok); salinan aturan di `OrderService` (ditolak: pelanggaran DRY).
- **Risiko**: `OrderService` menghitung `lineValue = sell_price × qty` dan diskon per item (baris 99/126/212); harus diuji bahwa total, pajak, dan diskon tetap benar dengan delta modifier.

### D3 — Tampilan modifier lintas layar
- **Decision**: Web: struk, detail Penjualan, dan ringkasan sudah membaca `modifiers` (F4) — cukup diuji untuk pesanan dari `PosView`, plus keranjang web dan modal pembayaran. Android: verifikasi `Documents.struk`, detail Pesanan Selesai, keranjang, dan ringkasan Tender menampilkan `mods`; tambahkan yang kurang.
- **Rationale**: Tidak membuat tata letak kedua.

### D4 — Sembunyikan varian "Standard"
- **Decision**: satu fungsi murni `VariantLabel.shown(name, variantCount)` di Android (dipakai kartu produk, keranjang, ringkasan, struk, prebill, KOT) yang mengembalikan `null` bila `variantCount == 1` dan nama (tanpa spasi, tanpa memperhatikan huruf besar/kecil) = "standard". Struk memakai jumlah varian dari data produk (bukan dari baris struk yang tak tahu saudaranya) — **ASUMSI**: `ReceiptView` perlu membawa `variantCount`, atau aturan dikirim server; ditentukan saat implementasi (T-tugas pertama di kelompok ini).
- **Alternatives**: menyembunyikan "Standard" di server untuk semua klien (lebih konsisten dengan struk web, tetapi mengubah kontrak untuk klien yang ada); ditunda.

### D5 — Karakter struk
- **Decision**: ganti `?` dengan transliterasi murni dan teruji di `EscPos`: tabel eksplisit (tanda kutip tipografis → `'`/`"`, tanda hubung panjang → `-`, `…` → `...`, `·` → `-`, `×` → `x`, `€` dst. bila ada), lalu dekomposisi Unicode NFD membuang tanda diakritik (é → e), lalu karakter yang masih tak terpetakan **dibuang** (bukan `?`).
- **Rationale**: printer tak diketahui tabel kode halamannya (TM-58V belum divalidasi, lihat riwayat 2026-10-26); transliterasi bebas ketergantungan printer dan bisa diuji tanpa perangkat.
- **Alternatives**: memilih code page lewat `ESC t n` (ditolak sementara: tak bisa diverifikasi tanpa printer; dicatat sebagai tindak lanjut).

### D6 — Navbar Android
- **Decision**: di `Header`: teks di samping logo memakai slot teks nama toko `StoreMark` → "Shift dibuka {jam buka}"; `ConnChip` menampilkan **nama pengguna** sebagai label, ikon/nada peringatan tetap menandai offline (label `conn.label` tidak lagi ditampilkan; banner offline terbatas tetap). Jam hidup = komposabel `LiveClock` (tick tiap detik berbasis `LaunchedEffect`/`produceState`) di Header dan dipakai ulang oleh panel PIN (memperbaiki F7). Hamburger: ketukan menampilkan/menyembunyikan rail; pilihan disimpan per perangkat mengikuti pola `SplitPreference`.
- **Rationale**: menghormati permintaan; kondisi offline tetap terlihat lewat ikon dan banner, jadi tidak ada informasi keselamatan yang hilang.
- **Catatan**: mode compact tidak punya rail → hamburger hanya berlaku di layar lebar (diterima; ditulis di asumsi).

### D7 — Istilah baku (Android)
- **Decision**: ganti label/tombol/pesan/judul: Counter→Kasir, Order→Pesanan, Perangkat→Koneksi (label menu dan judul layar), Transfer→EDC, Tunai bersih→Tunai. Kata benda "perangkat" yang berarti "tablet ini" dalam kalimat (mis. "Perangkat offline lebih dari 4 jam") ditulis ulang "Tablet ini …", bukan "Koneksi". Satu tes sumber (pola `OutletScopeRegistryTest`) memindai `ui/`, `print/`, `model/`, `state/` untuk istilah lama pada string literal. Pengenal kode (`OrderKind.COUNTER`, `Dest.SYNC`) tidak diubah.
- **ASUMSI**: "Transfer" di laporan shift (`amountOf("bank_transfer")`) adalah EDC dan label struk "Transfer bank" juga menjadi "EDC"; perlu dicek ke pemilik data `payment_channels` sebelum rilis.
- **Judul kasir**: `EditorScreen` menampilkan hanya nomor antrean; `Order.label` (dipakai daftar Pesanan dan struk) menjadi "Kasir {nomor}".

### D7b — Urutan menu dan layar awal
- **Decision**: `NAV` = Kasir, Meja, Pesanan, Shift, **Koneksi** (Koneksi masuk daftar, bukan terpisah di dasar rail) dan ikut BottomNav. Pasca-PIN/buka shift mendarat di `Dest.EDITOR` lewat `newCounter()` (saat ini `Dest.TABLES`, `PosStore.kt:540,615,622`).

### D8 — Pemilih Meja
- **Decision**: Android — pemilih Meja di layar Kasir (pesanan aktif) dan di detail Pesanan Terbuka memanggil `POST /tabs/{order}/move` (ditambahkan ke `PosBackend` dan `RemoteBackend`); daftar meja dari denah (`fetchFloorNow`) hanya meja aktif dan bebas, ditambah meja saat ini. Pesanan **Selesai**: `TabService::move()` diperluas agar menerima pesanan berstatus selesai (tanpa `assertTableFree`, tanpa mengubah `service_type`; hanya `table_id`, tetap satu outlet, aktif), dengan entri log aktivitas `table_changed` di transaksi yang sama (Konstitusi IV).
- **Rationale**: meja pesanan selesai tidak menahan meja (indeks UNIQUE hanya untuk pesanan terbuka), jadi mengubahnya hanya koreksi catatan.
- **Alternatives**: endpoint baru `PUT /tabs/{order}/table` (ditolak: dua jalur untuk satu aturan).

### D9 — QRIS otomatis Terverifikasi
- **Decision**: server — `TabPaymentController::store`/`PaymentRecorder::record()` mencatat pembayaran kanal QRIS langsung `verification = verified` (tidak `pending`), tanpa mengubah metode lain. Android — hapus tombol "Verifikasi", pil "Menunggu verifikasi", pemblokiran non-tunai oleh QRIS pending, dan instruksi menjadi "Jangan lupa untuk cek bukti transaksi di aplikasi QRIS". Rute `verify`/`fail` dan aturan `pending_payments` saat tutup shift **dipertahankan** untuk baris lama yang masih `pending`; UI Android menampilkan "Verifikasi" hanya untuk tender berstatus `PENDING` lama.
- **Rationale**: mencegah shift yang tak bisa ditutup bagi data lama; tidak ada migrasi data keuangan.

### D10 — Pesanan Selesai dan siklus shift
- **Decision**: detail Pesanan Selesai di Android menampilkan nomor, meja/Kasir, kasir, jam, item + modifier + catatan, subtotal, diskon, pajak (mengikuti `tax_show`), total, pembayaran per metode, cetak ulang. Daftar Selesai dibatasi ke shift terbuka milik terminal ini (server: filter `session_id` pada daftar tutup, sudah ada untuk daftar terbuka); `closedCache` dikosongkan saat shift ditutup dan dibaca ulang saat shift dibuka. Data tetap ada di Penjualan/laporan.
- **ASUMSI** (tertulis di spec): "clear" = hilang dari daftar aktif, bukan hapus.

### D11 — Status koneksi printer
- **Decision**: `PrinterStatus` ditambah `Connecting`; `Error` ditampilkan sebagai "Gagal tersambung". `PrinterService.connect()` mengatur `Connecting` sebelum soket dibuka dan batas waktu → `Error`. `PrinterPanel` menampilkan `LinearProgressIndicator` tak tentu saat `Connecting`.

### D12 — Cetak X dan Z
- **Decision**: `Documents.xReport(ShiftReport, …)` dan `Documents.zReport(…)` (judul, outlet, kasir, jam, penjualan, EDC/Tunai/QRIS, kas masuk/keluar, selisih untuk Z) lewat `PrintQueue` seperti struk; tombol di `ShiftScreens` memanggil antrean dan menampilkan status gagal/ulang. Label "EDC" dan "Tunai" sesuai D7.

### D13 — Kursor isian uang
- **Decision**: `OutlinedTextField` memakai `TextFieldValue` + `VisualTransformation` ribuan dengan `OffsetMapping` murni (digit tetap disimpan di `CountInput.typed`, tanpa titik), sehingga kursor selalu berada di posisi digit yang benar saat mengetik, menyunting, menghapus, dan menempel. Pemetaan offset diuji sebagai fungsi murni.
- **Alternatives**: mengatur ulang `selection` ke akhir setelah tiap format (ditolak: kursor melompat saat menyunting di tengah).

## Yang belum dapat diverifikasi di lingkungan ini
- Perilaku di tablet dan printer sungguhan (adb tidak tersedia, TM-58V belum divalidasi): D5, D6 (hamburger), D11, D12 butuh uji perangkat — dicatat di `quickstart.md`, bukan diklaim lulus.
