<?php

namespace App\Services\CustomerCommerce;

use App\Exceptions\CustomerCommerceException;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\CustomerOrder;
use App\Models\ProductVariant;
use App\Support\CustomerOrderNumber;
use App\Support\ReportSplit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer commerce (CONTRACT §8) — quote dan pembuatan pesanan pelanggan.
 * Controller hanya memvalidasi bentuk, mendelegasikan ke sini, dan membentuk
 * respons.
 *
 * Yang TIDAK PERNAH dipercaya dari klien: harga, subtotal/total, status,
 * pemilik pesanan, kepemilikan alamat, ketersediaan barang. Semuanya dihitung
 * ulang di sini dari basis data:
 *  - harga dari `product_variants.sell_price`;
 *  - kelayakan barang dari ProductVariant::scopeStorefrontSellable() (satu
 *    definisi yang sama dengan etalase);
 *  - alamat lewat `$account->addresses()` (bukan rule `exists:`, yang
 *    menembus batas akun dan batas DEMO/LIVE);
 *  - pemilik dari token (`$account`), mode data dari akunnya.
 *
 * STOK TIDAK BERGERAK saat pesanan dibuat. Checkout hanya MEMERIKSA stok di
 * bawah kunci baris varian; pengurangan terjadi saat staf mengonfirmasi
 * (StockService::applyMovement di CustomerOrderTransitionService). Akibat
 * yang disengaja: dua pesanan yang sama-sama menunggu konfirmasi bisa lolos
 * untuk stok yang sama, dan konfirmasi kedua akan ditolak staf.
 *
 * Uang dihitung dalam sen (integer) lewat ReportSplit supaya tidak terkena
 * jebakan float.
 */
class CustomerCheckoutService
{
    public const REASON_UNAVAILABLE = 'unavailable';

    public const REASON_INSUFFICIENT_STOCK = 'insufficient_stock';

    public const REASON_QTY_LIMIT = 'qty_limit';

    /** Percobaan insert pesanan (hanya berulang bila nomor pesanan acak bertabrakan). */
    private const INSERT_ATTEMPTS = 3;

    public function __construct(private CustomerOrderTransitionService $transitions) {}

    /**
     * Hitungan server atas keranjang, TANPA efek samping (tanpa kunci, tanpa
     * tulis). Telepon kontak tidak dituntut di sini: quote hanya menghargai
     * keranjang; kelengkapan kontak diperiksa saat pesanan dibuat.
     *
     * @param  array<string, mixed>  $payload  hasil validasi QuoteOrderRequest
     * @return array<string, mixed> bentuk `Quote` kontrak
     *
     * @throws ValidationException 422 alamat bukan milik akun
     * @throws CustomerCommerceException 409 `cart_invalid`
     */
    public function quote(CustomerAccount $account, array $payload): array
    {
        $canonical = $this->canonical($payload);

        $this->resolveAddress($account, $canonical);

        return $this->priceCart($canonical, lock: false)['quote'];
    }

