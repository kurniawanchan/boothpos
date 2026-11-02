<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MaterialReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 088 — tab Laporan → Bahan. Pengendali hanya memvalidasi dan membentuk respons; semua angka dari MaterialReport
 * (satu sumber bersama ekspor dan Dashboard). Gerbang: menu `reports` (pemegangnya melihat rupiah, research D6).
 */
class MaterialReportController extends Controller
{
    public function __construct(private MaterialReport $report) {}

    public function position(Request $request): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized_generic')], 403);
        }
        $params = $this->positionParams($request);
        $result = $this->report->positions($params);

        return response()->json([
            'data' => $result['rows'],
            'summary' => $result['summary'],
            'params' => array_intersect_key($params, array_flip(['window_days', 'risk_days', 'target_days'])),
            'computed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * 088 US2 — saran terpilih → draf PO per vendor (MaterialPurchaseDrafts). Gerbang: menu `reports` DAN izin membuat PO
     * (PurchaseOrderPolicy::create). 201 bila ada yang dibuat (baris yang ditolak dilaporkan); semua ditolak → 422.
     */
    public function purchaseDrafts(Request $request, \App\Services\MaterialPurchaseDrafts $drafts): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports') || ! $request->user()->can('create', \App\Models\PurchaseOrder::class)) {
            return response()->json(['message' => __('reports.not_authorized_generic')], 403);
        }
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.material_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001', 'max:999999'],
        ]);

        $result = $drafts->create($data['lines'], $request->user());
        if ($result['created'] === []) {
            return response()->json(['message' => __('reports.materials_drafts_none'), 'refused' => $result['refused']], 422);
        }

        return response()->json($result, 201);
    }

    /** 088 US4 — pemakaian per periode tanggal bisnis (bawaan 7 hari terakhir). */
    public function usage(Request $request): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized_generic')], 403);
        }
        $extra = $request->validate([
            'kind' => ['sometimes', 'array'],
            'kind.*' => ['string', 'in:ingredient,semi_finished,packaging'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->report->usage($this->period($request) + $extra + ['outlet_ids' => $this->outletIds($request)]));
    }

    /** 088 US4 — menu yang memakai satu bahan di periode. */
    public function usageByMenu(Request $request, \App\Models\Material $material): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized_generic')], 403);
        }
        $p = $this->period($request);

        return response()->json(['data' => $this->report->usageByMenu($material, $p['date_from'], $p['date_to'], $this->outletIds($request))]);
    }

    /**
     * 088 US5 — ekspor tiga sheet (posisi & risiko, saran pembelian, pemakaian periode) dari MaterialReport yang sama
     * dengan layar dan saringan yang sama. Teks literal, bukan formula (SafeSheetArrayExport).
     */
    public function export(Request $request)
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized_generic')], 403);
        }
        $positions = $this->report->positions($this->positionParams($request))['rows'];
        $usage = $this->report->usage($this->period($request) + array_intersect_key($this->positionParams($request), array_flip(['kind', 'search', 'outlet_ids'])))['data'];
        $num = fn ($v) => $v === null ? null : (float) $v;

        $positionRows = array_map(fn ($r) => [
            'code' => $r['code'], 'name' => $r['name'], 'outlet' => $r['outlet_code'], 'kind' => $r['kind'], 'unit' => $r['unit'],
            'on_hand' => (float) $r['on_hand'], 'daily_usage' => $num($r['daily_usage']), 'days_left' => $r['days_left'], 'status' => $r['status'],
            'options_at_risk' => implode('; ', array_map(fn ($o) => "{$o['group']}: {$o['option']} ({$o['portions']})", $r['at_risk_options'])),
            'products_at_risk' => implode('; ', array_column($r['at_risk_products'], 'name')),
            'unit_cost' => $num($r['unit_cost']), 'stock_value' => $num($r['stock_value']),
        ], $positions);
        $suggestionRows = array_values(array_map(fn ($r) => [
            'code' => $r['code'], 'name' => $r['name'], 'outlet' => $r['outlet_code'], 'unit' => $r['unit'],
            'qty' => (float) $r['suggestion']['qty'], 'vendor' => $r['suggestion']['vendor'],
            'unit_price' => $num($r['suggestion']['unit_price']), 'cost' => $num($r['suggestion']['cost']),
        ], array_filter($positions, fn ($r) => $r['suggestion'] !== null)));
        $usageRows = array_map(fn ($r) => [
            'code' => $r['code'], 'name' => $r['name'], 'outlet' => $r['outlet_code'], 'unit' => $r['unit'],
            'sold_net' => (float) $r['sold_net'], 'sold_net_value' => $num($r['sold_net_value']), 'wasted' => (float) $r['wasted'], 'wasted_value' => $num($r['wasted_value']),
            'adjusted_in' => (float) $r['adjusted_in'], 'adjusted_out' => (float) $r['adjusted_out'], 'purchased' => (float) $r['purchased'],
            'transferred_in' => (float) $r['transferred_in'], 'transferred_out' => (float) $r['transferred_out'],
        ], $usage);

        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\MultiSheetArrayExport([
            new \App\Exports\SafeSheetArrayExport(__('reports.materials_sheet_position'), ['code', 'name', 'outlet', 'kind', 'unit', 'on_hand', 'daily_usage', 'days_left', 'status', 'options_at_risk', 'products_at_risk', 'unit_cost', 'stock_value'], $positionRows),
            new \App\Exports\SafeSheetArrayExport(__('reports.materials_sheet_suggestions'), ['code', 'name', 'outlet', 'unit', 'qty', 'vendor', 'unit_price', 'cost'], $suggestionRows),
            new \App\Exports\SafeSheetArrayExport(__('reports.materials_sheet_usage'), ['code', 'name', 'outlet', 'unit', 'sold_net', 'sold_net_value', 'wasted', 'wasted_value', 'adjusted_in', 'adjusted_out', 'purchased', 'transferred_in', 'transferred_out'], $usageRows),
        ]), 'laporan-bahan.xlsx');
    }

    /**
     * Periode tanggal bisnis: bawaan 7 hari terakhir s.d. hari ini; `date_from ≤ date_to`, rentang ≤ 366 hari.
     *
     * @return array{date_from: string, date_to: string}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $to = $data['date_to'] ?? \App\Support\BusinessDay::today();
        $from = $data['date_from'] ?? \Carbon\CarbonImmutable::parse($to)->subDays(6)->toDateString();
        if ($from > $to || \Carbon\CarbonImmutable::parse($from)->diffInDays(\Carbon\CarbonImmutable::parse($to)) > 366) {
            throw \Illuminate\Validation\ValidationException::withMessages(['date_from' => [__('validation.before_or_equal', ['attribute' => 'date_from', 'date' => $to])]]);
        }

        return ['date_from' => $from, 'date_to' => $to];
    }

    /** Parameter posisi & risiko (data-model "Aturan validasi"); bawaan 7/3/7. */
    private function positionParams(Request $request): array
    {
        $data = $request->validate([
            'window_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'risk_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'target_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'status' => ['sometimes', 'array'],
            'status.*' => ['string', 'in:negative,out,low,ok,unused'],
            'kind' => ['sometimes', 'array'],
            'kind.*' => ['string', 'in:ingredient,semi_finished,packaging'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return ['window_days' => (int) ($data['window_days'] ?? 7), 'risk_days' => (int) ($data['risk_days'] ?? 3), 'target_days' => (int) ($data['target_days'] ?? 7)]
            + array_intersect_key($data, array_flip(['status', 'kind', 'search']))
            + ['outlet_ids' => $this->outletIds($request)];
    }

    /**
     * Saringan outlet halaman (`outlet_id[]`, catatan 2026-11-02; pola 071). Tanpa nilai = null (tidak menyaring);
     * ada nilai = dipotong dengan outlet yang boleh dilihat pengguna, `[]` bila tak satu pun (hasil kosong, bukan "semua").
     *
     * @return list<int>|null
     */
    private function outletIds(Request $request): ?array
    {
        $data = $request->validate([
            'outlet_id' => ['sometimes', 'array', 'max:100'],
            'outlet_id.*' => ['nullable', 'integer'],
        ]);

        return app(\App\Support\OutletContext::class)->narrowedOrNull($data['outlet_id'] ?? []);
    }
}
