<?php

namespace App\Services;

use App\Imports\PreorderImport;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Preorder;
use App\Models\ProductVariant;
use App\Support\Couriers;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 022-preorder-invoice-crud-overhaul (US7, FR-017/FR-018) — layout
 * ONE-ROW-PER-ORDER, MENGGANTIKAN SELURUHNYA format row-per-item milik
 * feature 007 (resolved Question 3 = full replace, tidak ada dua format
 * paralel). `event_id` diganti `event_name` (dicocokkan lewat nama, bukan
 * ID), `customer_phone`/`customer_email` dihapus, `fulfillment` menerima
 * kata "pickup"/"mail order" (bukan enum internal `pickup`/`courier`),
 * `pickup_day` ditulis sebagai teks "Day N" (diselesaikan ke tanggal asli
 * lewat rentang tanggal event yang cocok — sama seperti resolvePickupDay
 * AndCourier() di PreorderService, hanya berbeda BENTUK inputnya di sini),
 * dan item/qty/harga satuan pesanan ditulis sebagai TIGA kolom
 * comma-separated yang dicocokkan berdasarkan POSISI
 * (products[i] ↔ quantities[i] ↔ unit_prices[i]).
 *
 * Tetap meniru dua konvensi MasterDataImportService: validasi PENUH dulu
 * baru terapkan sekaligus (all-or-nothing), dan dry_run melewati jalur
 * validasi yang identik untuk pratinjau. export() memakai kolom yang SAMA
 * PERSIS dengan template()/HEADINGS, supaya file hasil ekspor bisa
 * diimpor balik apa adanya (round-trip, SC-005).
 */
class PreorderExportImportService
{
    private const HEADINGS = [
        'customer_name', 'event_name', 'fulfillment', 'pickup_day',
        'products', 'quantities', 'unit_prices',
        'shipping_cost', 'courier_name', 'expected_date', 'discount', 'notes',
    ];

    public function __construct(private PreorderService $preorderService) {}

