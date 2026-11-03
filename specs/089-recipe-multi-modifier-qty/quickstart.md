# Quickstart: Verifikasi 089

**Klasifikasi**: INTERNAL

Perintah untuk checkout ini (compose project `warkop_wjm`, app `:8010`, MySQL host `3308`). **Jangan menjalankan migrasi ke DB dev tanpa persetujuan pengembang**; tes memakai `warkop_wjm_test`.

## Tes otomatis

```bash
# Backend: SATU proses tes pada satu waktu (lihat memori "one test process per test DB")
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app \
  php artisan test --filter=Recipe -e CACHE_STORE=array -e SESSION_DRIVER=array

# Web
npm test

# Android
cd android-pos && ./gradlew testDebugUnitTest
```

Cakupan minimum yang harus ada: potong resep pada selesai/void/refund/buang, jumlah ×qty, Portions > 1, susut, dua resep aktif dijumlahkan, resep masa depan tidak memotong, resep varian tanpa resep tak berubah, migrasi data lama, bahan sama di banyak pilihan, kembar di satu pilihan ditolak, `modifier_selections` di luar batas 422, klien lama (`modifier_option_ids`) tetap jalan, harga `portion_per_option` ×qty, tidak ada biaya ganda pada `cost_price`.

## Skenario manual (butuh manusia: peramban dan tablet)

1. **Label**: buka Resep sebuah item → label "Portions", tidak ada kata "portions" di samping isian, banner baru menyebut stok dipotong saat pesanan selesai.
2. **Resep ganda**: tambah dua resep ("Dasar" cabai 2 gr; "Topping" cabai 1 gr + minyak 5 ml) untuk varian yang sama → daftar menampilkan keduanya. Stok cabai dan minyak masing-masing 100 → jual 3 porsi dan selesaikan → cabai 91 (3 gr × 3 porsi = 9 gr), minyak 85 (5 ml × 3 = 15 ml).
3. **Void**: void pesanan itu → saldo kembali 100/100 dan riwayat bahan memuat `return`.
4. **Modifier**: grup "Level Pedas" dengan pilihan 1/2/4 masing-masing memakai Cabe → tersimpan tanpa galat; menulis Cabe dua kali di satu pilihan → galat hanya pada pilihan itu.
5. **Jumlah pilihan**: atur "Telur" min 1, maks 3; di kasir web dan tablet pesan ×2 → harga naik 2 × selisih, pesan ×4 ditolak; struk/KOT menampilkan "Telur ×2".
6. **APK/tab lama**: pesan memakai pilihan tanpa jumlah → berhasil seperti sebelumnya. Bila minimum pilihan diatur >1, APK lama mendapat 422 (diketahui, lihat plan.md Risiko).
7. **Konsol peramban** bersih selama langkah 1–5.

Hasil dan yang belum terverifikasi dicatat di `specs/089-recipe-multi-modifier-qty/evidence.md`.
