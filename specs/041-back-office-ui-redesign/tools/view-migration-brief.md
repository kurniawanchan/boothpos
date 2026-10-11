# Brief: migrasi satu kelompok view ke page kit (fitur 041, US2)

Repo: `/Users/chan/www/warkop-wjm` (Laravel API + Vue 3 SPA di `resources/js`, Tailwind v4 CSS-first, Vitest di `qa-tests/`).
Branch kerja bersama; PEKERJA LAIN mengerjakan view lain SECARA BERSAMAAN. Hanya sentuh berkas milik Anda.

## Tujuan
Setiap halaman manajemen memakai struktur yang sama dari desain "Warkop WJM Back Office": judul besar + deskripsi satu baris +
tindakan di kanan, strip empat angka, tab dengan jumlah, baris filter + tabel dengan penghitung "N data". Ini perubahan
PRESENTASI: perilaku, data, izin, dan API TIDAK boleh berubah.

Referensi visual (lihat dengan Read, PNG): `/Users/chan/www/warkopwjm/design/screens/SaaS Restaurant Admin Dashboard/*.png`
(Dashboard, Pesanan, Produk, Inventori, Pembelian, Penjualan, Pelanggan, Supplier, Laporan, Karyawan-User, Pengaturan).
Token & aturan visual: `specs/041-back-office-ui-redesign/design-tokens.md`, `specs/041-back-office-ui-redesign/contracts/ui-contracts.md`.

## Kit yang TERSEDIA (baca kodenya dulu; komentar di atas tiap komponen menjelaskan kontraknya)
- `resources/js/components/page/`: `PageHeader`, `KpiStrip`/`KpiCard`, `TabBar`, `FilterBar`, `BulkBar`, `DetailPanel`,
  `SettingsLayout`, `FormDrawer`/`FormSection`, `ReasonDialog`.
- `resources/js/components/ui/`: `DataTable` (kini punya `selectable`/`selected`/`stickyHeader`/`error`), `StatusPill`
  (nada: neutral, accent, info, success, warning, danger), `EmptyState`, `LoadingState`, `ErrorState`, `BaseDrawer`, `BaseModal`, `BaseButton`, dst.
- `resources/js/composables/useQueryState.js` (filter/tab/halaman ke address bar), `usePaginatedList.js`.
- `resources/js/utils/formatters.js` (`formatIDR`, `formatCount`, `formatDateLong`, `pageRange`).
Kerangka (`layout/AppShell.vue`) otomatis menyembunyikan judul bawaannya begitu halaman memakai `<PageHeader>`, jadi JANGAN membuat
`<h1>` sendiri; pakai `PageHeader` dengan `title` dari kunci i18n yang sudah dipakai halaman itu (`nav.<nama>` / `nav.<nama>_subtitle`).

## Cara memigrasikan (aturan keputusan)
1. **Judul + tindakan**: pindahkan judul/deskripsi dan tombol tindakan utama halaman ke `PageHeader` (slot `actions`: satu tombol utama
   terisi, sisanya sekunder). Tombol yang tadinya di kepala kartu boleh pindah ke sini bila memang tindakan tingkat-halaman.
2. **KpiStrip**: HANYA bila halaman SUDAH memuat/menurunkan empat angka yang jujur dari data yang ada, dan angka itu sama dengan isi
   daftar (tanpa filter). JANGAN menambah panggilan API baru, JANGAN mengarang angka. Bila tak ada empat angka yang jujur: lewati KPI
   (tidak apa-apa; jangan memaksa). Nilai uang lewat `formatIDR`; nilai tak tersedia -> `unavailable`, bukan 0.
3. **TabBar**: hanya bila halaman memang punya bagian/tab; chip jumlah hanya bila jumlahnya diketahui dari data yang sudah dimuat.
4. **FilterBar**: ganti toolbar cari/filter buatan sendiri; semantik filter IDENTIK dengan sebelumnya. `count` = jumlah baris hasil
   filter yang benar-benar tampil (hasil total dari server bila daftar berpaginasi). Jangan memutus sinkronisasi URL yang sudah ada.
