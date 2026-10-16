<?php

namespace App\Services;

use App\Exceptions\InputRejectedException;
use App\Exceptions\TabConflictException;
use App\Models\CashierSession;
use App\Models\Customer;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\DiningTable;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 028 US2 — daur hidup tab: buka, tambah/ubah/hapus baris, tutup-kosong,
 * serah-terima. Pembayaran ada di TabSettlementService.
 *
 * Aturan yang berlaku di SEMUA method yang mengubah tab:
 *  - berjalan dalam satu transaksi yang pertama-tama mengunci baris order
 *    (`lockForUpdate`), sehingga dua pelayan yang menambah ke tab yang sama
 *    diserialkan dan tidak ada yang kehilangan item;
 *  - harga/total selalu dihitung di sini dari master data, tidak pernah dari klien;
 *  - stok hanya berubah lewat StockService (applyMovementIfTracked), dengan
 *    reference_id = id baris order_items (konvensi yang sama dengan movement
 *    'return' pada void ritel).
 */
class TabService
{
    public function __construct(
        private \App\Services\ReceiptNumbering $numbering,
        private StockService $stockService,
        private OrderService $orderService,
        private ActivityLogger $activityLogger,
        private ModifierResolver $modifiers,
    ) {}

    // --- buka ---------------------------------------------------------------

