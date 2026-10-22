<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * 065 / research D2–D3 — daftar shift untuk halaman Sesi Kasir web dan panel dashboard.
 *
 * Aturan lihat (SATU tempat, dipakai juga daftar kas masuk/keluar): owner/admin melihat SEMUA shift pada outlet dan
 * mode data yang diizinkan (scope global CashierSession); pengguna lain HANYA shift miliknya — `user_id` orang lain
 * dari klien diabaikan, bukan dipercaya. Angka per baris dari {@see ShiftReportService::figuresForMany()} (satu rumus
 * kas, kueri terkelompok); shift tertutup membaca nilai yang tersimpan.
 */
class ShiftListService
{
    public function __construct(private ShiftReportService $report) {}

    /** Shift yang boleh dilihat [user]. Scope outlet/mode tetap berlaku lewat global scope model. */
    public function visibleQuery(User $user): Builder
    {
        $query = CashierSession::query();

        return $user->isOwnerOrAdmin() ? $query : $query->where('user_id', $user->id);
    }

    /**
     * @param  array{status?: string, source?: string, user_id?: int|string|null, date_from?: ?string, date_to?: ?string}  $filters
     */
    public function paginate(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $status = $filters['status'] ?? 'open';

        $page = $this->visibleQuery($user)
            ->with(['user:id,name', 'device:id,name', 'outlet:id,code,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status === 'closed' ? 'closed' : 'open'))
            ->when(($filters['source'] ?? null) === 'app', fn ($q) => $q->whereNotNull('device_id'))
            ->when(($filters['source'] ?? null) === 'web', fn ($q) => $q->whereNull('device_id'))
            ->when($user->isOwnerOrAdmin() && ! empty($filters['user_id']), fn ($q) => $q->where('user_id', (int) $filters['user_id']))
            ->when(! empty($filters['date_from']), fn ($q) => $q->where('business_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->where('business_date', '<=', $filters['date_to']))
            // Terbuka dulu, lalu terbaru: yang sedang berjalan menurut waktu buka, yang tertutup menurut waktu tutup.
            ->orderByRaw("status = 'open' DESC")
            ->orderByRaw('COALESCE(closed_at, opened_at) DESC')
            ->orderByDesc('id')
            ->paginate($perPage);

        $figures = $this->report->figuresForMany($page->getCollection());
        $page->setCollection($page->getCollection()->map(fn (CashierSession $s) => $this->present($s, $figures[$s->id])));

        return $page;
    }

    /** Baris untuk panel dashboard: shift terbuka semua pengguna (maks. [limit]), terbaru dulu. */
    public function openShifts(User $user, int $limit = 50): array
    {
        $base = $this->visibleQuery($user)->where('status', 'open');
        $total = (clone $base)->count();
        $sessions = $base->with(['user:id,name', 'device:id,name', 'outlet:id,code,name'])
            ->orderByDesc('opened_at')->orderByDesc('id')->limit($limit)->get();
        $figures = $this->report->figuresForMany($sessions);

        return [
            'data' => $sessions->map(fn (CashierSession $s) => $this->present($s, $figures[$s->id]))->values()->all(),
            'total_open' => $total,
        ];
    }

    /** @param array<string, float|int> $f */
    public function present(CashierSession $s, array $f): array
    {
        $closed = $s->status === 'closed';
        $money = fn ($v) => $this->report->money($v);

        return [
            'id' => $s->id,
            // Sumber diturunkan dari terminal (research D4): terminal terisi = aplikasi, kosong = web.
            'source' => $s->device_id === null ? 'web' : 'app',
            'terminal_name' => $s->device?->name,
            'cashier' => $s->user ? ['id' => $s->user->id, 'name' => $s->user->name] : null,
            'outlet' => $s->outlet ? ['id' => $s->outlet->id, 'name' => $s->outlet->name] : null,
            'status' => $s->status,
            'business_date' => $s->business_date ? substr((string) $s->business_date, 0, 10) : null,
            'opened_at' => $s->opened_at?->toIso8601String(),
            'closed_at' => $s->closed_at?->toIso8601String(),
            'opening_cash' => $money($s->opening_cash),
            'order_count' => $f['order_count'],
            'sales_total' => $money($f['sales_total']),
            'cash_net' => $money($f['cash_net']),
            'noncash_total' => $money($f['noncash_total']),
            'cash_in' => $money($f['cash_in']),
            'cash_out' => $money($f['cash_out']),
            'void_count' => $f['void_count'],
            'void_total' => $money($f['void_total']),
            'expected_cash' => $money($f['expected_cash']),
            // Hanya shift tertutup yang punya hitungan: tersimpan, tidak dihitung ulang.
            'counted_total' => $closed && $s->closing_cash !== null ? $money($s->closing_cash) : null,
            'difference' => $closed && $s->cash_difference !== null ? $money($s->cash_difference) : null,
        ];
    }
}
