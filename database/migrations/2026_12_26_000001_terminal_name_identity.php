<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 064 — terminal dikenali HANYA lewat namanya (research.md D1–D4).
 *
 * - Nama terminal aktif dibuat unik per (outlet, mode data): duplikat lama diberi akhiran " 2", " 3", … (yang tertua
 *   tetap), nama kosong menjadi "Terminal". Tidak ada baris yang dihapus.
 * - `devices.code` boleh NULL: kode T01/T02 tidak lagi dibuat, diterima, atau ditampilkan; nilai lama dibiarkan
 *   (data riwayat, indeks uniknya tetap — NULL tidak saling bentrok).
 * - Nomor struk baru = nomor urut polos per OUTLET (`outlet_receipt_counters`). Dua outlet kini boleh sama-sama
 *   punya `000001`, jadi UNIQUE `receipt_no` global diganti UNIQUE `(outlet_id, receipt_no)`; nomor lama
 *   (`T01-000124`) tidak berubah dan tidak bisa bentrok dengan nomor 6 digit.
 * - `terminal_counters` dibiarkan (riwayat, tidak dibaca/ditulis lagi) — pola "hapus kode, simpan data" seperti 046.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dedupeActiveNames();

        Schema::table('devices', function (Blueprint $table) {
            $table->string('code', 12)->nullable()->change();
        });

        Schema::create('outlet_receipt_counters', function (Blueprint $table) {
            $table->foreignId('outlet_id')->primary()->constrained('outlets');
            $table->unsignedInteger('receipt_seq')->default(0);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_receipt_no_unique');
            $table->unique(['outlet_id', 'receipt_no'], 'uniq_orders_outlet_receipt_no');
        });
    }

    public function down(): void
    {
        $dup = DB::table('orders')->whereNotNull('receipt_no')->select('receipt_no')->groupBy('receipt_no')->havingRaw('COUNT(*) > 1')->exists();
        if ($dup) {
            throw new RuntimeException('Tidak bisa dikembalikan: ada nomor struk yang sama di beberapa outlet (UNIQUE global receipt_no).');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('uniq_orders_outlet_receipt_no');
            $table->unique('receipt_no', 'orders_receipt_no_unique');
        });

        Schema::dropIfExists('outlet_receipt_counters');

        // Kode asli tidak tersimpan untuk terminal baru: isi T01, T02, … per (outlet, mode) mengisi celah terkecil.
        $used = [];
        foreach (DB::table('devices')->whereNotNull('code')->get(['outlet_id', 'data_mode', 'code']) as $d) {
            $used[$d->outlet_id.'|'.$d->data_mode][strtoupper($d->code)] = true;
        }
        foreach (DB::table('devices')->whereNull('code')->orderBy('id')->get(['id', 'outlet_id', 'data_mode']) as $d) {
            $key = $d->outlet_id.'|'.$d->data_mode;
            $n = 1;
            while (isset($used[$key][$code = 'T'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)])) {
                $n++;
            }
            $used[$key][$code] = true;
            DB::table('devices')->where('id', $d->id)->update(['code' => $code]);
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->string('code', 12)->nullable(false)->change();
        });
    }

    /**
     * Terminal aktif yang namanya kembar (tanpa membedakan huruf besar/kecil dan spasi tepi) diberi akhiran angka.
     * Dua putaran: nama yang belum terpakai dicadangkan dulu, baru duplikatnya diberi akhiran — supaya terminal
     * yang sudah bernama "Kasir 2" tidak ikut diganti hanya karena duplikat "Kasir" lebih dulu mencari akhiran.
     */
    private function dedupeActiveNames(): void
    {
        $taken = [];
        $deferred = [];
        $rows = DB::table('devices')->whereNull('revoked_at')->orderBy('id')->get(['id', 'outlet_id', 'data_mode', 'name']);

        foreach ($rows as $d) {
            $key = $d->outlet_id.'|'.$d->data_mode;
            $base = trim((string) $d->name);
            if ($base === '') {
                $base = 'Terminal';
            }

            if (isset($taken[$key][mb_strtolower($base)])) {
                $deferred[] = [$d, $key, $base];

                continue;
            }
            $taken[$key][mb_strtolower($base)] = true;
            if ($base !== $d->name) {
                DB::table('devices')->where('id', $d->id)->update(['name' => mb_substr($base, 0, 80)]);
            }
        }

        foreach ($deferred as [$d, $key, $base]) {
            $name = $base;
            $n = 2;
            while (isset($taken[$key][mb_strtolower($name)])) {
                $name = $base.' '.$n++;
            }
            $taken[$key][mb_strtolower($name)] = true;
            DB::table('devices')->where('id', $d->id)->update(['name' => mb_substr($name, 0, 80)]);
        }
    }
};
