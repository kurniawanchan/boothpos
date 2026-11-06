# Quickstart: verifikasi 091

## Tes otomatis (tanpa backend)
```bash
npm test -- ModifierGroupsAdmin
```
Kasus yang harus ada (tulis dulu, harus gagal sebelum implementasi):
1. Grup tanpa tautan → kedua isian menampilkan "Tidak ada" (bukan "All"), tak ada daftar nama.
2. 2+ produk terpilih → nama semua tampil di bawah isian Products; urutan mengikuti daftar sumber.
3. 2 kategori terpilih → nama tampil di bawah Categories.
4. Tepat 1 pilihan → tak ada daftar tambahan.
5. Mencentang/membatalkan → daftar mengikuti; kosong lagi → "Tidak ada".
6. Id terpilih tanpa padanan nama → tak ada teks "undefined".
7. Payload `setModifierGroupAttachments` identik dengan sebelumnya untuk pilihan yang sama.
8. Regresi: `BaseMultiSelect` tanpa `allLabel` tetap menampilkan "Semua" (tes yang ada tetap hijau).

Jalankan juga seluruh Vitest (`npm test`) sebelum selesai.

## Verifikasi peramban (belum dilakukan oleh agen)
Restaurant Setup → Modifiers → Attach "Suhu Minuman": pilih 6 produk → nama tampil di bawah; pilih banyak → dialog tak meluap; kosongkan → "Tidak ada". Cek layar lain pemakai `BaseMultiSelect` (mis. filter Products) masih "Semua".
