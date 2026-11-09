<?php

namespace App\Services;

use App\Models\Preorder;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 065 — ORKESTRATOR aksi massal pada daftar Pre-orders. Sengaja TIDAK berisi aturan bisnis: tiap aksi memanggil
 * penulis yang SAMA dengan aksi individual (PreorderService::transitionStatus / setDispatchStatus) — jadi aturan, efek samping, dan pesannya tak mungkin
 * berbeda dari jalur satu per satu (Constitution I, spec FR-008). Yang ada di sini hanyalah: satu query ber-scope
 * untuk semua id, satu transaksi + kunci baris PER pre-order, bentuk hasil (succeeded|skipped|failed), dan audit.
 *
 * - `succeeded + skipped + failed = selected = results.length`, urutan permintaan dipertahankan.
 * - Pre-order yang tak layak DILEWATI dengan alasan; pengecualian tak terduga = `failed` dengan pesan generik
 *   (detail hanya di log) — tak pernah memblokir atau membatalkan pre-order lain.
 * - Pratinjau murni baca: tak menulis baris, log, stok, ataupun email.
 * - Baris audit `preorder_bulk_*` ditulis DI DALAM transaksi pre-order-nya (ikut rollback); satu baris agregat
 *   `preorder_bulk_operation` ditulis SETELAH loop (ringkasan pekerjaan yang sudah commit; gagal menulisnya
 *   tak pernah menggagalkan respons).
 */
class PreorderBulkService
{
    /** Status tujuan yang bisa ditawarkan di dialog status massal. */
    private const STATUS_TARGETS = ['dp_paid', 'arrived', 'settled', 'handed_over', 'cancelled'];

    public function __construct(
        private readonly PreorderService $preorders,
        private readonly ActivityLogger $logger,
    ) {}

    // ───────────────────────────── pratinjau (read-only) ─────────────────────────────

    /** @return array<string, mixed> */
    public function preview(string $action, array $ids, array $params): array
    {
        $loaded = $this->resolve($ids);

        $skipped = [];
        $eligible = 0;
        $extra = [];

        if ($action === 'status') {
            $extra['status_options'] = $this->statusOptions($loaded);
        }
        foreach ($ids as $id) {
            $p = $loaded->get($id);
            if (! $p) {
                $skipped[] = $this->skipRow($id, null, 'not_found');

                continue;
            }

            $blocker = match ($action) {
                'status' => isset($params['status']) ? $this->preorders->statusBlocker($p, $params['status']) : null,
                'dispatch' => $this->dispatchBlocker($p, $params['dispatch_status']),
            };

            if ($blocker) {
                $skipped[] = $this->skipRow($id, $p->preorder_number, $blocker['code'], $blocker['message']);
            } else {
                $eligible++;
            }
        }

        return array_merge([
            'action' => $action,
            'selected' => count($ids),
            'eligible' => $eligible,
            'skipped' => $skipped,
        ], $extra);
    }

