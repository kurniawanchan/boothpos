<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Menjaga dunia uji bersama: irisan berikutnya (etalase, checkout, fulfilment)
 * mengandalkan keadaan persis ini, jadi perubahan diam-diam pada fixture harus
 * gagal di sini, bukan sebagai test etalase yang tiba-tiba bocor.
 */
class CustomerCommerceFixtureTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    public function test_storefront_world_has_exactly_the_documented_states(): void
    {
        $w = $this->buildStorefrontWorld();

        $this->assertTrue($w['artist']->is_active);
        $this->assertTrue($w['category']->is_active);
        $this->assertNotNull($w['product']->published_at);
        $this->assertTrue($w['product']->published_at->isPast());
        $this->assertSame('gantungan-kunci-sakura', $w['product']->slug);
        $this->assertFalse($w['product']->is_preorder);

        foreach (['variantA' => 10, 'variantB' => 5] as $key => $stock) {
            $this->assertTrue($w[$key]->is_active, $key);
            $this->assertNotNull($w[$key]->published_at, $key);
            $this->assertSame($stock, $w[$key]->current_stock, $key);
            $this->assertSame($w['product']->id, $w[$key]->product_id, $key);
        }

        $this->assertSame(0, $w['outOfStockVariant']->current_stock);
        $this->assertNotNull($w['outOfStockVariant']->published_at);
        $this->assertTrue($w['outOfStockVariant']->is_active);

        $this->assertFalse($w['inactiveVariant']->is_active);
        $this->assertNotNull($w['inactiveVariant']->published_at);
        $this->assertSame(7, $w['inactiveVariant']->current_stock);

        $this->assertNull($w['unpublishedVariant']->published_at);
        $this->assertSame(3, $w['unpublishedVariant']->current_stock);

        $this->assertNull($w['unpublishedProduct']->published_at);
        $this->assertNotNull($w['unpublishedProductVariant']->published_at);
        $this->assertSame(4, $w['unpublishedProductVariant']->current_stock);
        $this->assertSame($w['unpublishedProduct']->id, $w['unpublishedProductVariant']->product_id);
    }

    public function test_storefront_stock_comes_from_stock_movements_never_a_direct_write(): void
    {
        $this->buildStorefrontWorld();

        foreach (ProductVariant::all() as $variant) {
            $this->assertSame(
                $variant->current_stock,
                (int) DB::table('stock_movements')->where('variant_id', $variant->id)->sum('qty_change'),
                "Stok varian {$variant->variant_name} harus sama dengan jumlah riwayatnya.",
            );
        }

        $this->assertSame(5, DB::table('stock_movements')->where('type', 'initial')->count());
    }

    public function test_customer_account_helper_creates_a_plain_contact_in_the_active_mode(): void
    {
        [$account, $token] = $this->customerWithToken(['email' => 'uji@example.test'], ['name' => 'Pembeli Uji', 'phone' => '0800-0000-0002']);

        $this->assertSame('uji@example.test', $account->email);
        $this->assertSame('Pembeli Uji', $account->customer->name);
        $this->assertNull($account->customer->artist_id, 'Kontak hasil registrasi tidak terikat ke seller mana pun.');
        $this->assertNull($account->customer->notes);
        $this->assertSame($account->data_mode, $account->customer->data_mode);
        $this->assertNull($account->disabled_at);

        $stored = PersonalAccessToken::findToken($token);
        $this->assertSame('customer-app', $stored->name);
        $this->assertSame(['customer'], $stored->abilities);
        $this->assertNotNull($stored->expires_at);
    }

    public function test_staff_token_helper_matches_the_staff_login_token(): void
    {
        $token = PersonalAccessToken::findToken($this->issueStaffToken(User::factory()->create()));

        $this->assertSame('pos-session', $token->name);
        $this->assertSame(['*'], $token->abilities);
        $this->assertNull($token->expires_at);
    }

    public function test_bearer_helper_sets_and_clears_the_authorization_header(): void
    {
        $this->bearer('abc');
        $this->assertSame('Bearer abc', $this->defaultHeaders['Authorization']);

        $this->bearer('def');
        $this->assertSame('Bearer def', $this->defaultHeaders['Authorization']);

        $this->withoutBearer();
        $this->assertArrayNotHasKey('Authorization', $this->defaultHeaders);
    }

    public function test_feature_flag_helpers_write_the_single_install_wide_setting(): void
    {
        $this->assertNull(Setting::where('key', 'customer_commerce_enabled')->first(), 'Tanpa baris = MATI.');

        $this->enableCustomerCommerce();
        $this->assertSame('1', Setting::get('customer_commerce_enabled'));

        $this->disableCustomerCommerce();
        $this->assertSame('0', Setting::get('customer_commerce_enabled'));
        $this->assertSame(1, Setting::where('key', 'customer_commerce_enabled')->count());
    }
}
