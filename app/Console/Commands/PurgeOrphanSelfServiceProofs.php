<?php

namespace App\Console\Commands;

use App\Models\PaymentProof;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * php artisan self-service:purge-orphan-proofs
 *
 * Unggahan bukti bayar dari halaman publik dilakukan SEBELUM kiriman pesanan. Pelanggan yang batal/menutup halaman
 * meninggalkan berkas yatim di disk privat. Perintah ini menghapus bukti publik (tanpa pengunggah) yang lebih tua dari
 * 24 jam, belum tertaut ke pembayaran, dan tidak dipakai kiriman mana pun. Bukti milik staf dan yang sudah tertaut tidak
 * pernah disentuh. Lintas mode DEMO/LIVE (tanpa global scope): yatim di mode mana pun tidak berguna lagi.
 */
class PurgeOrphanSelfServiceProofs extends Command
{
    protected $signature = 'self-service:purge-orphan-proofs {--hours=24 : Umur minimum bukti yatim (jam)}';

    protected $description = 'Hapus bukti bayar unggahan publik yang tidak pernah dikirim bersama pesanan';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $used = DB::table('self_service_orders')->select('proof_id');

        $orphans = PaymentProof::withoutGlobalScopes()
            ->whereNull('payment_id')
            ->whereNull('uploaded_by')
            ->where('created_at', '<', now()->subHours($hours))
            ->whereNotIn('id', $used)
            ->get();

        foreach ($orphans as $proof) {
            Storage::disk('local')->delete($proof->file_path);
            $proof->delete();
        }

        $this->info("Bukti yatim dihapus: {$orphans->count()}");

        return self::SUCCESS;
    }
}
