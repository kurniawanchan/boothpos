<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardQueryRequest;
use App\Services\AttentionService;
use App\Services\DashboardOrdersQuery;
use App\Services\DashboardService;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 042-dashboard-revamp — Dashboard. Controller tipis: validasi, izin menu, lalu delegasi ke service
 * (Konstitusi I). Semua endpoint hanya baca dan digerbang menu `dashboard`; server-lah penegaknya,
 * bukan tampilan.
 */
class DashboardController extends Controller
{
    public function summary(DashboardQueryRequest $request, DashboardService $service): JsonResponse
    {
        $this->authorizeDashboard($request);

        return response()->json($service->summary($request->input('period', 'today')));
    }

    public function attention(DashboardQueryRequest $request, AttentionService $service): JsonResponse
    {
        $this->authorizeDashboard($request);

        return response()->json($service->items($request->user()));
    }

    /**
     * 065 / US1 — panel "Shift terbuka": satu baris per shift terbuka (aplikasi dan web), maks. 50, terbaru dulu.
     * Owner/admin melihat semua pengguna; peran lain yang punya menu dashboard hanya miliknya (aturan di
     * ShiftListService). Live dan kecil, jadi tanpa cache.
     */
    public function shifts(\Illuminate\Http\Request $request, \App\Services\ShiftListService $list): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('dashboard'), 403, __('reports.not_authorized_generic'));

        return response()->json($list->openShifts($request->user(), 50));
    }

    /** Tabel "Order terbaru": baris + jumlah tab (semua filter kecuali tab) + tab yang disembunyikan. */
    public function orders(DashboardQueryRequest $request, DashboardOrdersQuery $query): JsonResponse
    {
        $this->authorizeDashboard($request);
        $filters = $request->validated();
        $page = $query->page($filters);

        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'counts' => $query->counts($filters),
            'hidden_tabs' => DashboardOrdersQuery::HIDDEN_TABS,
        ]);
    }

    /**
     * Ekspor CSV baris yang SAMA dengan layar (filter yang sama, tanpa halaman). Tanpa kontak pelanggan.
     * BOM UTF-8 supaya Excel membaca nama berhuruf non-ASCII dengan benar.
     */
    public function ordersExport(DashboardQueryRequest $request, DashboardOrdersQuery $query): StreamedResponse
    {
        $this->authorizeDashboard($request);
        $rows = $query->all($request->validated());

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['order_number', 'at', 'type', 'table', 'items', 'status', 'payment_state', 'methods', 'total_amount', 'paid_amount']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['order_number'], $r['at'], $r['type'], $r['table'], $r['items_summary'], $r['tab'], $r['payment_state'], implode('|', $r['methods']), $r['total_amount'], $r['paid_amount']]);
            }
            fclose($out);
        }, 'order-terbaru-'.BusinessDay::today().'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /** Ekspor Dashboard: empat angka (+ pembanding + titik tren) dan butir "Perlu tindakan" saat diekspor. */
    public function export(DashboardQueryRequest $request, DashboardService $summary, AttentionService $attention): StreamedResponse
    {
        $this->authorizeDashboard($request);
        $period = $request->input('period', 'today');
        $s = $summary->summary($period);
        $a = $attention->items($request->user());

        return response()->streamDownload(function () use ($s, $a, $period) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['computed_at', $s['computed_at']]);
            fputcsv($out, ['period', $period]);
            fputcsv($out, ['range', $s['range']['from'].' .. '.$s['range']['to']]);
            fputcsv($out, ['comparison_basis', $s['comparison']['basis']]);
            fputcsv($out, []);
            fputcsv($out, ['kpi', 'value', 'comparison', 'change']);
            foreach ($s['kpis'] as $name => $k) {
                fputcsv($out, [$name, $k['value'], $k['comparison'], $k['change_pct'] ?? $k['change_abs'] ?? '']);
            }
            fputcsv($out, []);
            foreach ($s['kpis'] as $name => $k) {
                fputcsv($out, ["trend:{$name}", 'point', 'value', 'comparison']);
                foreach ($k['series'] as $i => $pt) {
                    fputcsv($out, ['', $pt['t'], $pt['v'], $k['comparison_series'][$i]['v'] ?? '']);
                }
            }
            fputcsv($out, []);
            fputcsv($out, ['attention', 'severity', 'count', 'amount']);
            foreach ($a['items'] as $item) {
                fputcsv($out, [$item['kind'], $item['severity'], $item['count'], $item['amount'] ?? '']);
            }
            fclose($out);
        }, 'dashboard-'.$period.'-'.BusinessDay::today().'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    private function authorizeDashboard(DashboardQueryRequest $request): void
    {
        abort_unless($request->user()->canAccessMenu('dashboard'), 403, __('reports.not_authorized_generic'));
    }
}