    // ───────────────────────────── terapkan ─────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function apply(string $action, array $ids, array $params, User $user): array
    {
        // Satu permintaan bisa menyentuh ratusan pre-order (tiap satu transaksi + email status sinkron): batas waktu
        // dinaikkan HANYA untuk permintaan ini.
        @set_time_limit(600);

        $bulkRef = (string) Str::uuid();
        $loaded = $this->resolve($ids);

        $results = [];
        foreach ($ids as $id) {
            $seen = $loaded->get($id);
            if (! $seen) {
                $results[] = $this->resultRow($id, null, 'skipped', 'not_found');

                continue;
            }

            try {
                $notify = null;
                $outcome = DB::transaction(function () use ($action, $id, $params, $user, $bulkRef, &$notify) {
                    // Keadaan TERKINI di bawah kunci baris — bukan salinan dari pemuatan awal.
                    $p = Preorder::lockForUpdate()->with(['items'])->find($id);
                    if (! $p) {
                        return ['status' => 'skipped', 'reason' => 'not_found', 'message' => __('preorders.bulk_reason_not_found')];
                    }

                    return match ($action) {
                        'status' => $this->applyStatus($p, $params, $user, $bulkRef, $notify),
                        'dispatch' => $this->applyDispatch($p, $params, $user, $bulkRef),
                    };
                });

                // Email status dikirim SETELAH commit pre-order ini, tak pernah di dalamnya, dan tak pernah
                // menggagalkan perubahan (notifyStatusChangeSafely menelan galat).
                if ($notify instanceof Preorder) {
                    $this->preorders->notifyStatusChangeSafely($notify);
                }

                $results[] = array_merge(
                    $this->resultRow($id, $seen->preorder_number, $outcome['status'], $outcome['reason'] ?? null, $outcome['message'] ?? null),
                    isset($outcome['detail']) ? ['detail' => $outcome['detail']] : [],
                );
            } catch (ValidationException $e) {
                [$reason, $message] = $this->classify($e, $action, $id);
                $results[] = $this->resultRow($id, $seen->preorder_number, 'skipped', $reason, $message);
            } catch (Throwable $e) {
                // Detail teknis hanya ke log — pengguna menerima alasan generik (FR-035).
                Log::error('preorder.bulk_failed', ['action' => $action, 'preorder_id' => $id, 'bulk_ref' => $bulkRef, 'error' => $e->getMessage()]);
                $results[] = $this->resultRow($id, $seen->preorder_number, 'failed', 'failed');
            }
        }

        $counts = ['succeeded' => 0, 'skipped' => 0, 'failed' => 0];
        $byReason = [];
        foreach ($results as $r) {
            $counts[$r['status']]++;
            if ($r['status'] === 'skipped') {
                $byReason[$r['reason']] = ($byReason[$r['reason']] ?? 0) + 1;
            }
        }

        $this->logOperation($action, $user, $bulkRef, count($ids), $counts, $byReason, $params);

        return array_merge([
            'action' => $action,
            'bulk_ref' => $bulkRef,
            'selected' => count($ids),
        ], $counts, ['skipped_by_reason' => $byReason, 'results' => $results]);
    }

    // ───────────────────────────── cabang per aksi ─────────────────────────────

    private function applyStatus(Preorder $p, array $params, User $user, string $bulkRef, ?Preorder &$notify): array
    {
        $target = $params['status'];
        if ($blocker = $this->preorders->statusBlocker($p, $target)) {
            return $this->skip($blocker);
        }

        $from = $p->status;
        $updated = $this->preorders->transitionStatus($p, $target, $params['cancel_reason'] ?? null, $user);

        $this->audit($user, 'preorder_bulk_status', $p,
            __('preorders.bulk_status_log', ['number' => $p->preorder_number, 'from' => $from, 'to' => $updated->status]),
            ['status' => $from],
            ['status' => $updated->status, 'cancel_reason' => $target === 'cancelled' ? ($params['cancel_reason'] ?? null) : null, 'bulk_ref' => $bulkRef],
        );

        $notify = $updated;

        return ['status' => 'succeeded', 'detail' => ['status' => $updated->status]];
    }

    private function applyDispatch(Preorder $p, array $params, User $user, string $bulkRef): array
    {
        $target = $params['dispatch_status'];
        if ($blocker = $this->dispatchBlocker($p, $target)) {
            return $this->skip($blocker);
        }

        $old = ['dispatch_status' => $p->dispatch_status, 'invoice_sent_at' => $p->invoice_sent_at?->toIso8601String(), 'shipping_at' => $p->shipping_at?->toIso8601String()];
        $this->preorders->setDispatchStatus($p, $target);
        $new = ['dispatch_status' => $p->dispatch_status, 'invoice_sent_at' => $p->invoice_sent_at?->toIso8601String(), 'shipping_at' => $p->shipping_at?->toIso8601String(), 'bulk_ref' => $bulkRef];

        $this->audit($user, 'preorder_bulk_dispatch', $p,
            __('preorders.bulk_dispatch_log', ['number' => $p->preorder_number, 'to' => $target]), $old, $new);

        return ['status' => 'succeeded', 'detail' => ['dispatch_status' => $p->dispatch_status]];
    }

    // ───────────────────────────── penilaian kelayakan (pratinjau) ─────────────────────────────

    /** Penanda: `already_done` hanya dilaporkan di aksi massal (jalur individual mengizinkan menandai ulang). */
    private function dispatchBlocker(Preorder $p, string $target): ?array
    {
        if ($blocker = $this->preorders->dispatchBlocker($p, $target)) {
            return $blocker;
        }

        return $p->dispatch_status === $target
            ? ['code' => 'already_done', 'message' => __('preorders.bulk_reason_already_done')]
            : null;
    }

