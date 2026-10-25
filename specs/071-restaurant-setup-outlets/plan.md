# Implementation Plan: Outlet di Restaurant Setup

**Klasifikasi**: INTERNAL

**Branch**: `071-restaurant-setup-outlets` | **Date**: 2026-10-25 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/071-restaurant-setup-outlets/spec.md`

## Summary

Meja dan stasiun sudah milik satu outlet (`BelongsToOutlet`, `outlet_id` NOT NULL) dan resource-nya sudah mengirim `outlet_id`; daftar tidak menampilkannya, filter outlet tidak ada, dan outlet hanya bisa dipilih saat membuat. Rencana:

1. **Info + filter (US1)**: kolom Outlet (dicari dari `outletStore.outlets`, tanpa perubahan resource) dan filter Outlet pilihan-banyak yang dipegang `DiningSetupView` dan dibagi ke tab Meja/Stasiun. Kedua endpoint daftar menerima `outlet_id[]` yang dipotong dengan outlet yang boleh dilihat; klien tidak mengirim `X-Outlet-Id` pada dua daftar ini (pola 070, D1).
2. **Pindah outlet (US2)**: `PUT /dining-tables/{id}` dan `PUT /stations/{id}` menerima `outlet_id`. Satu service `OutletMoveGuard` memutuskan boleh/tidaknya (meja: tab terbuka; stasiun: dipakai kategori/item atau tiket dapur belum selesai), keunikan nama diperiksa pada outlet TUJUAN, dan pemindahan tercatat di log aktivitas dalam transaksi yang sama.
3. Formulir buat (Meja dan Stasiun) sudah punya kolom Outlet dan tidak berubah. Tanpa migrasi, tanpa perubahan Android.

## Technical Context

**Language/Version**: PHP 8 / Laravel (API); Vue 3 + Pinia + Vite (SPA)

**Primary Dependencies**: yang ada — `BelongsToOutlet`/`OutletScope`, `OutletContext`, `ActivityLogger`, `DataTable`, `BaseSelect`, `BaseMultiSelect`, `useOutletStore`. Tanpa dependensi baru.

**Storage**: MySQL 8; tabel `dining_tables`, `stations` (kolom `outlet_id` sudah ada, indeks unik `uniq_dining_tables_outlet_name` dan `uniq_stations_outlet_name`). Tanpa migrasi.

**Testing**: PHPUnit `tests/Feature/` (MySQL `warkop_wjm_test`; di kontainer semua `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array` SEBELUM nama layanan `app`); Vitest `qa-tests/`; uji layar nyata.

**Target Platform**: SPA back-office + API Laravel (satu server, satu basis data per organisasi).

**Project Type**: web-service + SPA (struktur repo yang ada).

**Performance Goals**: daftar meja/stasiun tanpa N+1 (nama outlet dari store klien, bukan relasi per baris).

**Constraints**: outlet ditentukan server dan hanya boleh menyempit di dalam outlet yang diizinkan (Konstitusi IV); satu implementasi per aturan (Konstitusi I); `docs/openapi-pos-mvp.yaml` dalam komit yang sama; teks UI/komentar/dokumen berbahasa Indonesia.

**Scale/Scope**: puluhan meja dan <10 stasiun per outlet, 2–4 outlet; 3 layar disentuh (DiningSetupView, TablesAdmin, StationsAdmin).

## Constitution Check

*GATE: lulus sebelum Phase 0; dievaluasi ulang setelah Phase 1 (lulus).*

| Prinsip | Penilaian |
|---|---|
| I. Satu jalur tulis, logika di service | LULUS. Aturan "boleh dipindah" hidup di SATU `OutletMoveGuard` yang dipakai kedua controller; controller hanya memvalidasi, mendelegasikan, membentuk respons. `outlet_id` tidak masuk `$fillable` (tetap diset eksplisit di controller seperti jalur buat). |
| II. Pengujian | LULUS dengan syarat tugas: tes Feature (filter, guard meja/stasiun, keunikan di outlet tujuan, izin outlet, log audit, riwayat tak berubah) dan Vitest (kolom, filter, kolom Outlet di formulir ubah, hanya kirim bila berubah) + uji layar nyata. |
| III. Konsistensi UX | LULUS. Memakai `DataTable`, `BaseMultiSelect`, `BaseSelect`; pola filter = Produk (070); token Tailwind saja. |
| IV. Keamanan / outlet | LULUS. `outlet_id[]` pada daftar dipotong `permittedIds()`; outlet tujuan harus `isPermitted` (403/422), tidak pernah dipercaya dari klien; pemindahan dan log audit satu transaksi. |
| V. Kinerja | LULUS. Tanpa cache baru; tanpa kueri per baris. |

Tidak ada pelanggaran → Complexity Tracking kosong.

## Project Structure

### Documentation (this feature)

```text
specs/071-restaurant-setup-outlets/
├── plan.md  ├── research.md  ├── data-model.md  ├── quickstart.md
├── contracts/api-restaurant-setup-071.md
├── checklists/requirements.md
└── tasks.md   # dibuat /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Api/{DiningTableController,StationController}.php   # index: outlet_id[]; update: pindah via guard
├── Http/Requests/{StoreDiningTableRequest,UpdateDiningTableRequest,StoreStationRequest,UpdateStationRequest}.php   # outlet_id saat ubah; keunikan di outlet tujuan
├── Services/OutletMoveGuard.php                                           # BARU: alasan penolakan pindah meja/stasiun
└── Support/OutletContext.php                                              # + narrowedIds(array): permintaan ∩ outlet yang boleh dilihat
lang/{id,en}/restaurant.php                                                 # pesan penolakan
resources/js/
├── api/client.js (+ qa-tests/unit/apiClientOutletHeader.test.js)           # opsi skipOutletHeader (dari 070)
├── api/{diningTables,stations}.js                                          # listX(params, options)
├── composables/useOutletPageFilter.js                                      # BARU: filter outlet halaman (isi dari bilah atas, 'semua' disengaja)
├── views/DiningSetupView.vue                                               # menaruh filter di atas tab Meja/Stasiun
├── components/restaurant/{TablesAdmin,StationsAdmin}.vue                   # prop outletIds, kolom Outlet, kolom Outlet di formulir ubah
└── locales/{id,en}.json
tests/Feature/  qa-tests/component/  qa-tests/unit/   docs/openapi-pos-mvp.yaml
```

**Structure Decision**: struktur web-app yang ada; satu service dan satu composable baru.

## Complexity Tracking

Tidak ada pelanggaran konstitusi yang perlu dibenarkan.
