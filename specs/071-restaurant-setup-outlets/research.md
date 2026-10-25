# Research: Outlet di Restaurant Setup

**Klasifikasi**: INTERNAL

Fakta berasal dari pembacaan kode saat ini; keputusan adalah usulan rencana. Tidak ada NEEDS CLARIFICATION tersisa.

## D1 — Filter outlet vs header `X-Outlet-Id`, dan ketergantungan pada 070

- **Fakta**: `OutletScope` menyaring `DiningTable`/`Station` dengan `effectiveIds()` (header bila ada). Dengan bilah atas = satu outlet, filter halaman tak bisa memilih outlet lain. Opsi klien `skipOutletHeader` yang menyelesaikannya dibangun di 070 (PR #56, belum digabung); `develop` belum memilikinya.
- **Decision**: pakai pola 070: dua panggilan daftar halaman ini tidak mengirim header dan mengirim `outlet_id[]` sendiri; server memotongnya dengan outlet yang diizinkan. Bila PR #56 belum digabung saat implementasi, ambil HANYA `resources/js/api/client.js` dan `qa-tests/unit/apiClientOutletHeader.test.js` dari cabang 070 (`git checkout origin/070-… -- <berkas>`); isinya identik sehingga merge berikutnya bersih.
- **Alternatives**: menunggu #56 digabung (memblokir); membangun mekanisme kedua (duplikasi).

## D2 — Di mana filter hidup

- **Fakta**: tab pada `DiningSetupView` adalah `ref` lokal (tidak ada di URL) dan komponen tab di-`v-if` (dipasang ulang saat berpindah).
- **Decision**: filter dipegang `DiningSetupView` lewat composable `useOutletPageFilter` dan dikirim ke `TablesAdmin`/`StationsAdmin` sebagai prop `outletIds`; hanya tampil di tab Meja/Stasiun dan dibagi di antara keduanya. Tidak masuk URL (sama dengan tab). Terisi dari `outletStore.selected`, mengikuti bila bilah atas berganti; pilihan "Semua outlet" yang disengaja saat bilah atas memilih satu outlet disimpan sebagai keadaan `all` di memori (bukan di URL, jadi tak perlu penanda `all` seperti 070).
- **Alternatives**: filter di tiap komponen (kembali ke awal tiap pindah tab); filter di URL (tab tak di URL, nilai tambah kecil).

## D3 — Info outlet pada baris

- **Fakta**: `DiningTableResource` dan `StationResource` sudah mengirim `outlet_id`; `outletStore.outlets` memuat `{id, code, name}` semua outlet yang boleh dilihat pengguna.
- **Decision**: kolom Outlet dicari di klien dari `outletStore.outlets` (kode tebal + nama), tanpa mengubah resource atau menambah relasi/kueri. Kolom hanya ada bila `outletStore.multi`.
- **Alternatives**: memuat relasi `outlet` di server (kueri tambahan, tak perlu karena store sudah ada).

## D4 — Filter di server

- **Decision**: `GET /dining-tables` dan `GET /stations` menerima `outlet_id[]` (array integer, 422 bila bukan). `OutletContext::narrowedIds(array $requested)` = `array_intersect(effectiveIds(), $requested)`; daftar kosong → hasil kosong (`whereRaw 1=0`/`whereIn [0]`). Tanpa parameter perilaku lama.
- **Rationale**: Konstitusi IV (permintaan hanya menyempit); satu helper kecil, dipakai kedua controller.

## D5 — Pindah outlet: satu jalur dan satu penjaga

- **Fakta**: jalur buat memvalidasi `isPermitted` lalu `$model->outlet_id = …` (tidak fillable). Komentar resource: "outlet pemilik (tetap sejak dibuat)" — fitur ini sengaja menggantinya.
- **Decision**: `update()` kedua controller: bila `outlet_id` dikirim dan berbeda dari outlet saat ini → (a) 403 `outlet_forbidden` bila tidak `isPermitted`; (b) `OutletMoveGuard::blocker($model)` → 409 dengan pesan alasan; (c) dalam `DB::transaction`: set `outlet_id` eksplisit, simpan, `ActivityLogger` aksi `moved` (outlet lama → baru). `outlet_id` sama dengan yang sekarang = bukan pemindahan (diabaikan).
- **Guard meja**: ada order berstatus open pada meja (`Order::withoutGlobalScopes`). Riwayat order tidak dipindah (tetap di outlet transaksi).
- **Guard stasiun**: dipakai `categories.station_id` atau `products.station_id` (termasuk soft-deleted, tanpa global scope — FK restrict tidak peduli) ATAU punya tiket dapur dengan item belum selesai (`KitchenTicketItem::UNFINISHED`: new/preparing/ready). Tiket lama yang selesai tetap di outlet lama.
- **Alternatives**: memindahkan beserta kategori/item (mengubah menu outlet lain, ditolak di spec); mengizinkan semua dan mengandalkan FK (tak ada FK ke outlet pada referensi → data yatim).

## D6 — Keunikan nama di outlet tujuan

- **Fakta**: indeks `uniq_dining_tables_outlet_name` / `uniq_stations_outlet_name`; `Store*Request::after()` memeriksa lewat kueri tanpa scope memakai outlet `$current` atau outlet baru.
- **Decision**: pada ubah, outlet efektif = `outlet_id` yang dikirim bila ada, selain itu outlet saat ini; pemeriksaan nama memakai itu sehingga tabrakan di tujuan menjadi 422 `name` (bukan 500 dari indeks). Pemeriksaan tetap lintas mode data seperti sekarang.

## D7 — Formulir ubah

- **Decision**: `BaseSelect` Outlet (tampil bila `outletStore.multi`) di formulir ubah Meja dan Stasiun, terisi `row.outlet_id`; payload memuat `outlet_id` HANYA bila berbeda dari nilai awal (menghindari pemeriksaan guard saat hanya mengubah nama). Galat 409 sudah ditoast global; 422 tampil di bidangnya. Daftar dimuat ulang setelah simpan; item yang pindah ke luar filter hilang dari daftar (diharapkan).
- Formulir buat tidak berubah.
