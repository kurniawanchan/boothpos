<?php

namespace App\Services;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Station;
use Illuminate\Database\Eloquent\Model;

/**
 * 071 — satu-satunya tempat aturan "meja/stasiun boleh dipindah outlet" DAN satu-satunya jalur menuliskan pemindahan
 * (Konstitusi I): dua controller memanggilnya, tidak ada salinan aturan per controller.
 *
 * Aturan (semuanya dibaca TANPA global scope — FK dan riwayat tidak peduli outlet maupun mode data):
 *  - Meja: ditolak bila ada order `open` di meja ini (pelayan masih memakainya; meja "pindah" di bawah tab yang berjalan).
 *  - Stasiun: ditolak bila masih ditunjuk kategori atau item menu (termasuk yang soft-deleted; FK restrict tetap
 *    menahannya) atau punya tiket dapur dengan item belum selesai (new/preparing/ready). Memindahkan beserta
 *    ketergantungannya sengaja tidak dibangun: itu mengubah menu outlet lain.
 * Pemindahan TIDAK menyentuh riwayat: order dan tiket lama tetap pada outlet transaksinya.
 */
class OutletMoveGuard
{
    public const OPEN_TAB = 'open_tab';

    public const IN_USE = 'in_use';

    public const OPEN_TICKETS = 'open_tickets';

    public function __construct(private ActivityLogger $log) {}

    public function tableBlocker(DiningTable $table): ?string
    {
        $open = Order::withoutGlobalScopes()->where('table_id', $table->id)->where('status', Order::STATUS_OPEN)->exists();

        return $open ? self::OPEN_TAB : null;
    }

    public function stationBlocker(Station $station): ?string
    {
        $used = Category::withoutGlobalScopes()->where('station_id', $station->id)->exists()
            || Product::withoutGlobalScopes()->where('station_id', $station->id)->exists();
        if ($used) {
            return self::IN_USE;
        }

        $unfinished = KitchenTicketItem::withoutGlobalScopes()
            ->whereIn('status', KitchenTicketItem::UNFINISHED)
            ->whereHas('ticket', fn ($t) => $t->withoutGlobalScopes()->where('station_id', $station->id))
            ->exists();

        return $unfinished ? self::OPEN_TICKETS : null;
    }

    /** Pesan untuk pengguna (Indonesia/Inggris menurut locale) dari kode penolakan. */
    public function message(string $blocker): string
    {
        return match ($blocker) {
            self::OPEN_TAB => __('restaurant.table_move_open_tab'),
            self::IN_USE => __('restaurant.station_move_in_use'),
            default => __('restaurant.station_move_open_tickets'),
        };
    }

    /**
     * Menuliskan pemindahan: `outlet_id` diset eksplisit (tidak fillable; nilainya sudah divalidasi diizinkan oleh
     * pemanggil) lalu dicatat di log aktivitas. Dipanggil DI DALAM transaksi pemanggil, supaya pemindahan yang
     * dibatalkan tidak meninggalkan log yang mengaku sudah terjadi.
     */
    public function applyMove(DiningTable|Station $item, int $toOutletId, ?int $userId): void
    {
        $from = (int) $item->outlet_id;
        $codes = Outlet::query()->whereIn('id', [$from, $toOutletId])->pluck('code', 'id');

        $item->outlet_id = $toOutletId;
        $item->save();

        $kind = $item instanceof DiningTable ? 'meja' : 'stasiun';
        $this->log->log(
            userId: $userId,
            action: 'moved',
            entityType: class_basename($item),
            entityId: $item->id,
            description: "Memindahkan {$kind} {$item->name} dari outlet ".($codes[$from] ?? $from).' ke '.($codes[$toOutletId] ?? $toOutletId).'.',
            oldValues: ['outlet_id' => $from],
            newValues: ['outlet_id' => $toOutletId],
        );
    }
}
