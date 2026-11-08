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

    /** Apakah pengguna ini punya seller mana pun yang bisa disetujuinya (dasar tombol "Setujui massal", 064). */
    public static function mayApproveAny(User $user): bool
    {
        return $user->isOwnerOrAdmin() || $user->artist_id !== null;
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

                $this->recordSeller($locked, $seller, $user, $method, $storedPath, $storedPath ? $image?->getMimeType() : null, null);

                return $locked;
            });
        } catch (\Throwable $e) {
            $this->deleteFile($storedPath); // transaksi batal → berkas yatim tidak dibiarkan
            throw $e;
        }
    }

    /**
     * Tulis SATU persetujuan seller + log `order_approved`-nya. Dipakai `approve()` (tunggal) dan `approveBatch()`
     * (massal): satu-satunya tempat baris `order_approvals` dibuat (Constitution I). WAJIB dipanggil di dalam transaksi
     * yang sudah mengunci baris order dan sudah memeriksa hak/status/kesegaran.
     *
     * @param  array<string, mixed>  $seller  satu elemen OrderApprovalSummary::for()['sellers']
     */
    private function recordSeller(Order $locked, array $seller, User $user, string $method, ?string $storedPath, ?string $mime, ?string $bulkRef): OrderApproval
    {
        $artistId = (int) $seller['artist_id'];

        try {
            $approval = $locked->approvals()->create([
                'artist_id' => $artistId,
                'artist_name' => $seller['artist_name'],
                'method' => $method,
                'image_path' => $storedPath,
                'image_mime' => $storedPath ? $mime : null,
                'items_hash' => $seller['items_hash'],
                'items_snapshot' => ['lines' => $seller['items'], 'subtotal' => $seller['subtotal']],
                'approved_by' => $user->id,
                'approver_name' => $user->name,
                'on_behalf' => (int) $user->artist_id !== $artistId,
                'approved_at' => now(),
                'bulk_ref' => $bulkRef,
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
            ] + ($bulkRef !== null ? ['bulk_ref' => $bulkRef] : []),
        );

        return $approval;
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
    // Persetujuan massal (064)
    // ------------------------------------------------------------------

    /**
     * Pratinjau (hanya baca): apa yang AKAN disetujui bila dikonfirmasi. Tidak menulis apa pun — tak ada baris, berkas,
     * maupun rekonsiliasi persetujuan basi. `plan` memuat `items_hash` terkini tiap seller: itulah isi yang akan ditinjau
     * pengguna, dan dikirim balik saat menerapkan sebagai acuan kesegaran (hanya bisa membuat sebuah penjualan DILEWATI).
     *
     * @param  array<int, int>  $orderIds
     * @return array<string, mixed>
     */
    public function previewBatch(array $orderIds, User $user): array
    {
        // Satu query untuk semua id (Constitution V). Lewat model ber-scope: mode DEMO/LIVE + seller (058) berlaku,
        // jadi id yang tak terlihat pengguna sama saja dengan tak ada (`not_found`), tanpa membocorkan apa pun.
        $orders = Order::query()->with(['items', 'approvals'])->whereIn('id', $orderIds)->get()->keyBy('id');
        // Satu peta nama untuk seluruh batch (pola daftar Sales) — tanpa ini tiap penjualan menambah satu query.
        $names = OrderApprovalSummary::namesFor(
            $orders->flatMap(fn (Order $o) => $o->items->pluck('artist_id'))->filter()->unique()->map(fn ($id) => (int) $id)->values()->all(),
        );

        $plan = [];
        $skipped = [];
        $perSeller = [];
        $approvals = 0;
        $eligible = 0;
        $cents = 0;

        foreach ($orderIds as $id) {
            $order = $orders->get($id);
            $verdict = $this->evaluate($order, $user, $names);

            if ($verdict['reason'] !== null) {
                $skipped[] = $this->skipRow($id, $order, $verdict['reason']);

                continue;
            }

            $eligible++;
            $planned = [];
            foreach ($verdict['sellers'] as $seller) {
                $planned[] = ['artist_id' => $seller['artist_id'], 'items_hash' => $seller['items_hash']];
                $approvals++;
                $sellerCents = (int) round(((float) $seller['subtotal']) * 100);
                $cents += $sellerCents;
                $perSeller[$seller['artist_id']] ??= ['artist_id' => $seller['artist_id'], 'artist_name' => $seller['artist_name'], 'approvals' => 0, 'cents' => 0];
                $perSeller[$seller['artist_id']]['approvals']++;
                $perSeller[$seller['artist_id']]['cents'] += $sellerCents;
            }
            $plan[] = ['order_id' => $id, 'sellers' => $planned];
        }

        return [
            'selected' => count($orderIds),
            'eligible_orders' => $eligible,
            'approvals' => $approvals,
            'amount' => number_format($cents / 100, 2, '.', ''),
            'sellers' => array_values(array_map(fn (array $r) => [
                'artist_id' => $r['artist_id'], 'artist_name' => $r['artist_name'],
                'approvals' => $r['approvals'], 'amount' => number_format($r['cents'] / 100, 2, '.', ''),
            ], $perSeller)),
            'skipped' => $skipped,
            'plan' => $plan,
        ];
    }

    /**
     * Terapkan persetujuan massal. SATU metode/gambar untuk semua; tiap PENJUALAN satu transaksi yang menyetujui semua
     * seller yang boleh disetujui pemanggil (jadi owner/admin atas nama banyak seller: semua atau tak satu pun untuk
     * penjualan itu), terlepas dari penjualan lain. Yang tak layak DILEWATI dengan alasan — tak pernah menggagalkan
     * batch. Gambar divalidasi dan disimpan SEKALI sebelum penjualan mana pun disentuh; path-nya dipakai bersama semua
     * baris batch (persetujuan append-only, tak ada jalur hapus, jadi aman — research D3).
     *
     * @param  array<int, int>  $orderIds
     * @param  array<int, array{order_id: int, sellers: array<int, array{artist_id: int, items_hash: string}>}>  $plan
     * @return array<string, mixed>
     */
    public function approveBatch(array $orderIds, array $plan, string $method, ?UploadedFile $image, User $user): array
    {
        $bulkRef = (string) Str::uuid();
        $expected = [];
        foreach ($plan as $row) {
            foreach ($row['sellers'] ?? [] as $s) {
                $expected[(int) $row['order_id']][(int) $s['artist_id']] = (string) $s['items_hash'];
            }
        }

        $storedPath = $this->storeImage($method, $image); // 422 di sini = belum ada yang tertulis
        $mime = $storedPath ? $image?->getMimeType() : null;

        $results = [];
        $approvedOrders = 0;
        $approvedSellers = 0;
        $byReason = [];

        try {
            foreach ($orderIds as $id) {
                $outcome = $this->approveOrderInBatch((int) $id, $expected[(int) $id] ?? [], $method, $storedPath, $mime, $user, $bulkRef);
                $results[] = $outcome;

                if ($outcome['status'] === 'approved') {
                    $approvedOrders++;
                    $approvedSellers += $outcome['sellers_approved'];
                } else {
                    $byReason[$outcome['reason']] = ($byReason[$outcome['reason']] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {
            if ($approvedSellers === 0) {
                $this->deleteFile($storedPath);
            }
            throw $e;
        }

        if ($approvedSellers === 0) {
            $this->deleteFile($storedPath); // tak ada baris yang merujuknya
        }

        $skippedTotal = count($orderIds) - $approvedOrders;

        // Ringkasan agregat (research D6): merangkum transaksi yang SUDAH commit, jadi bukan bagian dari satu transaksi;
        // log per persetujuan tetap ada di transaksinya masing-masing. Gagal menulisnya tak boleh menggagalkan respons.
        if ($approvedSellers > 0) {
            try {
                $this->activityLogger->log(
                    userId: $user->id,
                    action: 'order_bulk_approved',
                    entityType: 'Order',
                    entityId: null,
                    description: __('approvals.bulk_approved_log', [
                        'method' => __("approvals.methods.{$method}"), 'user' => $user->name,
                        'approved' => $approvedOrders, 'selected' => count($orderIds), 'skipped' => $skippedTotal,
                    ]),
                    newValues: [
                        'bulk_ref' => $bulkRef, 'method' => $method, 'selected' => count($orderIds),
                        'approved_orders' => $approvedOrders, 'approved_sellers' => $approvedSellers,
                        'skipped_by_reason' => $byReason,
                    ],
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [
            'bulk_ref' => $bulkRef,
            'method' => $method,
            'selected' => count($orderIds),
            'approved_orders' => $approvedOrders,
            'approved_sellers' => $approvedSellers,
            'skipped_total' => $skippedTotal,
            'skipped_by_reason' => (object) $byReason,
            'results' => $results,
        ];
    }

    /**
     * @param  array<int, string>  $expected  artist_id => items_hash yang ditinjau pengguna di pratinjau
     * @return array<string, mixed>
     */
    private function approveOrderInBatch(int $id, array $expected, string $method, ?string $storedPath, ?string $mime, User $user, string $bulkRef): array
    {
        $number = null;

        try {
            $order = Order::query()->find($id);
            if ($order === null) {
                return $this->skipRow($id, null, 'not_found') + ['status' => 'skipped'];
            }
            $number = $order->order_number;

            // Persetujuan basi dibatalkan + dicatat di transaksinya sendiri, persis seperti approve() tunggal.
            $this->reconcile($order, $user);

            $count = DB::transaction(function () use ($id, $expected, $method, $storedPath, $mime, $user, $bulkRef) {
                $locked = Order::query()->lockForUpdate()->find($id);
                if ($locked === null) {
                    throw new OrderApprovalException(__('approvals.skip_reasons.not_found'), 'not_found');
                }
                if ($locked->status === 'voided') {
                    throw new OrderApprovalException(__('approvals.skip_reasons.voided'), 'voided');
                }

                $this->reconcileLocked($locked, $user);
                $locked->unsetRelation('approvals')->unsetRelation('items');

                $verdict = $this->evaluate($locked, $user);
                if ($verdict['reason'] !== null) {
                    throw new OrderApprovalException(__("approvals.skip_reasons.{$verdict['reason']}"), $verdict['reason']);
                }

                // Kesegaran: isi tiap seller HARUS sama dengan yang ditinjau; seller baru yang belum ada di pratinjau pun
                // dianggap berubah. Satu saja tak cocok → seluruh penjualan dilewati (tak pernah setengah disetujui).
                foreach ($verdict['sellers'] as $seller) {
                    $hash = $expected[$seller['artist_id']] ?? null;
                    if ($hash === null || ! hash_equals($seller['items_hash'], $hash)) {
                        throw new OrderApprovalException(__('approvals.skip_reasons.changed'), 'changed');
                    }
                }

                foreach ($verdict['sellers'] as $seller) {
                    $this->recordSeller($locked, $seller, $user, $method, $storedPath, $mime, $bulkRef);
                }

                return count($verdict['sellers']);
            });

            return ['order_id' => $id, 'order_number' => $number, 'status' => 'approved', 'sellers_approved' => $count];
        } catch (OrderApprovalException $e) {
            return ['order_id' => $id, 'order_number' => $number, 'status' => 'skipped', 'reason' => $e->reason, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            // Rincian hanya ke log; klien menerima alasan generik (tak pernah SQL/jalur berkas).
            report($e);

            return ['order_id' => $id, 'order_number' => $number, 'status' => 'skipped', 'reason' => 'failed', 'message' => __('approvals.skip_reasons.failed')];
        }
    }

    /**
     * SATU definisi kelayakan massal (research D5), dipakai pratinjau dan penerapan. `reason` null = layak untuk
     * `sellers` (yang masih menunggu DAN boleh disetujui pengguna ini).
     *
     * @return array{reason: ?string, sellers: array<int, array<string, mixed>>}
     */
    private function evaluate(?Order $order, User $user, ?array $artistNames = null): array
    {
        if ($order === null) {
            return ['reason' => 'not_found', 'sellers' => []];
        }
        if ($order->status === 'voided') {
            return ['reason' => 'voided', 'sellers' => []];
        }

        $approvable = array_filter(
            OrderApprovalSummary::for($order, $artistNames)['sellers'],
            fn (array $s) => self::mayApproveFor($user, (int) $s['artist_id']),
        );
        if ($approvable === []) {
            return ['reason' => 'no_approvable_seller', 'sellers' => []];
        }

        $pending = array_values(array_filter($approvable, fn (array $s) => $s['state'] === 'pending'));
        if ($pending === []) {
            return ['reason' => 'already_approved', 'sellers' => []];
        }

        return ['reason' => null, 'sellers' => $pending];
    }

    /** @return array{order_id: int, order_number: ?string, reason: string, message: string} */
    private function skipRow(int $id, ?Order $order, string $reason): array
    {
        return [
            'order_id' => $id,
            'order_number' => $order?->order_number,
            'reason' => $reason,
            'message' => __("approvals.skip_reasons.{$reason}"),
        ];
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
                    'via_bulk' => $a->bulk_ref !== null,
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
