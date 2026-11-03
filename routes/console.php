<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 090 — bukti bayar unggahan publik yang tak pernah dikirim bersama pesanan dibersihkan harian (butuh `schedule:run` terjadwal; lihat RUNBOOK).
Schedule::command('self-service:purge-orphan-proofs')->daily();
