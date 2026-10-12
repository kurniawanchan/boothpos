<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/** 045 (T006) — fixture outlet dapat dipakai sebelum Stage 1, dan helper tahap lanjut gagal dengan pesan yang jelas. */
class OutletFixturesSmokeTest extends TestCase
{
    use OutletFixtures;
    use RefreshDatabase;

    public function test_it_creates_outlets_with_the_conventional_codes_and_names(): void
    {
        $outlets = $this->makeOutlets(3);

        $this->assertSame(['WJ1', 'WJ2', 'WJ3'], $outlets->pluck('code')->all());
        $this->assertSame('Warkop WJM 2', $outlets[1]->name);
    }

    public function test_a_shift_can_be_opened_for_an_outlet_before_the_outlet_stage_exists(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);

        $shift = $this->shiftAt($this->makeOutlets(1)->first(), $cashier);

        $this->assertSame('open', $shift->status);
    }

    public function test_helpers_of_later_stages_fail_loudly_until_their_schema_exists(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('outlet_user')) {
            $this->markTestSkipped('Stage 2 sudah ada; pembantu ini sudah aktif.');
        }

        $this->expectException(LogicException::class);
        $this->assignUser(User::factory()->create(), $this->makeOutlets(1));
    }
}
