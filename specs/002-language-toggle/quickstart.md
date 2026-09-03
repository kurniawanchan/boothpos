# Quickstart: Ganti Bahasa Antarmuka (Indonesia/English)

Manual smoke-test path once this feature is implemented — matches this
repo's established "verify in a real browser, not just unit tests"
Constitution requirement (Principle II). Assumes the app is already
running per `docs/RUNBOOK.md`.

1. **Layar login selalu Bahasa Indonesia.** Buka layar login tanpa login.
   Konfirmasi TIDAK ADA kontrol ganti bahasa di layar ini, dan seluruh
   teks (label, tombol, placeholder) Bahasa Indonesia. Coba login dengan
   password salah — konfirmasi pesan galat ("Username atau password
   salah.") tetap Bahasa Indonesia.

2. **Pengguna baru default English.** Login sebagai `owner`, buat
   pengguna baru tanpa mengatur bahasa apa pun (endpoint/CRUD pengguna
   tidak perlu field bahasa saat create — default `en` otomatis dari
   kolom database). Login sebagai pengguna baru itu — konfirmasi SELURUH
   aplikasi (dashboard, sidebar, dst.) tampil dalam English.

3. **Ganti bahasa setelah login, berlaku ke seluruh aplikasi.** Sebagai
   pengguna itu, cari kontrol ganti bahasa (tersedia dari mana pun,
   bukan hanya satu layar pengaturan) dan pilih Bahasa Indonesia.
   Konfirmasi SELURUH layar yang sedang terbuka DAN layar lain yang
   dibuka setelahnya (coba minimal 3 layar berbeda: POS, Kelola Produk,
   Laporan) berganti ke Bahasa Indonesia seketika, tanpa reload manual.

4. **Preferensi tersimpan per akun, bukan per perangkat.** Logout dari
   pengguna di atas. Login sebagai pengguna LAIN yang belum pernah
   mengatur bahasa, di perangkat/browser yang SAMA. Konfirmasi pengguna
   ini melihat English (default), TIDAK ikut-ikutan Bahasa Indonesia
   dari pengguna sebelumnya. Login ulang sebagai pengguna pertama —
   konfirmasi aplikasi langsung tampil Bahasa Indonesia lagi tanpa
   perlu memilih ulang.

5. **Pesan galat server ikut berbahasa preferensi pengguna.** Sebagai
   pengguna berbahasa Indonesia, picu galat validasi (mis. buat produk
   tanpa nama) dan galat bisnis 409 (mis. hapus role yang masih dipakai)
   — konfirmasi kedua pesan itu Bahasa Indonesia. Ganti ke English,
   picu galat yang sama lagi — konfirmasi pesan sekarang English.

6. **Struk selalu Bahasa Indonesia.** Sebagai pengguna yang sudah
   mengatur bahasa English, selesaikan satu transaksi POS dan buka
   struknya. Konfirmasi struk TETAP Bahasa Indonesia (label "Subtotal",
   "Kembalian", dst.) meski seluruh antarmuka di sekitarnya English.

7. **Data pengguna tidak ikut diterjemahkan.** Pastikan nama produk,
   nama artist, atau catatan bebas yang sudah diketik dalam Bahasa
   Indonesia (mis. "Kaos Polos Hitam") TETAP tampil apa adanya saat
   antarmuka dalam mode English — bukan diterjemahkan otomatis.

8. **Data form tidak hilang saat ganti bahasa di tengah pengisian.** Buka
   form Buat Produk, isi sebagian field, lalu ganti bahasa lewat kontrol
   global. Konfirmasi nilai yang sudah diisi TETAP ada, hanya label yang
   berganti bahasa.
