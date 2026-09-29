<?php

return [
    'not_courier_fulfillment' => 'Preorder ini tidak memakai metode kurir.',
    'shipment_already_exists' => 'Preorder ini sudah memiliki data pengiriman.',
    'invalid_status_transition' => "Preorder berstatus ':from' tidak dapat berpindah ke ':to'.",
    'not_fully_paid' => 'Preorder belum lunas, tidak dapat diserahkan. Sisa tagihan: :outstanding',
    'customer_not_found' => 'Customer tidak ditemukan.',

    // 021-preorder-form-updates
    'discount_exceeds_total' => 'Diskon tidak boleh melebihi subtotal ditambah ongkos kirim.',
    'pickup_day_not_applicable' => "Hari jemput tidak berlaku untuk fulfillment 'courier' (Mail Order).",
    'courier_not_applicable' => "Kurir tidak berlaku untuk fulfillment 'pickup' (Self Pickup).",
    'pickup_day_requires_event' => 'Hari jemput butuh event yang ditautkan.',
    'pickup_day_out_of_range' => 'Hari jemput harus berada dalam rentang tanggal event.',

    // 022-preorder-invoice-crud-overhaul (US1)
    'edit_not_allowed_status' => 'Preorder yang sudah "Diserahkan" atau "Dibatalkan" tidak dapat diubah lagi — transaksi ini sudah tertutup.',
    'edit_total_below_paid_amount' => 'Perubahan ini membuat total pesanan lebih kecil dari jumlah yang sudah dibayar pelanggan. Sesuaikan item atau gunakan aksi Batalkan.',
    'delete_not_allowed_status' => 'Preorder hanya dapat dihapus selagi berstatus "Dipesan" atau "Dibatalkan". Batalkan terlebih dahulu untuk status lainnya.',
    'delete_not_allowed_has_payment' => 'Preorder ini sudah memiliki pembayaran tercatat dan tidak dapat dihapus. Gunakan aksi Batalkan.',

    // 022-preorder-invoice-crud-overhaul (US5)
    'mail_subject_invoice' => 'Invoice pre-order',
    'mail_subject_payment_invoice' => 'Invoice pembayaran pre-order',

    // 021-preorder-form-updates (US4) — versi baris-impor dari aturan yang
    // sama di atas.
    'import_discount_invalid' => 'Baris :row: diskon tidak valid atau melebihi subtotal.',
    'import_pickup_day_not_applicable' => "Baris :row: hari jemput tidak berlaku untuk fulfillment 'courier' (Mail Order).",
    'import_courier_not_applicable' => "Baris :row: kurir tidak berlaku untuk fulfillment 'pickup' (Self Pickup).",
    'import_courier_unknown' => "Baris :row: kurir ':courier' tidak dikenal.",
    'import_pickup_day_requires_event' => 'Baris :row: hari jemput butuh event yang ditautkan dan valid.',
    'import_pickup_day_out_of_range' => 'Baris :row: hari jemput harus berada dalam rentang tanggal event.',

    // 007-preorder-import-export-notify (US3)
    'import_customer_name_required' => 'Nama pelanggan wajib diisi.',
    'import_event_not_found' => 'Event ID :id tidak ditemukan.',
    'import_sku_required' => 'Baris :row: SKU wajib diisi.',
    'import_sku_not_found' => "Baris :row: SKU ':sku' tidak ditemukan.",
    'import_qty_invalid' => 'Baris :row: qty harus minimal 1.',
    'import_no_items' => 'Baris :row: pesanan ini tidak memiliki item yang valid.',
    'import_nothing_saved' => 'Impor gagal — tidak ada baris yang disimpan.',
    'not_authorized' => 'Hanya owner/admin yang dapat mengakses fitur ini.',

    // 022-preorder-invoice-crud-overhaul (US7) — layout satu-baris-per-pesanan
    'import_event_name_not_found' => "Baris :row: event ':name' tidak ditemukan.",
    'import_event_name_ambiguous' => "Baris :row: nama event ':name' cocok dengan lebih dari satu event — gunakan nama yang unik.",
    'import_fulfillment_invalid' => "Baris :row: kolom receive method harus berisi 'pickup' atau 'mail order'.",
    'import_products_quantities_mismatch' => 'Baris :row: jumlah produk, quantity, dan unit price harus sama banyak.',
    'import_pickup_day_invalid_format' => "Baris :row: hari jemput harus ditulis dalam format 'Day 1', 'Day 2', dst.",
    'import_dispatch_status_invalid' => "Baris :row: dispatch_status harus kosong, 'pending', 'invoice_sent', atau 'shipping'.",
    'import_dispatch_shipping_mail_order_only' => "Baris :row: dispatch_status 'shipping' hanya berlaku untuk pre-order mail order.",
    'import_dispatch_date_invalid' => "Baris :row: :column bukan tanggal/waktu yang valid — gunakan mis. 2026-09-27T10:00:00+07:00.",
    'import_dispatch_date_not_applicable' => "Baris :row: :column tidak berlaku bila dispatch_status adalah ':status'.",
    'dispatch_status_shipping_mail_order_only' => "'Pengiriman berjalan' hanya berlaku untuk pre-order Mail Order.",
    'dispatch_status_cancelled' => 'Pre-order yang dibatalkan tidak lagi memiliki invoice atau pengiriman aktif untuk dilacak.',
];
