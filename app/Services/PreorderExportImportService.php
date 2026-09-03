<?php

namespace App\Services;

use App\Imports\PreorderImport;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Preorder;
use App\Models\ProductVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 007-preorder-import-export-notify (US3) — export/import pre-order lewat
 * SATU workbook terpisah (bukan sheet kelima di MasterDataSheets::ORDER —
 * pre-order adalah data transaksional, bukan master data toko, research.md
 * R3). Meniru dua konvensi MasterDataImportService yang paling penting:
 * validasi PENUH dulu baru terapkan sekaligus (all-or-nothing), dan
 * dry_run melewati jalur validasi yang identik untuk pratinjau.
 *
 * Satu baris berkas = satu ITEM. Baris-baris berurutan dengan
 * `customer_name` KOSONG dianggap kelanjutan pesanan pada baris terakhir
 * yang punya `customer_name` terisi (data-model.md) — konvensi pengarsipan
 * spreadsheet yang umum, meniru pola pengelompokan baris multi-item yang
 * sama seperti banyak sheet Excel lain.
 */
class PreorderExportImportService
{
    private const HEADINGS = [
        'customer_name', 'customer_phone', 'customer_email',
        'event_id', 'fulfillment', 'sku', 'qty', 'unit_price', 'notes',
    ];

    public function __construct(private PreorderService $preorderService) {}