    /**
     * @param array{session_id:int, table_id?:?int, tab_label?:?string, customer_id?:?int, local_ref?:?string} $data
     */
    public function open(array $data, User $user): Order
    {
        // Idempotensi: uuid klien yang sama mengembalikan tab pertama. Uuid
        // milik PENJUALAN ritel bukan retry pembukaan tab.
        if (! empty($data['local_ref'])) {
            $existing = Order::where('local_ref', $data['local_ref'])->first();
            if ($existing) {
                if ($existing->service_type === Order::SERVICE_RETAIL) {
                    throw ValidationException::withMessages(['local_ref' => __('restaurant.local_ref_not_a_tab')]);
                }

                return $this->loaded($existing);
            }
        }

        $session = CashierSession::findOrFail($data['session_id']);
        $this->assertSessionOpen($session);

        // 048 — perangkat dari header X-Device-Id: harus terminal AKTIF milik outlet yang terlihat pengguna
        // (Device ber-OutletScope → perangkat outlet lain tidak ditemukan). Tanpa header (SPA web) tetap berjalan.
        $device = null;
        if (! empty($data['device_header'])) {
            $device = Device::query()->whereNull('revoked_at')->find((int) $data['device_header']);
            if ($device === null) {
                throw InputRejectedException::withMessages(['device_id' => __('devices.invalid_device')]);
            }
        }

        $table = null;
        if (! empty($data['table_id'])) {
            $table = DiningTable::findOrFail($data['table_id']);
            if (! $table->is_active) {
                throw ValidationException::withMessages(['table_id' => __('restaurant.table_inactive')]);
            }
            $this->assertTableFree($table);
        }

        if (! empty($data['customer_id'])) {
            try {
                Customer::findOrFail($data['customer_id']);
            } catch (ModelNotFoundException) {
                throw InputRejectedException::withMessages(['customer_id' => __('orders_payments.customer_not_found')]);
            }
        }

        try {
            $order = DB::transaction(function () use ($data, $session, $user, $table, $device) {
                $order = new Order([
                    'order_number' => $this->orderService->generateOrderNumber(),
                    'session_id' => $session->id,
                    'customer_id' => $data['customer_id'] ?? null,
                    'user_id' => $user->id,
                    'channel' => 'offline',
                    'service_type' => $table ? Order::SERVICE_DINE_IN : Order::SERVICE_TAKEAWAY,
                    'table_id' => $table?->id,
                    'tab_label' => isset($data['tab_label']) && trim($data['tab_label']) !== '' ? trim($data['tab_label']) : null,
                    'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0, 'total_cost' => 0,
                    'paid_amount' => 0, 'change_amount' => 0,
                    'status' => Order::STATUS_OPEN,
                    'local_ref' => $data['local_ref'] ?? null,
                ]);
                // 048 — kolom yang TIDAK fillable (diturunkan server): tamu, perangkat, nomor antrean (counter saja).
                $order->forceFill([
                    'guest_count' => $data['guest_count'] ?? null,
                    'device_id' => $device?->id,
                    'queue_no' => $table === null && $device !== null ? $this->numbering->queueFor((int) $session->outlet_id) : null,
                ])->save();

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Dua pelayan membuka meja yang sama di detik yang sama: pemeriksaan
            // di atas sudah lolos untuk keduanya, indeks UNIQUE database yang
            // menahan yang kedua (research.md Keputusan 10).
            if ($table && str_contains($e->getMessage(), 'uniq_orders_open_table_key')) {
                // Pemenangnya biasanya sudah terlihat; kalau belum (belum commit
                // saat kita membaca), tetap konflik 409 — BUKAN galat 500.
                throw new TabConflictException(__('restaurant.table_occupied'), [
                    'existing_tab_id' => $table->openTab()->value('id'),
                ]);
            }
            if (! empty($data['local_ref']) && str_contains($e->getMessage(), 'local_ref')) {
                return $this->loaded(Order::where('local_ref', $data['local_ref'])->firstOrFail());
            }
            throw $e;
        }

        return $this->loaded($order);
    }

    // --- baris --------------------------------------------------------------

    /**
     * @param array{variant_id:int, qty:int, note?:?string, modifier_option_ids?:array<int,int>} $data
     */
    public function addLine(Order $tab, array $data, User $user): Order
    {
        // 043 — rilis/harga berjadwal yang jatuh tempo diterapkan di titik masuk ini (tanpa penjadwal).
        app(\App\Services\ReleaseService::class)->publishDueCached();
        app(\App\Services\RecipeService::class)->applyDueCached();

        return DB::transaction(function () use ($tab, $data, $user) {
            $order = $this->lockOpen($tab);

            $variant = ProductVariant::with('product')->lockForUpdate()->findOrFail($data['variant_id']);
            // 043 — satu aturan "bisa dipesan" (varian, produk/diskontinu, tanda 86): App\Support\MenuAvailability.
            \App\Support\MenuAvailability::assertOrderable($variant, 'variant_id', $order->outlet_id);
            // 045 Stage 3 — harga yang berlaku di outlet tab (lihat PriceService::applyOutletPrice).
            app(PriceService::class)->applyOutletPrice($variant, $order->outlet_id);

            $qty = (int) $data['qty'];
            $note = $this->cleanNote($data['note'] ?? null);

            // Pilihan modifier divalidasi & di-snapshot SERVER-side (ModifierResolver); harga
            // efektif per unit = harga menu + selisih pilihan. Validasi terjadi SEBELUM stok
            // disentuh, dan karena semuanya satu transaksi, penolakan tidak meninggalkan jejak.
            $resolved = $this->modifiers->resolve($variant, $data['modifier_option_ids'] ?? []);
            $snapshot = $resolved['snapshot'] ?: null;
            $unitPrice = $resolved['unit_price'];

            // Baris belum-terkirim yang IDENTIK (varian, catatan, pilihan modifier, harga efektif
            // saat ditambahkan) digabung. Harga ikut dicocokkan: item yang sudah di tab
            // mempertahankan harga saat ia ditambahkan, hanya item baru yang memakai harga terbaru
            // (spec, edge case "harga berubah di tengah makan").
            $line = $order->items()
                ->whereNull('sent_at')
                ->where('variant_id', $variant->id)
                ->where('sell_price', $unitPrice)
                ->when($note === null, fn ($q) => $q->whereNull('note'), fn ($q) => $q->where('note', $note))
                ->get()
                ->first(fn (OrderItem $l) => $this->sameOptions($l->modifiers, $snapshot));

            if ($line) {
                $line->qty += $qty;
                $line->line_total = round((float) $line->sell_price * $line->qty - (float) $line->discount_amount, 2);
                $line->save();
            } else {
                $line = $order->items()->create([
                    'variant_id' => $variant->id,
                    'home_outlet_id' => $variant->product->home_outlet_id,
                    'sku_snapshot' => $variant->sku,
                    'name_snapshot' => $variant->product->name.' — '.$variant->variant_name,
                    'qty' => $qty,
                    'cost_price' => $variant->cost_price,
                    'sell_price' => $unitPrice,
                    'discount_amount' => 0,
                    'line_total' => round($unitPrice * $qty, 2),
                    'note' => $note,
                    'modifiers' => $snapshot,
                ]);
            }

            $this->stockService->applyMovementIfTracked(
                variant: $variant, outletId: (int) $order->outlet_id, type: 'sale', qtyChange: -$qty,
                referenceType: 'order_item', referenceId: $line->id, userId: $user->id,
            );

            $this->recalculate($order);

            return $this->loaded($order);
        });
    }

    /**
     * @param array{qty?:int, note?:?string, modifier_option_ids?:array<int,int>} $data
     */
    public function updateLine(Order $tab, int $itemId, array $data, User $user): Order
    {
        return DB::transaction(function () use ($tab, $itemId, $data, $user) {
            $order = $this->lockOpen($tab);
            $line = $order->items()->findOrFail($itemId);
            $this->assertUnsent($line);

            $reprice = false;

            if (array_key_exists('note', $data)) {
                $line->note = $this->cleanNote($data['note']);
            }

            // Mengganti pilihan modifier: harga dasar = harga SAAT BARIS DITAMBAHKAN
            // (harga efektif lama dikurangi selisih lama), bukan harga menu hari ini.
            if (array_key_exists('modifier_option_ids', $data)) {
                $variant = ProductVariant::with('product')->findOrFail($line->variant_id);
                $oldDelta = collect($line->modifiers ?? [])->sum(fn ($m) => (float) $m['price_delta']);
                $resolved = $this->modifiers->resolve($variant, $data['modifier_option_ids'], (float) $line->sell_price - $oldDelta);

                $line->modifiers = $resolved['snapshot'] ?: null;
                $line->sell_price = $resolved['unit_price'];
                $reprice = true;
            }

            if (isset($data['qty']) && (int) $data['qty'] !== $line->qty) {
                $delta = (int) $data['qty'] - $line->qty;
                $variant = ProductVariant::lockForUpdate()->findOrFail($line->variant_id);

                $this->stockService->applyMovementIfTracked(
                    variant: $variant,
                    outletId: $this->outletOfLine($line),
                    type: $delta > 0 ? 'sale' : 'return',
                    qtyChange: -$delta,
                    referenceType: 'order_item', referenceId: $line->id, userId: $user->id,
                );

                $line->qty = (int) $data['qty'];
                $reprice = true;
            }

            if ($reprice) {
                $line->line_total = round((float) $line->sell_price * $line->qty - (float) $line->discount_amount, 2);
            }

            $line->save();
            $this->recalculate($order);
            $this->assertNotBelowPaid($order);

            return $this->loaded($order);
        });
    }

    /**
     * Menghapus/membatalkan satu baris tab.
     *
     * - Baris BELUM dikirim: dihapus bebas, stok kembali (tanpa persetujuan).
     * - Baris SUDAH dikirim (048 / S3): wajib `reason_code`; `kitchen_status` harus sama dengan status dapur
     *   sebenarnya (dihitung server, 422 bila berbeda); `stock_decision` wajib (`return` hanya bila belum mulai
     *   dimasak). Persetujuan `void_line` diperiksa di titik aksi; pemegang izin order.void lewat tanpa token.
     *
     * Perubahan, konsumsi token, dan baris log berada dalam SATU transaksi.
     *
     * @param  array<string, mixed>  $input  reason_code, reason, kitchen_status, stock_decision (bidang body)
     */
    public function removeLine(Order $tab, int $itemId, User $user, array $input = [], ?string $overrideToken = null): Order
    {
        return DB::transaction(function () use ($tab, $itemId, $user, $input, $overrideToken) {
            $order = $this->lockOpen($tab);
            $line = $order->items()->with('ticketItem')->findOrFail($itemId);

            if ($line->sent_at === null) {
                $this->returnStock($line, $line->qty, $user);
                $line->delete();
                $this->recalculate($order);
                $this->assertNotBelowPaid($order);

                return $this->loaded($order);
            }

            $kitchenStatus = self::kitchenStatusFor($line->ticketItem);
            $reasonCode = $input['reason_code'] ?? null;
            $stockDecision = $input['stock_decision'] ?? null;

            if ($reasonCode === null) {
                throw InputRejectedException::withMessages(['reason_code' => [__('restaurant.cancel_reason_required')]]);
            }
            if (($input['kitchen_status'] ?? null) !== $kitchenStatus) {
                throw ValidationException::withMessages(['kitchen_status' => [__('overrides.kitchen_status_changed')]]);
            }
            if ($stockDecision === null) {
                throw ValidationException::withMessages(['stock_decision' => [__('overrides.stock_decision_required')]]);
            }
            if ($stockDecision === 'return' && $kitchenStatus !== 'not_started') {
                throw ValidationException::withMessages(['stock_decision' => [__('overrides.stock_return_after_prep')]]);
            }

            // Subjek = id objek (tab, baris) + bidang body mentah, sama dengan saat persetujuan diterbitkan.
            app(OverrideGate::class)->requireFor(
                'void_line',
                ['tab_id' => $order->id, 'line_id' => $line->id, 'reason_code' => $reasonCode, 'kitchen_status' => $kitchenStatus, 'stock_decision' => $stockDecision, 'reason' => $input['reason'] ?? null],
                $overrideToken,
                $user,
                (int) $order->outlet_id,
                "order:{$order->id}:line:{$line->id}",
            );

            $ticketItem = $line->ticketItem;
            if ($stockDecision === 'return') {
                $this->returnStock($line, $line->qty, $user);
            }
            $ticketItem?->update([
                'status' => KitchenTicketItem::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $reasonCode,
            ]);

            $snapshot = ['line_id' => $line->id, 'name' => $line->name_snapshot, 'qty' => $line->qty, 'kitchen_status' => $kitchenStatus, 'stock_decision' => $stockDecision];
            $line->delete();
            $this->recalculate($order);
            $this->assertNotBelowPaid($order);

            // Terakhir: baris log hanya ada bila seluruh pembatalan berhasil.
            $this->activityLogger->log(
                userId: $user->id,
                action: 'item_cancelled',
                entityType: 'Order',
                entityId: $order->id,
                description: "Membatalkan {$snapshot['qty']}× {$snapshot['name']} dari tab {$order->order_number} (status dapur: {$kitchenStatus}). Stok: {$stockDecision}. Alasan: {$reasonCode}.",
                oldValues: $snapshot,
                newValues: ['reason_code' => $reasonCode, 'reason' => $this->cleanNote($input['reason'] ?? null)],
            );

            return $this->loaded($order);
        });
    }

    /** Status dapur item dalam kosa kata kontrak: not_started | started | made. */
    public static function kitchenStatusFor(?KitchenTicketItem $item): string
    {
        return match ($item?->status) {
            null, KitchenTicketItem::NEW => 'not_started',
            KitchenTicketItem::PREPARING => 'started',
            default => 'made',
        };
    }

    /**
     * 048 / S3 — membatalkan SELURUH tab terbuka (`POST /tabs/{id}/cancel`). 409 `has_payments` bila sudah
     * dibayar (tanpa alur refund). Selain itu persetujuan `cancel_order` bila peminta tanpa order.cancel.
     * Konsumsi token dan pembatalan dalam satu transaksi.
     */
    public function cancelTab(Order $tab, User $user, string $reasonCode, ?string $note, ?string $overrideToken): Order
    {
        return DB::transaction(function () use ($tab, $user, $reasonCode, $note, $overrideToken) {
            $order = $this->lockOpen($tab);
            if ($order->payments()->exists()) {
                throw new \App\Exceptions\ShiftRuleException(__('overrides.has_payments'), 'has_payments', [], 409);
            }

            app(OverrideGate::class)->requireFor(
                'cancel_order',
                ['tab_id' => $order->id, 'reason_code' => $reasonCode, 'reason' => $note],
                $overrideToken,
                $user,
                (int) $order->outlet_id,
                "order:{$order->id}",
            );

            return $this->voidTab($order, $note !== null && $note !== '' ? $reasonCode.' — '.$note : $reasonCode, $user);
        });
    }

    /**
     * Membatalkan SELURUH tab terbuka (owner/admin, alasan wajib — izin diperiksa di request).
     * Ditolak (409) bila sudah ada pembayaran: tidak ada alur refund. Stok hanya kembali untuk baris yang
     * belum dikirim atau item dapurnya masih `new`; item tiket yang belum selesai menjadi `cancelled`;
     * yang sudah `served` tetap final. Baris tetap ada pada order `voided` (seperti void penjualan).
     */
    public function voidTab(Order $tab, string $reason, User $user): Order
    {
        return DB::transaction(function () use ($tab, $reason, $user) {
            $order = $this->lockOpen($tab);

            if ($order->payments()->exists()) {
                throw ValidationException::withMessages(['tab' => __('restaurant.void_has_payments')]);
            }

            $returned = 0;
            foreach ($order->items()->with('ticketItem')->get() as $line) {
                $ticketItem = $line->ticketItem;

                if ($line->sent_at === null || $ticketItem === null || $ticketItem->status === KitchenTicketItem::NEW) {
                    $this->returnStock($line, $line->qty, $user);
                    $returned++;
                }

                if ($ticketItem && in_array($ticketItem->status, KitchenTicketItem::UNFINISHED, true)) {
                    $ticketItem->update([
                        'status' => KitchenTicketItem::CANCELLED,
                        'cancelled_at' => now(),
                        'cancelled_by' => $user->id,
                        'cancel_reason' => $reason,
                    ]);
                }
            }

            $order->update(['status' => Order::STATUS_VOIDED, 'void_reason' => $reason]);

            $this->activityLogger->log(
                userId: $user->id,
                action: 'tab_voided',
                entityType: 'Order',
                entityId: $order->id,
                description: "Membatalkan tab {$order->order_number} (total {$order->total_amount}). Alasan: {$reason}.",
                oldValues: ['status' => Order::STATUS_OPEN, 'total_amount' => $order->total_amount],
                newValues: ['reason' => $reason, 'lines_with_stock_returned' => $returned],
            );

            return $this->loaded($order);
        });
    }

    /** Mengembalikan stok sebuah baris, kecuali varian tidak dilacak atau sudah dihapus dari menu. */
    private function returnStock(OrderItem $line, int $qty, User $user): void
    {
        // Varian yang dihapus di tengah makan tidak lagi punya stok yang bisa dihitung.
        $variant = ProductVariant::withTrashed()->find($line->variant_id);
        if (! $variant || $variant->trashed()) {
            return;
        }

        $this->stockService->applyMovementIfTracked(
            variant: $variant, outletId: $this->outletOfLine($line), type: 'return', qtyChange: $qty,
            referenceType: 'order_item', referenceId: $line->id, userId: $user->id,
        );
    }

    /** 045 Stage 5 — stok bergerak di outlet ORDER baris itu (outlet shift saat tab dibuka). */
    private function outletOfLine(\App\Models\OrderItem $line): int
    {
        return (int) Order::withoutGlobalScopes()->whereKey($line->order_id)->value('outlet_id');
    }

    // --- tutup kosong / serah-terima ---------------------------------------

    /** Tab tanpa baris ditutup tanpa efek stok/penjualan; mejanya kosong lagi. */
    /**
     * 048 / B13 — tandai pra-bill tercetak. Tidak mengubah status, stok, atau total; hanya penanda untuk denah ("Bill
     * tercetak") dan hitungan cetak. Boleh diulang (hitungan naik). Tab harus terbuka.
     */
    public function prebill(Order $tab, User $user): Order
    {
        return DB::transaction(function () use ($tab, $user) {
            $tab = $this->lockOpen($tab);
            $tab->forceFill(['prebill_at' => now(), 'prebill_count' => min(255, (int) $tab->prebill_count + 1)])->save();
            $this->activityLogger->log($user->id, 'tab_prebill', 'Order', $tab->id, "Pra-bill {$tab->order_number} dicetak");

            return $this->loaded($tab);
        });
    }

    public function closeEmpty(Order $tab, User $user): void
    {
        DB::transaction(function () use ($tab) {
            $order = $this->lockOpen($tab);

            if ($order->items()->exists()) {
                throw ValidationException::withMessages(['tab' => __('restaurant.tab_not_empty')]);
            }

            $order->update(['status' => Order::STATUS_VOIDED, 'void_reason' => __('restaurant.empty_tab_reason')]);
        });
    }

    /** Memindahkan tab terbuka ke shift lain yang masih terbuka (owner/admin — dicek di controller). */
    public function handover(Order $tab, int $sessionId, User $user): Order
    {
        return DB::transaction(function () use ($tab, $sessionId, $user) {
            $order = $this->lockOpen($tab);
            $target = CashierSession::findOrFail($sessionId);
            $this->assertSessionOpen($target);

            if ($target->id !== $order->session_id) {
                $from = $order->session_id;
                $order->update(['session_id' => $target->id]);

                $this->activityLogger->log(
                    userId: $user->id,
                    action: 'handed_over',
                    entityType: 'Order',
                    entityId: $order->id,
                    description: "Tab {$order->order_number} diserahkan dari shift #{$from} ke shift #{$target->id}.",
                    oldValues: ['session_id' => $from],
                    newValues: ['session_id' => $target->id],
                );
            }

            return $this->loaded($order);
        });
    }

    // --- pindah / gabung ------------------------------------------------------

    /** Memindahkan tab terbuka ke meja KOSONG lain (juga mendudukkan tab takeaway di sebuah meja). */
    public function move(Order $tab, int $tableId, User $user): Order
    {
        try {
            return DB::transaction(function () use ($tab, $tableId) {
                $order = $this->lockOpen($tab);
                $table = DiningTable::findOrFail($tableId);

                if (! $table->is_active) {
                    throw ValidationException::withMessages(['table_id' => __('restaurant.table_inactive')]);
                }

                // 045 Stage 2 (D17) — tab hanya boleh pindah ke meja di outlet yang sama.
                if ($table->outlet_id !== $order->outlet_id) {
                    throw new TabConflictException(__('restaurant.outlet_mismatch'), ['code' => 'outlet_mismatch']);
                }

                if ($order->table_id !== $table->id) {
                    $this->assertTableFree($table);
                    $order->update(['table_id' => $table->id, 'service_type' => Order::SERVICE_DINE_IN]);
                }

                return $this->loaded($order);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Balapan dengan tab lain yang baru duduk di meja yang sama: indeks UNIQUE database yang menahan.
            if (str_contains($e->getMessage(), 'uniq_orders_open_table_key')) {
                throw new TabConflictException(__('restaurant.table_occupied'), [
                    'existing_tab_id' => DiningTable::whereKey($tableId)->first()?->openTab()->value('id'),
                ]);
            }
            throw $e;
        }
    }

    /**
     * Menggabungkan tab SUMBER ke tab TARGET (shift yang sama). Baris, tiket dapur dan item tiketnya
     * pindah apa adanya: status persiapan ikut, stok TIDAK disentuh. Sumber ditutup (`voided`,
     * alasan "digabung") dan mejanya kosong. Sumber yang sudah punya pembayaran ditolak: tidak ada
     * logika alokasi pembayaran (sama seperti pisah tagihan).
     */
    public function merge(Order $target, int $sourceId, User $user): Order
    {
        return DB::transaction(function () use ($target, $sourceId, $user) {
            // Kunci kedua baris dengan urutan id tetap supaya dua penggabungan bersilang tidak saling-tunggu (deadlock).
            $ids = [$target->id, $sourceId];
            sort($ids);
            $locked = [];
            foreach ($ids as $id) {
                $locked[$id] = $this->lock(Order::findOrFail($id));
            }
            $targetRow = $locked[$target->id];
            $source = $locked[$sourceId];

            if ($source->id === $targetRow->id) {
                throw ValidationException::withMessages(['source_tab_id' => __('restaurant.merge_same_tab')]);
            }
            foreach ([$targetRow, $source] as $row) {
                if ($row->status !== Order::STATUS_OPEN) {
                    throw ValidationException::withMessages(['status' => __('restaurant.tab_not_open')]);
                }
            }
            if ($source->session_id !== $targetRow->session_id) {
                throw ValidationException::withMessages(['source_tab_id' => __('restaurant.merge_different_shift')]);
            }
            if ($source->payments()->exists()) {
                throw ValidationException::withMessages(['source_tab_id' => __('restaurant.merge_source_has_payments')]);
            }

            OrderItem::where('order_id', $source->id)->update(['order_id' => $targetRow->id]);
            // Tiket (dan item tiketnya) mengikuti: layar stasiun harus menampilkan tab yang MASIH ADA.
            KitchenTicket::where('order_id', $source->id)->update(['order_id' => $targetRow->id]);

            $this->recalculate($source);
            $source->update(['status' => Order::STATUS_VOIDED, 'void_reason' => __('restaurant.merged_reason', ['number' => $targetRow->order_number])]);
            $this->recalculate($targetRow);

            $this->activityLogger->log(
                userId: $user->id,
                action: 'merged',
                entityType: 'Order',
                entityId: $targetRow->id,
                description: "Tab {$source->order_number} digabung ke {$targetRow->order_number}.",
                oldValues: ['source_order_id' => $source->id, 'source_order_number' => $source->order_number],
                newValues: ['target_order_id' => $targetRow->id],
            );

            return $this->loaded($targetRow);
        });
    }

    // --- bantu (dipakai juga oleh TabSettlementService) ---------------------

    /** Mengunci baris order dan memastikan ia adalah tab yang masih terbuka. */
    public function lockOpen(Order $tab): Order
    {
        $order = $this->lock($tab);

        if ($order->status !== Order::STATUS_OPEN) {
            throw ValidationException::withMessages(['status' => __('restaurant.tab_not_open')]);
        }

        return $order;
    }

    /** Mengunci baris order; penjualan ritel BUKAN tab → 404. */
    public function lock(Order $tab): Order
    {
        $order = Order::lockForUpdate()->findOrFail($tab->id);

        if ($order->service_type === Order::SERVICE_RETAIL) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$tab->id]);
        }

        return $order;
    }

    public function assertSessionOpen(CashierSession $session): void
    {
        // 048 — kode mesin `shift_required` (409) agar terminal menampilkan tindakan yang tepat; respons
        // dirender oleh bootstrap/app.php untuk ShiftRuleException di rute API.
        if ($session->status !== 'open') {
            throw new \App\Exceptions\ShiftRuleException(__('orders_payments.session_not_open'), 'shift_required');
        }
    }

    /**
     * Menghitung ulang subtotal/diskon/pajak/total dari baris-barisnya (satu-satunya tempat — 048: termasuk pajak).
     * `$discount` (opsional) menggantikan diskon tingkat-order yang tersimpan; selalu dibatasi subtotal.
     * Total = subtotal − diskon + pajak eksklusif (TaxCalculator); pajak dan tarifnya disimpan sebagai snapshot.
     */
    public function recalculate(Order $order, ?float $discount = null): void
    {
        $items = $order->items()->get(['qty', 'cost_price', 'line_total']);

        $subtotal = round((float) $items->sum('line_total'), 2);
        $cost = round((float) $items->sum(fn ($i) => (float) $i->cost_price * $i->qty), 2);
        // Diskon tingkat-order tidak boleh melebihi subtotal baru.
        $discount = min($discount ?? (float) $order->discount_amount, $subtotal);
        $tax = \App\Support\TaxCalculator::compute($subtotal - $discount);

        $order->forceFill([
            'subtotal' => $subtotal,
            'total_cost' => $cost,
            'discount_amount' => $discount,
            'tax_amount' => $tax['tax'],
            'tax_rate_snapshot' => $tax['rate'],
            'total_amount' => round($subtotal - $discount + $tax['tax'], 2),
        ])->save();
    }

    public function loaded(Order $order): Order
    {
        return $order->fresh(['items.ticketItem.ticket.station', 'payments', 'table', 'cashier:id,name', 'device:id,code']);
    }

    private function assertTableFree(DiningTable $table): void
    {
        $existing = $table->openTab()->first();

        if ($existing) {
            throw new TabConflictException(__('restaurant.table_occupied'), ['existing_tab_id' => $existing->id]);
        }
    }

    private function assertUnsent(OrderItem $line): void
    {
        if ($line->sent_at !== null) {
            throw ValidationException::withMessages(['item' => __('restaurant.line_already_sent')]);
        }
    }

    /** Tidak ada alur refund: total tidak boleh turun di bawah yang sudah dibayar. */
    private function assertNotBelowPaid(Order $order): void
    {
        if (round((float) $order->fresh()->total_amount, 2) < round((float) $order->paid_amount, 2)) {
            throw ValidationException::withMessages(['total_amount' => __('restaurant.total_below_paid')]);
        }
    }

    /** Dua daftar pilihan dianggap sama bila himpunan id opsinya sama, apa pun urutan kirimnya. */
    private function sameOptions(?array $a, ?array $b): bool
    {
        $ids = fn (?array $snap) => collect($snap ?? [])->pluck('option_id')->map(fn ($i) => (int) $i)->sort()->values()->all();

        return $ids($a) === $ids($b);
    }

    private function cleanNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        return $note === '' ? null : $note;
    }
}
