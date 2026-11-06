# Research: 091

## D1 — "Tidak ada" lewat prop `allLabel`, bukan ubah komponen
- **Temuan**: `BaseMultiSelect` menampilkan `allLabel` (bawaan `common.all`) saat `modelValue` kosong; `placeholder` hanya dipakai bila satu id terpilih tak ada di daftar. Jadi `:placeholder="mod_attach_none"` di dialog Attach tidak pernah tampil dan isian kosong menulis "All". `allLabel` juga menjadi teks baris pertama di daftar (memilihnya mengosongkan pilihan).
- **Keputusan**: dialog Attach memberi `:all-label="t('dining_setup.mod_attach_none')"` pada kedua isian; `:placeholder` yang tak berfungsi dihapus.
- **Alasan**: nol perubahan pada 9 pemakai; baris pertama daftar ikut menjadi "Tidak ada", konsisten dengan isian.
- **Alternatif**: prop baru `emptyLabel` di komponen (lebih banyak permukaan, tak perlu); mengubah bawaan komponen (merusak filter lain).

## D2 — Daftar nama di pemanggil, bukan di komponen
- **Keputusan**: `computed` di `ModifierGroupsAdmin.vue` memetakan id terpilih → nama lewat `categories`/`products` yang sudah dimuat, mempertahankan urutan daftar sumber; ditampilkan hanya bila ≥ 2 terpilih (FR-001/FR-002).
- **Alasan**: permintaan khusus dialog ini (spec Assumptions); komponen pilih-banyak lain tak perlu daftar.
- **Alternatif**: slot/prop `summary` di `BaseMultiSelect` (cakupan lebih luas dari permintaan).

## D3 — Id terpilih yang tak ada di daftar
- Pemetaan melewati id tanpa nama (tak tampil "undefined"); hitungan di isian (`N selected`) tetap dari `modelValue.length`, jadi pengguna tetap melihat jumlah sebenarnya. Perbandingan id memakai `==` seperti komponen (id bisa string/angka).

## D4 — Batas tinggi
- Wadah daftar: `max-h` ± 120px + `overflow-y-auto`, nama dipisah koma dalam satu paragraf yang membungkus (`break-words`); aman di dialog `max-w-[520px]` dan layar sempit. Nilai memakai token/ukuran Tailwind yang sudah dipakai berkas ini, bukan hex.
- **Alternatif**: chip per nama (lebih berat visual untuk puluhan produk; ditolak).

## D5 — Teks
- Kunci baru bila diperlukan (mis. label kecil di atas daftar) ditambahkan ke `en.json` dan `id.json` di bawah `dining_setup`; `mod_attach_none` sudah ada di kedua bahasa. Konstitusi III: copy Indonesia.
