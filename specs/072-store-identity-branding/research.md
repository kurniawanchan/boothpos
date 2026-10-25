# Research: 072-store-identity-branding

## D1 — Sumber logo untuk sidebar/favicon
**Keputusan**: tambahkan `store_logo_url` ke `GET /settings/features`, diselesaikan `ImageUploadService::url(Setting::get('store_logo_path'))`.
**Alasan**: `store_name` sudah di sana (009) agar tersedia app-wide untuk semua peran; `GET /settings` hanya owner/admin (`SettingPolicy`), jadi kasir tidak bisa membaca logo dari sana. Logo di disk `public` dan sudah tampil di struk — bukan rahasia.
**Alternatif**: panggil `GET /settings` (403 untuk kasir); endpoint baru (berlebihan).

## D2 — Mengganti judul tab dan favicon
**Keputusan**: composable `useBranding` (dipasang di `AppShell`) memantau `storeName`, `appName`, `storeLogoUrl` dan menyetel `document.title` serta `link[rel=icon]` (dibuat bila belum ada). Aturan gabung di `utils/documentTitle.js`: toko kosong → aplikasi; sama (tanpa beda huruf besar/kecil) → sekali; selain itu "{toko} – {aplikasi}".
**Alasan**: SPA di balik satu `app.blade.php`; judul harus berubah tanpa muat ulang (FR-006). Dirender server-side tidak cocok karena nama bisa berubah di sesi yang sama dan berbeda per mode.
**Pra-login**: `features` butuh auth, jadi halaman login memakai `<title>BoothPOS</title>` dan `favicon.ico` bawaan di blade (ASSUMPTION spec). Saat logout, composable dilepas dan judul/ikon dikembalikan ke bawaan.
**Cache ikon**: `ImageUploadService::store` memberi nama acak, jadi URL logo baru tiap unggahan — tidak perlu cache-buster.
**Gambar gagal dimuat**: favicon tidak bisa dideteksi gagal secara andal; sidebar memakai `@error` untuk jatuh ke kotak huruf. Favicon cukup jatuh ke ikon browser bila URL rusak (diterima).

## D3 — Sidebar
**Keputusan**: slot 28×28 menampilkan `<img>` logo (`object-contain`) bila `storeLogoUrl` ada dan tidak error; selain itu kotak huruf `bg-brand` dengan huruf pertama `storeName || appName`. Judul = `storeName || appName`; baris kedua tetap `tierLabel`.
**Alasan**: FR-001–003 mempertahankan perilaku lama saat kosong. Nama panjang sudah `truncate`.

## D4 — POS satu layar (akar masalah)
**Temuan**: `AppShell` memakai `min-h-screen` di akar sehingga tinggi akar mengikuti isi; `main` sudah `overflow-auto` namun tidak pernah dibatasi, jadi grid produk memanjangkan halaman dan footer keranjang (`h-full` di `PosView`) ikut terdorong ke bawah. `PosView` sendiri sudah `overflow-auto` pada kolom produk.
**Keputusan**: ubah akar `AppShell` menjadi `h-screen` (RailNav sudah `h-screen sticky`). `main` (`min-h-0 flex-1 overflow-auto`) jadi satu-satunya penggulir halaman; `PosView` `h-full` kini punya tinggi pasti, kolom produk menggulir sendiri, `PosCartPanel` (`flex-1 overflow-auto` untuk baris, footer di bawah) tetap penuh.
**Risiko**: halaman lain yang mengandalkan gulir jendela kini menggulir di dalam `main`. Mitigasi: periksa tiap layar utama di browser; pencarian `position: sticky` yang bergantung viewport (header tabel) dicek manual. Pilihan lebih sempit (hanya rute POS) ditolak karena membuat dua perilaku shell.
**Alternatif**: `max-h-[calc(100vh-…)]` di PosView — angka ajaib yang bergantung tinggi TopBar/banner; ditolak (konstitusi III).
**Catatan**: kolom produk POS tetap menyisakan kotak pencarian dan filter di atas daftar; SC-004 menuntut keduanya tidak hilang saat menggulir — bagian atas dibuat tidak ikut menggulir (grid di wadah gulir terpisah).

**Revisi (saat implementasi)**: `h-screen` saja tidak cukup. Rute POS tidak memakai `<PageHeader>`, jadi `AppShell` menampilkan judul bawaan (±70 px) di atas `<RouterView>`; akar `PosView` `h-full` = 100% tinggi `main`, maka isi `main` = judul + 100% melampaui `main` dan `main` tetap menggulir sebesar judul. Perbaikan akhir: `meta.fillHeight` pada rute POS membuat `main` kolom flex (hanya rute itu), akar `PosView` `min-h-0 flex-1` (mengisi SISA tinggi), kolom produk tanpa gulir, dan grid dalam wadah `min-h-0 flex-1 overflow-auto`.

## D5 — Menyegarkan setelah simpan
**Keputusan**: `StoreIdentitySection` memanggil `settings.load()` setelah simpan nama dan setelah unggah logo (atau setter state yang ada). Nama aplikasi sudah lewat `setAppName`.
**Alasan**: kini simpan Identitas toko tidak menyentuh store, jadi sidebar basi sampai muat ulang (melanggar FR-006).
