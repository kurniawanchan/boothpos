# Data Model: 091

Tidak ada entitas, kolom, atau migrasi baru. State lokal dialog Attach yang sudah ada (`attachCategories`, `attachProducts`: larik id) tidak berubah bentuk dan tetap menjadi satu-satunya sumber untuk payload `PUT` lampiran. Daftar nama adalah nilai turunan (`computed`) dari larik itu + daftar kategori/produk yang sudah dimuat; tidak disimpan.
