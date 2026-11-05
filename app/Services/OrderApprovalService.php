<?php

namespace App\Services;

use App\Exceptions\OrderApprovalException;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\User;
use App\Support\OrderApprovalSummary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 059 — SATU-SATUNYA penulis `order_approvals` (Constitution I). Satu seller unik = satu persetujuan;
 * status keseluruhan diturunkan oleh OrderApprovalSummary dan tidak pernah disimpan.
 *
 * Tiap penulisan: kunci baris order → semua pemeriksaan → berkas → baris → activity log, dalam SATU
 * transaksi (log yang dibatalkan tak pernah tertinggal, Constitution IV). Berkas ditulis di dalam
 * transaksi dan dihapus bila transaksi gagal (pola SellerSettlementService::acknowledge()).
 *
 * KEWAJIBAN untuk kode mana pun di masa depan yang mengubah/menghapus/memindahkan `order_items`: panggil
 * `reconcile()` di dalam transaksinya. Kebenaran tampilan tidak bergantung padanya (persetujuan yang
 * hash-nya tak cocok sudah terbaca "menunggu"), tetapi pembatalan tercatat + log baru tertulis lewat sini
 * atau pada persetujuan berikutnya.
 */
class OrderApprovalService
{
    public const MAX_IMAGE_KILOBYTES = 5120;

    private const DIRECTORY = 'order-approvals';

    private const MIME_EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    public function __construct(private ActivityLogger $activityLogger) {}

    // ------------------------------------------------------------------
    // Hak (satu definisi, dipakai service, presenter, dan endpoint gambar)
    // ------------------------------------------------------------------

    /** Owner/admin untuk seller mana pun, atau pengguna yang terikat ke seller itu (058). */
    public static function mayApproveFor(User $user, int $artistId): bool
    {
        if ($user->isOwnerOrAdmin()) {
            return true;
        }

        return $user->artist_id !== null && (int) $user->artist_id === $artistId;
    }

    /** Gambar tanda tangan: owner/admin, penyetuju, atau pengguna terikat ke seller itu (FR-014). */
    public static function mayViewImage(OrderApproval $approval, User $user): bool
    {
        if ($approval->image_path === null) {
            return false;
        }

        return $user->isOwnerOrAdmin()
            || (int) $approval->approved_by === (int) $user->id
            || ($user->artist_id !== null && (int) $user->artist_id === (int) $approval->artist_id);
    }

    // ------------------------------------------------------------------
    // Menulis
    // ------------------------------------------------------------------