5. **Tabel**: tetap `DataTable`. Pakai `selectable`/`BulkBar` HANYA bila halaman sudah punya aksi massal. Tampilkan keadaan
   memuat/kosong/galat lewat prop `loading`/`emptyMessage`/`error` (galat -> tombol coba lagi memuat ulang).
6. **Modal/drawer detail**: biarkan, kecuali penggantian ke `DetailPanel` trivial dan tak mengubah perilaku. Jangan menulis ulang
   dialog yang rumit.
7. **Alasan bebas-teks** pada konfirmasi berisiko: biarkan (kode alasan baru datang di fase lain).
8. **View besar**: pecah menjadi subkomponen (`resources/js/views/<area>/...` atau `components/<area>/...`) TANPA mengubah perilaku.
9. **Gaya**: hanya token dari `resources/css/app.css` (kelas seperti `bg-brand`, `text-muted`, `border-line`, `bg-mint-50`); tidak ada hex
   mentah. Desain bersudut siku (radius token = 0). Ikon: Phosphor (`ph-duotone ph-...`) seperti yang sudah ada.
10. **Hidden, not disabled**: kendali yang tak boleh dipakai peran itu tidak dirender (pola `auth.canAccessMenu(...)` yang sudah ada).
11. **Penamaan & bahasa**: teks UI/komentar/commit berbahasa Indonesia; komentar menjelaskan MENGAPA.

## Perilaku harus utuh
- Semua tes yang ada untuk berkas milik Anda harus tetap lulus. Bila sebuah asersi HARUS berubah karena markup berpindah (mis. judul
  kini dari PageHeader), ubah dengan alasan yang jelas di komentar; jangan melemahkan cakupan atau menghapus tes.
- Tambahkan tes untuk struktur baru (PageHeader ada, angka KPI sama dengan isi daftar, FilterBar count, tab jumlah, keadaan galat).
- Jalankan HANYA tes milik Anda: `npx vitest run <berkas tes milik Anda>`; lalu `npx vitest run qa-tests/unit/localeParity.test.js qa-tests/unit/localeKeys.test.js`.

## Terjemahan (JANGAN edit `resources/js/locales/*.json` langsung — dikerjakan paralel)
Buat berkas additions sementara (di `/tmp`) lalu jalankan:
`python3 -I specs/041-back-office-ui-redesign/tools/merge_locale.py /tmp/<nama>.json`
Format: `{"id": {"namespace": {"kunci": "teks"}}, "en": {"namespace": {"kunci": "text"}}}`. Kedua bahasa harus punya himpunan kunci yang sama.
Skrip menolak menimpa kunci yang ada dengan nilai berbeda (keluar kode 2) — pakai namespace/kunci milik halaman Anda. Memakai ulang kunci yang
sudah ada (mis. `nav.*`, `common.*`, `page.*`, `frame.*`) tidak perlu digabung.

## DILARANG
- Mengubah: `resources/js/components/page/*`, `components/ui/*`, `components/layout/*`, `composables/*` (kecuali menambah berkas baru milik Anda),
  `resources/css/app.css`, `router/*`, `stores/*`, `utils/*` bersama, view lain, backend (`app/`, `routes/`, `database/`, `tests/Feature/`).
  Jika kit perlu diubah/diperluas, JANGAN ubah — laporkan persisnya apa dan mengapa.
- Perintah git apa pun (jangan commit/stash/checkout). Menjalankan seluruh suite. Menyentuh server/Docker/DB.
- Menambah dependensi npm.

## Laporan akhir (ringkas, ≤ 300 kata)
Berkas yang diubah/dibuat; apa yang dimigrasikan per halaman (header/KPI/tab/filter/tabel) dan APA YANG SENGAJA DILEWATI beserta alasannya;
hasil tes yang dijalankan (jumlah lulus/gagal); setiap kebutuhan perubahan kit; risiko/keraguan.
