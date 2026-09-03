<?php

return [
    'vendor_delete_has_prices' => 'Vendor masih memiliki harga bahan yang terdaftar dan tidak dapat dihapus.',
    'material_delete_has_vendor_prices' => 'Bahan masih memiliki harga vendor yang terdaftar dan tidak dapat dihapus.',
    'material_delete_used_in_bom' => 'Bahan masih dipakai pada BOM salah satu varian produk dan tidak dapat dihapus.',
    'not_authorized_vendor_prices' => 'Hanya owner/admin/inventory yang dapat mengelola harga vendor.',
    'not_authorized_bom_manage' => 'Hanya owner/admin/inventory yang dapat mengelola BOM.',
    'not_authorized_bom_view' => 'Hanya owner/admin/inventory yang dapat melihat BOM.',
    'not_authorized_cost_breakdown' => 'Hanya owner/admin/inventory yang dapat melihat rincian modal.',
    'vendor_price_already_exists' => 'Vendor ini sudah punya harga untuk bahan ini. Ubah harganya lewat endpoint update.',
    'bom_line_already_exists' => 'Bahan ini sudah ada di BOM varian ini. Ubah jumlahnya lewat endpoint update.',
    'insufficient_material_stock' => 'Stok bahan :material tidak cukup (tersedia: :available).',
];