    public function export(array $filters): array
    {
        $query = Preorder::query()
            ->with('customer')
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['event_id']), fn ($q) => $q->where('event_id', $filters['event_id']))
            ->when(! empty($filters['customer_id']), fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(! empty($filters['fulfillment']), fn ($q) => $q->where('fulfillment', $filters['fulfillment']))
            ->when(! empty($filters['search']), fn ($q) => $q->whereHas(
                'customer',
                fn ($cq) => $cq->where('name', 'like', '%'.$filters['search'].'%')
            ))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->orderByDesc('created_at');

        return $query->get()->map(fn (Preorder $p) => [
            'preorder_number' => $p->preorder_number,
            'customer_name' => $p->customer?->name,
            'status' => $p->status,
            'fulfillment' => $p->fulfillment,
            'total_amount' => number_format((float) $p->total_amount, 2, '.', ''),
            'paid_amount' => number_format((float) $p->paid_amount, 2, '.', ''),
            'created_at' => $p->created_at?->toDateTimeString(),
        ])->all();
    }

    public function template(): array
    {
        return [array_combine(self::HEADINGS, self::HEADINGS)];
    }

    /**
     * @return array{applied: bool, dry_run: bool, created_count: int, created_customer_count: int, preorder_ids: int[], row_errors: array}
     */
    public function import(UploadedFile $file, bool $dryRun, \App\Models\User $importedBy): array
    {
        $rows = Excel::toArray(new PreorderImport, $file)[0] ?? [];
        $groups = $this->groupRows($rows);

        $rowErrors = [];
        $validated = [];

        foreach ($groups as $group) {
            $errors = [];
            $customerName = trim((string) ($group['customer_name'] ?? ''));

            if ($customerName === '') {
                $errors[] = __('preorders.import_customer_name_required');
            }

            if (! empty($group['event_id']) && ! Event::where('id', $group['event_id'])->exists()) {
                $errors[] = __('preorders.import_event_not_found', ['id' => $group['event_id']]);
            }

            $items = [];
            foreach ($group['items'] as $item) {
                $sku = trim((string) ($item['sku'] ?? ''));
                $qty = (int) ($item['qty'] ?? 0);
                $unitPrice = (float) ($item['unit_price'] ?? 0);

                if ($sku === '') {
                    $errors[] = __('preorders.import_sku_required', ['row' => $item['row']]);

                    continue;
                }

                $variant = ProductVariant::with('product')->where('sku', $sku)->first();
                if (! $variant) {
                    $errors[] = __('preorders.import_sku_not_found', ['row' => $item['row'], 'sku' => $sku]);

                    continue;
                }

                if ($qty < 1) {
                    $errors[] = __('preorders.import_qty_invalid', ['row' => $item['row']]);

                    continue;
                }

                $items[] = ['variant' => $variant, 'qty' => $qty, 'unit_price' => $unitPrice, 'line_total' => $qty * $unitPrice];
            }

            if ($items === []) {
                $errors[] = __('preorders.import_no_items');
            }

            if ($errors !== []) {
                $rowErrors[] = ['row' => $group['first_row'], 'errors' => $errors];

                continue;
            }

            $validated[] = [
                'customer_name' => $customerName,
                'customer_phone' => trim((string) ($group['customer_phone'] ?? '')) ?: null,
                'customer_email' => trim((string) ($group['customer_email'] ?? '')) ?: null,
                'event_id' => $group['event_id'] ?: null,
                'fulfillment' => in_array($group['fulfillment'] ?? '', ['pickup', 'courier'], true) ? $group['fulfillment'] : 'pickup',
                'notes' => $group['notes'] ?? null,
                'items' => $items,
            ];
        }

        if ($rowErrors !== []) {
            return [
                'applied' => false, 'dry_run' => $dryRun,
                'created_count' => 0, 'created_customer_count' => 0, 'preorder_ids' => [],
                'row_errors' => $rowErrors,
            ];
        }

        if ($dryRun) {
            return [
                'applied' => false, 'dry_run' => true,
                'created_count' => count($validated), 'created_customer_count' => 0, 'preorder_ids' => [],
                'row_errors' => [],
            ];
        }

        $preorderIds = [];
        $createdCustomerCount = 0;

        DB::transaction(function () use ($validated, $importedBy, &$preorderIds, &$createdCustomerCount) {
            foreach ($validated as $order) {
                $customer = Customer::where('name', $order['customer_name'])->first();
                if (! $customer) {
                    $customer = Customer::create([
                        'name' => $order['customer_name'],
                        'phone' => $order['customer_phone'],
                        'email' => $order['customer_email'],
                    ]);
                    $createdCustomerCount++;
                }

                $subtotal = array_sum(array_column($order['items'], 'line_total'));

                // 007-preorder-import-export-notify (FR-010) — SELALU
                // 'ordered', paid_amount 0, apa pun data di berkas. Harga
                // baris DIPAKAI APA ADANYA (bukan dihitung ulang dari
                // ProductVariant::sell_price saat ini) — pengecualian
                // disengaja terhadap "server selalu menghitung ulang",
                // karena ini mencatat transaksi historis yang sudah
                // terjadi di luar sistem (research.md R4), bukan checkout
                // baru — jadi dibuat langsung dengan Preorder::create(),
                // BUKAN lewat PreorderService::create() (yang men-charge
                // ulang dari master data).
                $preorder = Preorder::create([
                    'preorder_number' => $this->preorderService->generateNumber(),
                    'event_id' => $order['event_id'],
                    'customer_id' => $customer->id,
                    'user_id' => $importedBy->id,
                    'status' => 'ordered',
                    'fulfillment' => $order['fulfillment'],
                    'subtotal' => $subtotal,
                    'shipping_cost' => 0,
                    'total_amount' => $subtotal,
                    'paid_amount' => 0,
                    'notes' => $order['notes'],
                ]);

                foreach ($order['items'] as $item) {
                    $variant = $item['variant'];
                    $preorder->items()->create([
                        'variant_id' => $variant->id,
                        'artist_id' => $variant->product->artist_id,
                        'sku_snapshot' => $variant->sku,
                        'name_snapshot' => $variant->product->name.' — '.$variant->variant_name,
                        'qty' => $item['qty'],
                        'cost_price' => $variant->cost_price,
                        'sell_price' => $item['unit_price'],
                        'line_total' => $item['line_total'],
                    ]);
                }

                $preorderIds[] = $preorder->id;
            }
        });

        return [
            'applied' => true, 'dry_run' => false,
            'created_count' => count($preorderIds), 'created_customer_count' => $createdCustomerCount,
            'preorder_ids' => $preorderIds, 'row_errors' => [],
        ];
    }

    /**
     * @return array<int, array{customer_name: ?string, customer_phone: ?string, customer_email: ?string, event_id: ?int, fulfillment: ?string, notes: ?string, first_row: int, items: array}>
     */
    private function groupRows(array $rows): array
    {
        $groups = [];
        $currentIndex = -1;

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 2; // +1 for 0-index, +1 for the heading row itself
            $customerName = trim((string) ($row['customer_name'] ?? ''));

            if ($customerName !== '' || $currentIndex === -1) {
                $groups[] = [
                    'customer_name' => $customerName,
                    'customer_phone' => $row['customer_phone'] ?? null,
                    'customer_email' => $row['customer_email'] ?? null,
                    'event_id' => $row['event_id'] ?? null,
                    'fulfillment' => $row['fulfillment'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'first_row' => $rowNumber,
                    'items' => [],
                ];
                $currentIndex++;
            }

            $groups[$currentIndex]['items'][] = ['sku' => $row['sku'] ?? null, 'qty' => $row['qty'] ?? null, 'unit_price' => $row['unit_price'] ?? null, 'row' => $rowNumber];
        }

        return $groups;
    }
}
