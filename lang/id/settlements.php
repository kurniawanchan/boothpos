<?php

/**
 * 076-settlement-tab-session-branding — pesan galat modul settlement seller.
 * Kuncinya sama dengan `code` pada respons 409 (SettlementRuleException).
 */
return [
    'settlement_exists' => 'Sudah ada settlement aktif untuk seller dan event ini.',
    'not_draft' => 'Settlement ini sudah difinalkan dan tidak dapat diubah dengan cara ini. Buka kembali (reopen) atau tambahkan penyesuaian.',
    'not_finalized' => 'Settlement ini belum difinalkan.',
    'nothing_to_settle' => 'Tidak ada yang dapat di-settle: belum ada penjualan, pre-order terbayar, atau potongan/penyesuaian.',
    'reconciliation_mismatch' => 'Rekonsiliasi tidak seimbang (:figure); settlement tidak dapat difinalkan.',
    'was_finalized' => 'Draf yang pernah difinalkan tidak dapat dihapus (nomornya sudah diberikan).',
    'has_payments' => 'Settlement yang sudah memiliki pembayaran tidak dapat dibuka kembali.',
    'net_below_paid' => 'Penyesuaian ini membuat total lebih kecil dari yang sudah dibayarkan.',
    'direction_locked' => 'Penyesuaian ini membalik arah pembayaran padahal sudah ada pembayaran.',
    'already_settled' => 'Settlement ini sudah lunas.',
    'nothing_to_pay' => 'Settlement ini bernilai nol dan tidak memerlukan pembayaran.',
    'deduction_after_finalization' => 'Setelah difinalkan hanya penyesuaian (adjustment) yang dapat ditambahkan.',

    // Validasi penyesuaian/potongan
    'amount_invalid' => 'Jumlah harus berupa angka dengan paling banyak dua desimal.',
    'amount_positive' => 'Jumlah harus lebih dari nol dengan paling banyak dua desimal.',
    'amount_zero' => 'Jumlah tidak boleh nol.',
    'amount_deduction_positive' => 'Ketik potongan sebagai angka positif; sistem yang mengurangkannya.',
    'not_authorized' => 'Anda tidak berhak mengakses modul settlement.',

    'amount_exceeds_balance' => 'Jumlah melebihi sisa yang terutang; maksimum :max.',
    'client_ref_taken' => 'Referensi pembayaran ini sudah dipakai pada settlement lain.',
    'acknowledgement_note_required' => 'Catatan wajib diisi untuk pengakuan di luar sistem.',

    'created' => 'Membuat settlement :seller untuk event :event.',
    'refreshed' => 'Menyegarkan sumber settlement :seller (:event).',
    'deleted' => 'Menghapus draf settlement :seller (:event).',
    'adjustment_added' => 'Menambah :kind ":label" pada settlement :seller (:event).',
    'adjustment_updated' => 'Mengubah :kind ":label" pada settlement :seller (:event).',
    'adjustment_removed' => 'Menghapus :kind ":label" dari settlement :seller (:event).',
    'finalized' => 'Memfinalkan settlement :number (:seller, :event).',
    'reopened' => 'Membuka kembali settlement :number (:seller, :event).',
    'acknowledged' => 'Mencatat pengakuan seller pada settlement :number.',
    'payment_added' => 'Mencatat pembayaran settlement :number.',
    'payment_removed' => 'Menghapus pembayaran settlement :number.',

    'kind_deduction' => 'potongan',
    'kind_adjustment' => 'penyesuaian',
];