    /**
     * Membuat pesanan `awaiting_confirmation`, idempoten per (akun, kunci).
     *
     * Satu transaksi, urutannya penting:
     *  1. Kunci baris akun. Semua checkout akun ini mengantre di sini, jadi
     *     dua ketukan "Pesan" yang bersamaan diproses satu per satu.
     *  2. Cari (akun, kunci). Sudah ada + isi sama → kembalikan pesanan ASLI
     *     ($replayed = true, tanpa baris baru, tanpa memeriksa ulang
     *     stok/harga); isi berbeda → 409 `idempotency_key_reused`. Pencarian
     *     selalu lewat akun: kunci milik pelanggan lain tidak terlihat.
     *  3. Alamat (milik akun) dan kontak.
     *  4. Kunci varian (lockForUpdate, id menaik supaya dua checkout tidak
     *     saling mengunci), periksa kelayakan dan stok, hitung harga.
     *  5. Bandingkan dengan `expected_total` → 409 `price_changed` + quote baru.
     *  6. Tulis pesanan, item, lalu peristiwa status pertama lewat
     *     CustomerOrderTransitionService. Tidak ada pergerakan stok.
     *
     * @param  array<string, mixed>  $payload  hasil validasi PlaceOrderRequest
     * @param  bool|null  $replayed  diisi true bila yang dikembalikan pesanan lama (controller menjawab 200, bukan 201)
     *
     * @throws ValidationException 422 alamat bukan milik akun / telepon kontak kosong
     * @throws CustomerCommerceException 409 `idempotency_key_reused`, `cart_invalid`, `price_changed`
     */
    public function place(CustomerAccount $account, array $payload, string $idempotencyKey, ?bool &$replayed = null): CustomerOrder
    {
        $replayed = false;

        $canonical = $this->canonical($payload);
        $hash = $this->payloadHash($canonical);
        $expectedTotal = ReportSplit::cents($payload['expected_total'] ?? null);

        $order = DB::transaction(function () use ($account, $canonical, $hash, $expectedTotal, $idempotencyKey, &$replayed) {
            // Closure ini diulang bila terkena deadlock (lihat akhir transaksi): hanya `$replayed` yang
            // dimiliki dari luar, jadi dikembalikan ke keadaan awal di setiap percobaan.
            $replayed = false;

            // Lewat model (DataModeScope): akun dari mode data lain tidak ditemukan, dan itu memang harus gagal.
            $locked = CustomerAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            $existing = $this->findByKey($locked, $idempotencyKey);

            if ($existing !== null) {
                $replayed = true;

                return $this->replay($existing, $hash);
            }

            $address = $this->resolveAddress($account, $canonical);
            $contact = $this->resolveContact($account, $canonical);
            $cart = $this->priceCart($canonical, lock: true);

            if ($cart['total_cents'] !== $expectedTotal) {
                throw CustomerCommerceException::make('price_changed', 409, ['quote' => $cart['quote']]);
            }

            for ($attempt = 1; ; $attempt++) {
                try {
                    $order = $this->insertOrder($locked, $canonical, $contact, $address, $cart, $idempotencyKey, $hash);

                    break;
                } catch (UniqueConstraintViolationException $e) {
                    // Insert pesanan adalah tulis PERTAMA transaksi ini, jadi tidak ada yang perlu digulung:
                    // MySQL hanya membatalkan pernyataan yang gagal dan transaksinya tetap bisa dipakai.
                    //
                    // (a) Indeks (akun, kunci): pesanan dengan kunci yang sama muncul setelah pencarian di
                    //     atas (permintaan kembar yang lolos dari kunci baris akun). Indeks unik adalah jaring
                    //     terakhir; jawabannya harus replay yang sama, bukan 500. Pembacaan MENGUNCI supaya
                    //     melihat baris yang baru di-commit transaksi lain (bukan snapshot transaksi ini).
                    $rival = $this->findByKey($locked, $idempotencyKey, lockingRead: true);

                    if ($rival !== null) {
                        $replayed = true;

                        return $this->replay($rival, $hash);
                    }

                    // (b) Indeks nomor pesanan: dua transaksi memilih nomor acak yang sama. Coba nomor lain.
                    if ($attempt >= self::INSERT_ATTEMPTS) {
                        throw $e;
                    }
                }
            }

            $order->items()->createMany($cart['items']);

            $this->transitions->recordPlaced($order);

            return $order;
        }, CustomerOrderTransitionService::DEADLOCK_ATTEMPTS); // mengunci baris varian: ulangi bila deadlock dengan penjualan POS

        return $order->load(['items', 'shipment', 'statusEvents']);
    }

    /**
     * Bentuk kanonis muatan: hanya field kontrak, teks di-trim, kosong = null,
     * angka sebagai integer. `address_id` hanya bermakna untuk kurir (untuk
     * ambil di tempat diabaikan), dan urutan baris dipertahankan untuk respons.
     *
     * @param  array<string, mixed>  $payload
     * @return array{fulfilment_method: string, address_id: ?int, contact: array{name: ?string, phone: ?string}, items: list<array{variant_id: int, qty: int}>, notes: ?string}
     */
    private function canonical(array $payload): array
    {
        $method = (string) $payload['fulfilment_method'];

        $items = [];
        foreach ((array) $payload['items'] as $line) {
            $items[] = ['variant_id' => (int) $line['variant_id'], 'qty' => (int) $line['qty']];
        }

        // FormRequest sudah menolak varian kembar; dijaga lagi di sini karena baris kembar akan lolos
        // dari pemeriksaan stok per baris (2 + 2 dari stok 3).
        if (count(array_unique(array_column($items, 'variant_id'))) !== count($items)) {
            throw ValidationException::withMessages(['items' => [__('customer_commerce.checkout.duplicate_variant')]]);
        }

        return [
            'fulfilment_method' => $method,
            'address_id' => $method === CustomerOrder::FULFILMENT_COURIER ? (int) ($payload['address_id'] ?? 0) : null,
            'contact' => [
                'name' => $this->text($payload['contact']['name'] ?? null),
                'phone' => $this->text($payload['contact']['phone'] ?? null),
            ],
            'items' => $items,
            'notes' => $this->text($payload['notes'] ?? null),
        ];
    }

