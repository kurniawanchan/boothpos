INTERNAL

# Bentuk jawaban yang DISUSUN dari kode PHP, bukan rekaman — feature/catalog

Berkas di sini TIDAK direkam dari server. Backend belum pernah menjawab kasus ini saat koleksi
Bruno dijalankan, jadi bentuknya disusun dari sumber PHP dan dipisah dari `real/` supaya tidak
disangka rekaman. Bila kelak direkam, pindahkan ke `real/` dan hapus di sini.

- `customer_commerce_disabled_503` (+ `.meta`: 503, `retry-after: 3600`): badan = `CustomerCommerceProblem::response()`
  (urutan kunci `type,title,status,detail,code,request_id`) dipanggil `EnsureCustomerCommerceEnabled` dengan
  kode `customer_commerce_disabled`; `title`/`detail` disalin dari `lang/id/customer_commerce.php`; header
  `Retry-After` = `RETRY_AFTER_SECONDS` (3600) pada middleware itu. `request_id` sengaja nilai jelas-palsu.
