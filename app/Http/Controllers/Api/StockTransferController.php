<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialStockMovement;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Services\OutletMenuService;
use App\Services\StockTransferService;
use App\Support\OutletContext;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 045 Stage 6 — transfer stok antar outlet (contracts/api-stock-transfers.md). Controller tipis: aturan di
 * StockTransferService. Gerbang berlapis, semuanya di server: menu `stock`, izin aksi (`stock.transfer.*`), dan
 * penugasan outlet (asal untuk kirim/batal, tujuan untuk terima; admin semua). Transfer di luar jangkauan → 404.
 */
class StockTransferController extends Controller
{
    public function __construct(private StockTransferService $service, private OutletContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->gate($request);

        $query = $this->scoped()->with(['fromOutlet:id,code,name', 'toOutlet:id,code,name', 'dispatcher:id,name', 'receiver:id,name', 'canceller:id,name'])->withCount('lines');
        $outletIds = array_filter(array_map('intval', (array) $request->query('outlet_id', [])));

        $query
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('discrepancy'), fn ($q) => $q->where('has_discrepancy', true))
            ->when($outletIds !== [], fn ($q) => $q->where(fn ($w) => $w->whereIn('from_outlet_id', $outletIds)->orWhereIn('to_outlet_id', $outletIds)))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('dispatched_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('dispatched_at', '<=', $request->date('to')))
            ->orderByDesc('dispatched_at')->orderByDesc('id');

        $page = $query->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (StockTransfer $t) => $this->summary($t, $request))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->gate($request);
        $transfer = $this->scoped()->with(['fromOutlet:id,code,name', 'toOutlet:id,code,name', 'dispatcher:id,name', 'receiver:id,name', 'canceller:id,name', 'lines'])->findOrFail($id);

        return response()->json($this->detail($transfer, $request));
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate($request, PermissionCatalog::TRANSFER_DISPATCH);