    /**
     * SHA-256 muatan kanonis: metode, alamat, kontak, baris (diurutkan per
     * varian), catatan. `expected_total` SENGAJA tidak ikut: ia tebakan
     * klien atas hasil, bukan isi pesanan — percobaan ulang setelah harga
     * berubah tetap permintaan yang sama.
     *
     * @param  array<string, mixed>  $canonical
     */
    private function payloadHash(array $canonical): string
    {
        usort($canonical['items'], fn (array $a, array $b) => $a['variant_id'] <=> $b['variant_id']);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Pesanan akun ini dengan kunci idempotensi itu. SELALU lewat relasi
     * akun: pencarian global akan membiarkan satu pelanggan menyelidiki
     * kunci milik pelanggan lain.
     */
    private function findByKey(CustomerAccount $account, string $idempotencyKey, bool $lockingRead = false): ?CustomerOrder
    {
        $query = $account->orders()->where('idempotency_key', $idempotencyKey);

        return ($lockingRead ? $query->lockForUpdate() : $query)->first();
    }

    private function replay(CustomerOrder $existing, string $hash): CustomerOrder
    {
        if (! hash_equals((string) $existing->idempotency_hash, $hash)) {
            throw CustomerCommerceException::make('idempotency_key_reused', 409);
        }

        return $existing;
    }

    /**
     * Alamat kirim untuk pesanan kurir, dicari lewat pemiliknya. Alamat yang
     * tidak ada dan alamat milik akun lain dijawab dengan galat field yang
     * SAMA, supaya keberadaan alamat orang lain tidak terbaca.
     *
     * @param  array<string, mixed>  $canonical
     */
    private function resolveAddress(CustomerAccount $account, array $canonical): ?CustomerAddress
    {
        if ($canonical['fulfilment_method'] !== CustomerOrder::FULFILMENT_COURIER) {
            return null;
        }

        return $account->addresses()->whereKey($canonical['address_id'])->first()
            ?? throw ValidationException::withMessages(['address_id' => [__('customer_commerce.checkout.address_invalid')]]);
    }

    /**
     * Kontak pesanan: isian `contact` bila ada, selain itu profil. E-mail
     * selalu milik akun (tidak bisa dikirim klien). Telepon wajib ada di
     * hasil akhir: toko harus bisa menghubungi pemesan.
     *
     * @param  array<string, mixed>  $canonical
     * @return array{name: string, phone: string, email: string}
     */
    private function resolveContact(CustomerAccount $account, array $canonical): array
    {
        $name = $canonical['contact']['name'] ?? $this->text($account->customer?->name);
        $phone = $canonical['contact']['phone'] ?? $this->text($account->customer?->phone);

        $errors = [];
        if ($name === null) {
            $errors['contact.name'] = [__('customer_commerce.checkout.contact_name_required')];
        }
        if ($phone === null) {
            $errors['contact.phone'] = [__('customer_commerce.checkout.contact_phone_required')];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['name' => $name, 'phone' => $phone, 'email' => (string) $account->email];
    }

    /**
     * Memeriksa setiap baris keranjang terhadap varian LAYAK JUAL dan
     * menghitung harganya dari `sell_price`.
     *
     * Dengan $lock, baris varian dikunci (FOR UPDATE) dalam urutan id menaik;
     * syarat produknya subquery EXISTS, jadi hanya baris varian yang
     * terkunci. Varian yang tidak lolos scope (tidak ada, terhapus, mode data
     * lain, nonaktif, belum terbit, stok 0, produknya tersembunyi) dijawab
     * `unavailable` tanpa pembedaan, supaya keberadaannya tidak terbaca.
     *
     * @param  array<string, mixed>  $canonical
     * @return array{quote: array<string, mixed>, items: list<array<string, mixed>>, total_cents: int}
     *
     * @throws CustomerCommerceException 409 `cart_invalid` dengan `errors.items`
     */
    private function priceCart(array $canonical, bool $lock): array
    {
        $ids = array_column($canonical['items'], 'variant_id');

        $query = ProductVariant::query()->storefrontSellable()->whereKey($ids)->orderBy('id')->with('product');

        $variants = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');

        $perLineLimit = (int) config('customer_commerce.max_qty_per_line');
        $problems = [];
        $lines = [];
        $items = [];
        $subtotal = 0;

        foreach ($canonical['items'] as ['variant_id' => $variantId, 'qty' => $qty]) {
            /** @var ProductVariant|null $variant */
            $variant = $variants->get($variantId);

            if ($variant === null || $variant->product === null) {
                $problems[] = ['variant_id' => $variantId, 'reason' => self::REASON_UNAVAILABLE, 'max_qty' => 0];

                continue;
            }

            if ($qty > $perLineLimit) {
                $problems[] = ['variant_id' => $variantId, 'reason' => self::REASON_QTY_LIMIT, 'max_qty' => $variant->storefrontMaxQty()];

                continue;
            }

            if ($qty > (int) $variant->current_stock) {
                $problems[] = ['variant_id' => $variantId, 'reason' => self::REASON_INSUFFICIENT_STOCK, 'max_qty' => $variant->storefrontMaxQty()];

                continue;
            }

            $unit = ReportSplit::cents($variant->sell_price);
            $lineTotal = $unit * $qty;
            $subtotal += $lineTotal;
            $name = $variant->product->name.' — '.$variant->variant_name;

            $lines[] = [
                'variant_id' => $variant->id,
                'product_slug' => $variant->product->slug,
                'name' => $name,
                'image_url' => $variant->image_url, // accessor: gambar varian, lalu gambar produk (relasi sudah dimuat)
                'unit_price' => ReportSplit::money($unit),
                'qty' => $qty,
                'line_total' => ReportSplit::money($lineTotal),
                'max_qty' => $variant->storefrontMaxQty(),
            ];

            $items[] = [
                'variant_id' => $variant->id,
                'sku_snapshot' => $variant->sku,
                'name_snapshot' => $name,
                'unit_price' => ReportSplit::money($unit),
                'qty' => $qty,
                'line_total' => ReportSplit::money($lineTotal),
            ];
        }

        if ($problems !== []) {
            throw CustomerCommerceException::make('cart_invalid', 409, ['items' => $problems]);
        }

        // Tidak ada tarif ongkir otomatis di v1: untuk kurir, toko menetapkan ongkir saat mengonfirmasi.
        $shippingCost = 0;
        $total = $subtotal + $shippingCost;

        return [
            'quote' => [
                'fulfilment_method' => $canonical['fulfilment_method'],
                'lines' => $lines,
                'subtotal' => ReportSplit::money($subtotal),
                'shipping_cost' => ReportSplit::money($shippingCost),
                'shipping_cost_pending' => $canonical['fulfilment_method'] === CustomerOrder::FULFILMENT_COURIER,
                'total' => ReportSplit::money($total),
            ],
            'items' => $items,
            'total_cents' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @param  array{name: string, phone: string, email: string}  $contact
     * @param  array<string, mixed>  $cart
     */
    private function insertOrder(
        CustomerAccount $account,
        array $canonical,
        array $contact,
        ?CustomerAddress $address,
        array $cart,
        string $idempotencyKey,
        string $hash,
    ): CustomerOrder {
        $order = new CustomerOrder([
            'order_number' => CustomerOrderNumber::generate(),
            'customer_account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'fulfilment_method' => $canonical['fulfilment_method'],
            'status' => CustomerOrderTransitionService::INITIAL_STATUS,
            'contact_name' => $contact['name'],
            'contact_phone' => $contact['phone'],
            'contact_email' => $contact['email'],
            'shipping_address' => $address?->toSnapshot(),
            'subtotal' => $cart['quote']['subtotal'],
            'shipping_cost' => $cart['quote']['shipping_cost'],
            'total' => $cart['quote']['total'],
            'notes' => $canonical['notes'],
            'idempotency_key' => $idempotencyKey,
            'idempotency_hash' => $hash,
        ]);

        // Mode pesanan = mode akunnya, eksplisit: keduanya harus selalu berada di mode yang sama.
        $order->data_mode = $account->data_mode;
        $order->save();

        return $order;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
