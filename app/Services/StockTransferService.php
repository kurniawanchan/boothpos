<?php

namespace App\Services;

use App\Models\Material;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Support\OutletContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 045 Stage 6 — SATU-SATUNYA penulis transfer stok antar outlet (kirim → terima, atau batal).
 *
 *  - Kirim: saldo outlet asal turun SEKETIKA (`transfer_out`); barang tampil "dalam perjalanan" dan tidak bisa dijual di
 *    mana pun. Terima: saldo outlet tujuan naik sebesar yang diterima (`transfer_in`); kurang dari yang dikirim wajib
 *    beralasan dan tercatat sebagai selisih; lebih dari yang dikirim ditolak. Batal (hanya in_transit): barang kembali ke
 *    outlet asal (`transfer_cancel`). Tidak ada penyuntingan sesudah kirim.
 *  - Semua pergerakan lewat StockService/MaterialStockService (jalur tunggal, saldo tak pernah negatif) di transaksi yang
 *    sama dengan baris transfer dan jejak audit.
 *  - Urutan kunci DETERMINISTIK: baris diproses terurut (jenis, id item), dan tiap pergerakan mengunci baris item lalu baris
 *    saldo — dua transfer yang saling silang (A→B dan B→A) mengunci dengan urutan yang sama, sehingga tidak ada deadlock.
 *  - Invarian I3: Σ saldo semua outlet + barang dalam perjalanan + selisih tercatat tetap sepanjang kirim/terima/batal.
 */
class StockTransferService
{
    public function __construct(
        private StockService $stock,
        private MaterialStockService $materials,
        private ActivityLogger $log,
    ) {}

    /**
     * @param  list<array{kind: string, item_id: int, qty: int|float|string}>  $lines
     * @return array{transfer: StockTransfer, replayed: bool}
     *
     * @throws ValidationException 422 aturan input; 409 saldo tidak cukup (dari service stok)
     */
    public function dispatch(User $by, int $fromId, int $toId, array $lines, ?string $note = null, ?string $clientRef = null): array
    {
        // Idempoten HANYA untuk pengirim yang sama dengan rute yang sama: `client_ref` milik orang lain (atau rute lain)
        // tidak boleh membocorkan transfer itu lewat pengulangan — ditolak 409, bukan dikembalikan.
        if ($clientRef !== null && ($existing = StockTransfer::withoutGlobalScopes()->where('client_ref', $clientRef)->first()) !== null) {
            if ((int) $existing->dispatched_by !== (int) $by->id || (int) $existing->from_outlet_id !== $fromId || (int) $existing->to_outlet_id !== $toId) {
                throw ValidationException::withMessages(['client_ref' => __('stock.transfer_client_ref_conflict')])->status(409);
            }

            return ['transfer' => $existing->load('lines'), 'replayed' => true];
        }

        if ($fromId === $toId) {
            throw ValidationException::withMessages(['to_outlet_id' => __('stock.transfer_same_outlet')]);
        }
        $outlets = Outlet::query()->whereIn('id', [$fromId, $toId])->get()->keyBy('id');
        foreach ([$fromId => 'from_outlet_id', $toId => 'to_outlet_id'] as $id => $field) {
            if (! isset($outlets[$id]) || ! $outlets[$id]->is_active) {
                throw ValidationException::withMessages([$field => __('stock.transfer_outlet_inactive')]);
            }
        }

        $normalised = $this->normalise($lines);

        return DB::transaction(function () use ($by, $fromId, $toId, $normalised, $note, $clientRef) {
            $transfer = new StockTransfer(['number' => $this->generateNumber(), 'status' => StockTransfer::IN_TRANSIT, 'note' => $note, 'client_ref' => $clientRef]);
            $transfer->from_outlet_id = $fromId;
            $transfer->to_outlet_id = $toId;
            $transfer->dispatched_by = $by->id;
            $transfer->dispatched_at = now();
            $transfer->save();

            foreach ($normalised as $line) {
                $transfer->lines()->create(['kind' => $line['kind'], 'item_id' => $line['item_id'], 'qty_sent' => $line['qty']]);
                $this->move($line['kind'], $line['item_id'], $fromId, 'transfer_out', -$line['qty'], $transfer, $by);
            }

            $this->log->log(
                userId: $by->id, action: 'stock_transfer_dispatched', entityType: 'StockTransfer', entityId: $transfer->id,
                description: "Transfer {$transfer->number} dikirim dari outlet #{$fromId} ke #{$toId} (".count($normalised).' baris).',
                newValues: ['from_outlet_id' => $fromId, 'to_outlet_id' => $toId, 'lines' => $normalised],
            );

            return ['transfer' => $transfer->load('lines'), 'replayed' => false];
        });
    }

