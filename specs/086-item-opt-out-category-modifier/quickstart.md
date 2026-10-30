# Quickstart: memverifikasi 086

**Klasifikasi**: INTERNAL

1. Siapkan kategori dengan grup "Suhu" (Setup restoran → lampirkan ke kategori) dan dua item di kategori itu (A dan B).
2. Halaman Produk → item A → "Modifier groups": "Suhu" tampil di "Dari kategori" dengan tombol "Lepas". Lepas, simpan.
3. Harapan: "Suhu" pindah ke "Dilepas untuk item ini" dengan tombol "Pulihkan"; formulir Ubah item A menampilkan keadaan yang sama.
4. POS web dan tablet: tambah A, tidak ada pertanyaan "Suhu". Tambah B, "Suhu" tetap ditanya.
5. Kategori tidak berubah (buka Setup restoran: "Suhu" masih terlampir).
6. Pulihkan di A, simpan: "Suhu" kembali ditanya untuk A.
7. Masuk sebagai peran tanpa izin mengubah produk: tombol Lepas/Pulihkan tidak ada sama sekali.
8. Tambah grup "Es" ke kategori: A mendapat "Es", tetap tanpa "Suhu" (bila dilepas lagi).

Tes otomatis (kontainer; flag `-e` SEBELUM nama layanan):

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=ModifierExclusion
npm test -- InheritedModifierList
```

Bukti peramban dan tablet dicatat di `evidence.md` setelah dijalankan; sebelum itu statusnya "belum diverifikasi".
