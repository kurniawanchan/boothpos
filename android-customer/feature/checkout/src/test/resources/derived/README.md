INTERNAL

# Bentuk jawaban yang DISUSUN dari kode PHP, bukan rekaman — feature/checkout

Berkas di sini TIDAK direkam dari server. Backend belum pernah menjawab kasus ini saat koleksi
Bruno dijalankan, jadi bentuknya disusun dari sumber PHP dan dipisah dari `real/` supaya tidak
disangka rekaman. Bila kelak direkam, pindahkan ke `real/` dan hapus di sini.

- `customer_commerce_disabled_503` (+ `.meta`): sama dengan di `feature/catalog` (503, `retry-after: 3600`);
  middleware `EnsureCustomerCommerceEnabled` juga menggerbangi quote dan buat pesanan.
- `price_changed_409` (+ `.meta`: 409): `CustomerCheckoutService::place()` melempar
  `CustomerCommerceException::make('price_changed', 409, ['quote' => $cart['quote']])`, jadi `errors.quote`
  adalah quote yang bentuknya persis `data` jawaban quote (`priceCart()`). Strukturnya disalin dari rekaman
  #34; hanya harga yang diubah (75000.00 → 80000.00) untuk mewakili perubahan harga. `title`/`detail` dari
  `lang/id/customer_commerce.php`; `request_id` sengaja nilai jelas-palsu.
