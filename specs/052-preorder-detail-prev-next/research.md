**Klasifikasi**: INTERNAL

# Research: Navigasi Sebelumnya/Berikutnya di Detail Pre-order

Semua temuan di bawah berasal dari pembacaan kode saat ini (`develop` @ `f2bc8b79`), bukan asumsi, kecuali yang ditandai ASUMSI.

## Fakta yang ditemukan

- Daftar: `PreorderController::index()` = `applyFilters()` + `applySort()` + `paginate()` (`app/Http/Controllers/Api/PreorderController.php` ±baris 42–98). `summary()` memakai `applyFilters()` yang sama; `PreorderExportImportService` punya rantai filternya sendiri dan `orderByDesc('created_at')` sendiri (tidak memakai `applySort()`).
- `applySort()` (±baris 548–578): whitelist `preorder_number`, `customer_name` (JOIN `customers`, `select('preorders.*')`), `status`, `fulfillment`, `total_amount`, `outstanding` (`orderByRaw total_amount - paid_amount`), `created_at`, `updated_at`; bawaan `orderByDesc('preorders.created_at')`. **Tidak satu cabang pun punya pengikat (tie-breaker).**
- `show()` (±baris 177) memuat detail lengkap dan tidak punya pemeriksaan peran inline/FormRequest/Policy — sama seperti `index()`: semua pengguna yang login (di dalam grup `auth:sanctum`) boleh membaca pre-order; batasnya hanya mode DEMO/LIVE lewat `HasDataMode`.
- SPA: `PreordersView.vue` memakai `usePaginatedList(listPreorders)`; `params` reaktif adalah satu-satunya sumber filter/urutan aktif (filter, `search`, `sort_by`/`sort_dir`, `page`, `per_page`). Detail dibuka oleh `openDetail(row)` (dari baris) atau `openDetailById(id)` (tautan `?preorder_id=`, anak split, sumber split, hasil duplikat). `openDetailById` **menutup panel** bila gagal memuat.
- `BaseDrawer.vue` punya slot `header` (judul + subjudul default) dan menangani `Escape` lewat `@keydown` pada overlay-nya; modal lain (pembayaran, konfirmasi, edit) di-Teleport ke `body` sendiri, jadi event keyboard dari modal itu tidak mengalir ke handler drawer. Form pengiriman (`showShipmentForm`) dirender INLINE di dalam drawer.
- `DataTable.vue` sudah menerima `rowClass(row)`.
- Tes yang ada: `tests/Feature/PreorderSearchTest.php` (filter/sort list), `qa-tests/component/PreordersView.test.js`.

## Decision 1 — Tetangga dihitung di server lewat endpoint baru `GET /preorders/{preorder}/neighbors`

- **Keputusan**: endpoint baru menerima parameter filter/urutan yang SAMA dengan `GET /preorders` dan mengembalikan `previous`/`next` (`id`, `preorder_number`) serta posisi. Implementasinya memakai `applyFilters()` + `applySort()` yang sudah ada.
- **Alasan**: (a) satu sumber urutan — tetangga tidak mungkin berbeda dari daftar karena memakai builder yang persis sama (Prinsip I); (b) melintasi halaman (FR-004) dan pre-order yang dibuka dari tautan (bukan dari halaman yang dimuat) tidak bisa dijawab dari halaman 25 baris di klien; (c) satu panggilan per buka-detail, tanpa memuat halaman tetangga.
- **Alternatif ditolak**:
  - *Hitung di klien dari `items` halaman aktif, ambil halaman sebelah saat di tepi*: butuh logika berbeda untuk tengah/tepi/tautan, tetap butuh request di tepi, dan tidak bisa menjawab "tidak ada di daftar" untuk tautan dari laman lain.
  - *Tambah `previous`/`next` ke respons `show()`*: `show()` tidak tahu konteks daftar; menempelkan parameter daftar ke endpoint detail mencampur dua tanggung jawab dan memberatkan setiap pemanggil `show()` lain (modal, refresh).

## Decision 2 — Cari posisi dengan `pluck` id berurutan, bukan keyset

- **Keputusan**: jalankan query daftar tersaring+terurut dengan `pluck('preorders.id')` (hanya kolom id, tanpa eager-load), cari indeks id yang dibuka, ambil id di indeks −1/+1, lalu satu query kecil untuk nomor kedua tetangga.
- **Alasan**: sederhana dan mustahil menyimpang dari urutan daftar. Skala: pre-order per instalasi toko berkisar puluhan sampai beberapa ribu (ASUMSI dari bentuk produk: satu toko, booth per event); `pluck` beberapa ribu integer adalah satu query ringan tanpa hidrasi model. Ekspresi `outstanding` dan JOIN `customer_name` ikut tanpa perlakuan khusus.
- **Alternatif ditolak**: *keyset* (`WHERE (kolom, id) > (nilai, id) ORDER BY … LIMIT 1`) — lebih hemat pada jutaan baris, tetapi menuntut perbandingan khusus untuk 8 cabang urutan × 2 arah, ekspresi mentah `outstanding`, JOIN pelanggan, dan NULL pada `updated_at`; itu salinan kedua logika urutan (melanggar Prinsip I) untuk skala yang tidak ada. Ditinjau ulang hanya jika satu instalasi melewati ~50.000 pre-order.

## Decision 3 — Pengikat stabil `preorders.id` di `applySort()` untuk semua cabang

