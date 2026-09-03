<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Material;
use App\Models\Order;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\Vendor;
use App\Models\VendorMaterialPrice;
use App\Support\ModeGate;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SakanaFridgeDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 003-seed-demo-live T010 (US1) — memverifikasi bentuk dan idempotensi
 * dataset dummy "Demo Sakana Fridge" (FR-001/FR-002/FR-003).
 */
class SakanaFridgeDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function runSeeders(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(SakanaFridgeDemoSeeder::class);
    }

    public function test_seeder_populates_the_full_expected_dataset(): void
    {
        $this->runSeeders();

        ModeGate::runAs('demo', function () {
            $this->assertSame(1, Event::where('status', 'active')->count());
            $this->assertSame(3, Artist::count());
            $this->assertSame(3, Category::count());
            $this->assertSame(9, Product::count());
            $this->assertSame(27, ProductVariant::count());
            $this->assertTrue(ProductVariant::where('current_stock', '>', 0)->count() === 27);
            $this->assertSame(3, Customer::count());
            $this->assertSame(6, Vendor::count());
            $this->assertGreaterThanOrEqual(8, Material::count());
            $this->assertGreaterThanOrEqual(1, VendorMaterialPrice::where('material_id', Material::where('code', 'MAT-AKR3')->value('id'))->count());
            $this->assertGreaterThanOrEqual(1, ProductVariantBomLine::count());
            $this->assertGreaterThanOrEqual(1, Order::where('status', 'completed')->count());

            $preorderStatuses = Preorder::pluck('status')->unique()->values()->all();
            $this->assertContains('dp_paid', $preorderStatuses);
            $this->assertContains('handed_over', $preorderStatuses);
        });
    }

    public function test_every_seeded_row_is_tagged_demo(): void
    {
        $this->runSeeders();

        $tables = [
            Event::class, Artist::class, Category::class, Product::class, ProductVariant::class,
            Customer::class, Vendor::class, Material::class, VendorMaterialPrice::class,
            ProductVariantBomLine::class, Order::class, Preorder::class,
        ];

        foreach ($tables as $modelClass) {
            $modes = ModeGate::runAs('demo', fn () => $modelClass::query()->pluck('data_mode')->unique()->values()->all());
            $this->assertSame(['demo'], $modes, "{$modelClass} punya baris yang bukan data_mode=demo");
        }
    }

    public function test_running_the_seeder_twice_does_not_duplicate_anything(): void
    {
        $this->runSeeders();

        $counts = ModeGate::runAs('demo', fn () => [
            'events' => Event::count(),
            'artists' => Artist::count(),
            'categories' => Category::count(),
            'products' => Product::count(),
            'variants' => ProductVariant::count(),
            'customers' => Customer::count(),
            'vendors' => Vendor::count(),
            'materials' => Material::count(),
            'orders' => Order::count(),
            'preorders' => Preorder::count(),
        ]);

        $this->seed(SakanaFridgeDemoSeeder::class);

        $countsAfterRerun = ModeGate::runAs('demo', fn () => [
            'events' => Event::count(),
            'artists' => Artist::count(),
            'categories' => Category::count(),
            'products' => Product::count(),
            'variants' => ProductVariant::count(),
            'customers' => Customer::count(),
            'vendors' => Vendor::count(),
            'materials' => Material::count(),
            'orders' => Order::count(),
            'preorders' => Preorder::count(),
        ]);

        $this->assertSame($counts, $countsAfterRerun);
    }
}
