<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PinAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 074 — owner/admin mengatur PIN pengguna dari layar Pengguna (ketik atau "Generate"), status PIN di daftar, dan efeknya
 * (kunci bersih, sesi tablet berakhir, log tanpa PIN). Sengaja memakai PIN yang berbeda-beda supaya bisa dicari di respons dan log.
 */
class UserPinAdminTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private Device $device;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = Outlet::first() ?? Outlet::factory()->create();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->cashier->outlets()->sync([$this->outlet->id]);

        $this->device = new Device(['name' => 'Kasir Depan', 'paired_at' => now()]);
        $this->device->setAttribute('outlet_id', $this->outlet->id);
        $this->device->save();
    }

    private function asAdmin(string $role = 'owner'): User
    {
        $admin = User::factory()->create(['role' => $role]);
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function setPin(User $target, string $pin, ?string $source = null)
    {
        return $this->putJson("/api/v1/users/{$target->id}/pin", array_filter(['pin' => $pin, 'source' => $source], fn ($v) => $v !== null));
    }

    private function androidLogin(User $user, string $pin)
    {
        return $this->postJson('/api/v1/auth/pin-sessions', [
            'outlet_id' => $this->outlet->id, 'user_id' => $user->id, 'pin' => $pin, 'device_id' => $this->device->id,
        ]);
    }

    private function weak(string $pin): bool
    {
        $same = count(array_unique(str_split($pin))) === 1;

        return $same || in_array($pin, ['012345', '123456', '234567', '345678', '456789', '987654', '876543', '765432', '654321', '543210'], true);
    }

    // ---------- US1 — set a PIN for a user who has none ----------

    public function test_admin_sets_a_pin_for_a_user_with_none_and_the_user_can_sign_in(): void
    {
        $this->asAdmin();
        $this->assertNull($this->cashier->fresh()->pin_hash);

        $res = $this->setPin($this->cashier, '482916', 'typed')->assertOk()
            ->assertJsonPath('id', $this->cashier->id)->assertJsonPath('has_pin', true)->assertJsonPath('pin_locked', false);

        $this->assertStringNotContainsString('482916', $res->getContent());
        $this->assertJsonMissingPathAll($res, ['pin', 'pin_hash']);
        $this->androidLogin($this->cashier, '482916')->assertOk()->assertJsonPath('user.id', $this->cashier->id);
    }

    private function assertJsonMissingPathAll($res, array $paths): void
    {
        foreach ($paths as $path) {
            $res->assertJsonMissingPath($path);
        }
    }

    public function test_weak_malformed_and_bad_source_are_refused_and_nothing_changes(): void
    {
        $this->asAdmin();

        foreach (['111111', '123456', '654321', '000000'] as $weak) {
            $this->setPin($this->cashier, $weak)->assertStatus(422)->assertJsonPath('code', 'pin_too_weak');
        }
        foreach (['12345', '12345a', '1234567', ''] as $bad) {
            $this->setPin($this->cashier, $bad)->assertStatus(422)->assertJsonValidationErrors('pin');
        }
        $this->setPin($this->cashier, '482916', 'robot')->assertStatus(422)->assertJsonValidationErrors('source');

        $this->assertNull($this->cashier->fresh()->pin_hash);
        $this->assertSame(0, ActivityLog::whereIn('action', ['pin_set', 'pin_replaced'])->count());
    }

    public function test_only_owner_or_admin_may_set_a_pin_having_the_users_menu_is_not_enough(): void
    {
        $role = Role::create(['name' => 'Staf Pengguna', 'menu_keys' => ['dashboard', 'users'], 'all_outlets' => true]);
        $staff = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($staff, 'sanctum');

        $this->setPin($this->cashier, '482916')->assertForbidden();
        $this->postJson('/api/v1/pin-suggestions')->assertForbidden();
        $this->actingAs($this->cashier, 'sanctum');
        $this->setPin($this->cashier, '482916')->assertForbidden();
        $this->assertNull($this->cashier->fresh()->pin_hash);
    }

    public function test_unauthenticated_and_unknown_user_are_refused(): void
    {
        $this->putJson("/api/v1/users/{$this->cashier->id}/pin", ['pin' => '482916'])->assertUnauthorized();
        $this->postJson('/api/v1/pin-suggestions')->assertUnauthorized();

        $this->asAdmin();
        $this->putJson('/api/v1/users/999999/pin', ['pin' => '482916'])->assertNotFound();
    }

    public function test_suggestions_are_valid_six_digit_pins_admin_only_and_never_stored(): void
    {
        $this->asAdmin();
        $before = [User::whereNotNull('pin_hash')->count(), ActivityLog::count()];

        foreach (range(1, 20) as $_) {
            $pin = $this->postJson('/api/v1/pin-suggestions')->assertOk()->json('pin');
            $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
            $this->assertFalse($this->weak($pin), "saran PIN lemah: $pin");
        }

        $this->assertSame($before, [User::whereNotNull('pin_hash')->count(), ActivityLog::count()], 'saran tidak boleh menyimpan apa pun');
    }

    /** Volume dicek langsung pada pembuatnya (rute dibatasi 30/menit, jadi 300 panggilan HTTP akan kena 429 — itu yang diuji di bawah). */
    public function test_the_generator_never_returns_a_weak_pin_over_many_draws(): void
    {
        $service = app(\App\Services\PinAuthService::class);
        $seen = [];
        foreach (range(1, 3000) as $_) {
            $pin = $service->suggest();
            $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
            $this->assertFalse($this->weak($pin), "saran PIN lemah: $pin");
            $seen[$pin] = true;
        }
        $this->assertGreaterThan(2900, count($seen), 'saran harus acak, bukan berulang');
    }

    public function test_suggestions_are_throttled(): void
    {
        $this->asAdmin();
        foreach (range(1, 30) as $_) {
            $this->postJson('/api/v1/pin-suggestions')->assertOk();
        }

        $this->postJson('/api/v1/pin-suggestions')->assertStatus(429);
    }

    public function test_a_suggested_pin_can_be_saved_and_used(): void
    {
        $this->asAdmin();
        $pin = $this->postJson('/api/v1/pin-suggestions')->json('pin');

        $this->setPin($this->cashier, $pin, 'generated')->assertOk()->assertJsonPath('has_pin', true);
        $this->androidLogin($this->cashier, $pin)->assertOk();
    }

    public function test_a_set_writes_one_audit_entry_without_the_pin_and_a_refusal_writes_none(): void
    {
        $admin = $this->asAdmin();
        $this->setPin($this->cashier, '739204', 'generated')->assertOk();

        $log = ActivityLog::where('action', 'pin_set')->sole();
        $this->assertSame($admin->id, (int) $log->user_id);
        $this->assertSame($this->cashier->id, (int) $log->entity_id);
        $this->assertSame('generated', $log->new_values['source']);
        $dump = json_encode($log->toArray());
        $this->assertStringNotContainsString('739204', $dump);
        $this->assertStringNotContainsString('pin_hash', $dump);
        $this->assertStringNotContainsString((string) $this->cashier->fresh()->pin_hash, $dump);

        $this->setPin($this->cashier, '222222')->assertStatus(422);
        $this->assertSame(1, ActivityLog::whereIn('action', ['pin_set', 'pin_replaced'])->count());
    }

    /**
     * Jejak tumpukan PHP mencetak argumen metode. Kegagalan tak terduga di dalam adminSet (mis. galat DB) akan menuliskan PIN asli ke
     * laravel.log kecuali parameternya ditandai #[\SensitiveParameter] — kebocoran jenis ini sudah terlihat di log lama untuk jalur
     * PIN penyetuju. Sekaligus membuktikan bahwa perubahan PIN dibatalkan (transaksi) bila pencatatan gagal.
     */
    public function test_an_unexpected_failure_inside_the_set_neither_leaks_the_pin_in_the_stack_trace_nor_keeps_the_change(): void
    {
        $admin = User::factory()->create(['role' => 'owner']);
        $logger = \Mockery::mock(ActivityLogger::class);
        // Dilempar DARI DALAM panggilan (bukan dibuat di sini), supaya jejak tumpukannya melewati adminSet seperti galat nyata.
        $logger->shouldReceive('log')->andReturnUsing(function () {
            throw new \RuntimeException('log gagal');
        });
        $service = new PinAuthService($logger);

        try {
            $service->adminSet($admin, $this->cashier, '739204', 'typed');
            $this->fail('seharusnya melempar');
        } catch (\RuntimeException $e) {
            $this->assertSame('log gagal', $e->getMessage());
            $this->assertStringNotContainsString('739204', $e->getTraceAsString());
        }

        $this->assertNull($this->cashier->fresh()->pin_hash, 'PIN tidak boleh berubah bila pencatatan gagal');
    }

    public function test_source_defaults_to_typed(): void
    {
        $this->asAdmin();
        $this->setPin($this->cashier, '482916')->assertOk();

        $this->assertSame('typed', ActivityLog::where('action', 'pin_set')->sole()->new_values['source']);
    }

    // ---------- US2 — status in the list ----------

    public function test_the_list_shows_pin_status_and_lock_without_secrets(): void
    {
        $this->asAdmin();
        $locked = User::factory()->create(['role' => 'cashier']);
        $locked->forceFill(['pin_hash' => Hash::make('482916'), 'pin_locked_until' => now()->addMinutes(3), 'pin_failed_attempts' => 2])->save();
        $expired = User::factory()->create(['role' => 'cashier']);
        $expired->forceFill(['pin_hash' => Hash::make('482916'), 'pin_locked_until' => now()->subMinute()])->save();

        $res = $this->getJson('/api/v1/users?per_page=100')->assertOk();
        $byId = collect($res->json('data'))->keyBy('id');

        $this->assertFalse($byId[$this->cashier->id]['has_pin']);
        $this->assertFalse($byId[$this->cashier->id]['pin_locked']);
        $this->assertTrue($byId[$locked->id]['has_pin']);
        $this->assertTrue($byId[$locked->id]['pin_locked']);
        $this->assertTrue($byId[$expired->id]['has_pin']);
        $this->assertFalse($byId[$expired->id]['pin_locked'], 'kunci yang sudah lewat tidak tampil terkunci');
        foreach ($byId as $row) {
            $this->assertArrayNotHasKey('pin_hash', $row);
            $this->assertArrayNotHasKey('pin_failed_attempts', $row);
            $this->assertArrayNotHasKey('pin_locked_until', $row);
        }
    }

    public function test_the_has_pin_filter_narrows_the_list(): void
    {
        $this->asAdmin();
        $withPin = User::factory()->create(['role' => 'cashier']);
        $withPin->forceFill(['pin_hash' => Hash::make('482916')])->save();

        $none = collect($this->getJson('/api/v1/users?per_page=100&has_pin=0')->assertOk()->json('data'))->pluck('id')->all();
        $some = collect($this->getJson('/api/v1/users?per_page=100&has_pin=1')->assertOk()->json('data'))->pluck('id')->all();
        $all = collect($this->getJson('/api/v1/users?per_page=100')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($this->cashier->id, $none);
        $this->assertNotContains($withPin->id, $none);
        $this->assertContains($withPin->id, $some);
        $this->assertNotContains($this->cashier->id, $some);
        $this->assertContains($this->cashier->id, $all);
        $this->assertContains($withPin->id, $all);
    }

    // ---------- US3 — change or reset an existing PIN ----------

    public function test_replacing_a_pin_refuses_the_old_one_clears_the_lock_and_ends_tablet_sessions(): void
    {
        $this->asAdmin();
        $this->setPin($this->cashier, '482916')->assertOk();
        $token = $this->androidLogin($this->cashier, '482916')->assertOk()->json('token');
        $this->cashier->createToken('web-session', ['*']);
        $this->assertSame(1, $this->cashier->tokens()->where('name', 'pos-pin')->count());

        foreach (range(1, 5) as $_) {
            $this->androidLogin($this->cashier, '000111');
        }
        $this->androidLogin($this->cashier, '482916')->assertStatus(429);
        $this->assertNotNull($this->cashier->fresh()->pin_locked_until);

        $this->setPin($this->cashier, '739204')->assertOk()->assertJsonPath('pin_locked', false);

        $fresh = $this->cashier->fresh();
        $this->assertSame(0, $fresh->pin_failed_attempts);
        $this->assertNull($fresh->pin_locked_until);
        $this->assertNotNull($fresh->pin_changed_at);
        $this->assertSame(0, $this->cashier->tokens()->where('name', 'pos-pin')->count(), 'sesi PIN di tablet berakhir');
        $this->assertSame(1, $this->cashier->tokens()->where('name', 'web-session')->count(), 'token kata sandi tidak disentuh');
        $this->assertNotEmpty($token);

        $this->androidLogin($this->cashier, '482916')->assertStatus(401);
        $this->androidLogin($this->cashier, '739204')->assertOk();
    }

    public function test_a_replacement_is_logged_as_pin_replaced_and_leaves_password_role_and_outlets_alone(): void
    {
        $this->asAdmin();
        $this->setPin($this->cashier, '482916')->assertOk();
        $before = $this->cashier->fresh();

        $this->setPin($this->cashier, '739204')->assertOk();

        $this->assertSame(1, ActivityLog::where('action', 'pin_set')->count());
        $this->assertSame(1, ActivityLog::where('action', 'pin_replaced')->count());
        $after = $this->cashier->fresh();
        $this->assertSame($before->password, $after->password);
        $this->assertSame($before->role_id, $after->role_id);
        $this->assertSame([$this->outlet->id], $after->outlets()->pluck('outlets.id')->all());
    }

    public function test_an_admin_can_set_their_own_pin_and_the_last_set_is_the_only_working_pin(): void
    {
        $admin = $this->asAdmin('admin');
        $this->setPin($admin, '482916')->assertOk()->assertJsonPath('has_pin', true);
        $this->assertTrue(Hash::check('482916', $admin->fresh()->pin_hash));

        $this->setPin($this->cashier, '739204')->assertOk();
        $this->setPin($this->cashier, '518273')->assertOk();

        $this->androidLogin($this->cashier, '739204')->assertStatus(401);
        $this->androidLogin($this->cashier, '518273')->assertOk();
    }

    public function test_the_same_pin_may_be_used_by_several_users(): void
    {
        $this->asAdmin();
        $other = User::factory()->create(['role' => 'cashier']);

        $this->setPin($this->cashier, '482916')->assertOk();
        $this->setPin($other, '482916')->assertOk();
    }
}
