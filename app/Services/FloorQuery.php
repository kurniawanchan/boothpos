<?php

namespace App\Services;

use App\Models\DiningTable;
use App\Models\Order;

/**
 * 028 US1 — data lantai untuk GET /floor: semua meja aktif per area, status
 * kosong/terisi, dan tab takeaway. Jumlah query KONSTAN (tidak bergantung
 * jumlah meja): satu query meja, satu query tab terbuka (jumlah baris belum
 * terkirim dihitung sebagai subquery di query yang sama).
 *
 * `version` adalah hash isi respons itu sendiri, BUKAN turunan updated_at:
 * dua perubahan dalam detik yang sama (granularitas timestamp MySQL) tetap
 * menghasilkan token berbeda, jadi layar yang polling tidak pernah melewatkan
 * perubahan. Field yang berubah-ubah menurut jam (mis. lama menunggu) sengaja
 * TIDAK ada di payload ini supaya token stabil saat tidak ada perubahan nyata.
 */
class FloorQuery
{
    public function build(): array
    {
        $tabs = Order::query()
            ->where('status', Order::STATUS_OPEN)
            ->withCount([
                'items as unsent_count' => fn ($q) => $q->whereNull('sent_at'),
                // Item yang sudah siap disajikan, belum diantar (subquery — tetap satu query).
                'ticketItems as ready_count' => fn ($q) => $q->where('kitchen_ticket_items.status', 'ready'),
            ])
            ->get(['id', 'order_number', 'table_id', 'tab_label', 'service_type', 'total_amount', 'session_id', 'created_at']);

        $byTable = $tabs->whereNotNull('table_id')->keyBy('table_id');

        $tables = DiningTable::query()->where('is_active', true)->get()
            // Urut manual (display_order) dulu, lalu nama secara alami: "2" sebelum "10".
            ->sort(fn ($a, $b) => $a->display_order <=> $b->display_order ?: strnatcasecmp($a->name, $b->name));

        $areas = $tables->groupBy('area')
            ->map(fn ($group, $area) => [
                'area' => (string) $area,
                'tables' => $group->values()->map(fn (DiningTable $t) => $this->tableRow($t, $byTable->get($t->id)))->all(),
            ])
            // Area bernama urut abjad (alami); meja tanpa area ('') selalu terakhir.
            ->sort(fn ($a, $b) => ($a['area'] === '') <=> ($b['area'] === '') ?: strnatcasecmp($a['area'], $b['area']))
            ->values()->all();

        $takeaway = $tabs->whereNull('table_id')->sortBy('id')->values()
            ->map(fn (Order $o) => $this->tabRow($o) + ['tab_label' => $o->tab_label])->all();

        $payload = ['areas' => $areas, 'takeaway_tabs' => $takeaway];
        $payload['version'] = md5(json_encode($payload));

        return $payload;
    }

    private function tableRow(DiningTable $table, ?Order $tab): array
    {
        return [
            'id' => $table->id,
            'name' => $table->name,
            'seats' => $table->seats,
            'is_active' => $table->is_active,
            'state' => $tab ? 'occupied' : 'free',
            'tab' => $tab ? $this->tabRow($tab) : null,
        ];
    }

    private function tabRow(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'total_amount' => number_format((float) $order->total_amount, 2, '.', ''),
            'opened_at' => $order->created_at?->toIso8601String(),
            'session_id' => $order->session_id,
            'ready_count' => (int) $order->ready_count,
            'unsent_count' => (int) $order->unsent_count,
        ];
    }
}