    /**
     * @param  array<int, array{qty_received?: int|float|string, reason?: string|null}>  $received  dikunci id baris; kosong = terima semua seperti dikirim
     *
     * @throws ValidationException 422 selisih tanpa alasan / lebih dari dikirim; 409 bukan in_transit
     */
    public function receive(StockTransfer $transfer, User $by, array $received = []): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $by, $received) {
            $transfer = StockTransfer::withoutGlobalScopes()->lockForUpdate()->findOrFail($transfer->id);
            $this->assertInTransit($transfer);

            $lines = $transfer->lines()->orderBy('kind')->orderBy('item_id')->get();
            $errors = [];
            $plan = [];
            foreach ($lines as $line) {
                $input = $received[$line->id] ?? [];
                $qty = isset($input['qty_received']) ? round((float) $input['qty_received'], 3) : (float) $line->qty_sent;
                $reason = isset($input['reason']) ? trim((string) $input['reason']) : '';

                if ($qty < 0 || $qty > (float) $line->qty_sent + 0.0005) {
                    $errors["lines.{$line->id}.qty_received"] = __('stock.transfer_over_receipt');
                } elseif ($qty < (float) $line->qty_sent - 0.0005 && $reason === '') {
                    $errors["lines.{$line->id}.reason"] = __('stock.transfer_reason_required');
                }
                $plan[] = [$line, $qty, $reason];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $discrepancy = false;
            foreach ($plan as [$line, $qty, $reason]) {
                $short = $qty < (float) $line->qty_sent - 0.0005;
                $discrepancy = $discrepancy || $short;
                $line->update(['qty_received' => $qty, 'discrepancy_reason' => $short ? $reason : null]);
                if ($qty > 0) {
                    $this->move($line->kind, (int) $line->item_id, (int) $transfer->to_outlet_id, 'transfer_in', $qty, $transfer, $by);
                }
            }

            $transfer->status = StockTransfer::RECEIVED;
            $transfer->has_discrepancy = $discrepancy;
            $transfer->received_by = $by->id;
            $transfer->received_at = now();
            $transfer->save();

            $this->log->log(
                userId: $by->id, action: 'stock_transfer_received', entityType: 'StockTransfer', entityId: $transfer->id,
                description: "Transfer {$transfer->number} diterima".($discrepancy ? ' dengan selisih' : '').'.',
                newValues: ['has_discrepancy' => $discrepancy],
            );

            return $transfer->load('lines');
        });
    }

    /** @throws ValidationException 409 bila bukan in_transit */
    public function cancel(StockTransfer $transfer, User $by): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $by) {
            $transfer = StockTransfer::withoutGlobalScopes()->lockForUpdate()->findOrFail($transfer->id);
            $this->assertInTransit($transfer);

            foreach ($transfer->lines()->orderBy('kind')->orderBy('item_id')->get() as $line) {
                $this->move($line->kind, (int) $line->item_id, (int) $transfer->from_outlet_id, 'transfer_cancel', (float) $line->qty_sent, $transfer, $by);
            }

            $transfer->status = StockTransfer::CANCELLED;
            $transfer->cancelled_by = $by->id;
            $transfer->cancelled_at = now();
            $transfer->save();

            $this->log->log(
                userId: $by->id, action: 'stock_transfer_cancelled', entityType: 'StockTransfer', entityId: $transfer->id,
                description: "Transfer {$transfer->number} dibatalkan; barang kembali ke outlet asal.",
            );

            return $transfer->load('lines');
        });
    }

    // --- internal -----------------------------------------------------------------------------------

    private function assertInTransit(StockTransfer $transfer): void
    {
        if ($transfer->status !== StockTransfer::IN_TRANSIT) {
            throw ValidationException::withMessages(['status' => __($transfer->status === StockTransfer::RECEIVED ? 'stock.transfer_already_received' : 'stock.transfer_not_in_transit')])->status(409);
        }
    }

    /**
     * Memvalidasi dan mengurutkan baris (jenis, id item) — urutan inilah kunci urutan penguncian yang deterministik.
     *
     * @param  list<array{kind?: string, item_id?: int, qty?: mixed}>  $lines
     * @return list<array{kind: string, item_id: int, qty: float|int}>
     */
    private function normalise(array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('stock.transfer_lines_required')]);
        }

        $out = [];
        $seen = [];
        foreach ($lines as $i => $line) {
            $kind = $line['kind'] ?? null;
            $itemId = (int) ($line['item_id'] ?? 0);
            $qty = $line['qty'] ?? null;

            if (! in_array($kind, ['variant', 'material'], true) || $itemId <= 0 || ! is_numeric($qty) || (float) $qty <= 0) {
                throw ValidationException::withMessages(["lines.{$i}" => __('stock.transfer_line_invalid')]);
            }
            if (isset($seen["{$kind}:{$itemId}"])) {
                throw ValidationException::withMessages(["lines.{$i}" => __('stock.transfer_line_duplicate')]);
            }
            $seen["{$kind}:{$itemId}"] = true;

            if ($kind === 'variant') {
                $variant = ProductVariant::query()->find($itemId);
                if ($variant === null) {
                    throw ValidationException::withMessages(["lines.{$i}.item_id" => __('stock.transfer_item_not_found')]);
                }
                if (! $variant->tracksOwnStock()) { // 087 US6 — juga produk bersumber stok `modifier`
                    throw ValidationException::withMessages(["lines.{$i}.item_id" => __('stock.transfer_untracked', ['sku' => $variant->sku])]);
                }
                if ((float) $qty != (int) $qty) {
                    throw ValidationException::withMessages(["lines.{$i}.qty" => __('stock.transfer_whole_qty')]);
                }
                $qty = (int) $qty;
            } else {
                if (! Material::query()->whereKey($itemId)->exists()) {
                    throw ValidationException::withMessages(["lines.{$i}.item_id" => __('stock.transfer_item_not_found')]);
                }
                $qty = round((float) $qty, 3);
            }

            $out[] = ['kind' => $kind, 'item_id' => $itemId, 'qty' => $qty];
        }

        usort($out, fn ($a, $b) => [$a['kind'], $a['item_id']] <=> [$b['kind'], $b['item_id']]);

        return $out;
    }

    private function move(string $kind, int $itemId, int $outletId, string $type, float|int $qtyChange, StockTransfer $transfer, User $by): void
    {
        if ($kind === 'variant') {
            $this->stock->applyMovement(
                variant: ProductVariant::query()->findOrFail($itemId), outletId: $outletId, type: $type, qtyChange: (int) $qtyChange,
                referenceType: 'stock_transfer', referenceId: $transfer->id, reason: $transfer->number, userId: $by->id,
            );

            return;
        }

        $this->materials->applyMovement(
            material: Material::query()->findOrFail($itemId), outletId: $outletId, type: $type, qtyChange: (float) $qtyChange,
            referenceType: 'stock_transfer', referenceId: $transfer->id, userId: $by->id,
        );
    }

    /** TRF-YYYYMMDD-#### — dihitung lintas mode data (nomor UNIQUE se-tabel), seperti nomor order. */
    private function generateNumber(): string
    {
        $count = StockTransfer::withoutGlobalScopes()->whereDate('created_at', now()->toDateString())->count();

        return sprintf('TRF-%s-%04d', now()->format('Ymd'), $count + 1);
    }
}
