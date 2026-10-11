<?php

namespace App\Services;

use App\Models\MenuChange;
use App\Models\MenuRelease;
use App\Models\User;
use App\Services\Release\ChangeApplier;
use App\Services\Release\ItemLifecycleApplier;
use App\Services\Release\PriceChangeApplier;
use App\Support\BusinessDay;
use App\Support\MenuSettings;
use App\Support\ModeGate;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 043-products-page-redesign — mesin rilis menu (research.md D2, D3, D17). Mengumpulkan perubahan yang siap,
 * menerbitkan versi bernomor, dan MENULISKAN perubahan ke kolom hidup (tidak ada sumber harga paralel). Satu
 * implementasi untuk dua mode: dengan rilis aktif perubahan menunggu rilis; dengan rilis mati hanya perubahan
 * berjadwal (`effective_from` di depan) yang diterapkan saat jatuh tempo.
 *
 * Tanpa penjadwal: `publishDueCached()` dipanggil di titik masuk yang butuh data terkini (daftar POS, pembuatan
 * order/tab, poll ketersediaan, daftar Produk) dan menerapkan yang sudah jatuh tempo — paling sering tiap 5 detik.
 */
class ReleaseService
{
    /** @var array<string,class-string<ChangeApplier>> */
    private const APPLIERS = [
        MenuChange::KIND_PRICE => PriceChangeApplier::class,
        MenuChange::KIND_ITEM_PUBLISH => ItemLifecycleApplier::class,
        MenuChange::KIND_ITEM_DISCONTINUE => ItemLifecycleApplier::class,
        MenuChange::KIND_ITEM_REACTIVATE => ItemLifecycleApplier::class,
        MenuChange::KIND_MODIFIER_LINK => \App\Services\Release\ModifierLinkApplier::class,
    ];

    public function __construct(private ActivityLogger $log) {}

    /** Perubahan yang boleh ikut rilis (tanpa persetujuan, atau sudah disetujui), urut pengajuan. */
    public function releasable(): Collection
    {
        return MenuChange::query()->releasable()->whereNull('release_id')->orderBy('id')->get();
    }

    /**
     * Membuat versi baru. `publishAt` null/lewat = diterapkan seketika; di depan = dijadwalkan.
     *
     * @param  list<int>|null  $changeIds  null = semua yang siap
     *
     * @throws ValidationException 409 `changes_not_ready` bila ada pilihan yang belum siap; 422 bila tidak ada isi
     */
    public function publish(User $by, ?CarbonInterface $publishAt = null, ?string $note = null, ?array $changeIds = null): MenuRelease
    {
        return DB::transaction(function () use ($by, $publishAt, $note, $changeIds) {
            $ready = $this->releasable();
            $selected = $changeIds === null ? $ready : $ready->whereIn('id', $changeIds)->values();

            if ($changeIds !== null && $selected->count() !== count(array_unique($changeIds))) {
                $notReady = MenuChange::query()->whereIn('id', $changeIds)->whereNotIn('id', $selected->pluck('id'))->pluck('id')->all();
                throw ValidationException::withMessages(['change_ids' => __('products.changes_not_ready')])->status(409);
            }
            if ($selected->isEmpty()) {
                throw ValidationException::withMessages(['change_ids' => __('products.nothing_to_release')]);
            }

            $mode = ModeGate::current();
            $version = (int) MenuRelease::query()->withoutGlobalScopes()->where('data_mode', $mode)->lockForUpdate()->max('version') + 1;
            $at = $publishAt ?? now();

            $release = MenuRelease::create([
                'version' => $version, 'status' => MenuRelease::SCHEDULED, 'publish_at' => $at, 'note' => $note,
                'requested_by' => $selected->first()->requested_by, 'released_by' => $by->id,
                'approved_by' => $selected->map(fn (MenuChange $c) => $c->approval?->decided_by)->filter()->first(),
            ]);
            MenuChange::whereIn('id', $selected->pluck('id'))->update(['release_id' => $release->id]);

            $this->log->log(
                userId: $by->id, action: 'menu_release_created', entityType: 'MenuRelease', entityId: $release->id,
                description: "Rilis menu v{$version} dibuat (".$selected->count().' perubahan).',
                newValues: ['version' => $version, 'publish_at' => $at->toIso8601String(), 'change_ids' => $selected->pluck('id')->all()],
            );

            if ($at->lessThanOrEqualTo(now())) {
                $this->apply($release, $by);
            }

            return $release->fresh();
        });
    }

    /** Menerapkan isi sebuah rilis ke kolom hidup, lalu menandainya terbit. Atomik. */
    public function apply(MenuRelease $release, User $by): MenuRelease
    {
        return DB::transaction(function () use ($release, $by) {
            $release = MenuRelease::query()->lockForUpdate()->findOrFail($release->id);
            if ($release->status !== MenuRelease::SCHEDULED) {
                return $release;
            }

            foreach ($release->changes()->orderBy('id')->get() as $change) {
                $this->applyChange($change, $by, $release);
            }

            $release->update(['status' => MenuRelease::PUBLISHED, 'published_at' => now()]);

            $this->log->log(
                userId: $by->id, action: 'menu_release_published', entityType: 'MenuRelease', entityId: $release->id,
                description: "Rilis menu v{$release->version} diterbitkan.",
            );

            return $release->fresh();
        });
    }

