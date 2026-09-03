<?php

return [
    'not_courier_fulfillment' => 'Preorder ini tidak memakai metode kurir.',
    'shipment_already_exists' => 'Preorder ini sudah memiliki data pengiriman.',
    'invalid_status_transition' => "Preorder berstatus ':from' tidak dapat berpindah ke ':to'.",
    'not_fully_paid' => 'Preorder belum lunas, tidak dapat diserahkan. Sisa tagihan: :outstanding',
    'customer_not_found' => 'Customer tidak ditemukan.',

    // 007-preorder-import-export-notify (US3)
    'import_customer_name_required' => 'Nama pelanggan wajib diisi.',
    'import_event_not_found' => 'Event ID :id tidak ditemukan.',
    'import_sku_required' => 'Baris :row: SKU wajib diisi.',
    'import_sku_not_found' => "Baris :row: SKU ':sku' tidak ditemukan.",
    'import_qty_invalid' => 'Baris :row: qty harus minimal 1.',
    'import_no_items' => 'Pesanan ini tidak memiliki item yang valid.',
    'import_nothing_saved' => 'Impor gagal — tidak ada baris yang disimpan.',
    'not_authorized' => 'Hanya owner/admin yang dapat mengakses fitur ini.',
];
