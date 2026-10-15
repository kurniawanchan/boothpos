<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\BackupService;
use App\Services\DemoClone\LiveToDemoCloner;
use App\Services\DemoClone\SafeError;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan app:clone-live-to-demo [--force] [--yes]
 *
 * Mengganti SELURUH data DEMO dengan salinan persis data LIVE (baris,
 * baris soft-deleted, gambar, bukti bayar). Data LIVE hanya dibaca.
 * Default = simulasi; --force menulis; cadangan pengaman selalu dibuat
 * lebih dulu. Pesan kesalahan tidak pernah memuat nilai baris (PII).
 */
class CloneLiveToDemo extends Command
{
    public const CONFIRM_QUESTION = 'Semua data DEMO akan DIHAPUS lalu diganti salinan data LIVE (termasuk data pelanggan apa adanya). Lanjutkan?';

    protected $signature = 'app:clone-live-to-demo
                            {--force : Tulis ke database (tanpa ini hanya simulasi)}
                            {--yes : Lewati pertanyaan konfirmasi}';

    protected $description = 'Ganti data DEMO dengan salinan persis data LIVE (data LIVE tidak diubah)';

    public function handle(LiveToDemoCloner $cloner, BackupService $backups): int
    {
        $plan = $cloner->plan();
        $this->renderPlan($plan);

        if (! $this->option('force')) {
            $this->warn('Simulasi — tidak ada yang ditulis. Jalankan ulang dengan --force untuk melakukannya.');

            return self::SUCCESS;
        }

        if (array_sum(array_column($plan['tables'], 'live_clone')) === 0) {
            $this->error('Tidak ada data LIVE untuk disalin; dibatalkan (data DEMO tidak dihapus).');

            return self::FAILURE;
        }

        if (! $this->option('yes')) {
            // Tanpa terminal interaktif (cron, --no-interaction) tidak ada yang bisa
            // menjawab; jangan mengandalkan default prompt — hentikan eksplisit
            // supaya penghapusan DEMO tidak pernah jalan tanpa persetujuan.
            if (! $this->input->isInteractive()) {
                $this->error('Mode non-interaktif membutuhkan --yes untuk menghapus data DEMO; dibatalkan.');

                return self::FAILURE;
            }

            if (! $this->confirm(self::CONFIRM_QUESTION, false)) {
                $this->warn('Dibatalkan.');

                return self::FAILURE;
            }
        }

        try {
            $backup = $backups->create();
        } catch (BackupException $e) {
            $this->error('Cadangan pengaman gagal, dibatalkan: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Cadangan pengaman: '.$backup['id'].' (pulihkan dengan app:restore bila perlu).');

        try {
            $report = $cloner->run();
        } catch (\Throwable $e) {
            $message = SafeError::describe($e);
            Log::error('app:clone-live-to-demo gagal: '.$message);
            $this->error('Gagal; semua perubahan dibatalkan. '.$message);

            return self::FAILURE;
        }

        $this->renderReport($report);

        return self::SUCCESS;
    }

    private function renderPlan(array $plan): void
    {
        $rows = [];

        foreach ($plan['tables'] as $table => $counts) {
            $rows[] = [$table, $counts['demo_delete'], $counts['live_clone']];
        }

        $this->table(['Tabel', 'DEMO dihapus', 'LIVE disalin'], $rows);
        $this->line(sprintf(
            'Berkas yang akan disalin: %d (%s); sumber hilang: %d',
            $plan['files']['files'],
            $this->megabytes($plan['files']['bytes']),
            $plan['files']['missing'],
        ));
    }

    private function renderReport(array $report): void
    {
        $this->info('Selesai. DEMO kini mencerminkan LIVE.');
        $this->line(sprintf(
            'Berkas disalin: %d (%s); sumber hilang: %d; referensi stok dibuang: %d',
            $report['files']['copied'],
            $this->megabytes($report['files']['bytes']),
            $report['files']['missing'],
            $report['references_dropped'],
        ));

        foreach ($report['skipped'] as $table => $count) {
            $this->warn("{$table}: {$count} baris dilewati karena berkas sumber hilang.");
        }

        if ($report['files_cleanup_failed'] ?? false) {
            $this->warn('DEMO sudah diganti; pembersihan berkas DEMO lama gagal — berkas lama mungkin tersisa di disk.');
        }

        // Kode artis/kategori DEMO dialokasikan ulang (kolomnya terlalu sempit
        // untuk akhiran); tampilkan padanannya. Kode bisnis, bukan PII.
        foreach (['artists' => 'Artis', 'categories' => 'Kategori'] as $table => $label) {
            foreach ($report['codes'][$table] ?? [] as $liveCode => $demoCode) {
                $this->line("Kode {$label}: {$liveCode} → {$demoCode}");
            }
        }
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 2).' MB';
    }
}