        $data = $request->validate([
            'from_outlet_id' => ['required', 'integer', 'exists:outlets,id'],
            'to_outlet_id' => ['required', 'integer', 'exists:outlets,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'client_ref' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.kind' => ['required', 'string'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric'],
        ]);

        // Mengirim hanya dari outlet yang boleh dilihat/dikelola pengirim (admin: semua).
        if (! $this->context->isPermitted((int) $data['from_outlet_id'])) {
            return response()->json(['message' => __('outlets.forbidden'), 'code' => 'outlet_forbidden'], 403);
        }

        $result = $this->service->dispatch($request->user(), (int) $data['from_outlet_id'], (int) $data['to_outlet_id'], $data['lines'], $data['note'] ?? null, $data['client_ref'] ?? null);
        $transfer = $result['transfer']->load(['fromOutlet:id,code,name', 'toOutlet:id,code,name', 'dispatcher:id,name', 'lines']);

        return response()->json($this->detail($transfer, $request), $result['replayed'] ? 200 : 201);
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $this->gate($request, PermissionCatalog::TRANSFER_RECEIVE);
        $transfer = $this->scoped()->findOrFail($id);

        if (! $this->context->isEntitled((int) $transfer->to_outlet_id)) {
            return response()->json(['message' => __('outlets.forbidden'), 'code' => 'outlet_forbidden'], 403);
        }

        $data = $request->validate([
            'lines' => ['sometimes', 'array'],
            'lines.*.line_id' => ['required_with:lines', 'integer'],
            'lines.*.qty_received' => ['nullable', 'numeric', 'min:0'],
            'lines.*.reason' => ['nullable', 'string', 'max:255'],
        ]);
        $byLine = collect($data['lines'] ?? [])->mapWithKeys(fn ($l) => [(int) $l['line_id'] => ['qty_received' => $l['qty_received'] ?? null, 'reason' => $l['reason'] ?? null]])
            ->map(fn ($l) => array_filter($l, fn ($v) => $v !== null))->all();

        $done = $this->service->receive($transfer, $request->user(), $byLine)->load(['fromOutlet:id,code,name', 'toOutlet:id,code,name', 'dispatcher:id,name', 'receiver:id,name', 'lines']);

        return response()->json($this->detail($done, $request));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->gate($request, PermissionCatalog::TRANSFER_CANCEL);
        $transfer = $this->scoped()->findOrFail($id);

        if (! $this->context->isEntitled((int) $transfer->from_outlet_id)) {
            return response()->json(['message' => __('outlets.forbidden'), 'code' => 'outlet_forbidden'], 403);
        }

        $done = $this->service->cancel($transfer, $request->user())->load(['fromOutlet:id,code,name', 'toOutlet:id,code,name', 'dispatcher:id,name', 'canceller:id,name', 'lines']);

        return response()->json($this->detail($done, $request));
    }

    /** GET /stock/transfers/discrepancies — baris dengan selisih (yang dikirim − yang diterima). */
    public function discrepancies(Request $request): JsonResponse
    {
        $this->gate($request);
        $ids = $this->scoped()->where('has_discrepancy', true)->pluck('id');
        $lines = StockTransferLine::query()->whereIn('transfer_id', $ids)->whereColumn('qty_received', '<', 'qty_sent')->with('transfer.fromOutlet:id,code,name', 'transfer.toOutlet:id,code,name', 'transfer.receiver:id,name')->orderByDesc('id')->get();
        $names = $this->itemNames($lines);

        return response()->json(['data' => $lines->map(fn (StockTransferLine $l) => [
            'transfer_id' => $l->transfer_id, 'number' => $l->transfer->number,
            'from_outlet' => $this->outletRef($l->transfer->fromOutlet), 'to_outlet' => $this->outletRef($l->transfer->toOutlet),
            'kind' => $l->kind, 'item' => $names["{$l->kind}:{$l->item_id}"] ?? null,
            'qty_sent' => $this->qty($l->qty_sent), 'qty_received' => $this->qty($l->qty_received), 'shortfall' => $this->qty($l->shortfall()),
            'reason' => $l->discrepancy_reason, 'received_by' => $l->transfer->receiver?->name, 'received_at' => $l->transfer->received_at?->toIso8601String(),
        ])->values()]);
    }

    /** GET /stock/in-transit — jumlah yang sedang dalam perjalanan per item dan arah. */
    public function inTransit(Request $request): JsonResponse
    {
        $this->gate($request);
        $outletIds = array_filter(array_map('intval', (array) $request->query('outlet_id', [])));
        $transfers = $this->scoped()->where('status', StockTransfer::IN_TRANSIT)
            ->when($outletIds !== [], fn ($q) => $q->where(fn ($w) => $w->whereIn('from_outlet_id', $outletIds)->orWhereIn('to_outlet_id', $outletIds)))
            ->with(['fromOutlet:id,code,name', 'toOutlet:id,code,name', 'lines'])->get();
        $names = $this->itemNames($transfers->flatMap->lines);

        $rows = [];
        foreach ($transfers as $t) {
            foreach ($t->lines as $l) {
                $key = "{$l->kind}:{$l->item_id}:{$t->from_outlet_id}:{$t->to_outlet_id}";
                $rows[$key] ??= ['kind' => $l->kind, 'item' => $names["{$l->kind}:{$l->item_id}"] ?? null, 'from_outlet' => $this->outletRef($t->fromOutlet), 'to_outlet' => $this->outletRef($t->toOutlet), 'qty' => 0.0, 'transfers' => []];
                $rows[$key]['qty'] += (float) $l->qty_sent;
                $rows[$key]['transfers'][] = $t->number;
            }
        }

        return response()->json(['data' => collect($rows)->map(fn ($r) => $r + [])->map(function ($r) {
            $r['qty'] = $this->qty($r['qty']);

            return $r;
        })->values()]);
    }

    // --- helpers -----------------------------------------------------------------------------------

    /** Menu `stock` (+ izin aksi bila ada); izin dan outlet ditegakkan di server, UI hanya menyembunyikan. */
    private function gate(Request $request, ?string $permission = null): void
    {
        $user = $request->user();
        abort_unless($user->canAccessMenu('stock'), 403, __('stock.not_authorized'));
        if ($permission !== null) {
            abort_unless($user->hasPermission($permission), 403, __('reports.not_authorized_generic'));
        }
    }

    /** Transfer yang salah satu outletnya boleh dilihat pemanggil (dan menurut pilihan X-Outlet-Id). */
    private function scoped()
    {
        $ids = $this->context->entitledIds();
        $ids = $ids === null ? null : ($ids ?: [0]);

        return StockTransfer::query()->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('from_outlet_id', $ids)->orWhereIn('to_outlet_id', $ids)));
    }

    private function qty(mixed $v): ?string
    {
        return $v === null ? null : rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    }

    private function outletRef($o): ?array
    {
        return $o ? ['id' => $o->id, 'code' => $o->code, 'name' => $o->name] : null;
    }

    private function summary(StockTransfer $t, Request $request): array
    {
        return [
            'id' => $t->id, 'number' => $t->number, 'status' => $t->status, 'has_discrepancy' => $t->has_discrepancy, 'note' => $t->note,
            'from_outlet' => $this->outletRef($t->fromOutlet), 'to_outlet' => $this->outletRef($t->toOutlet), 'line_count' => $t->lines_count ?? $t->lines->count(),
            'dispatched_at' => $t->dispatched_at?->toIso8601String(), 'dispatched_by' => $t->dispatcher?->name,
            'received_at' => $t->received_at?->toIso8601String(), 'received_by' => $t->receiver?->name,
            'cancelled_at' => $t->cancelled_at?->toIso8601String(), 'cancelled_by' => $t->canceller?->name,
            // Hak aksi untuk transfer INI dihitung server; UI hanya menampilkan/menyembunyikan.
            'can' => $this->can($t, $request),
        ];
    }

    private function can(StockTransfer $t, Request $request): array
    {
        $user = $request->user();
        $open = $t->status === StockTransfer::IN_TRANSIT;

        return [
            'receive' => $open && $user->hasPermission(PermissionCatalog::TRANSFER_RECEIVE) && $this->context->isEntitled((int) $t->to_outlet_id),
            'cancel' => $open && $user->hasPermission(PermissionCatalog::TRANSFER_CANCEL) && $this->context->isEntitled((int) $t->from_outlet_id),
        ];
    }

    /** @param  \Illuminate\Support\Collection<int, StockTransferLine>  $lines */
    private function itemNames($lines): array
    {
        $variantIds = $lines->where('kind', 'variant')->pluck('item_id')->unique()->all();
        $materialIds = $lines->where('kind', 'material')->pluck('item_id')->unique()->all();
        $names = [];
        foreach (ProductVariant::withTrashed()->with(['product' => fn ($q) => $q->withTrashed()])->whereIn('id', $variantIds)->get() as $v) {
            $names["variant:{$v->id}"] = ['id' => $v->id, 'sku' => $v->sku, 'name' => ($v->product?->name ?? '').' — '.$v->variant_name];
        }
        foreach (Material::withTrashed()->whereIn('id', $materialIds)->get() as $m) {
            $names["material:{$m->id}"] = ['id' => $m->id, 'sku' => $m->code, 'name' => $m->name];
        }

        return $names;
    }

    private function detail(StockTransfer $t, Request $request): array
    {
        $names = $this->itemNames($t->lines);
        $menu = app(OutletMenuService::class);
        $products = ProductVariant::withTrashed()->whereIn('id', $t->lines->where('kind', 'variant')->pluck('item_id'))->pluck('product_id', 'id');

        $lines = $t->lines->map(function (StockTransferLine $l) use ($names, $menu, $products, $t) {
            $warn = $l->kind === 'variant' && isset($products[$l->item_id]) && ! $menu->isCarried((int) $products[$l->item_id], (int) $t->to_outlet_id);

            return [
                'id' => $l->id, 'kind' => $l->kind, 'item' => $names["{$l->kind}:{$l->item_id}"] ?? null,
                'qty_sent' => $this->qty($l->qty_sent), 'qty_received' => $this->qty($l->qty_received), 'shortfall' => $this->qty($l->shortfall()),
                'discrepancy_reason' => $l->discrepancy_reason,
                'warnings' => $warn ? ['not_carried_at_destination'] : [],
            ];
        })->values();

        $ledger = StockMovement::query()->with('outlet:id,code,name')->where('reference_type', 'stock_transfer')->where('reference_id', $t->id)->orderBy('id')->get()
            ->map(fn ($m) => ['kind' => 'variant', 'item_id' => $m->variant_id, 'outlet' => $this->outletRef($m->outlet), 'type' => $m->type, 'qty_change' => $m->qty_change, 'stock_before' => $m->stock_before, 'stock_after' => $m->stock_after, 'at' => $m->created_at])
            ->concat(MaterialStockMovement::query()->with('outlet:id,code,name')->where('reference_type', 'stock_transfer')->where('reference_id', $t->id)->orderBy('id')->get()
                ->map(fn ($m) => ['kind' => 'material', 'item_id' => $m->material_id, 'outlet' => $this->outletRef($m->outlet), 'type' => $m->type, 'qty_change' => $this->qty($m->qty_change), 'stock_before' => $this->qty($m->stock_before), 'stock_after' => $this->qty($m->stock_after), 'at' => $m->created_at]));

        return $this->summary($t, $request) + [
            'lines' => $lines,
            'steps' => array_values(array_filter([
                ['step' => 'dispatched', 'by' => $t->dispatcher?->name, 'at' => $t->dispatched_at?->toIso8601String()],
                $t->received_at ? ['step' => 'received', 'by' => $t->receiver?->name, 'at' => $t->received_at->toIso8601String()] : null,
                $t->cancelled_at ? ['step' => 'cancelled', 'by' => $t->canceller?->name, 'at' => $t->cancelled_at->toIso8601String()] : null,
            ])),
            'ledger' => $ledger->values(),
        ];
    }
}
