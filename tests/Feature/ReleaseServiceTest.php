<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\MenuChange;
use App\Models\MenuRelease;
use App\Models\PriceHistory;
use App\Models\ReasonCode;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\PriceService;
use App\Services\ProductLifecycleService;
use App\Services\ReleaseService;
use App\Support\ModeGate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T025, T027) — mesin rilis: kumpulkan, terbitkan versi, tulis ke kolom hidup,
 * jadwal tanpa penjadwal (lazy), dan dua mode.
 */
class ReleaseServiceTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function releasesOn(): void
    {
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean']);
    }

    private function setClock(string $when): void
    {
        CarbonImmutable::setTestNow($when);
        \Illuminate\Support\Carbon::setTestNow($when);
    }

    private function queuePrice($variant, float $to): MenuChange
    {
        app(PriceService::class)->setBasePrice($variant, $to, $this->owner);

        return MenuChange::orderByDesc('id')->first();
    }

    public function test_a_release_applies_prices_and_lifecycle_writes_history_and_marks_changes_released(): void
    {
        $this->releasesOn();
        $v = $this->makeVariant(stock: 1, price: 10000);
        $this->queuePrice($v, 10500);
        $gone = $this->makeVariant(stock: 1)->product;
        app(ProductLifecycleService::class)->discontinue($gone, $this->owner, ReasonCode::where('category', 'discontinue')->value('id'));

        $release = app(ReleaseService::class)->publish($this->owner, null, 'rilis pertama');

        $this->assertSame([1, MenuRelease::PUBLISHED], [$release->version, $release->status]);
        $this->assertSame('10500.00', (string) $v->fresh()->sell_price);
        $this->assertSame('discontinued', $gone->fresh()->status);
        $h = PriceHistory::where('variant_id', $v->id)->orderByDesc('id')->first();
        $this->assertSame(['release', $release->id], [$h->source, $h->release_id]);
        $this->assertSame(2, MenuChange::where('status', MenuChange::RELEASED)->where('release_id', $release->id)->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'menu_release_published', 'entity_id' => $release->id]);
    }

    public function test_versions_are_sequential_and_an_empty_release_is_refused(): void
    {
        $this->releasesOn();
        $svc = app(ReleaseService::class);
        $v = $this->makeVariant(stock: 1, price: 10000);

        $this->queuePrice($v, 10100);
        $this->assertSame(1, $svc->publish($this->owner)->version);
        $this->queuePrice($v->fresh(), 10200);
        $this->assertSame(2, $svc->publish($this->owner)->version);

        try {
            $svc->publish($this->owner);
            $this->fail('rilis kosong harus ditolak');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    public function test_changes_awaiting_approval_cannot_be_released_until_approved(): void
    {
        $this->releasesOn();
        $v = $this->makeVariant(stock: 1, price: 10000);
        $big = $this->queuePrice($v, 20000);                       // +100% → perlu persetujuan
        $small = $this->queuePrice($this->makeVariant(stock: 1, price: 10000), 10200);
        $this->assertSame(MenuChange::AWAITING_APPROVAL, $big->status);

        $svc = app(ReleaseService::class);
        try {
            $svc->publish($this->owner, null, null, [$big->id, $small->id]);
            $this->fail('harus 409');
        } catch (ValidationException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame('10000.00', (string) $v->fresh()->sell_price);

        // Default = hanya yang siap: perubahan kecil terbit, yang besar tetap menunggu.
        $svc->publish($this->owner);
        $this->assertSame(MenuChange::RELEASED, $small->fresh()->status);
        $this->assertSame(MenuChange::AWAITING_APPROVAL, $big->fresh()->status);

        $role = Role::factory()->create(['name' => 'Manager', 'menu_keys' => ['products'], 'permissions' => ['menu.price.approve']]);
        $approver = User::factory()->create(['role_id' => $role->id]);
        app(ApprovalService::class)->approve(Approval::sole(), $approver);

        $second = $svc->publish($this->owner);
        $this->assertSame('20000.00', (string) $v->fresh()->sell_price);
        $this->assertSame($approver->id, $second->approved_by);
        $this->assertSame($approver->id, PriceHistory::where('variant_id', $v->id)->orderByDesc('id')->value('approved_by'));
    }

    public function test_a_scheduled_release_applies_at_the_first_call_after_its_time(): void
    {
        $this->setClock('2026-10-11 05:30:00');
        $this->releasesOn();
        $v = $this->makeVariant(stock: 1, price: 10000);
        $this->queuePrice($v, 10300);

        $release = app(ReleaseService::class)->publish($this->owner, CarbonImmutable::parse('2026-10-11 09:00:00'));
        $this->assertSame(MenuRelease::SCHEDULED, $release->status);
        $this->assertSame('10000.00', (string) $v->fresh()->sell_price);

        $this->assertSame(0, app(ReleaseService::class)->publishDue());           // belum waktunya
        $this->setClock('2026-10-11 09:00:01');
        $this->assertSame(1, app(ReleaseService::class)->publishDue());

        $this->assertSame('10300.00', (string) $v->fresh()->sell_price);
        $this->assertSame(MenuRelease::PUBLISHED, $release->fresh()->status);
    }

    public function test_a_scheduled_release_can_be_cancelled_but_a_published_one_cannot(): void
    {
        $this->releasesOn();
        $svc = app(ReleaseService::class);
        $v = $this->makeVariant(stock: 1, price: 10000);
        $change = $this->queuePrice($v, 10300);

        $scheduled = $svc->publish($this->owner, now()->addDay());
        $svc->cancel($scheduled, $this->owner);
        $this->assertSame([MenuRelease::CANCELLED, null], [$scheduled->fresh()->status, $change->fresh()->release_id]);
        $this->assertSame(MenuChange::PENDING, $change->fresh()->status);               // kembali siap dirilis

        $published = $svc->publish($this->owner);
        try {
            $svc->cancel($published, $this->owner);
            $this->fail('harus 409');
        } catch (ValidationException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function test_a_failure_while_applying_rolls_everything_back(): void
    {
        $this->releasesOn();
        $a = $this->makeVariant(stock: 1, price: 10000);
        $b = $this->makeVariant(stock: 1, price: 10000);
        $this->queuePrice($a, 10100);
        $badChange = $this->queuePrice($b, 10200);
        $badChange->update(['payload' => ['product_name' => 'rusak']]);   // tanpa new_price → penerap kedua gagal

        try {
            app(ReleaseService::class)->publish($this->owner);
            $this->fail('harus gagal');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('new_price', $e->getMessage());
        }

        $this->assertSame('10000.00', (string) $a->fresh()->sell_price);
        $this->assertSame(0, MenuRelease::count());
    }

    public function test_immediate_mode_applies_a_scheduled_price_when_it_falls_due_and_not_before(): void
    {
        $this->setClock('2026-10-11 05:30:00');                       // rilis mati
        $v = $this->makeVariant(stock: 1, price: 10000);
        app(PriceService::class)->setBasePrice($v, 12000, $this->owner, 'edit', ['effective_from' => '2026-10-13']);
        $svc = app(ReleaseService::class);

        $this->assertSame(0, $svc->publishDue());
        $this->assertSame('10000.00', (string) $v->fresh()->sell_price);

        $this->setClock('2026-10-13 05:00:00');
        $this->assertSame(1, $svc->publishDue());
        $this->assertSame('12000.00', (string) $v->fresh()->sell_price);
        $this->assertSame(MenuChange::RELEASED, MenuChange::sole()->status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("entryPoints")]
    public function test_every_entry_point_publishes_due_changes_lazily(string $entry): void
    {
        $this->setClock('2026-10-11 05:30:00');
        $session = $this->makeShift($this->owner);
        $v = $this->makeVariant(stock: 5, price: 10000);
        app(PriceService::class)->setBasePrice($v, 12000, $this->owner, 'edit', ['effective_from' => '2026-10-12']);
        $this->setClock('2026-10-12 05:30:00');
        Cache::flush();

        match ($entry) {
            'product_list' => $this->getJson('/api/v1/products?with_variants=1')->assertOk(),
            'catalog_list' => $this->getJson('/api/v1/products?tab=menu')->assertOk(),
            'availability_poll' => $this->getJson('/api/v1/menu/availability')->assertOk(),
            'checkout' => $this->postJson('/api/v1/orders', ['session_id' => $session->id, 'local_ref' => (string) \Illuminate\Support\Str::uuid(),
                'items' => [['variant_id' => $v->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 20000]]])->assertCreated(),
            'tab_line' => $this->apiAddLine($this->apiOpenTab($session->id, \App\Models\DiningTable::factory()->create()->id)->json('id'), $v->id, 1)->assertCreated(),
        };

        $this->assertSame('12000.00', (string) $v->fresh()->sell_price, $entry);
    }

    public static function entryPoints(): array
    {
        return array_map(fn ($e) => [$e], ['product_list', 'catalog_list', 'availability_poll', 'checkout', 'tab_line']);
    }

    public function test_the_cached_check_runs_at_most_once_per_window(): void
    {
        Cache::flush();
        $svc = \Mockery::mock(ReleaseService::class)->makePartial();
        $svc->shouldReceive('publishDue')->once();

        $svc->publishDueCached();
        $svc->publishDueCached();                                      // dalam jendela 5 dtk → dilewati

        $this->assertTrue(Cache::has('menu.publish_due:'.ModeGate::current()));
    }

    public function test_release_data_never_mixes_between_demo_and_live(): void
    {
        $this->releasesOn();
        $v = $this->makeVariant(stock: 1, price: 10000);
        $this->queuePrice($v, 10100);
        app(ReleaseService::class)->publish($this->owner);

        ModeGate::runAs(ModeGate::DEMO, function () {
            $this->assertSame(0, MenuRelease::count());
            $this->assertSame(0, MenuChange::count());
        });
    }
}
