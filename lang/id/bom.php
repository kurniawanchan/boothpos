<?php

// 034-seller-po-bom — pesan galat BOM bersumber purchase order.
return [
    'not_authorized' => 'Anda tidak berhak mengelola BOM dari purchase order.',
    'line_not_eligible' => 'Baris purchase order ini tidak bisa dipakai: bukan milik seller varian ini, atau purchase order-nya belum/tidak berstatus ordered, received, atau paid.',
    'duplicate_line' => 'Baris purchase order ini sudah ada di BOM varian ini. Ubah jumlahnya pada baris yang ada.',
    'qty_must_be_positive' => 'Jumlah harus lebih besar dari nol.',
    'complete_empty' => 'BOM masih kosong, belum bisa ditandai selesai.',
    'complete_has_legacy' => 'BOM masih memuat baris lama tanpa sumber purchase order: :rows. Ganti dengan baris purchase order atau hapus dulu.',
    'complete_invalid_row' => 'Ada baris BOM dengan sumber atau jumlah yang tidak valid.',
    'cost_price_locked' => 'Harga modal varian ini mengikuti BOM yang sudah selesai. Buka kembali BOM untuk mengubahnya secara manual.',
    'bom_complete_blocks_legacy_write' => 'BOM varian ini sudah selesai. Buka kembali BOM sebelum menambah atau mengubah baris lama.',
    'copy_different_product' => 'BOM hanya bisa disalin antar varian dari produk yang sama.',
    'copy_requires_confirmation' => 'Varian tujuan sudah punya baris BOM. Konfirmasi untuk menggantinya.',
    'copy_no_next' => 'Tidak ada varian berikutnya untuk produk ini.',
    'copy_same_variant' => 'Varian sumber dan tujuan tidak boleh sama.',
    'purchase_order_seller_in_use' => 'Seller purchase order ini tidak bisa diubah karena barisnya sudah dipakai BOM.',
    'source_replace_not_allowed' => 'Baris ini tidak bisa diganti sumbernya.',
    'copy_source_empty' => 'BOM sumber masih kosong, tidak ada yang bisa disalin.',
    'copy_no_other_variants' => 'Produk ini tidak punya varian lain.',
];
