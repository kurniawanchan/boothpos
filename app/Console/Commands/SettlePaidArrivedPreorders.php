<?php

namespace App\Console\Commands;

use App\Models\Preorder;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PreorderService;
use App\Support\ModeGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * php artisan app:settle-paid-arrived-preorders [--apply --by=<username>]
 *
 * Koreksi sekali pakai (spec 061, US4): pre-order yang macet di "arrived" padahal sudah lunas. Dulu
 * "settled" hanya tercapai lewat pembayaran SESUDAH barang tiba; yang dilunasi SEBELUM tiba tak punya
 * jalan maju (tak ada tombol Settle, serah terima menuntut "settled"). Kode baru sudah menyelesaikannya
 * saat tiba (PreorderService::settleArrivedIfFullyPaid); perintah ini memperbaiki baris yang sudah
 * terlanjur macet, di DEMO maupun LIVE.
 *
 * Default = DRY-RUN (hanya daftar). --apply butuh --by=<owner/admin>. Idempoten: hanya baris yang MASIH
 * arrived + lunas yang dipilih. Sengaja lebih ringan dari app:sync-stock-from-transactions (tanpa kode
 * rencana/Excel/cadangan): yang berubah hanya `status` arrived → settled pada baris yang sudah memenuhi
 * aturan penyelesaian; stok, pembayaran, dan total tidak disentuh. Keluaran hanya memuat nomor
 * pre-order dan hitungan, tak pernah data pelanggan. Lihat specs/061-preorder-arrived-no-stock-movement/.
 */
class SettlePaidArrivedPreorders extends Command
{
    private const MODES = ['live', 'demo'];

    protected $signature = 'app:settle-paid-arrived-preorders
                            {--apply : Terapkan koreksi (tanpa ini hanya dry-run)}
                            {--by= : Username pelaksana (owner/admin)}';

    protected $description = 'Settle pre-order yang sudah lunas tetapi macet di status arrived (dry-run bawaan)';

    public function handle(PreorderService $preorders, ActivityLogger $logger): int
    {
        $apply = (bool) $this->option('apply');

        $actor = null;
        if ($apply) {
            $actor = $this->resolveActor((string) $this->option('by'));
            if ($actor === null) {
                return self::FAILURE;
            }
        }

        $candidates = $this->candidates($preorders);

        if ($candidates === []) {
            $this->info('Tidak ada pre-order yang perlu dikoreksi.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->line(sprintf('DRY-RUN: %d pre-order akan di-settle. Tidak ada yang diubah.', count($candidates)));
            foreach ($candidates as [$mode, $id, $number]) {
                $this->line("  [{$mode}] {$number}");
            }
            $this->line('Jalankan ulang dengan --apply --by=<username owner/admin> untuk menerapkan.');

            return self::SUCCESS;
        }

        $settled = 0;
        $failed = 0;

        foreach ($candidates as [$mode, $id, $number]) {
            try {
                ModeGate::runAs($mode, function () use ($id, $preorders, $logger, $actor, &$settled) {
                    DB::transaction(function () use ($id, $preorders, $logger, $actor, &$settled) {
                        // Dikunci dan dibaca ulang: kalau sejak daftar disusun status berubah
                        // (mis. sudah di-settle manual), baris dilewati, bukan ditimpa.
                        $pre = Preorder::lockForUpdate()->find($id);

                        if ($pre === null || ! $preorders->settleArrivedIfFullyPaid($pre)) {
                            return;
                        }

                        // Di dalam transaksi yang sama: log tak boleh mengklaim perubahan yang batal.
                        $logger->log(
                            $actor->id, 'preorder_status_corrected', 'preorder', $pre->id,
                            'Koreksi status: sudah lunas tetapi macet di arrived, di-settle (spec 061).',
                            ['status' => 'arrived'], ['status' => 'settled'],
                        );

                        $settled++;
                    });
                });
                $this->line("  [{$mode}] {$number} → settled");
            } catch (\Throwable $e) {
                $failed++;
                // Pesan galat penuh hanya ke log aplikasi; layar memuat nomor saja.
                Log::error('app:settle-paid-arrived-preorders gagal untuk satu pre-order', ['preorder_id' => $id, 'exception' => $e]);
                $this->error("  [{$mode}] {$number} GAGAL (lihat log aplikasi)");
            }
        }

        $this->info("Selesai: {$settled} pre-order di-settle, {$failed} gagal.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Pre-order arrived + lunas di tiap mode. Memakai scope mode bawaan model di dalam runAs()
     * (baris mode lain tersembunyi oleh DataModeScope), jadi DEMO dan LIVE dikerjakan bergantian.
     *
     * @return array<int, array{0: string, 1: int, 2: string}> [mode, id, nomor]
     */
    private function candidates(PreorderService $preorders): array
    {
        $found = [];

        foreach (self::MODES as $mode) {
            ModeGate::runAs($mode, function () use ($mode, $preorders, &$found) {
                Preorder::where('status', 'arrived')->orderBy('id')->each(function (Preorder $pre) use ($mode, $preorders, &$found) {
                    if ($preorders->isArrivedAndFullyPaid($pre)) {
                        $found[] = [$mode, $pre->id, $pre->preorder_number];
                    }
                });
            });
        }

        return $found;
    }

    private function resolveActor(string $username): ?User
    {
        if ($username === '') {
            $this->error('--apply butuh --by=<username> (owner/admin).');

            return null;
        }

        $user = User::where('username', $username)->first();

        if ($user === null || ! $user->isOwnerOrAdmin()) {
            $this->error('Pengguna tidak ditemukan atau bukan owner/admin.');

            return null;
        }

        return $user;
    }
}
