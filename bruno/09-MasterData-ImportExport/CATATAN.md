Koleksi ini tidak bisa menghasilkan berkas `.xlsx` biner secara otomatis
(bukan format teks), jadi tiga request yang mengunggah workbook butuh
berkas contoh disiapkan manual di folder ini, relatif terhadap lokasi
`.bru`-nya, sama seperti pola `sample-proof.jpg` di `04-Payment/`:

- `contoh-impor-valid.xlsx` — dipakai oleh request 2 (dry-run) dan 3
  (impor sungguhan). Cara tercepat membuatnya: jalankan request 1 di
  folder ini (`GET /imports/master-data/template`) lewat Bruno atau
  `curl`, simpan hasilnya sebagai `contoh-impor-valid.xlsx` di sini, isi
  baris contoh pada sheet `artists`/`categories`/`products`/`stock` dengan
  kode yang BELUM ada di database (mis. artist `code: BRN`), lalu simpan.
  Karena impor bersifat upsert, request 3 boleh dijalankan berkali-kali
  dengan aman — baris kedua dst akan berstatus `updated`/`unchanged`,
  bukan gagal.
- `contoh-impor-invalid.xlsx` — dipakai oleh request 4 (negative case).
  Salinan dari berkas di atas dengan SATU baris sengaja dirusak, misalnya
  baris `products` yang menunjuk `category_code` yang tidak ada di sheet
  `categories` maupun di database — untuk memicu galat per-baris 422
  (`errors[0].column: category_code`) tanpa mengubah data apa pun (pola
  semua-atau-tidak-sama-sekali).

Belum pernah dijalankan sungguhan di sandbox pembuat koleksi ini — sama
seperti seluruh koleksi Bruno lain di sini, lihat `bruno/README.md`.