- **Keputusan**: setiap cabang `applySort()` diakhiri `orderBy('preorders.id', <arah yang sama>)`; bawaan menjadi `created_at desc, id desc`.
- **Alasan**: FR-005/SC-003. Tanpa pengikat, MySQL boleh mengembalikan baris bernilai sama (status, pelanggan, `created_at` hasil impor massal yang berdetik sama) dalam urutan berbeda per query — tetangga bisa melompat atau berulang, dan paginasi daftar yang SUDAH ADA pun bisa menampilkan satu pre-order di dua halaman. Arah pengikat mengikuti arah urutan supaya "terbaru di atas" tetap konsisten untuk baris yang berdetik sama.
- **Dampak**: urutan tampilan daftar hanya berubah di antara baris yang bernilai sama (sebelumnya tidak terdefinisi). `summary()` tidak mengurutkan; ekspor punya urutannya sendiri dan tidak disentuh (di luar lingkup).

## Decision 4 — Pre-order yang tidak ada di daftar: `in_list: false`, kedua tetangga `null`

- **Keputusan**: jika id yang dibuka tidak lolos filter aktif (dibuka dari tautan riwayat pelanggan/laporan, atau statusnya baru berubah), respons `200` dengan `in_list: false`, `position: null`, `previous`/`next` `null`. Pre-order yang tidak ada / mode lain → `404` biasa dari route-model binding (scope `HasDataMode`).
- **Alasan**: spec edge case "dibuka bukan dari daftar". Tidak menebak "tetangga terdekat" karena tidak ada posisi yang jujur untuk baris yang tidak tampil.

## Decision 5 — Satu loader detail dengan penghitung urutan; navigasi tidak menutup panel saat gagal

- **Keputusan**: navigasi memanggil loader berbasis id (`openDetailById`) yang diberi penghitung urutan (`detailSeq`) — respons yang datang setelah permintaan yang lebih baru dibuang, baik untuk detail maupun tetangga (FR-008). Mode navigasi tidak menutup panel saat gagal: menampilkan toast, mempertahankan detail sebelumnya, dan memuat ulang tetangga (pre-order tujuan mungkin sudah dihapus).
- **Alasan**: pelajaran fitur 049 (pencarian POS): respons lambat menimpa yang lebih baru. `openDetail(row)` dan `openDetailById(id)` saat ini tidak punya penjaga urutan sama sekali.
- **Alternatif ditolak**: membatalkan request lewat `AbortController` — klien axios proyek tidak memakainya di tempat lain; penghitung urutan sudah menjadi pola di `PosView` (`searchSeq`/`scanSeq`).

## Decision 6 — Tetangga dihitung ulang setiap kali detail dimuat

- **Keputusan**: `getPreorderNeighbors()` dipanggil (paralel dengan `getPreorder()`) setiap kali detail dibuka, dinavigasi, atau di-`refreshDetail()` (setelah ubah status, penanda, pembayaran, split, duplikat). Tidak ada cache tetangga.
- **Alasan**: perubahan status bisa mengeluarkan pre-order dari filter; hapus/duplikat/split mengubah isi daftar. Menghitung ulang membuat label tombol selalu jujur (SC-004) dengan biaya satu query ringan.

## Decision 7 — Kontrol di slot `header` BaseDrawer; nonaktif ≠ disembunyikan

- **Keputusan**: header detail memakai slot `header` untuk menaruh judul/subjudul + kelompok tombol `‹ PO-… | PO-… ›` di samping tombol tutup. Pada tepi daftar/`in_list: false`/form pengiriman terbuka/sedang memuat, tombol tetap tampil tetapi nonaktif dengan `BaseTooltip` alasan.
- **Alasan konstitusi**: Prinsip III melarang kontrol nonaktif untuk fungsi yang tidak diizinkan *peran*-nya; ini bukan soal izin (semua peran yang bisa membuka detail boleh bernavigasi), melainkan keadaan sementara — preseden: aksi PO "Delete" nonaktif dengan alasan (fitur 035), Split nonaktif saat sudah dibayar (fitur 027). `BaseTooltip` dipakai, bukan `title` (aturan fitur 037).
- `BaseDrawer.vue` sendiri tidak diubah (dipakai layar lain); hanya slot-nya diisi.

## Decision 8 — Panah keyboard ditangani di panel detail, hanya di luar kolom isian

- **Keputusan**: handler `keydown` pada konten drawer: `ArrowLeft`/`ArrowRight` tanpa modifier menjalankan navigasi bila target bukan `input`/`textarea`/`select`/`[contenteditable]`/listbox terbuka, dan kontrol yang bersangkutan aktif.
- **Alasan**: FR-013. Modal yang menimpa drawer di-Teleport terpisah, jadi event-nya tidak sampai ke handler drawer (dicek di `BaseDrawer.vue`); form pengiriman inline dicakup oleh status nonaktif kontrol.

## Decision 9 — Sorot baris yang sedang dibuka

- **Keputusan**: `rowClass` pada `DataTable` daftar Pre-order menambahkan kelas sorotan dari token yang sudah ada (`bg-mint-50`, `--color-mint-50` di `resources/css/app.css`; tanpa hex) bila `showDetail && row.id === detail.id`. Halaman daftar TIDAK dipindah otomatis saat tetangga ada di halaman lain.
- **Alasan**: FR-012 hanya meminta sorotan bila baris ada di halaman yang dimuat. Memindah halaman di belakang panel memicu muat-ulang daftar dan menggeser fokus — perilaku tambahan yang tidak diminta.
