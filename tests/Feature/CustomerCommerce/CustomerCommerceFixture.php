<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Customer commerce — dunia uji bersama untuk seluruh test fitur ini.
 *
 * Aturan yang dijaga di sini supaya tiap test tidak mengulang (dan salah):
 *  - Stok SELALU dibuat lewat StockService::applyMovement() (jenis `initial`),
 *    tidak pernah menulis `current_stock` langsung, supaya riwayat stok cocok.
 *  - Token pelanggan dibuat persis seperti di produksi: nama `customer-app`,
 *    ability `customer`, kedaluwarsa dari config. Test batas keamanan WAJIB
 *    memakai token sungguhan + bearer(), bukan actingAs() (di bawah actingAs
 *    `tokenCan()` selalu false dan guard tidak benar-benar diuji).
 *  - Guard Sanctum meng-cache pengguna selama satu method test; bearer()
 *    memanggil Auth::forgetGuards() tiap kali token diganti.
 *
 * Nama method sengaja tidak memakai put/get/seed/call (bentrok dengan TestCase).
 */
trait CustomerCommerceFixture
{
    /**
     * Etalase kecil di mode data yang sedang aktif:
     *  - `product`  TERBIT, seller & kategori aktif, dengan lima varian:
     *      `variantA`, `variantB`      layak jual (terbit, aktif, stok 10 dan 5)
     *      `outOfStockVariant`         terbit & aktif tetapi stok 0
     *      `inactiveVariant`           terbit & berstok tetapi `is_active = false`
     *      `unpublishedVariant`        aktif & berstok tetapi belum terbit
     *  - `unpublishedProduct` BELUM terbit; variannya (`unpublishedProductVariant`)
     *    sendiri terbit & berstok, jadi hanya status produknya yang menyembunyikannya.
     *
     * @return array<string,mixed>
     */
    protected function buildStorefrontWorld(): array
    {
        $artist = Artist::factory()->create(['name' => 'Seller Etalase', 'is_active' => true]);
        $category = Category::factory()->create(['name' => 'Gantungan Kunci', 'is_active' => true]);

        $product = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'name' => 'Gantungan Kunci Sakura', 'description' => 'Akrilik dua sisi.',
        ]);
        $this->publishForStorefront($product);

        $variant = function (Product $product, string $name, int $price, int $stock, bool $published = true, bool $active = true): ProductVariant {
            $variant = ProductVariant::factory()->create([
                'product_id' => $product->id, 'variant_name' => $name, 'cost_price' => 5000, 'sell_price' => $price,
            ]);

            if ($stock > 0) {
                app(StockService::class)->applyMovement($variant, 'initial', $stock);
            }
            if ($published) {
                $this->publishForStorefront($variant);
            }
            if (! $active) {
                $variant->update(['is_active' => false]);
            }

            return $variant->fresh();
        };

        $unpublishedProduct = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Poster Belum Terbit',
        ]);

        return [
            'artist' => $artist,
            'category' => $category,
            'product' => $product->fresh(),
            'variantA' => $variant($product, 'Merah', 25000, 10),
            'variantB' => $variant($product, 'Biru', 30000, 5),
            'outOfStockVariant' => $variant($product, 'Hijau', 25000, 0),
            'inactiveVariant' => $variant($product, 'Kuning', 25000, 7, active: false),
            'unpublishedVariant' => $variant($product, 'Ungu', 25000, 3, published: false),
            'unpublishedProduct' => $unpublishedProduct->fresh(),
            'unpublishedProductVariant' => $variant($unpublishedProduct, 'Standard', 40000, 4),
        ];
    }

    /**
     * Menerbitkan produk/varian langsung di basis data uji. Jalur staf yang
     * sesungguhnya (POST …/publish) diuji di test-nya sendiri; `published_at`
     * sengaja bukan kolom fillable, jadi di sini diisi lewat forceFill.
     */
    protected function publishForStorefront(Product|ProductVariant $model, mixed $at = null): void
    {
        $model->forceFill(['published_at' => $at ?? now()->subMinute()])->save();
    }

    /**
     * Satu produk TERBIT (seller & kategori aktif) dengan satu varian layak
     * jual, di mode data aktif. Dipakai test yang butuh banyak produk atau
     * satu produk "hampir layak" yang lalu dirusak satu syaratnya.
     *
     * @param  array<string,mixed>  $attrs  atribut products (artist_id/category_id boleh diisi)
     * @return array{0: Product, 1: ProductVariant}
     */
    protected function makeStorefrontProduct(array $attrs = [], int $price = 10000, int $stock = 5): array
    {
        $product = Product::factory()->create($attrs);
        $this->publishForStorefront($product);

        return [$product->fresh(), $this->addStorefrontVariant($product, 'Standard', $price, $stock)];
    }

    /** Varian terbit & aktif; stok (bila > 0) dibuat lewat StockService, bukan tulis langsung. */
    protected function addStorefrontVariant(Product $product, string $name, int $price, int $stock, bool $published = true): ProductVariant
    {
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'variant_name' => $name, 'cost_price' => 5000, 'sell_price' => $price,
        ]);

        if ($stock > 0) {
            app(StockService::class)->applyMovement($variant, 'initial', $stock);
        }
        if ($published) {
            $this->publishForStorefront($variant);
        }

        return $variant->fresh();
    }

    /**
     * Akun pelanggan + baris kontaknya (`customers`) di mode data aktif.
     * Kata sandi bawaan factory adalah nilai uji `password`.
     *
     * @param  array<string,mixed>  $account  atribut customer_accounts
     * @param  array<string,mixed>  $customer  atribut customers
     */
    protected function makeCustomerAccount(array $account = [], array $customer = []): CustomerAccount
    {
        if (! array_key_exists('customer_id', $account)) {
            $account['customer_id'] = Customer::factory()->create($customer + [
                'social_handle' => null, 'notes' => null, 'address' => null, 'artist_id' => null,
            ])->id;
        }

        return CustomerAccount::factory()->create($account);
    }

    /** Token sungguhan persis seperti yang diterbitkan login/registrasi (CONTRACT §1). */
    protected function issueCustomerToken(CustomerAccount $account): string
    {
        return $account->createToken(
            'customer-app',
            ['customer'],
            now()->addMinutes((int) config('customer_commerce.token_ttl_minutes')),
        )->plainTextToken;
    }

    /** Token staf sungguhan (nama dan ability sama dengan AuthController::login). */
    protected function issueStaffToken(User $user): string
    {
        return $user->createToken('pos-session')->plainTextToken;
    }

    /**
     * Memakai $token untuk permintaan berikutnya. Guard dilupakan dulu: tanpa
     * itu permintaan kedua dalam satu method test diam-diam memakai pengguna
     * hasil resolve permintaan pertama (lihat AuthTest::test_logout_revokes_current_token).
     */
    protected function bearer(string $token): static
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /** Melepas token: permintaan berikutnya anonim. */
    protected function withoutBearer(): static
    {
        Auth::forgetGuards();

        return $this->withoutHeader('Authorization');
    }

    /** Akun baru sekaligus tokennya: [akun, token polos]. */
    protected function customerWithToken(array $account = [], array $customer = []): array
    {
        $created = $this->makeCustomerAccount($account, $customer);

        return [$created, $this->issueCustomerToken($created)];
    }

    /**
     * Alamat tersimpan milik $account, langsung lewat factory. Aturan "alamat
     * pertama otomatis utama" milik CustomerAddressService dan diuji lewat
     * API; di sini `is_default` mengikuti $attrs apa adanya.
     *
     * @param  array<string,mixed>  $attrs
     */
    protected function makeCustomerAddress(CustomerAccount $account, array $attrs = []): CustomerAddress
    {
        return CustomerAddress::factory()->create(['customer_account_id' => $account->id] + $attrs);
    }

    /** Kunci idempotensi baru yang sah (16–64 karakter huruf/angka/`_`/`-`). */
    protected function newIdempotencyKey(): string
    {
        return Str::random(32);
    }

    /**
     * Badan POST /customer/orders dan /customer/orders/quote (CONTRACT §8).
     * Bawaan: ambil di tempat, tanpa `contact` (jatuh ke profil), tanpa
     * `expected_total` (isi lewat $overrides saat membuat pesanan).
     *
     * @param  array<int, array{0: int, 1: int}>  $lines  pasangan [variant_id, qty]
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    protected function checkoutBody(array $lines, array $overrides = []): array
    {
        return $overrides + [
            'fulfilment_method' => 'pickup',
            'items' => array_map(fn (array $line) => ['variant_id' => $line[0], 'qty' => $line[1]], $lines),
        ];
    }

    /** POST /customer/orders dengan token sungguhan dan header Idempotency-Key (baru bila tidak diberikan). */
    protected function submitCheckout(string $token, array $body, ?string $idempotencyKey = null): TestResponse
    {
        return $this->bearer($token)
            ->withHeader('Idempotency-Key', $idempotencyKey ?? $this->newIdempotencyKey())
            ->postJson('/api/v1/customer/orders', $body);
    }

    /**
     * Pesanan lewat checkout SUNGGUHAN (POST /customer/orders dengan token
     * pelanggan), jadi snapshot, total, dan peristiwa pertamanya persis
     * seperti produksi. `expected_total` dihitung dari harga jual varian.
     * Saklar fitur dinyalakan bila belum.
     *
     * @param  array<int, array{0: int, 1: int}>  $lines  pasangan [variant_id, qty]
     * @param  array<string,mixed>  $overrides  mis. ['fulfilment_method' => 'courier', 'address_id' => 5]
     */
    protected function placeOrderThroughCheckout(string $token, array $lines, array $overrides = []): CustomerOrder
    {
        $this->enableCustomerCommerce();

        $total = 0;
        foreach ($lines as [$variantId, $qty]) {
            $total += (int) round((float) ProductVariant::findOrFail($variantId)->sell_price * 100) * $qty;
        }

        $response = $this->submitCheckout($token, $this->checkoutBody($lines, $overrides + [
            'expected_total' => number_format($total / 100, 2, '.', ''),
        ]));
        $response->assertCreated();

        return CustomerOrder::where('order_number', $response->json('data.order_number'))->firstOrFail();
    }

    /**
     * Pesanan langsung di basis data pada status tertentu, untuk menyiapkan
     * keadaan awal test transisi tanpa menjalankan seluruh alur. Yang dibuat
     * KONSISTEN dengan status itu: satu item per $lines, `stock_deducted_at` +
     * pergerakan stok `sale` bila statusnya sudah melewati konfirmasi (lewat
     * StockService, bukan tulis langsung), dan baris pengiriman untuk
     * pesanan kurir yang sudah dikirim. Riwayat status sengaja TIDAK dibuat.
     *
     * @param  array<int, array{0: ProductVariant, 1: int}>  $lines
     * @param  array<string,mixed>  $attrs
     */
    protected function makeOrderInStatus(string $status, array $lines, bool $courier = false, array $attrs = []): CustomerOrder
    {
        // Pesanan yang masih menunggu, atau sudah batal, tidak punya stok yang sedang dipotong.
        $deducted = ! in_array($status, [CustomerOrder::STATUS_AWAITING_CONFIRMATION, CustomerOrder::STATUS_CANCELLED], true);

        $factory = CustomerOrder::factory()->status($status);
        $order = ($courier ? $factory->courier() : $factory)->create($attrs + [
            'stock_deducted_at' => $deducted ? now()->subMinutes(5) : null,
            'confirmed_at' => $deducted ? now()->subMinutes(5) : null,
        ]);

        foreach ($lines as [$variant, $qty]) {
            $item = CustomerOrderItem::factory()->create([
                'customer_order_id' => $order->id, 'variant_id' => $variant->id, 'qty' => $qty,
                'sku_snapshot' => $variant->sku, 'unit_price' => $variant->sell_price,
                'line_total' => number_format((float) $variant->sell_price * $qty, 2, '.', ''),
            ]);

            if ($deducted) {
                app(StockService::class)->applyMovement($variant->fresh(), 'sale', -$qty, 'customer_order_item', $item->id);
            }
        }

        if ($courier && in_array($status, [CustomerOrder::STATUS_SHIPPED, CustomerOrder::STATUS_DELIVERED], true)) {
            $order->shipment()->create([
                'courier_name' => 'JNE', 'service_name' => 'REG', 'tracking_number' => 'JNE-0001',
                'shipping_cost' => $order->shipping_cost, 'dispatched_at' => now()->subMinutes(3),
                'delivered_at' => $status === CustomerOrder::STATUS_DELIVERED ? now()->subMinute() : null,
            ]);
        }

        return $order->fresh();
    }

    /**
     * Staf dengan menu `customer_orders` (+ menu lain bila diminta).
     * `$actions = null` berarti semua aksi menu (aturan 057: menu_actions
     * NULL = akses penuh); daftar = hanya aksi itu; [] = hanya lihat.
     *
     * @param  list<string>|null  $actions
     * @param  list<string>  $extraMenus
     * @param  array<string,mixed>  $userAttrs
     */
    protected function fulfilmentStaff(?array $actions = null, array $extraMenus = [], array $userAttrs = []): User
    {
        $role = Role::factory()->create([
            'menu_keys' => array_merge(['customer_orders'], $extraMenus),
            'menu_actions' => $actions === null ? null : ['customer_orders' => $actions],
        ]);

        $user = User::factory()->create($userAttrs);
        $user->update(['role_id' => $role->id]);

        return $user->fresh();
    }

    /** Permintaan berikutnya memakai token STAF sungguhan milik $user (guard dilupakan dulu, lihat bearer()). */
    protected function asStaff(User $user): static
    {
        return $this->bearer($this->issueStaffToken($user));
    }

    /**
     * Saklar fitur (CONTRACT §2): satu baris `settings` untuk seluruh instalasi;
     * baris yang tidak ada berarti MATI. Ditulis lewat model supaya cache
     * Setting::get() ikut dibatalkan.
     */
    protected function enableCustomerCommerce(): void
    {
        $this->switchCustomerCommerce(true);
    }

    protected function disableCustomerCommerce(): void
    {
        $this->switchCustomerCommerce(false);
    }

    private function switchCustomerCommerce(bool $on): void
    {
        Setting::updateOrCreate(
            ['key' => 'customer_commerce_enabled'],
            ['value' => $on ? '1' : '0', 'type' => 'boolean', 'group' => 'customer_commerce'],
        );
    }
}
