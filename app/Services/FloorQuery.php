<?php

namespace App\Services;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Outlet;
use App\Support\OutletContext;
use App\Support\PaymentSummary;

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
 *
 * 044 — papan Meja di halaman Sales memakai snapshot yang SAMA: tiap order terbuka kini membawa `paid_amount`
 * (total terbayar turunan, rumus tunggal PaymentSummary::orderPaidSql — satu kolom di query yang ada, bukan satu
 * query per order), dan meja NONAKTIF yang masih memegang order terbuka tetap dicantumkan supaya uang yang
 * sedang berjalan tidak hilang dari layar. Meja nonaktif tanpa order tetap disembunyikan.
 */
class FloorQuery
{
    public function build(): array
    {
        $tabs = Order::query()
            ->select(['id', 'outlet_id', 'order_number', 'table_id', 'tab_label', 'service_type', 'total_amount', 'session_id', 'created_at', 'guest_count', 'prebill_at', 'device_id', 'queue_no'])
            ->with('device:id,code')
            ->selectRaw('GREATEST(0, '.PaymentSummary::orderPaidSql('orders').') as paid_total')
            ->where('status', Order::STATUS_OPEN)
            ->withCount([
                'items as unsent_count' => fn ($q) => $q->whereNull('sent_at'),
                // Item yang sudah siap disajikan, belum diantar (subquery — tetap satu query).
                'ticketItems as ready_count' => fn ($q) => $q->where('kitchen_ticket_items.status', 'ready'),
            ])
            ->get();

        $byTable = $tabs->whereNotNull('table_id')->keyBy('table_id');

        $tables = DiningTable::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $byTable->keys()))
            ->get()
            // Urut manual (display_order) dulu, lalu nama secara alami: "2" sebelum "10".
            ->sort(fn ($a, $b) => $a->display_order <=> $b->display_order ?: strnatcasecmp($a->name, $b->name));

        $outletIds = app(OutletContext::class)->effectiveIds();

        // Satu outlet yang berlaku → bentuk lama (areas, takeaway_tabs). Beberapa → dikelompokkan per outlet.
        if (count($outletIds) > 1) {
            $payload = ['outlets' => Outlet::query()->whereIn('id', $outletIds)->orderBy('id')->get(['id', 'code', 'name'])
                ->map(fn (Outlet $o) => ['id' => $o->id, 'code' => $o->code, 'name' => $o->name]
                    + $this->groups($tabs->where('outlet_id', $o->id), $tables->where('outlet_id', $o->id), $byTable))
                ->all()];
        } else {
            $payload = $this->groups($tabs, $tables, $byTable);
        }
        $payload['version'] = md5(json_encode($payload));

        return $payload;
    }

    /** @return array{areas: list<array>, takeaway_tabs: list<array>} */
    private function groups($tabs, $tables, $byTable): array
    {
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

        return ['areas' => $areas, 'takeaway_tabs' => $takeaway];
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
            'paid_amount' => number_format((float) $order->paid_total, 2, '.', ''),
            'opened_at' => $order->created_at?->toIso8601String(),
            'session_id' => $order->session_id,
            'ready_count' => (int) $order->ready_count,
            'unsent_count' => (int) $order->unsent_count,
            // 048 — pax, status "Bill tercetak", dan terminal pemilik (kunci meja lintas perangkat). Nilai TIDAK bergantung jam.
            'guest_count' => $order->guest_count,
            'bill_printed' => $order->prebill_at !== null,
            'device_code' => $order->device?->code,
            'queue_no' => $order->queue_no,
        ];
    }
}