    public function export(array $filters): array
    {
        $query = Preorder::query()
            ->with(['customer', 'event', 'items'])
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
            'customer_name' => $p->customer?->name,
            'event_name' => $p->event?->name,
            'fulfillment' => $p->fulfillment === 'courier' ? 'mail order' : 'pickup',
            'pickup_day' => $this->pickupDayToLabel($p),
            'products' => $p->items->pluck('sku_snapshot')->implode(','),
            'quantities' => $p->items->pluck('qty')->implode(','),
            'unit_prices' => $p->items->map(fn ($i) => number_format((float) $i->sell_price, 2, '.', ''))->implode(','),
            'shipping_cost' => number_format((float) $p->shipping_cost, 2, '.', ''),
            'courier_name' => $p->courier_name,
            'expected_date' => $p->expected_date?->toDateString(),
            'discount' => number_format((float) $p->discount, 2, '.', ''),
            'notes' => $p->notes,
        ])->all();
    }

    /**
     * research.md Decision 7 — arah kebalikan dari resolvePickupDayLabel():
     * tanggal asli yang sudah tersimpan diubah kembali jadi label
     * "Day N" relatif terhadap tanggal mulai event, supaya export bisa
     * diimpor ulang apa adanya (round-trip).
     */
    private function pickupDayToLabel(Preorder $p): ?string
    {
        if (! $p->pickup_day || ! $p->event) {
            return null;
        }

        $dayNumber = $p->event->start_date->diffInDays($p->pickup_day) + 1;

        return "Day {$dayNumber}";
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

        $rowErrors = [];
        $validated = [];

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 2; // +1 for 0-index, +1 for the heading row itself
            $errors = [];

            $customerName = trim((string) ($row['customer_name'] ?? ''));
            if ($customerName === '') {
                $errors[] = __('preorders.import_customer_name_required');
            }

            $eventName = trim((string) ($row['event_name'] ?? ''));
            $eventId = null;
            if ($eventName !== '') {
                $matches = Event::where('name', $eventName)->get();
                if ($matches->count() === 0) {
                    $errors[] = __('preorders.import_event_name_not_found', ['row' => $rowNumber, 'name' => $eventName]);
                } elseif ($matches->count() > 1) {
                    $errors[] = __('preorders.import_event_name_ambiguous', ['row' => $rowNumber, 'name' => $eventName]);
                } else {
                    $eventId = $matches->first()->id;
                }
            }

            $fulfillmentInput = strtolower(trim((string) ($row['fulfillment'] ?? '')));
            $fulfillment = match ($fulfillmentInput) {
                'pickup' => 'pickup',
                'mail order' => 'courier',
                default => null,
            };
            if ($fulfillment === null) {
                $errors[] = __('preorders.import_fulfillment_invalid', ['row' => $rowNumber]);
                $fulfillment = 'pickup'; // fallback so the rest of the row can still be validated
            }

            // FR-018 — products/quantities/unit_prices dicocokkan lewat
            // POSISI; jumlah yang berbeda ditolak sebagai galat baris,
            // bukan dipotong/ditebak diam-diam.
            $products = $this->splitCsv($row['products'] ?? null);
            $quantities = $this->splitCsv($row['quantities'] ?? null);
            $unitPrices = $this->splitCsv($row['unit_prices'] ?? null);

            $items = [];
            if ($products === []) {
                $errors[] = __('preorders.import_no_items', ['row' => $rowNumber]);
            } elseif (count($products) !== count($quantities) || count($products) !== count($unitPrices)) {
                $errors[] = __('preorders.import_products_quantities_mismatch', ['row' => $rowNumber]);
            } else {
                foreach ($products as $idx => $sku) {
                    $sku = trim($sku);
                    $qty = (int) trim($quantities[$idx]);
                    $unitPrice = (float) trim($unitPrices[$idx]);

                    if ($sku === '') {
                        $errors[] = __('preorders.import_sku_required', ['row' => $rowNumber]);

                        continue;
                    }

                    $variant = ProductVariant::with('product')->where('sku', $sku)->first();
                    if (! $variant) {
                        $errors[] = __('preorders.import_sku_not_found', ['row' => $rowNumber, 'sku' => $sku]);

                        continue;
                    }

                    if ($qty < 1) {
                        $errors[] = __('preorders.import_qty_invalid', ['row' => $rowNumber]);

                        continue;
                    }

                    $items[] = ['variant' => $variant, 'qty' => $qty, 'unit_price' => $unitPrice, 'line_total' => $qty * $unitPrice];
                }
            }

            $subtotal = array_sum(array_column($items, 'line_total'));

            $discountInput = $row['discount'] ?? null;
            $discount = ($discountInput === null || $discountInput === '') ? 0.0 : (float) $discountInput;
            if ($discount < 0 || $discount > $subtotal) {
                $errors[] = __('preorders.import_discount_invalid', ['row' => $rowNumber]);
            }

            // research.md Decision 7 — sama persis dengan aturan cross-field
            // PreorderService::resolvePickupDayAndCourier(), hanya bentuk
            // input pickup_day-nya "Day N" (bukan tanggal asli).
            $pickupDayInput = trim((string) ($row['pickup_day'] ?? ''));
            $pickupDayInput = $pickupDayInput !== '' ? $pickupDayInput : null;
            $courierInput = trim((string) ($row['courier_name'] ?? ''));
            $courierInput = $courierInput !== '' ? $courierInput : null;
            $pickupDay = null;
            $courierName = null;

            if ($fulfillment === 'courier') {
                if ($pickupDayInput !== null) {
                    $errors[] = __('preorders.import_pickup_day_not_applicable', ['row' => $rowNumber]);
                }
                if ($courierInput !== null && ! in_array($courierInput, Couriers::OPTIONS, true)) {
                    $errors[] = __('preorders.import_courier_unknown', ['row' => $rowNumber, 'courier' => $courierInput]);
                } else {
                    $courierName = $courierInput ?: Couriers::DEFAULT;
                }
            } else { // pickup
                if ($courierInput !== null) {
                    $errors[] = __('preorders.import_courier_not_applicable', ['row' => $rowNumber]);
                }
                if ($pickupDayInput !== null) {
                    $event = $eventId ? Event::find($eventId) : null;
                    if (! $event) {
                        $errors[] = __('preorders.import_pickup_day_requires_event', ['row' => $rowNumber]);
                    } else {
                        $dayNumber = $this->parseDayLabel($pickupDayInput);
                        if ($dayNumber === null) {
                            $errors[] = __('preorders.import_pickup_day_invalid_format', ['row' => $rowNumber]);
                        } else {
                            $pickupDay = $event->start_date->copy()->addDays($dayNumber - 1)->toDateString();
                            if ($pickupDay > $event->end_date->toDateString()) {
                                $errors[] = __('preorders.import_pickup_day_out_of_range', ['row' => $rowNumber]);
                                $pickupDay = null;
                            }
                        }
                    }
                }
            }

            if ($errors !== []) {
                $rowErrors[] = ['row' => $rowNumber, 'errors' => $errors];

                continue;
            }

            $validated[] = [
                'customer_name' => $customerName,
                'event_id' => $eventId,
                'fulfillment' => $fulfillment,
                'shipping_cost' => (float) ($row['shipping_cost'] ?? 0),
                'discount' => $discount,
                'pickup_day' => $pickupDay,
                'courier_name' => $courierName,
                'expected_date' => trim((string) ($row['expected_date'] ?? '')) ?: null,
                'notes' => $row['notes'] ?? null,
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
                    $customer = Customer::create(['name' => $order['customer_name']]);
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
                    'shipping_cost' => $order['shipping_cost'],
                    'discount' => $order['discount'],
                    'total_amount' => $subtotal + $order['shipping_cost'] - $order['discount'],
                    'pickup_day' => $order['pickup_day'],
                    'courier_name' => $order['courier_name'],
                    'expected_date' => $order['expected_date'],
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
     * @return string[]
     */
    private function splitCsv(mixed $value): array
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return [];
        }

        return array_map('trim', explode(',', $value));
    }

    /**
     * "Day 1" / "day 2" / "Day  3" → 1 / 2 / 3; null kalau bentuknya tidak
     * dikenali (research.md Decision 7).
     */
    private function parseDayLabel(string $label): ?int
    {
        if (preg_match('/^day\s*(\d+)$/i', $label, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }
}
