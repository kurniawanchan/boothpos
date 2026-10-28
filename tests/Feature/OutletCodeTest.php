<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 079 / US3 — kode outlet 3–5 huruf/angka, huruf besar, unik tanpa beda huruf, boleh disunting. */
class OutletCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Master: tanpa ini outlet kedua ditolak LicenseGate (403) sebelum aturan kode sempat dievaluasi.
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => '1', 'type' => 'boolean', 'group' => 'licensing']);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /** @return array<string, array{0: string}> */
    public static function validCodes(): array
    {
        return ['3 letters' => ['abc'], '4 mixed' => ['wj2a'], '5 mixed' => ['kd001'], '5 digits' => ['12345']];
    }

    /** @return array<string, array{0: string}> */
    public static function invalidCodes(): array
    {
        return ['too short' => ['ab'], 'too long' => ['abcdef'], 'symbol' => ['ab-1'], 'space' => ['ab 1'], 'empty' => ['']];
    }

    #[DataProvider('validCodes')]
    public function test_a_code_of_three_to_five_letters_or_digits_is_accepted_and_stored_uppercase(string $code): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/outlets', ['code' => $code, 'name' => 'Outlet'])
            ->assertCreated()->assertJsonPath('code', strtoupper($code));
        $this->assertDatabaseHas('outlets', ['code' => strtoupper($code)]);
    }

    #[DataProvider('invalidCodes')]
    public function test_a_code_that_breaks_the_rule_is_refused_with_the_rule_message(string $code): void
    {
        $this->actingAsRole('owner');

        $response = $this->postJson('/api/v1/outlets', ['code' => $code, 'name' => 'Outlet'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        if ($code !== '') {
            $this->assertStringContainsString('3–5', $response->json('errors.code.0'));
        }
    }

    public function test_a_duplicate_code_is_refused_ignoring_letter_case(): void
    {
        $this->actingAsRole('owner');
        Outlet::factory()->create(['code' => 'KD001']);

        $this->postJson('/api/v1/outlets', ['code' => 'kd001', 'name' => 'Lagi'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_the_code_can_be_edited_to_a_free_value_and_saving_the_own_code_is_not_a_duplicate(): void
    {
        $this->actingAsRole('owner');
        $outlet = Outlet::factory()->create(['code' => 'WJM']);

        $this->putJson("/api/v1/outlets/{$outlet->id}", ['code' => 'wjm', 'name' => 'Sama'])->assertOk();
        $this->putJson("/api/v1/outlets/{$outlet->id}", ['code' => 'WJM2X', 'name' => 'Baru'])
            ->assertOk()->assertJsonPath('code', 'WJM2X');
        $this->assertDatabaseHas('outlets', ['id' => $outlet->id, 'code' => 'WJM2X']);
    }

    public function test_the_code_cannot_be_edited_to_another_outlets_code(): void
    {
        $this->actingAsRole('owner');
        Outlet::factory()->create(['code' => 'AAA']);
        $outlet = Outlet::factory()->create(['code' => 'BBB']);

        $this->putJson("/api/v1/outlets/{$outlet->id}", ['code' => 'aaa', 'name' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertDatabaseHas('outlets', ['id' => $outlet->id, 'code' => 'BBB']);
    }

    public function test_the_code_of_a_deleted_outlet_can_be_used_again(): void
    {
        $this->actingAsRole('owner');
        $old = Outlet::factory()->create(['code' => 'OLD']);
        $old->delete();

        // Validasi DAN indeks UNIQUE database (kolom turunan code_live) sama-sama mengabaikan outlet terhapus.
        $this->postJson('/api/v1/outlets', ['code' => 'old', 'name' => 'Pakai Ulang'])->assertCreated();
        $this->assertSame(2, Outlet::withTrashed()->where('code', 'OLD')->count());
    }

    public function test_a_code_change_is_written_to_the_activity_log_with_old_and_new_values(): void
    {
        $user = $this->actingAsRole('owner');
        $outlet = Outlet::factory()->create(['code' => 'AAA', 'name' => 'Satu']);

        $this->putJson("/api/v1/outlets/{$outlet->id}", ['code' => 'AAAAA', 'name' => 'Satu'])->assertOk();

        $log = ActivityLog::query()->where('entity_type', 'Outlet')->where('entity_id', $outlet->id)->where('action', 'updated')->firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('AAA', $log->old_values['code']);
        $this->assertSame('AAAAA', $log->new_values['code']);
    }

    public function test_an_update_that_changes_nothing_writes_no_log(): void
    {
        $this->actingAsRole('owner');
        $outlet = Outlet::factory()->create(['code' => 'AAA', 'name' => 'Satu', 'address' => null, 'contact_phone' => null, 'contact_email' => null, 'notes' => null]);

        $this->putJson("/api/v1/outlets/{$outlet->id}", ['code' => 'AAA', 'name' => 'Satu'])->assertOk();

        $this->assertSame(0, ActivityLog::query()->where('entity_type', 'Outlet')->where('action', 'updated')->count());
    }

    public function test_a_cashier_cannot_edit_a_code(): void
    {
        $outlet = Outlet::factory()->create(['code' => 'AAA']);
        $this->actingAsRole('cashier');

        $this->putJson("/api/v1/outlets/{$outlet->id}", ['code' => 'BBB', 'name' => 'X'])->assertForbidden();
        $this->assertDatabaseHas('outlets', ['id' => $outlet->id, 'code' => 'AAA']);
    }
}
