<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\PosSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 048 / T009 — bawaan konservatif dan penolakan nilai tak valid pada pengaturan POS. */
class PosSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_keep_existing_installs_unchanged(): void
    {
        $this->assertSame(0.0, PosSettings::taxRate());
        $this->assertSame('Pajak', PosSettings::taxLabel());
        $this->assertSame(200_000, PosSettings::cashOutApprovalThreshold());
        $this->assertSame(5_000, PosSettings::varianceTolerance());
        $this->assertSame(120, PosSettings::edcDuplicateWindowSeconds());
        $this->assertSame(1, PosSettings::reprintFreeCopies());
    }

    public function test_configured_values_are_read(): void
    {
        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => '11', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'variance_tolerance'], ['value' => '10000', 'type' => 'string']);

        $this->assertSame(11.0, PosSettings::taxRate());
        $this->assertSame(10_000, PosSettings::varianceTolerance());
    }

    public function test_invalid_values_fall_back_to_defaults(): void
    {
        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => '150', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'cash_out_approval_threshold'], ['value' => '-5', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'reprint_free_copies'], ['value' => 'banyak', 'type' => 'string']);

        $this->assertSame(0.0, PosSettings::taxRate());
        $this->assertSame(200_000, PosSettings::cashOutApprovalThreshold());
        $this->assertSame(1, PosSettings::reprintFreeCopies());
    }
}