    /**
     * Catat persetujuan seorang seller.
     *
     * @param  array{artist_id: int|string, method: string, items_hash: string}  $data
     */
    public function approve(Order $order, array $data, ?UploadedFile $image, User $user): Order
    {
        $artistId = (int) $data['artist_id'];
        $method = (string) $data['method'];
        $storedPath = null;

        // Hak diperiksa SEBELUM keadaan penjualan dibuka: pengguna tak berhak tak boleh mengintip status.
        if (! self::mayApproveFor($user, $artistId)) {
            throw new OrderApprovalException(__('approvals.approval_not_allowed'), 'approval_not_allowed', 403);
        }

        // Persetujuan basi dibatalkan dan dicatat di transaksinya SENDIRI (committed) sebelum percobaan ini: bila
        // percobaan ditolak (mis. `approval_stale`), jejak pembatalannya tetap ada, tidak ikut digulung.
        $this->reconcile($order, $user);

        try {
            return DB::transaction(function () use ($order, $data, $image, $user, $artistId, $method, &$storedPath) {
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

                if ($locked->status === 'voided') {
                    throw new OrderApprovalException(__('approvals.order_voided'), 'order_voided');
                }

                // Persetujuan basi dibereskan dulu supaya slot aktifnya bebas dan pembatalannya tercatat.
                $this->reconcileLocked($locked, $user);
                $locked->unsetRelation('approvals')->unsetRelation('items');

                $seller = OrderApprovalSummary::for($locked)['sellers'][$artistId] ?? null;
                if ($seller === null) {
                    throw ValidationException::withMessages(['artist_id' => __('approvals.seller_not_required')]);
                }
                if ($seller['state'] === 'approved') {
                    throw new OrderApprovalException(__('approvals.already_approved'), 'already_approved');
                }
                if (! hash_equals($seller['items_hash'], (string) $data['items_hash'])) {
                    throw new OrderApprovalException(__('approvals.approval_stale'), 'approval_stale');
                }

                $storedPath = $this->storeImage($method, $image);

                try {
                    $approval = $locked->approvals()->create([
                        'artist_id' => $artistId,
                        'artist_name' => $seller['artist_name'],
                        'method' => $method,
                        'image_path' => $storedPath,
                        'image_mime' => $storedPath ? ($image?->getMimeType()) : null,
                        'items_hash' => $seller['items_hash'],
                        'items_snapshot' => ['lines' => $seller['items'], 'subtotal' => $seller['subtotal']],
                        'approved_by' => $user->id,
                        'approver_name' => $user->name,
                        'on_behalf' => (int) $user->artist_id !== $artistId,
                        'approved_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Jaring terakhir bila dua permintaan lolos pemeriksaan bersamaan: DB tetap menjaga satu baris aktif.
                    throw new OrderApprovalException(__('approvals.already_approved'), 'already_approved');
                }

                $this->activityLogger->log(
                    userId: $user->id,
                    action: 'order_approved',
                    entityType: 'Order',
                    entityId: $locked->id,
                    description: __('approvals.approved_log', [
                        'seller' => $seller['artist_name'], 'number' => $locked->order_number,
                        'method' => __("approvals.methods.{$method}"),
                        'behalf' => $approval->on_behalf ? __('approvals.behalf_suffix') : '',
                    ]),
                    newValues: [
                        'artist_id' => $artistId, 'method' => $method, 'on_behalf' => $approval->on_behalf,
                        'items_hash' => substr($seller['items_hash'], 0, 15),
                    ],
                );

                return $locked;
            });
        } catch (\Throwable $e) {
            $this->deleteFile($storedPath); // transaksi batal → berkas yatim tidak dibiarkan
            throw $e;
        }
    }

    /**
     * Tandai tak berlaku persetujuan aktif yang barisnya sudah berubah (hash tak cocok, atau seller sudah
     * tak punya baris). Riwayat tetap: tidak ada baris maupun berkas yang dihapus. Idempoten.
     *
     * @return int jumlah persetujuan yang dibatalkan
     */
    public function reconcile(Order $order, ?User $user = null): int
    {
        return DB::transaction(function () use ($order, $user) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            return $this->reconcileLocked($locked, $user);
        });
    }

    private function reconcileLocked(Order $order, ?User $user): int
    {
        // Penjualan batal: catatan persetujuan dibiarkan apa adanya (FR-022), tak dibatalkan walau barisnya berubah.
        if ($order->status === 'voided') {
            return 0;
        }

        $order->unsetRelation('approvals')->unsetRelation('items');
        $sellers = OrderApprovalSummary::for($order)['sellers'];
        $count = 0;

        foreach ($order->approvals()->whereNull('invalidated_at')->get() as $approval) {
            $current = $sellers[$approval->artist_id]['items_hash'] ?? null;
            if ($current !== null && hash_equals($approval->items_hash, $current)) {
                continue;
            }

            $approval->update(['invalidated_at' => now(), 'invalidation_reason' => 'items_changed', 'active_slot' => null]);
            $count++;

            $this->activityLogger->log(
                userId: $user?->id,
                action: 'order_approval_invalidated',
                entityType: 'Order',
                entityId: $order->id,
                description: __('approvals.invalidated_log', ['seller' => $approval->artist_name, 'number' => $order->order_number]),
                newValues: ['artist_id' => $approval->artist_id, 'approval_id' => $approval->id, 'reason' => 'items_changed'],
            );
        }

        $order->unsetRelation('approvals');

        return $count;
    }

    // ------------------------------------------------------------------
    // Membaca (presenter tunggal untuk GET dan respons POST)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function present(Order $order, User $user): array
    {
        $order->loadMissing(['items', 'approvals']);
        $summary = OrderApprovalSummary::for($order);
        $open = $order->status !== 'voided';

        $sellers = array_values(array_map(function (array $s) use ($user, $open) {
            /** @var OrderApproval|null $a */
            $a = $s['approval'];

            return [
                'artist_id' => $s['artist_id'],
                'artist_name' => $s['artist_name'],
                'status' => $s['state'],
                'stale' => $s['stale'],
                'items' => $s['items'],
                'subtotal' => $s['subtotal'],
                'items_hash' => $s['items_hash'],
                'can_approve' => $open && $s['state'] === 'pending' && self::mayApproveFor($user, $s['artist_id']),
                'approval' => $a ? self::presentApproval($a, $user) : null,
            ];
        }, $summary['sellers']));

        $data = [
            'order_id' => $order->id,
            'status' => $summary['status'],
            'total' => $summary['total'],
            'approved' => $summary['approved'],
            'pending' => $summary['pending'],
            'progress' => $summary['approved'].'/'.$summary['total'],
            'sellers' => $sellers,
        ];

        // Riwayat hanya untuk owner/admin: DILEWATKAN (bukan kosong) bagi yang lain, jadi SPA tak memutuskan dari peran.
        if ($user->isOwnerOrAdmin()) {
            $data['history'] = $order->approvals->sortByDesc('id')->values()->map(
                fn (OrderApproval $a) => self::presentApproval($a, $user) + [
                    'artist_id' => $a->artist_id, 'artist_name' => $a->artist_name,
                    'invalidated_at' => $a->invalidated_at?->toIso8601String(), 'invalidation_reason' => $a->invalidation_reason,
                ],
            )->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private static function presentApproval(OrderApproval $a, User $user): array
    {
        return [
            'id' => $a->id,
            'method' => $a->method,
            'approver_id' => $a->approved_by,
            'approver_name' => $a->approver_name,
            'on_behalf' => $a->on_behalf,
            'approved_at' => $a->approved_at->toIso8601String(),
            'has_image' => $a->image_path !== null,
            'can_view_image' => self::mayViewImage($a, $user),
        ];
    }

    // ------------------------------------------------------------------
    // Berkas
    // ------------------------------------------------------------------

    /** Simpan gambar (draw/upload) di disk PRIVAT, nama acak, jenis dibaca dari ISI berkas; stempel tanpa berkas. */
    private function storeImage(string $method, ?UploadedFile $image): ?string
    {
        if ($method === 'stamp') {
            if ($image !== null) {
                throw ValidationException::withMessages(['image' => __('approvals.image_not_allowed_for_stamp')]);
            }

            return null;
        }
        if ($image === null) {
            throw ValidationException::withMessages(['image' => __('approvals.image_required')]);
        }
        if ($image->getSize() === 0 || $image->getSize() > self::MAX_IMAGE_KILOBYTES * 1024) {
            throw ValidationException::withMessages(['image' => __('approvals.image_required')]);
        }

        $mime = $image->getMimeType();
        if (! isset(self::MIME_EXTENSIONS[$mime])) {
            throw ValidationException::withMessages(['image' => __('approvals.unsupported_file_type')]);
        }

        $path = $image->storeAs(self::DIRECTORY, Str::uuid()->toString().'.'.self::MIME_EXTENSIONS[$mime], 'local');
        if ($path === false) {
            throw new \RuntimeException('Gagal menyimpan berkas persetujuan.');
        }

        return $path;
    }

    private function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }
}
