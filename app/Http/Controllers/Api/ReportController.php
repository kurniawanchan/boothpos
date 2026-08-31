<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArtistSettlement;
use App\Models\Event;
use App\Services\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function __construct(private SettlementService $settlementService) {}

    public function sales(Request $request): JsonResponse
    {
        $groupBy = $request->string('group_by', 'product')->value();

        $base = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('artists', 'artists.id', '=', 'order_items.artist_id')
            ->where('orders.status', 'completed')
            ->when($request->filled('event_id'), fn ($q) => $q->where('orders.event_id', $request->integer('event_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('orders.created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('orders.created_at', '<=', $request->date('date_to')));

        $labelColumn = match ($groupBy) {
            'category' => DB::raw('products.category_id'), // disederhanakan; join category bila perlu nama
            'artist' => 'artists.name',
            'day' => DB::raw('DATE(orders.created_at)'),
            default => 'products.name',
        };

        $rows = (clone $base)
            ->selectRaw('
                '.($groupBy === 'day' ? 'DATE(orders.created_at)' : ($groupBy === 'artist' ? 'artists.name' : 'products.name')).' as label,
                SUM(order_items.qty) as unit_count,
                SUM(order_items.line_total) as amount
            ')
            ->groupBy('label')
            ->orderByDesc('amount')
            ->get();

        $totals = (clone $base)->selectRaw('
            COUNT(DISTINCT orders.id) as order_count,
            SUM(order_items.qty) as unit_count,
            SUM(order_items.line_total + order_items.discount_amount) as gross_sales,
            SUM(order_items.discount_amount) as discount_total,
            SUM(order_items.line_total) as net_sales
        ')->first();

        $event = $request->filled('event_id') ? Event::find($request->integer('event_id')) : null;

        return response()->json(['event' => $event, 'totals' => $totals, 'rows' => $rows]);
    }

    public function profit(Request $request): JsonResponse
    {
        if (! $request->user()->isOwnerOrAdmin()) {
            return response()->json(['message' => 'Hanya owner/admin yang dapat mengakses laporan ini.'], 403);
        }

        $eventId = $request->validate(['event_id' => ['required', 'integer', 'exists:events,id']])['event_id'];
        $event = Event::findOrFail($eventId);

        $totals = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.event_id', $eventId)
            ->where('orders.status', 'completed')
            ->selectRaw('
                SUM(order_items.line_total) as revenue,
                SUM(order_items.cost_price * order_items.qty) as cost_of_goods
            ')->first();

        $revenue = (float) ($totals->revenue ?? 0);
        $cost = (float) ($totals->cost_of_goods ?? 0);
        $grossProfit = $revenue - $cost;
        $netProfit = $grossProfit - (float) $event->event_cost;

        return response()->json([
            'event' => $event,
            'revenue' => number_format($revenue, 2, '.', ''),
            'cost_of_goods' => number_format($cost, 2, '.', ''),
            'gross_profit' => number_format($grossProfit, 2, '.', ''),
            'event_cost' => number_format((float) $event->event_cost, 2, '.', ''),
            'net_profit' => number_format($netProfit, 2, '.', ''),
        ]);
    }

    public function artistSettlements(Request $request): JsonResponse
    {
        // Sejalan dengan PRD 7.13 (kasir tidak boleh mengakses laporan
        // modal/keuntungan) dan konsisten dengan profit()/
        // recordSettlementPayment() di controller ini — rekap hasil
        // artist memuat payable_amount dan deduction, data komersial yang
        // sama sensitifnya dengan laporan profit, jadi harus digerbang
        // sama, bukan cuma mutasinya (recordSettlementPayment) saja.
        if (! $request->user()->isOwnerOrAdmin()) {
            return response()->json(['message' => 'Hanya owner/admin yang dapat mengakses laporan ini.'], 403);
        }

        $eventId = $request->validate(['event_id' => ['required', 'integer', 'exists:events,id']])['event_id'];
        $event = Event::findOrFail($eventId);

        // Selalu dihitung ulang agar angka live, bukan dibaca mentah dari
        // cache — konsisten dengan keputusan desain SettlementService.
        $this->settlementService->recalculateForEvent($event);

        $settlements = ArtistSettlement::with('artist')->where('event_id', $eventId)->get();

        $data = $settlements->map(fn (ArtistSettlement $s) => [
            'id' => $s->id, 'artist_id' => $s->artist_id, 'artist_name' => $s->artist->name,
            'total_sales' => number_format((float) $s->total_sales, 2, '.', ''),
            'total_units' => $s->total_units,
            'deduction' => number_format((float) $s->deduction, 2, '.', ''),
            'payable_amount' => number_format((float) $s->payable_amount, 2, '.', ''),
            'paid_amount' => number_format((float) $s->paid_amount, 2, '.', ''),
            'outstanding' => number_format((float) $s->payable_amount - (float) $s->paid_amount, 2, '.', ''),
            'status' => $s->status,
        ]);

        return response()->json(['event' => $event, 'data' => $data]);
    }

    public function recordSettlementPayment(Request $request, ArtistSettlement $settlement): JsonResponse
    {
        if (! $request->user()->isOwnerOrAdmin()) {
            return response()->json(['message' => 'Tidak berhak.'], 403);
        }

        $validated = $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);

        $settlement = $this->settlementService->recordPayment($settlement, (float) $validated['amount']);

        return response()->json($settlement);
    }

    /**
     * ASSUMPTION: PRD sengaja tidak menyebut nama pustaka Excel (lihat
     * PRD 9.1). Saya pilih maatwebsite/excel karena ini pustaka Excel
     * Laravel paling mapan — belum diverifikasi jalan di sandbox ini,
     * perlu `composer require maatwebsite/excel` di lingkungan lokal.
     */
    public function export(Request $request, string $report)
    {
        if (! in_array($report, ['sales', 'profit', 'artist-settlements'], true)) {
            return response()->json(['message' => 'Laporan tidak dikenali.'], 404);
        }

        // BUG YANG DITEMUKAN & DIPERBAIKI — match() di bawah sebelumnya
        // tidak punya cabang 'profit' walau route mengizinkan nilai itu
        // (lihat where('report', 'sales|profit|artist-settlements') di
        // routes/api.php). Akibatnya export profit selalu jatuh ke
        // 'default' dan diam-diam menghasilkan file kosong tanpa galat
        // apa pun — tidak ketahuan kecuali membuka isi file-nya.
        [$response, $dataKey, $filename] = match ($report) {
            'sales' => [$this->sales($request), 'rows', 'laporan-penjualan.xlsx'],
            'profit' => [$this->profit($request), null, 'laporan-profit.xlsx'],
            'artist-settlements' => [$this->artistSettlements($request), 'data', 'rekap-artist.xlsx'],
        };

        // profit() dan artistSettlements() menegakkan otorisasinya sendiri
        // (403 untuk kasir). Galat itu WAJIB diteruskan apa adanya, bukan
        // ditelan lalu diam-diam mengekspor berkas kosong — kalau tidak,
        // batasan akses laporan modal/keuntungan (PRD 7.13) bisa dilewati
        // lewat endpoint export ini.
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $payload = json_decode($response->getContent(), true);

        // Laporan profit berbentuk satu objek ringkasan (bukan daftar
        // baris) — dibungkus jadi satu baris supaya tetap kompatibel
        // dengan GenericArrayExport yang mengasumsikan array of rows.
        $rows = $dataKey === null ? [$payload] : $payload[$dataKey];

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\GenericArrayExport($rows),
            $filename
        );
    }
}
