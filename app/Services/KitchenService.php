<?php

namespace App\Services;

use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Station;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 028 US4 — alur dapur/bar: kirim tab menjadi tiket per stasiun, ubah status item,
 * dan umpan layar stasiun.
 *
 * Aturan inti:
 *  - "kirim" bersifat ATOMIK: seluruh baris belum-terkirim menjadi tiket dalam satu
 *    transaksi yang mengunci baris tab — kegagalan di tengah tidak meninggalkan
 *    tiket setengah-jadi, dan dua pelayan yang menekan kirim bersamaan tidak
 *    membuat tiket ganda (yang kedua mendapati tidak ada yang baru → 409);
 *  - stasiun ditentukan produk → kategori → tidak ada; stasiun nonaktif dilewati;
 *  - status hanya maju: new → preparing → ready → served. Pembatalan BUKAN lewat sini.
 */
class KitchenService
{
    /** Item selesai/batal tetap tampak di layar stasiun selama jendela ini. */
    public const RECENT_MINUTES = 30;

    public function __construct(private TabService $tabs) {}

    /**
     * @return array{tab: Order, tickets: Collection<int, KitchenTicket>}
     */
    public function send(Order $tab, User $user): array
    {
        return DB::transaction(function () use ($tab, $user) {
            $order = $this->tabs->lockOpen($tab);

            $lines = $order->items()->whereNull('sent_at')->orderBy('id')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['tab' => __('restaurant.nothing_to_send')]);
            }

            $activeStations = Station::where('is_active', true)->pluck('id')->flip();
            $variants = ProductVariant::withTrashed()
                ->whereIn('id', $lines->pluck('variant_id'))
                ->with(['product' => fn ($q) => $q->withTrashed()->with(['category' => fn ($c) => $c->withTrashed()])])
                ->get()->keyBy('id');

            $round = (int) KitchenTicket::where('order_id', $order->id)->max('round_no') + 1;
            $now = now();
            $tickets = collect();

            // Pengelompokan per stasiun; kunci 'none' = item yang tidak perlu dimasak.
            $groups = $lines->groupBy(fn (OrderItem $line) => $this->stationFor($variants->get($line->variant_id), $activeStations) ?? 'none');

            foreach ($groups as $stationKey => $groupLines) {
                $ticket = KitchenTicket::create([
                    'order_id' => $order->id,
                    'station_id' => $stationKey === 'none' ? null : (int) $stationKey,
                    'round_no' => $round,
                    'sent_by' => $user->id,
                    'sent_at' => $now,
                ]);

                foreach ($groupLines as $line) {
                    $ticket->items()->create([
                        'order_item_id' => $line->id,
                        'name_snapshot' => $line->name_snapshot,
                        'qty' => $line->qty,
                        // 042 — nilai baris saat dikirim (sudah termasuk modifier dan diskon baris), disalin
                        // karena baris order dihapus bila dibatalkan sesudah dikirim; dipakai Dashboard
                        // "Void setelah fire". Dibagi qty agar `qty × unit_price` = nilai baris.
                        'unit_price' => $line->qty > 0 ? round((float) $line->line_total / (int) $line->qty, 2) : null,
                        'modifiers' => $line->modifiers,
                        'note' => $line->note,
                        // Tanpa stasiun → tidak ada yang memasak: langsung siap saji.
                        'status' => $stationKey === 'none' ? KitchenTicketItem::READY : KitchenTicketItem::NEW,
                        'ready_at' => $stationKey === 'none' ? $now : null,
                        'ready_by' => $stationKey === 'none' ? $user->id : null,
                    ]);
                }

                $tickets->push($ticket->load(['station', 'items', 'order.table']));
            }

            OrderItem::whereIn('id', $lines->pluck('id'))->update(['sent_at' => $now]);

            return ['tab' => $this->tabs->loaded($order), 'tickets' => $tickets];
        });
    }

    /**
     * @throws AuthorizationException peran tidak boleh melakukan transisi ini
     * @throws ValidationException    transisi tidak sah (loncat/mundur/sudah final) → 409
     */
    public function transition(KitchenTicketItem $item, string $to, User $user): KitchenTicketItem
    {
        return DB::transaction(function () use ($item, $to, $user) {
            $locked = KitchenTicketItem::lockForUpdate()->findOrFail($item->id);

            // new→preparing→ready = pekerjaan stasiun (`kitchen`); ready→served boleh pelayan (`floor`) ATAU stasiun.
            $allowed = $to === KitchenTicketItem::SERVED
                ? $user->canAccessMenu('floor') || $user->canAccessMenu('kitchen')
                : $user->canAccessMenu('kitchen');
            if (! $allowed) {
                throw new AuthorizationException(__('restaurant.forbidden'));
            }

            $next = [KitchenTicketItem::NEW => KitchenTicketItem::PREPARING, KitchenTicketItem::PREPARING => KitchenTicketItem::READY, KitchenTicketItem::READY => KitchenTicketItem::SERVED];
            if (($next[$locked->status] ?? null) !== $to) {
                throw ValidationException::withMessages(['status' => __('restaurant.ticket_transition_invalid', ['from' => $locked->status, 'to' => $to])]);
            }

            $locked->update(["{$to}_at" => now(), "{$to}_by" => $user->id, 'status' => $to]);

            return $locked->fresh();
        });
    }

    /**
     * Umpan layar sebuah stasiun: tiketnya yang masih punya item belum-selesai, ditambah item
     * selesai/batal dalam RECENT_MINUTES terakhir. Tertua dulu. Tiap tiket hanya membawa
     * item yang relevan.
     *
     * @return Collection<int, KitchenTicket>
     */
    public function feed(Station $station): Collection
    {
        $cutoff = now()->subMinutes(self::RECENT_MINUTES);
        $relevant = fn ($q) => $q->where(fn ($w) => $w
            ->whereIn('status', KitchenTicketItem::UNFINISHED)
            ->orWhere(fn ($s) => $s->where('status', KitchenTicketItem::SERVED)->where('served_at', '>=', $cutoff))
            ->orWhere(fn ($c) => $c->where('status', KitchenTicketItem::CANCELLED)->where('cancelled_at', '>=', $cutoff)));

        return KitchenTicket::query()
            ->where('station_id', $station->id)
            ->whereHas('items', $relevant)
            ->with(['station', 'order.table', 'items' => fn ($q) => $relevant($q)->orderBy('id')])
            ->orderBy('sent_at')->orderBy('id')
            ->get();
    }

    /** Satu tiket UTUH (semua item) untuk tampilan cetak. */
    public function ticketForPrint(KitchenTicket $ticket): KitchenTicket
    {
        return $ticket->load(['station', 'order.table', 'items' => fn ($q) => $q->orderBy('id')]);
    }

    private function stationFor(?ProductVariant $variant, Collection $activeStations): ?int
    {
        $product = $variant?->product;

        foreach ([$product?->station_id, $product?->category?->station_id] as $candidate) {
            if ($candidate !== null && $activeStations->has($candidate)) {
                return (int) $candidate;
            }
        }

        return null;
    }
}
