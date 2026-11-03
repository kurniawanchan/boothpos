<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DiningTable;
use App\Models\Setting;
use App\Models\User;
use App\Support\InvalidSelfServiceQr;
use App\Support\SelfServiceQrToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 090 / US2 — QR per meja dari Restaurant Setup: pembuatan (satu/banyak/menurut filter), pencabutan, dan gerbangnya.
 */
class DiningTableQrTest extends TestCase
{
    use OutletFixtures;
    use RefreshDatabase;

    private const BASE = 'http://192.168.1.10:8010';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'inventory']); // peran bawaan dengan `dining_setup`
        $this->actingAs($this->manager, 'sanctum');
    }

    private function useBaseUrl(string $url = self::BASE): void
    {
        Setting::updateOrCreate(['key' => 'self_service_base_url'], ['value' => $url, 'type' => 'string']);
    }

    private function tokenOf(string $url): string
    {
        $this->assertStringStartsWith(self::BASE.'/pesan/', $url);

        return substr($url, strlen(self::BASE.'/pesan/'));
    }

    public function test_batch_by_ids_returns_a_labelled_signed_url_per_table(): void
    {
        $this->useBaseUrl();
        $a = DiningTable::factory()->inArea('Indoor')->create(['name' => 'A1']);
        $b = DiningTable::factory()->create(['name' => 'B2']);

        $res = $this->postJson('/api/v1/dining-tables/qr-batch', ['ids' => [$a->id, $b->id]])->assertOk();

        $items = collect($res->json('items'))->keyBy('table_id');
        $this->assertCount(2, $items);
        $this->assertSame('A1', $items[$a->id]['table_name']);
        $this->assertSame('Indoor', $items[$a->id]['area']);
        $this->assertNotEmpty($items[$a->id]['outlet_name']);
        // Token di URL benar-benar membuka meja itu (dan hanya itu).
        $this->assertSame($a->id, SelfServiceQrToken::verify($this->tokenOf($items[$a->id]['url']))->id);
        $this->assertSame($b->id, SelfServiceQrToken::verify($this->tokenOf($items[$b->id]['url']))->id);
        $res->assertJsonPath('skipped.inactive', 0);
    }

    public function test_inactive_tables_are_skipped_and_counted(): void
    {
        $this->useBaseUrl();
        $on = DiningTable::factory()->create();
        $off = DiningTable::factory()->create(['is_active' => false]);

        $res = $this->postJson('/api/v1/dining-tables/qr-batch', ['ids' => [$on->id, $off->id]])->assertOk();

        $this->assertSame([$on->id], collect($res->json('items'))->pluck('table_id')->all());
        $res->assertJsonPath('skipped.inactive', 1);
    }

    public function test_batch_by_filter_uses_the_same_criteria_as_the_list(): void
    {
        $this->useBaseUrl();
        DiningTable::factory()->inArea('Indoor')->create(['name' => 'A1']);
        DiningTable::factory()->inArea('Indoor')->create(['name' => 'A2']);
        DiningTable::factory()->inArea('Teras')->create(['name' => 'T1']);

        $byArea = $this->postJson('/api/v1/dining-tables/qr-batch', ['filter' => ['area' => 'Indoor']])->assertOk();
        $byQuery = $this->postJson('/api/v1/dining-tables/qr-batch', ['filter' => ['q' => 'T1']])->assertOk();
        $all = $this->postJson('/api/v1/dining-tables/qr-batch', ['filter' => []])->assertOk();

        $this->assertEqualsCanonicalizing(['A1', 'A2'], collect($byArea->json('items'))->pluck('table_name')->all());
        $this->assertSame(['T1'], collect($byQuery->json('items'))->pluck('table_name')->all());
        $this->assertCount(3, $all->json('items'));
    }

    public function test_more_than_200_tables_is_refused_instead_of_truncated(): void
    {
        $this->useBaseUrl();
        DiningTable::factory()->count(201)->create();

        $this->postJson('/api/v1/dining-tables/qr-batch', ['filter' => []])
            ->assertStatus(422)->assertJsonPath('code', 'qr_batch_too_large');

        $this->postJson('/api/v1/dining-tables/qr-batch', ['ids' => range(1, 201)])->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    public function test_the_request_needs_exactly_one_of_ids_or_filter(): void
    {
        $this->useBaseUrl();

        $this->postJson('/api/v1/dining-tables/qr-batch', [])->assertStatus(422);
        $this->postJson('/api/v1/dining-tables/qr-batch', ['ids' => [1], 'filter' => []])->assertStatus(422);
        $this->postJson('/api/v1/dining-tables/qr-batch', ['ids' => ['x']])->assertStatus(422);
    }

    public function test_without_a_reachable_base_url_no_qr_is_made(): void
    {
        $t = DiningTable::factory()->create();

        // Tanpa pengaturan, asal permintaan tes adalah http://localhost → tidak bisa dijangkau ponsel pelanggan.
        $this->postJson('/api/v1/dining-tables/qr-batch', ['ids' => [$t->id]])
            ->assertStatus(409)->assertJsonPath('code', 'qr_base_url_invalid');

        $this->useBaseUrl('http://localhost:8010'); // bahkan bila terlanjur tersimpan, tetap ditolak
        $this->postJson('/api/v1/dining-tables/qr-batch', ['ids' => [$t->id]])->assertStatus(409);
    }

    public function test_the_origin_of_the_staff_page_is_used_when_it_is_reachable(): void
    {
        $t = DiningTable::factory()->create();

        // URL absolut: klien tes memakai host-nya sebagai asal permintaan (seperti halaman staf yang dibuka lewat IP LAN).
        $res = $this->postJson('http://192.168.1.10:8010/api/v1/dining-tables/qr-batch', ['ids' => [$t->id]])->assertOk();

        $this->assertStringStartsWith('http://192.168.1.10:8010/pesan/', $res->json('items.0.url'));
    }

    public function test_only_roles_with_dining_setup_can_make_qr_codes(): void
    {
        $this->useBaseUrl();
        $t = DiningTable::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->postJson('/api/v1/dining-tables/qr-batch', ['ids' => [$t->id]])->assertForbidden();
        $this->postJson("/api/v1/dining-tables/{$t->id}/qr/rotate")->assertForbidden();
    }

    public function test_guests_cannot_make_qr_codes(): void
    {
        $this->useBaseUrl();
        $t = DiningTable::factory()->create();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/dining-tables/qr-batch', ['ids' => [$t->id]])->assertUnauthorized();
    }

    public function test_tables_of_outlets_the_user_cannot_see_are_not_included(): void
    {
        $this->useBaseUrl();
        [$mine, $other] = $this->makeOutlets(2);
        $own = $this->tableAt($mine, 'A1');
        $foreign = $this->tableAt($other, 'B1');
        $user = $this->assignUser(User::factory()->create(['role' => 'inventory']), [$mine]);
        $this->actingAs($user, 'sanctum');

        $res = $this->postJson('/api/v1/dining-tables/qr-batch', ['ids' => [$own->id, $foreign->id]])->assertOk();

        $this->assertSame([$own->id], collect($res->json('items'))->pluck('table_id')->all());
    }

    public function test_rotating_kills_the_old_qr_and_returns_a_working_new_one(): void
    {
        $this->useBaseUrl();
        $t = DiningTable::factory()->create(['name' => 'A1']);
        $old = $this->tokenOf($this->postJson('/api/v1/dining-tables/qr-batch', ['ids' => [$t->id]])->json('items.0.url'));

        $res = $this->postJson("/api/v1/dining-tables/{$t->id}/qr/rotate")->assertOk();
        $new = $this->tokenOf($res->json('url'));

        $this->assertNotSame($old, $new);
        $this->assertSame(InvalidSelfServiceQr::QR_INVALID, $this->reasonOf($old));
        $this->assertSame($t->id, SelfServiceQrToken::verify($new)->id);
    }

    public function test_the_activity_log_records_generation_and_rotation_without_personal_data(): void
    {
        $this->useBaseUrl();
        $t = DiningTable::factory()->create(['name' => 'A1']);

        $this->postJson('/api/v1/dining-tables/qr-batch', ['ids' => [$t->id]])->assertOk();
        $this->postJson("/api/v1/dining-tables/{$t->id}/qr/rotate")->assertOk();

        $generated = ActivityLog::where('action', 'table_qr_generated')->firstOrFail();
        $rotated = ActivityLog::where('action', 'table_qr_rotated')->firstOrFail();
        $this->assertSame($this->manager->id, $generated->user_id);
        $this->assertSame($t->id, (int) $rotated->entity_id);
        // Token/URL/nonce adalah rahasia penandatanganan: tidak boleh masuk log.
        $dump = json_encode([$generated->toArray(), $rotated->toArray()]);
        $this->assertStringNotContainsString('/pesan/', $dump);
        $this->assertStringNotContainsString((string) $t->fresh()->qr_nonce, $dump);
    }

    public function test_the_table_list_says_whether_qr_codes_can_be_made(): void
    {
        DiningTable::factory()->create();

        $this->getJson('/api/v1/dining-tables')->assertOk()->assertJsonPath('data.0.qr_ready', false);

        $this->useBaseUrl();
        $this->getJson('/api/v1/dining-tables')->assertOk()->assertJsonPath('data.0.qr_ready', true);
    }

    public function test_the_base_url_setting_refuses_localhost_but_accepts_a_lan_address(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $save = fn (string $v) => $this->putJson('/api/v1/settings', ['settings' => [['key' => 'self_service_base_url', 'value' => $v]]]);

        $save('http://localhost:8010')->assertStatus(422);
        $save('http://127.0.0.1')->assertStatus(422);
        $save('ftp://toko.example')->assertStatus(422);
        $save('bukan url')->assertStatus(422);
        $save(self::BASE)->assertOk();
        $save('')->assertOk(); // kosong = pakai asal halaman staf
    }

    private function reasonOf(string $token): ?string
    {
        try {
            SelfServiceQrToken::verify($token);
        } catch (InvalidSelfServiceQr $e) {
            return $e->reason;
        }

        return null;
    }
}