    /** @throws ValidationException 409 bila rilis sudah terbit atau dibatalkan */
    public function cancel(MenuRelease $release, User $by): MenuRelease
    {
        return DB::transaction(function () use ($release, $by) {
            $release = MenuRelease::query()->lockForUpdate()->findOrFail($release->id);
            if ($release->status !== MenuRelease::SCHEDULED) {
                throw ValidationException::withMessages(['release' => __('products.release_not_cancellable')])->status(409);
            }

            MenuChange::where('release_id', $release->id)->update(['release_id' => null]);
            $release->update(['status' => MenuRelease::CANCELLED]);
            $this->log->log(userId: $by->id, action: 'menu_release_cancelled', entityType: 'MenuRelease', entityId: $release->id, description: "Rilis menu v{$release->version} dibatalkan.");

            return $release->fresh();
        });
    }

    /**
     * Membatalkan SATU perubahan yang belum dirilis (peminta sendiri, atau pemegang izin menerbitkan rilis).
     * Persetujuan yang masih menunggu ikut ditutup (ditolak dengan catatan), bukan dibiarkan yatim.
     *
     * @throws ValidationException 403 bukan peminta/pemegang izin; 409 sudah dirilis/dibatalkan/ikut rilis terjadwal
     */
    public function cancelChange(MenuChange $change, User $by): MenuChange
    {
        return DB::transaction(function () use ($change, $by) {
            $change = MenuChange::query()->lockForUpdate()->findOrFail($change->id);

            if ($change->requested_by !== $by->id && ! $by->hasPermission(\App\Support\PermissionCatalog::RELEASE_PUBLISH)) {
                throw ValidationException::withMessages(['change' => __('products.change_cancel_forbidden')])->status(403);
            }
            if (! in_array($change->status, [MenuChange::PENDING, MenuChange::AWAITING_APPROVAL, MenuChange::APPROVED], true) || $change->release_id !== null) {
                throw ValidationException::withMessages(['change' => __('products.change_not_cancellable')])->status(409);
            }

            if ($change->approval_id !== null) {
                \App\Models\Approval::where('id', $change->approval_id)->where('decision', \App\Models\Approval::PENDING)
                    ->update(['decision' => \App\Models\Approval::REJECTED, 'note' => 'Dibatalkan bersama perubahannya', 'decided_at' => now()]);
            }
            $change->update(['status' => MenuChange::CANCELLED]);
            $this->log->log(userId: $by->id, action: 'menu_change_cancelled', entityType: 'MenuChange', entityId: $change->id, description: 'Perubahan menu dibatalkan sebelum dirilis.');

            return $change->fresh();
        });
    }

    /** Menerapkan yang jatuh tempo: rilis terjadwal; dan, bila rilis mati, perubahan berjadwal. Mengembalikan jumlahnya. */
    public function publishDue(): int
    {
        $n = 0;

        $due = MenuRelease::query()->where('status', MenuRelease::SCHEDULED)->where('publish_at', '<=', now())->orderBy('version')->get();
        foreach ($due as $release) {
            $this->apply($release, $this->actor($release->released_by));
            $n++;
        }

        if (! MenuSettings::releaseEnabled()) {
            $changes = MenuChange::query()->where('status', MenuChange::PENDING)->whereNull('release_id')
                ->whereNotNull('effective_from')->where('effective_from', '<=', BusinessDay::today())->orderBy('id')->get();
            foreach ($changes as $change) {
                DB::transaction(function () use ($change) {
                    $this->applyChange($change, $this->actor($change->requested_by), null);
                });
                $n++;
            }
        }

        return $n;
    }

    /** `publishDue()` paling sering sekali per 5 detik per mode data — murah dipanggil di tiap titik masuk. */
    public function publishDueCached(): void
    {
        if (Cache::add('menu.publish_due:'.ModeGate::current(), 1, 5)) {
            $this->publishDue();
        }
    }

    // --- internal -----------------------------------------------------------------------------------

    private function applyChange(MenuChange $change, User $by, ?MenuRelease $release): void
    {
        $applier = app(self::APPLIERS[$change->kind] ?? throw new \LogicException("Jenis perubahan tak dikenal: {$change->kind}"));
        $ok = $applier->apply($change, $by, $release);

        $change->update($ok
            ? ['status' => MenuChange::RELEASED, 'release_id' => $release?->id ?? $change->release_id]
            : ['status' => MenuChange::CANCELLED]);
    }

    private function actor(?int $userId): User
    {
        return ($userId ? User::find($userId) : null) ?? User::query()->orderBy('id')->firstOrFail();
    }
}
