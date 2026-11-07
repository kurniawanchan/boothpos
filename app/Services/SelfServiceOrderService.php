<?php

namespace App\Services;

use App\Exceptions\ShiftRuleException;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\PaymentChannel;
use App\Models\PaymentProof;
use App\Models\ProductVariant;
use App\Models\SelfServiceOrder;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\MenuAvailability;
use App\Support\PhoneNormalizer;
use App\Support\ShiftSource;
use App\Support\TaxCalculator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 090 — SATU-SATUNYA penulis `self_service_orders` (Konstitusi I). Kiriman pelanggan DITAHAN di entitas ini; baris
 * `orders`, stok, pembayaran, dan tiket dapur baru dibuat saat staf memverifikasi lalu mengirim ke dapur (research D1/D3),
 * lewat layanan yang sudah ada, bukan jalur tulis baru.
 *
 * Harga, pajak, dan total SELALU dihitung di sini dari data server (MenuAvailability, PriceService, ModifierResolver,
 * TaxCalculator). Isi permintaan publik tidak pernah dipercaya untuk uang (Konstitusi IV), dan hasilnya disimpan sebagai
 * potret yang tidak ditulis ulang dari data master.
 */
class SelfServiceOrderService
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private ModifierResolver $modifiers,
        private PriceService $prices,
        private TabService $tabs,
        private KitchenService $kitchen,
        private TabSettlementService $settlement,
        private OverrideGate $gate,
    ) {}

    /**
     * Kanal QRIS yang ditampilkan ke pelanggan: aktif, bertipe `qr_ewallet`, dan sudah punya gambar. null = tidak ada
     * (pesan mandiri tidak bisa dilanjutkan ke pembayaran). Dipakai konteks publik dan kirim, satu aturan.
     */
    public static function activeQrisChannel(): ?PaymentChannel
    {
        return PaymentChannel::query()
            ->where('type', 'qr_ewallet')
            ->where('is_active', true)
            ->whereNotNull('qr_image_path')
            ->where('qr_image_path', '!=', '')
            ->orderBy('display_order')->orderBy('id')
            ->first();
    }

    /**
     * Menerima kiriman pelanggan. Konteks outlet SUDAH terikat ke outlet token oleh `self-service.table`.
     *
     * @param  array{local_ref:string, service_type:string, proof_token:string, items:list<array<string,mixed>>}  $data
     * @return array{order: SelfServiceOrder, replayed: bool}
     *
     * @throws ShiftRuleException `qris_unavailable` / `item_unavailable` (409)
     * @throws ValidationException bukti tidak sah/terpakai, pilihan modifier melanggar aturan
     */
    public function submit(DiningTable $table, array $data): array
    {
        // Idempotensi: UNIQUE `local_ref` berlaku lintas mode/outlet, jadi dicari tanpa global scope. Kiriman ulang dari meja
        // yang sama mengembalikan yang pertama; local_ref milik meja lain bukan retry dan tidak boleh membuka struknya.
        $existing = SelfServiceOrder::withoutGlobalScopes()->where('local_ref', $data['local_ref'])->first();
        if ($existing !== null) {
            if ((int) $existing->table_id !== (int) $table->id) {
                throw ValidationException::withMessages(['local_ref' => __('self_service.local_ref_taken')]);
            }

            return ['order' => $existing, 'replayed' => true];
        }

        $channel = self::activeQrisChannel();
        if ($channel === null) {
            throw new ShiftRuleException(__('self_service.qris_unavailable'), 'qris_unavailable', [], 409);
        }

        $proof = $this->claimableProof($data['proof_token']);
        $outletId = (int) $table->outlet_id;
        [$lines, $subtotal] = $this->priceLines($data['items'], $outletId);

        $tax = TaxCalculator::compute($subtotal);
        $total = round($subtotal + $tax['tax'], 2);
        $customer = $this->customerFrom($data['customer'] ?? null);

        try {
            $order = DB::transaction(function () use ($table, $data, $channel, $proof, $lines, $subtotal, $tax, $total, $outletId, $customer) {
                $order = $this->insertWithReference($outletId, [
                    'receipt_token' => Str::random(40),
                    'local_ref' => $data['local_ref'],
                    'outlet_id' => $outletId,
                    'table_id' => $table->id,
                    'table_name_snapshot' => $table->name,
                    'service_type' => $data['service_type'],
                    'status' => SelfServiceOrder::AWAITING,
                    'lines' => $lines,
                    'subtotal' => $subtotal,
                    'tax_amount' => $tax['tax'],
                    'tax_rate_snapshot' => $tax['rate'],
                    'total_amount' => $total,
                    'customer_name' => $customer['name'],
                    'customer_phone' => $customer['phone'],
                    'customer_email' => $customer['email'],
                    'payment_channel_id' => $channel->id,
                    'proof_id' => $proof->id,
                ]);

                // Log di transaksi yang sama (Konstitusi IV); pengguna null = pelanggan publik; TANPA data pribadi.
                $this->activityLogger->log(
                    userId: null,
                    action: 'self_service_submitted',
                    entityType: 'SelfServiceOrder',
                    entityId: $order->id,
                    description: sprintf('Pesanan mandiri %s dari %s', $order->reference_no, $table->name),
                    newValues: ['reference_no' => $order->reference_no, 'table' => $table->name, 'total_amount' => $total, 'item_count' => count($lines)],
                );

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Dua kiriman bersamaan memakai bukti/local_ref yang sama: yang kedua kalah di indeks UNIQUE database.
            if (str_contains($e->getMessage(), 'uniq_self_service_proof')) {
                throw ValidationException::withMessages(['proof_token' => __('self_service.proof_used')]);
            }
            if (str_contains($e->getMessage(), 'local_ref')) {
                $winner = SelfServiceOrder::withoutGlobalScopes()->where('local_ref', $data['local_ref'])->first();
                if ($winner !== null && (int) $winner->table_id === (int) $table->id) {
                    return ['order' => $winner, 'replayed' => true];
                }
            }
            throw $e;
        }

        return ['order' => $order, 'replayed' => false];
    }

    // --- tindakan staf ---------------------------------------------------------------------------------------------

    /**
     * Verifikasi pembayaran: staf sudah memeriksa foto bukti dan mutasi QRIS. Belum membuat order/stok/tiket.
     * Aturan "siapa boleh" sama dengan verifikasi QRIS di tab (izin `payment.qris.verify` lewat OverrideGate::holds);
     * jalur persetujuan penyelia untuk kasir tanpa izin tidak ikut (keputusan MVP, research D4).
     */
    public function verify(SelfServiceOrder $submission, string $reference, User $user): SelfServiceOrder
    {
        return DB::transaction(function () use ($submission, $reference, $user) {
            $row = $this->lock($submission);
            $this->assertMayJudge($user);

            if ($row->status !== SelfServiceOrder::AWAITING) {
                throw $this->invalidTransition($row);
            }
            $reference = trim($reference);
            if (mb_strlen($reference) < 4) {
                throw ValidationException::withMessages(['reference' => __('self_service.reference_min')]);
            }

            $row->forceFill(['status' => SelfServiceOrder::VERIFIED, 'verified_by' => $user->id, 'verified_at' => now(), 'verify_reference' => mb_substr($reference, 0, 100)])->save();

            $this->activityLogger->log(
                userId: $user->id,
                action: 'self_service_verified',
                entityType: 'SelfServiceOrder',
                entityId: $row->id,
                description: sprintf('Memverifikasi pembayaran pesanan mandiri %s', $row->reference_no),
                oldValues: ['status' => SelfServiceOrder::AWAITING],
                newValues: ['status' => SelfServiceOrder::VERIFIED],
            );

            return $row->refresh();
        });
    }

    /**
     * Tolak: bukti tidak valid. Final. Tidak membuat order, tidak menyentuh stok, tidak ada tiket dapur (FR-019) —
     * karena order memang belum ada.
     */
    public function reject(SelfServiceOrder $submission, string $reason, User $user): SelfServiceOrder
    {
        return DB::transaction(function () use ($submission, $reason, $user) {
            $row = $this->lock($submission);
            $this->assertMayJudge($user);

            if (! $row->isPending()) {
                throw $this->invalidTransition($row);
            }
            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => __('self_service.reject_reason_required')]);
            }

            $old = $row->status;
            $row->forceFill(['status' => SelfServiceOrder::REJECTED, 'rejected_by' => $user->id, 'rejected_at' => now(), 'reject_reason' => mb_substr($reason, 0, 255)])->save();

            $this->activityLogger->log(
                userId: $user->id,
                action: 'self_service_rejected',
                entityType: 'SelfServiceOrder',
                entityId: $row->id,
                description: sprintf('Menolak pesanan mandiri %s', $row->reference_no),
                oldValues: ['status' => $old],
                newValues: ['status' => SelfServiceOrder::REJECTED, 'reason' => mb_substr($reason, 0, 255)],
            );

            return $row->refresh();
        });
    }

    /**
     * "Kirim ke dapur": SATU transaksi yang mengubah kiriman tertahan menjadi order nyata lewat layanan yang sudah ada
     * (research D3) — tidak ada jalur tulis baru untuk stok, pembayaran, atau tiket (Konstitusi I):
     *
     *   order `open` tanpa meja → addLine (ketersediaan, harga outlet, modifier, STOK) → total harus sama dengan yang
     *   dibayar pelanggan → tiket dapur → pelunasan QRIS terverifikasi (completed, bahan modifier) → isi `table_id`.
     *
     * Gagal di langkah mana pun membatalkan SEMUANYA (stok kembali), dan kiriman tetap `verified` agar bisa dicoba lagi
     * atau ditolak.
     *
     * @return array{submission: SelfServiceOrder, order: Order, tickets: int}
     *
     * @throws ShiftRuleException `invalid_transition`, `no_open_shift`, `item_unavailable`, `total_changed` (409)
     */
    public function dispatch(SelfServiceOrder $submission, User $user): array
    {
        // 092 — transaksi terluar yang membaca data terbaru (READ COMMITTED): `addLine`/`settle` di dalamnya menjalankan
        // penjaga kapasitas bahan, yang harus melihat baris tab lain yang baru di-commit.
        return app(MenuStockService::class)->transaction(function () use ($submission, $user) {
            $row = $this->lock($submission);

            if ($row->status !== SelfServiceOrder::VERIFIED) {
                throw $this->invalidTransition($row);
            }

            // Order wajib punya shift; shift dicari lewat SATU-SATUNYA pencari (ShiftSource) dan harus di outlet pesanan.
            $session = ShiftSource::openFor($user, request(), lock: true);
            if ($session === null || (int) $session->outlet_id !== (int) $row->outlet_id) {
                throw new ShiftRuleException(__('self_service.no_open_shift'), 'no_open_shift', [], 409);
            }

            $this->assertLinesOrderable($row);

            $order = $this->tabs->openForSelfService($row, $session, $user, $this->customerIdFor($row));
            try {
                foreach ($row->lines as $line) {
                    $order = $this->tabs->addLine($order, array_filter([
                        'variant_id' => (int) $line['variant_id'],
                        'qty' => (int) $line['qty'],
                        'note' => $line['note'] ?? null,
                        'modifier_selections' => ($line['modifier_selections'] ?? []) !== [] ? $line['modifier_selections'] : null,
                    ], fn ($v) => $v !== null), $user);
                }
            } catch (ValidationException $e) {
                // Pilihan modifier/varian berubah sejak kirim (mis. opsi dinonaktifkan): tak bisa dibangun ulang apa adanya.
                throw new ShiftRuleException(__('self_service.item_unavailable'), 'item_unavailable', ['items' => []], 409);
            }

            // Pelanggan membayar nominal yang ia lihat; selisih karena harga/pajak berubah TIDAK pernah ditagih diam-diam.
            if (abs((float) $order->total_amount - (float) $row->total_amount) > 0.005) {
                throw new ShiftRuleException(__('self_service.total_changed'), 'total_changed', [
                    'paid' => number_format((float) $row->total_amount, 2, '.', ''), 'current' => number_format((float) $order->total_amount, 2, '.', ''),
                ], 409);
            }

            $this->kitchen->send($order, $user);

            $order = $this->settlement->settle($order->fresh(), [
                'client_ref' => (string) Str::uuid(),
                'payments' => [[
                    'method' => 'qr_ewallet',
                    'channel_id' => $row->payment_channel_id,
                    'amount' => (float) $row->total_amount,
                    'proof_token' => $row->proof->proof_token,
                    'reference' => $row->verify_reference,
                    'notes' => 'Pesanan mandiri '.$row->reference_no,
                ]],
            ], $user);

            // Jejak audit: pemverifikasi sebenarnya (settle() hanya menandai `verified`, tanpa siapa dan kapan).
            $order->payments()->firstOrFail()->forceFill(['verified_by' => $row->verified_by, 'verified_at' => $row->verified_at])->save();

            // Baru sekarang meja diisi: order sudah `completed`, jadi indeks "satu order open per meja" tidak berlaku.
            $order = Order::findOrFail($order->id);
            $order->forceFill(['table_id' => $row->table_id])->save();

            $row->forceFill(['status' => SelfServiceOrder::DISPATCHED, 'order_id' => $order->id, 'dispatched_by' => $user->id, 'dispatched_at' => now()])->save();

            $this->activityLogger->log(
                userId: $user->id,
                action: 'self_service_dispatched',
                entityType: 'SelfServiceOrder',
                entityId: $row->id,
                description: sprintf('Mengirim pesanan mandiri %s ke dapur (order %s)', $row->reference_no, $order->order_number),
                oldValues: ['status' => SelfServiceOrder::VERIFIED],
                newValues: ['status' => SelfServiceOrder::DISPATCHED, 'order_id' => $order->id],
            );

            return ['submission' => $row->refresh(), 'order' => $order->refresh(), 'tickets' => KitchenTicket::where('order_id', $order->id)->count()];
        });
    }

    /**
     * Data diri dari halaman publik → nilai bersih. Disimpan DI ENTITAS (bukan `customers`): rute publik tidak boleh menulis
     * atau mengenali data master (mencegah enumerasi pelanggan). Telepon disimpan dalam bentuk kanonik.
     *
     * @return array{name: ?string, phone: ?string, email: ?string}
     */
    private function customerFrom(?array $raw): array
    {
        $name = trim((string) ($raw['name'] ?? ''));
        $phone = PhoneNormalizer::canonical($raw['phone'] ?? null);
        $email = trim((string) ($raw['email'] ?? ''));
        if ($name === '' && $phone === null && $email === '') {
            return ['name' => null, 'phone' => null, 'email' => null];
        }

        return ['name' => $name !== '' ? $name : null, 'phone' => $phone, 'email' => $email !== '' ? mb_strtolower($email) : null];
    }

    /**
     * Pelanggan ditautkan saat dispatch (staf yang bertindak): dicocokkan lewat telepon kanonik, dibuat bila belum ada.
     * Pelanggan lama TIDAK ditimpa (nama/surel yang sudah tersimpan menang). Tamu → null.
     */
    private function customerIdFor(SelfServiceOrder $row): ?int
    {
        if ($row->customer_phone === null) {
            return null;
        }

        // Nomor lama tersimpan apa adanya ("0812 3456 7890", "+62…"), jadi kandidat dicari lewat 8 digit terakhir pada kolom yang
        // sudah dibuang non-angkanya, lalu dibandingkan bentuk kanoniknya di PHP.
        $tail = substr($row->customer_phone, -8);
        $existing = Customer::query()->whereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ['%'.$tail])->get()
            ->first(fn (Customer $c) => PhoneNormalizer::canonical($c->phone) === $row->customer_phone);

        return ($existing ?? Customer::create([
            'name' => $row->customer_name ?? $row->customer_phone, 'phone' => $row->customer_phone, 'email' => $row->customer_email,
        ]))->id;
    }

    /** Semua item masih bisa dipesan di outlet pesanan, sebelum order apa pun dibuat. */
    private function assertLinesOrderable(SelfServiceOrder $row): void
    {
        $variants = ProductVariant::query()->with('product')->whereIn('id', collect($row->lines)->pluck('variant_id')->unique())->get()->keyBy('id');

        $unavailable = [];
        foreach ($row->lines as $line) {
            $variant = $variants->get((int) $line['variant_id']);
            $reason = $variant === null ? 'not_found' : MenuAvailability::unavailableReason($variant, (int) $row->outlet_id);
            if ($reason !== null) {
                $unavailable[(int) $line['variant_id']] = ['variant_id' => (int) $line['variant_id'], 'name' => $line['name'], 'reason' => $reason];
            }
        }

        if ($unavailable !== []) {
            throw new ShiftRuleException(__('self_service.item_unavailable'), 'item_unavailable', ['items' => array_values($unavailable)], 409);
        }
    }

    /** Hanya pemegang izin verifikasi QRIS yang boleh menilai bukti (memverifikasi/menolak). 403 dengan kode mesin. */
    private function assertMayJudge(User $user): void
    {
        if (! $this->gate->holds($user, 'qris_verify')) {
            throw new ShiftRuleException(__('self_service.verify_not_allowed'), 'verify_not_allowed', [], 403);
        }
    }

    private function lock(SelfServiceOrder $submission): SelfServiceOrder
    {
        // Query ber-scope (mode + outlet): kiriman outlet lain tidak ditemukan.
        return SelfServiceOrder::query()->lockForUpdate()->findOrFail($submission->id);
    }

    private function invalidTransition(SelfServiceOrder $row): ShiftRuleException
    {
        return new ShiftRuleException(__('self_service.invalid_transition', ['status' => __('self_service.status_'.$row->status)]), 'invalid_transition', ['status' => $row->status], 409);
    }

    /** Bukti yang boleh dipakai pelanggan publik: diunggah tanpa pengguna, belum tertaut pembayaran/kiriman lain. */
    private function claimableProof(string $token): PaymentProof
    {
        $proof = PaymentProof::query()->where('proof_token', $token)->whereNull('payment_id')->whereNull('uploaded_by')->whereNull('superseded_at')->first();

        if ($proof === null) {
            throw ValidationException::withMessages(['proof_token' => __('self_service.proof_invalid')]);
        }
        if (SelfServiceOrder::withoutGlobalScopes()->where('proof_id', $proof->id)->exists()) {
            throw ValidationException::withMessages(['proof_token' => __('self_service.proof_used')]);
        }

        return $proof;
    }

    /**
     * Harga tiap baris dari server. Semua item bermasalah dikumpulkan dan dilaporkan sekaligus (409) supaya pelanggan
     * memperbaiki keranjang sekali jalan, tanpa menghapus yang lain.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: list<array<string, mixed>>, 1: float} potret baris dan subtotal
     */
    private function priceLines(array $items, int $outletId): array
    {
        $variants = ProductVariant::query()->with('product')->whereIn('id', collect($items)->pluck('variant_id')->unique())->get()->keyBy('id');

        $unavailable = [];
        foreach ($items as $item) {
            $variant = $variants->get((int) $item['variant_id']);
            $reason = $variant === null ? 'not_found' : MenuAvailability::unavailableReason($variant, $outletId);
            if ($reason !== null) {
                $unavailable[(int) $item['variant_id']] = ['variant_id' => (int) $item['variant_id'], 'reason' => $reason];
            }
        }
        if ($unavailable !== []) {
            throw new ShiftRuleException(__('self_service.item_unavailable'), 'item_unavailable', ['items' => array_values($unavailable)], 409);
        }

        // 092 FR-013 — menu beresep yang bahannya tidak cukup ditolak SEBELUM pelanggan membayar (penyaring awal;
        // pemeriksaan penentu ada saat dispatch). Respons publik tidak memuat nama bahan (renderer RecipeStockException).
        $qtyByVariant = [];
        foreach ($items as $item) {
            $qtyByVariant[(int) $item['variant_id']] = ($qtyByVariant[(int) $item['variant_id']] ?? 0) + (int) $item['qty'];
        }
        app(MenuStockService::class)->assertQuantitiesAvailable($qtyByVariant, $outletId, $variants->map(fn (ProductVariant $v) => $v->product?->name ?? '')->all());

        $lines = [];
        $subtotal = 0.0;
        foreach ($items as $item) {
            /** @var ProductVariant $variant */
            $variant = $variants->get((int) $item['variant_id']);
            // Harga outlet QR (bukan harga dasar) — resolver modifier lalu memakai harga itu sebagai dasar.
            $this->prices->applyOutletPrice($variant, $outletId);
            $resolved = $this->modifiers->resolve($variant, $this->modifiers->selectionFrom($item) ?? []);

            $qty = (int) $item['qty'];
            $lineTotal = round($resolved['unit_price'] * $qty, 2);
            $subtotal += $lineTotal;

            $selection = $this->modifiers->selectionFrom($item) ?? [];
            $lines[] = [
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'name' => $variant->lineName(),
                'qty' => $qty,
                'unit_price' => number_format($resolved['unit_price'], 2, '.', ''),
                'line_total' => number_format($lineTotal, 2, '.', ''),
                'note' => isset($item['note']) && trim((string) $item['note']) !== '' ? trim((string) $item['note']) : null,
                // Dipakai dispatch untuk membangun ulang baris lewat TabService::addLine; `modifiers` = potret tampilan struk.
                'modifier_selections' => array_map(fn ($id, $q) => ['option_id' => (int) $id, 'qty' => (int) $q], array_keys($selection), array_values($selection)),
                'modifiers' => $resolved['snapshot'],
            ];
        }

        return [$lines, round($subtotal, 2)];
    }

    /**
     * Menyimpan dengan nomor mandiri `SS-YYMMDD-NNN` (urut per outlet per hari bisnis). UNIQUE
     * `(outlet_id, business_date, reference_no)` menjamin tak ada kembar; bentrok dua kiriman bersamaan dicoba ulang.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insertWithReference(int $outletId, array $attributes): SelfServiceOrder
    {
        $businessDate = BusinessDay::dateFor(now());
        $prefix = 'SS-'.date('ymd', strtotime($businessDate)).'-';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $last = SelfServiceOrder::withoutGlobalScopes()
                ->where('outlet_id', $outletId)->where('business_date', $businessDate)
                ->orderByDesc('id')->value('reference_no');
            $next = $last === null ? 1 : ((int) preg_replace('/^.*-/', '', $last)) + 1;

            $order = new SelfServiceOrder;
            $order->forceFill($attributes + [
                'business_date' => $businessDate,
                'reference_no' => $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT),
            ]);

            try {
                // Savepoint: bentrok nomor tidak boleh membatalkan transaksi luar.
                DB::transaction(fn () => $order->save());

                return $order;
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'uniq_self_service_reference')) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Gagal membuat nomor pesanan mandiri setelah beberapa percobaan.');
    }
}
