# Kontrak: kasus paritas pajak (Web = Android = Server)

**Klasifikasi**: INTERNAL

Satu berkas data, `qa-tests/fixtures/tax-parity-cases.json`, dibuat saat implementasi dan dibaca oleh TIGA suite tes. Rumus acuan: `tax = round_half_up(max(0, base) × rate ÷ 100)`, rupiah penuh, per pesanan.

| Suite | Yang diuji terhadap berkas |
|-------|----------------------------|
| PHPUnit | `TaxCalculator::compute($base, $rate)` |
| Vitest | `taxOnBase(base, rate)` dan `cartTotals({subtotal, discount, taxRate})` |
| JUnit Android | pemetaan angka server ke `Order.tax`/`total` dan teks label `taxLabelText` (Android tidak menghitung pajak; yang dijaga: tak ada angka tertulis dan tarif ditampilkan apa adanya) |

## Kasus minimum (nilai sudah dihitung tangan)

| base | rate | tax | catatan |
|-----:|-----:|----:|---------|
| 17000 | 100 | 17000 | gejala pemilik: 100% |
| 60000 | 5 | 3000 | |
| 60000 | 10 | 6000 | tarif lama tetap benar |
| 1001 | 2.5 | 25 | 25,025 dibulatkan turun |
| 999 | 2.5 | 25 | 24,975 dibulatkan naik |
| 5 | 10 | 1 | 0,5 → naik (setengah ke atas) |
| 1000 | 0.5 | 5 | tarif di bawah 1% |
| 0 | 10 | 0 | dasar 0 |
| −500 | 10 | 0 | dasar negatif dijepit ke 0 |
| 12345 | 0 | 0 | tarif 0 = tanpa pajak |
| 99999 | 100 | 99999 | batas atas tarif |

Pesanan lengkap (pengujian server): tarif disimpan lewat `PUT /settings`, lalu satu pesanan dibuat; `tax_amount`, `tax_rate`, `total_amount` pada `POST /orders`, `GET /orders/{id}`, tab, dan struk harus sama dengan kolom `tax` di atas. Diskon tingkat-pesanan dikurangkan SEBELUM pajak.

## Aturan perubahan

Menambah atau mengubah kasus di berkas ini harus lulus di ketiga suite sebelum digabung. Tes pemindai sumber (D3) memastikan tidak ada angka tarif tertulis di jalur pajak.
