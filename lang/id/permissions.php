<?php

// 057 — izin aksi per menu.
return [
    'action_not_allowed' => 'Peran Anda tidak berhak melakukan ini (:action pada :menu).',
    'owner_locked' => 'Peran Owner selalu punya akses penuh dan tidak bisa dibatasi.',
    'unknown_menu' => 'Menu tidak dikenal: :menu.',
    'unknown_action' => 'Aksi ":action" tidak dikenal untuk menu :menu.',
    'menu_not_in_role' => 'Menu :menu bukan bagian dari peran ini.',
    'actions' => [
        'view' => 'Lihat', 'create' => 'Tambah', 'edit' => 'Ubah', 'delete' => 'Hapus',
        'draft' => 'Draf', 'status' => 'Ubah status', 'bom' => 'Bill of materials', 'catalog' => 'Katalog',
        'import' => 'Impor', 'export' => 'Ekspor', 'adjust' => 'Sesuaikan stok', 'payment' => 'Pembayaran',
        'dispatch' => 'Status pengiriman', 'verify' => 'Verifikasi pembayaran', 'split' => 'Pisah', 'duplicate' => 'Duplikat',
        'notify' => 'Kirim notifikasi', 'activate' => 'Aktifkan / nonaktifkan', 'mark_paid' => 'Tandai lunas', 'cancel' => 'Batalkan',
        'void' => 'Batalkan penjualan', 'payment_method' => 'Ubah metode bayar', 'reference' => 'Referensi penjualan', 'receipts' => 'Unduh struk',
        'refresh' => 'Segarkan', 'finalize' => 'Finalisasi', 'reopen' => 'Buka kembali', 'acknowledge' => 'Tanda terima', 'payout' => 'Pembayaran ke penjual',
        'backup' => 'Cadangan', 'restore' => 'Pulihkan', 'branding' => 'Identitas toko',
    ],
];
