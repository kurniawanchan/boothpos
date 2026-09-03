<?php

return [
    'invalid_status_transition' => "Event berstatus ':from' tidak dapat berpindah ke ':to'.",
    'cannot_close_open_sessions' => 'Event tidak dapat ditutup: masih ada :count sesi kasir yang belum ditutup.',
    'no_open_session' => 'Tidak ada sesi terbuka.',
    'already_has_open_session' => 'Anda masih memiliki sesi kasir yang terbuka. Tutup sesi tersebut terlebih dahulu.',
    'opening_cash_entries_mismatch' => 'Total rincian kas per artist harus sama dengan kas awal.',
    'session_only_on_active_event' => 'Sesi hanya dapat dibuka pada event yang berstatus aktif.',
    'not_authorized_close_session' => 'Anda tidak berhak menutup sesi ini.',
    'session_already_closed' => 'Sesi sudah ditutup.',
    'not_authorized_view_summary' => 'Anda tidak berhak melihat ringkasan sesi ini.',
];
