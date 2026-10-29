<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Backup\BackupException;
use App\Services\BackupService;
use App\Services\StockSync\StockSyncApplier;
use App\Services\StockSync\StockSyncPlanner;
use App\Services\StockSync\StockSyncReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan app:sync-stock-from-transactions [--apply --approve=<kode> --by=<username> [--yes]]
 *
 * Koreksi sekali pakai untuk stok LIVE yang diketik SESUDAH penjualan / serah-terima pre-order sudah ada (angka yang
 * diketik mengabaikan transaksi itu). Default = DRY-RUN: tidak menulis apa pun; menulis Excel tinjauan dan mencetak
 * KODE RENCANA. Menerapkan butuh --apply --approve=<kode dari dry-run yang sudah ditinjau>: kode dihitung ulang dan harus
 * sama, jadi transaksi atau perubahan stok baru sejak peninjauan membatalkan persetujuan lama. Lihat
 * specs/051-stock-sync-from-transactions/ untuk alasan setiap aturan; mengikuti pola app:clone-live-to-demo
 * (simulasi bawaan, konfirmasi, cadangan pengaman sebelum menulis).
 */
class SyncStockFromTransactions extends Command
{
    public const CONFIRM_QUESTION = 'Stok LIVE akan DIUBAH sesuai rencana yang sudah Anda tinjau di Excel. Lanjutkan?';

    /**
     * Gerbang peran sama dengan ekspor/impor master data (CLAUDE.md "canManageMasterData" — nama konsep; mekanismenya
     * pengecekan menu_keys ini): owner/admin/inventory, bukan kasir.
     */
    private const ALLOWED_MENUS = ['artists', 'categories', 'products', 'stock', 'vendors', 'materials', 'roles', 'users'];

    protected $signature = 'app:sync-stock-from-transactions
                            {--apply : Terapkan koreksi (tanpa ini hanya dry-run)}
                            {--approve= : Kode rencana dari dry-run yang sudah ditinjau}
                            {--by= : Username pelaksana (owner/admin/inventory)}
                            {--yes : Lewati pertanyaan konfirmasi}';

    protected $description = 'Sinkronkan stok LIVE dengan transaksi yang dibuat sebelum stok riil diketik (dry-run bawaan)';

    public function handle(StockSyncPlanner $planner, StockSyncApplier $applier, StockSyncReport $report, BackupService $backups): int
    {
        $plan = $planner->plan();

        if (! $this->option('apply')) {
            return $this->dryRun($plan, $report);
        }

        $approve = (string) $this->option('approve');
        if ($approve === '') {
            $this->error('--apply butuh --approve=<kode rencana>. Jalankan dulu tanpa --apply, tinjau Excel-nya, lalu pakai kode yang dicetak.');

            return self::FAILURE;
        }

        $by = $this->resolveUser((string) $this->option('by'));
        if ($by === null) {
            return self::FAILURE;
        }

        if (! hash_equals($plan['hash'], $approve)) {
            $path = $report->store($plan, 'dryrun');
            $this->error('Kode rencana tidak cocok: data berubah sejak dry-run yang Anda tinjau (atau kodenya salah). Tidak ada yang diubah.');
            $this->line("Excel terbaru: {$path}");
            $this->line("Kode rencana terbaru: {$plan['hash']} — tinjau ulang, lalu jalankan lagi dengan kode ini.");

            return self::FAILURE;
        }

        if ($plan['counts'][StockSyncPlanner::STATUS_CHANGE] + $plan['counts'][StockSyncPlanner::STATUS_ATTENTION] === 0) {
            $this->info('Tidak ada varian yang perlu diubah.');

            return self::SUCCESS;
        }

        if (! $this->option('yes')) {
            // Tanpa terminal interaktif tidak ada yang bisa menjawab; jangan mengandalkan default prompt.
            if (! $this->input->isInteractive()) {
                $this->error('Mode non-interaktif membutuhkan --yes; dibatalkan.');

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
            $result = $applier->apply($plan, $by);
        } catch (\Throwable $e) {
            Log::error('app:sync-stock-from-transactions gagal: '.$e->getMessage());
            $this->error('Gagal; semua perubahan dibatalkan. '.$e->getMessage());

            return self::FAILURE;
        }

        $path = $report->store($plan, 'applied', $by, $result);
        $this->info("Selesai: {$result['changed']} varian diubah, {$result['units']} unit dikurangkan.");
        $this->line("Laporan hasil: {$path}");
        $this->line('Jalankan dry-run lagi untuk memastikan tidak ada perubahan tersisa.');

        return self::SUCCESS;
    }

    private function dryRun(array $plan, StockSyncReport $report): int
    {
        $path = $report->store($plan, 'dryrun');

        $this->table(['Status', 'Jumlah varian'], array_map(fn ($s, $n) => [$s, $n], array_keys($plan['counts']), $plan['counts']));
        $this->line("Total pengurang bersih: {$plan['totals']['taken']} unit; kekurangan (dibatasi ke 0): {$plan['totals']['shortfall']} unit");
        $this->line("Excel tinjauan: {$path}");
        $this->line("Kode rencana: {$plan['hash']}");
        $this->warn('Dry-run — tidak ada yang diubah. Setelah meninjau Excel, terapkan dengan:');
        $this->line("  php artisan app:sync-stock-from-transactions --apply --approve={$plan['hash']} --by=<username>");

        return self::SUCCESS;
    }

    private function resolveUser(string $username): ?User
    {
        if ($username === '') {
            $this->error('--apply butuh --by=<username> (owner/admin/inventory).');

            return null;
        }

        $user = User::where('username', $username)->first();

        if ($user === null || ! $user->canAccessAnyMenu(self::ALLOWED_MENUS)) {
            $this->error('Pengguna tidak ditemukan atau tidak berwenang mengelola data master.');

            return null;
        }

        return $user;
    }
}
