<?php

return [
    'invalid_status_transition' => "Event berstatus ':from' tidak dapat berpindah ke ':to'.",
    'cannot_close_open_sessions' => 'Event tidak dapat ditutup: masih ada :count sesi kasir yang belum ditutup.',
    'no_open_session' => 'Tidak ada sesi terbuka.',
    'already_has_open_session' => 'Anda masih memiliki sesi kasir yang terbuka. Tutup sesi tersebut terlebih dahulu.',
    'opening_cash_entries_mismatch' => 'Total rincian kas per penjual harus sama dengan kas awal.',
    'session_only_on_active_event' => 'Sesi hanya dapat dibuka pada event yang berstatus aktif.',
    'not_authorized_close_session' => 'Anda tidak berhak menutup sesi ini.',
    'session_already_closed' => 'Sesi sudah ditutup.',
    'not_authorized_view_summary' => 'Anda tidak berhak melihat ringkasan sesi ini.',
    'event_delete_has_transactions' => 'Event masih memiliki transaksi (order/pre-order) dan tidak dapat dihapus.',
    'event_delete_has_settlements' => 'Event sudah memiliki settlement seller dan tidak dapat dihapus.',

    // 023-event-availability-invoice-redesign (US1)
    'available_on_requires_multi_day' => 'Hari tersedia hanya berlaku untuk event yang berlangsung lebih dari satu hari.',
    'available_on_label' => 'Hari tersedia',
    'available_on_day_1' => 'Hari 1 ({date})',
    'available_on_day_2' => 'Hari 2 ({date})',

    // 063-reopen-closed-shift
    'not_authorized_reopen_session' => 'Hanya Owner yang dapat membuka kembali shift yang sudah ditutup.',
    'session_not_closed' => 'Shift ini belum ditutup, jadi tidak perlu dibuka kembali.',
    'cashier_has_open_session' => 'Kasir shift ini masih memiliki shift lain yang terbuka. Tutup shift tersebut terlebih dahulu.',
    'reopen_reason_required' => 'Alasan membuka kembali shift wajib diisi (3–500 karakter).',
];