    /** @return array<int, array{status:string,count:int}> hanya status yang bisa dituju ≥ 1 pre-order terpilih. */
    private function statusOptions(Collection $loaded): array
    {
        $options = [];
        foreach (self::STATUS_TARGETS as $target) {
            $count = $loaded->filter(fn (Preorder $p) => $this->preorders->statusBlocker($p, $target) === null)->count();
            if ($count > 0) {
                $options[] = ['status' => $target, 'count' => $count];
            }
        }

        return $options;
    }

    // ───────────────────────────── bantu ─────────────────────────────

    /** Satu query ber-scope (mode DEMO/LIVE + penjual) untuk semua id; yang tak terlihat = tak ada. */
    private function resolve(array $ids): Collection
    {
        return Preorder::query()
            ->with(['items'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * Memetakan penolakan service ke kode alasan di SATU tempat. Kode ditentukan dari KUNCI galat dan keadaan
     * pre-order (bukan dari teks pesan, yang bisa berubah/terlokalisasi); teks yang ditampilkan tetap teks service
     * itu sendiri, sehingga kata-kata jalur individual dan massal tak bisa berbeda.
     *
     * @return array{0:string,1:string}
     */
    private function classify(ValidationException $e, string $action, int $id): array
    {
        $keys = array_keys($e->errors());
        $message = $e->getMessage();
        $state = Preorder::query()->find($id);

        $reason = match (true) {
            in_array('qty_change', $keys, true) => 'insufficient_stock',
            in_array('status', $keys, true) && $action === 'status' => $state && ! in_array($state->status, ['handed_over', 'cancelled'], true) && $state->outstanding() > 0.01
                ? 'not_fully_paid' : 'invalid_transition',
            default => 'rejected',
        };

        return [$reason, $message];
    }

    /** @param array{code:string,message:string} $blocker */
    private function skip(array $blocker): array
    {
        return ['status' => 'skipped', 'reason' => $blocker['code'], 'message' => $blocker['message']];
    }

    private function skipRow(int $id, ?string $number, string $code, ?string $message = null): array
    {
        return [
            'preorder_id' => $id, 'preorder_number' => $number, 'reason' => $code,
            'message' => $message ?? __('preorders.bulk_reason_'.$code),
        ];
    }

    private function resultRow(int $id, ?string $number, string $status, ?string $reason = null, ?string $message = null): array
    {
        $row = ['preorder_id' => $id, 'preorder_number' => $number, 'status' => $status];
        if ($reason !== null) {
            $row['reason'] = $reason;
            $row['message'] = $message ?? __('preorders.bulk_reason_'.$reason);
        }

        return $row;
    }

    private function audit(User $user, string $action, Preorder $p, string $description, ?array $old, array $new): void
    {
        // Entity type sama dengan baris audit pre-order yang sudah ada (PaymentService: 'Preorder').
        $this->logger->log($user->id, $action, 'Preorder', $p->id, $description, $old, $new);
    }

    /** Baris agregat — ringkasan pekerjaan yang SUDAH commit; gagal menulisnya tak pernah menggagalkan respons. */
    private function logOperation(string $action, User $user, string $bulkRef, int $selected, array $counts, array $byReason, array $params): void
    {
        try {
            $this->logger->log(
                $user->id, 'preorder_bulk_operation', 'Preorder', null,
                __('preorders.bulk_operation_log', [
                    'action' => $action, 'user' => $user->name, 'succeeded' => $counts['succeeded'],
                    'selected' => $selected, 'skipped' => $counts['skipped'], 'failed' => $counts['failed'],
                ]),
                null,
                [
                    'bulk_ref' => $bulkRef, 'action' => $action, 'selected' => $selected,
                    'succeeded' => $counts['succeeded'], 'skipped' => $counts['skipped'], 'failed' => $counts['failed'],
                    'skipped_by_reason' => $byReason,
                    // Parameter bersama saja — tanpa nomor referensi/catatan bebas atau data penerima.
                    'params' => array_intersect_key($params, array_flip(['status', 'dispatch_status'])),
                ],
            );
        } catch (Throwable $e) {
            Log::warning('preorder.bulk_operation_log_failed', ['bulk_ref' => $bulkRef, 'error' => $e->getMessage()]);
        }
    }
}
