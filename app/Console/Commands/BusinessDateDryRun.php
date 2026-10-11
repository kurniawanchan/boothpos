<?php

namespace App\Console\Commands;

use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 042-dashboard-revamp (T008) — laporan KERING sebelum migrasi pengisian tanggal bisnis.
 *
 * Laporan lama mengelompokkan penjualan menurut `DATE(created_at)` dalam UTC; tanggal bisnis baru
 * memakai zona waktu toko (default Asia/Jakarta). Penjualan antara 00.00 dan 07.00 WIB karena itu
 * pindah ke hari lokal yang benar. Perintah ini TIDAK menulis apa pun: ia mendaftar baris yang akan
 * pindah hari, dan order dine-in/takeaway yang `updated_at`-nya berjarak > 12 jam dari `created_at`
 * (tebakan `completed_at` untuk tab yang diedit setelah dilunasi kurang bisa dipercaya).
 */
class BusinessDateDryRun extends Command
{
    protected $signature = 'app:business-date-dry-run {--limit=20 : jumlah baris contoh yang dicetak}';

    protected $description = 'Daftar order yang berpindah hari bisnis bila tanggal bisnis diisi dari zona waktu toko (hanya baca)';

    public function handle(): int
    {
        $moved = [];
        $uncertain = [];
        $total = 0;

        DB::table('orders')->orderBy('id')->chunkById(500, function ($rows) use (&$moved, &$uncertain, &$total) {
            foreach ($rows as $row) {
                $total++;
                $completedAt = $row->status === 'completed'
                    ? ($row->service_type === 'retail' ? $row->created_at : ($row->updated_at ?? $row->created_at))
                    : null;
                $basis = $completedAt ?? $row->created_at;
                if ($basis === null) {
                    continue;
                }

                $utc = CarbonImmutable::parse($basis, 'UTC');
                $new = BusinessDay::dateFor($utc);

                if ($new !== $utc->toDateString()) {
                    $moved[] = [$row->id, $row->order_number, $utc->toDateString(), $new, $row->total_amount];
                }

                if ($row->status === 'completed' && $row->service_type !== 'retail' && $row->updated_at && $row->created_at
                    && abs(CarbonImmutable::parse($row->updated_at, 'UTC')->diffInHours(CarbonImmutable::parse($row->created_at, 'UTC'))) > 12) {
                    $uncertain[] = [$row->id, $row->order_number, $row->created_at, $row->updated_at];
                }
            }
        });

        $this->line('Zona waktu toko: '.BusinessDay::timezone().' · jam batas hari: '.BusinessDay::cutoff());
        $this->line("Order diperiksa: {$total}");
        $this->line('Order yang pindah hari (tanggal UTC lama → tanggal bisnis baru): '.count($moved));
        $this->line('Order dine-in/takeaway dengan created_at dan updated_at berjarak > 12 jam: '.count($uncertain));

        $limit = (int) $this->option('limit');
        if ($moved !== []) {
            $this->table(['id', 'nomor', 'tanggal UTC', 'tanggal bisnis', 'total'], array_slice($moved, 0, $limit));
            $perDay = collect($moved)->groupBy(fn ($m) => $m[2].' → '.$m[3])->map->count()->sortKeys();
            $this->table(['perpindahan', 'jumlah order'], $perDay->map(fn ($n, $k) => [$k, $n])->values()->all());
        }
        if ($uncertain !== []) {
            $this->table(['id', 'nomor', 'created_at', 'updated_at'], array_slice($uncertain, 0, $limit));
        }

        $this->info('Tidak ada yang ditulis.');

        return self::SUCCESS;
    }
}
