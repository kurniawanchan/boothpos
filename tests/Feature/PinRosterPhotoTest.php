<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 077 / US7 — daftar staf tanpa token membawa `photo_url` untuk avatar di layar PIN (null → klien memakai inisial). Tes ini juga
 * menjaga apa yang TIDAK boleh ikut keluar dari rute tanpa token.
 */
class PinRosterPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = Outlet::first() ?? Outlet::factory()->create();
    }

    private function staff(string $name, ?string $photoPath = null): User
    {
        $u = User::factory()->create(['role' => 'cashier', 'name' => $name, 'photo_path' => $photoPath]);
        $u->outlets()->sync([$this->outlet->id]);
        $u->forceFill(['pin_hash' => Hash::make('482916')])->save();

        return $u;
    }

    private function roster(): array
    {
        return collect($this->getJson('/api/v1/staff/roster?outlet_id='.$this->outlet->id)->assertOk()->json('data'))->keyBy('id')->all();
    }

    public function test_a_user_with_a_photo_gets_its_resolved_url_and_one_without_gets_null(): void
    {
        $with = $this->staff('Sari', 'user-photos/sari.jpg');
        $without = $this->staff('Budi');

        $rows = $this->roster();

        $this->assertStringEndsWith('/storage/user-photos/sari.jpg', $rows[$with->id]['photo_url']);
        $this->assertNull($rows[$without->id]['photo_url']);
    }

    public function test_existing_keys_are_unchanged_and_no_secret_or_identity_leaks(): void
    {
        $u = $this->staff('Sari', 'user-photos/sari.jpg');

        $row = $this->roster()[$u->id];

        $this->assertEqualsCanonicalizing(['id', 'name', 'role', 'photo_url', 'has_pin', 'locked'], array_keys($row));
        $json = json_encode($row);
        foreach (['username', 'pin_hash', 'email', 'photo_path'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'"', $json);
        }
    }

    public function test_the_roster_stays_scoped_to_the_requested_outlet(): void
    {
        $inside = $this->staff('Dalam', 'user-photos/a.jpg');
        $other = Outlet::factory()->create();
        $outside = User::factory()->create(['role' => 'cashier', 'photo_path' => 'user-photos/b.jpg']);
        $outside->outlets()->sync([$other->id]);

        $ids = array_keys($this->roster());

        $this->assertContains($inside->id, $ids);
        $this->assertNotContains($outside->id, $ids);
    }
}
