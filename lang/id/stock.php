<?php

// 036-bom-variant-stock-ux — pesan layar/riwayat stok.
return [
    'not_authorized' => 'Anda tidak memiliki akses ke pergerakan stok.',
    'outlet_required' => 'Pilih outlet yang stoknya diubah.',
    'transfer_same_outlet' => 'Outlet asal dan tujuan harus berbeda.',
    'transfer_outlet_inactive' => 'Transfer dari atau ke outlet nonaktif tidak diizinkan.',
    'transfer_over_receipt' => 'Tidak bisa menerima lebih dari yang dikirim.',
    'transfer_reason_required' => 'Alasan wajib bila yang diterima kurang dari yang dikirim.',
    'transfer_already_received' => 'Transfer ini sudah diterima.',
    'transfer_not_in_transit' => 'Transfer ini tidak lagi dalam perjalanan.',
    'transfer_lines_required' => 'Tambahkan minimal satu baris.',
    'transfer_line_invalid' => 'Setiap baris butuh jenis (varian atau bahan), item, dan jumlah di atas nol.',
    'transfer_line_duplicate' => 'Item yang sama muncul dua kali dalam transfer ini.',
    'transfer_item_not_found' => 'Item tidak ditemukan.',
    'transfer_untracked' => 'Item :sku tidak melacak stok, jadi tidak bisa ditransfer.',
    'transfer_whole_qty' => 'Item ditransfer dalam jumlah bulat.',
    'transfer_client_ref_conflict' => 'Referensi klien ini sudah dipakai untuk transfer lain.',
];
