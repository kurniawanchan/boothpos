<?php

namespace Tests\Feature\SellerScope;

use App\Models\Artist;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Support\SellerGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSellerAssignmentTest extends TestCase
{
    use RefreshDatabase, SellerScopeFixture;

    private function owner(): User
    {
        $u = User::factory()->create(['role' => 'owner']);
        $this->actingAs($u, 'sanctum');

        return $u;
    }

    public function test_owner_assigns_changes_and_clears_a_users_seller(): void
    {
        $this->owner();
        $a = Artist::factory()->create();
        $b = Artist::factory()->create();
        $cashier = User::factory()->create(['role' => 'cashier']);

        $this->putJson("/api/v1/users/{$cashier->id}", ['artist_id' => $a->id])
            ->assertOk()->assertJsonPath('artist.id', $a->id)->assertJsonPath('artist_id', $a->id);

        $this->putJson("/api/v1/users/{$cashier->id}", ['artist_id' => $b->id])->assertOk()->assertJsonPath('artist.id', $b->id);
        $this->putJson("/api/v1/users/{$cashier->id}", ['artist_id' => null])->assertOk()->assertJsonPath('artist', null);

        $this->assertSame(3, ActivityLog::where('action', 'user_seller_changed')->count());
        $this->assertNull($cashier->fresh()->artist_id);
    }

    public function test_seller_is_shown_in_list_detail_and_filterable(): void
    {
        $this->owner();
        $a = Artist::factory()->create();
        $withSeller = User::factory()->create(['role' => 'cashier', 'artist_id' => $a->id]);
        User::factory()->create(['role' => 'cashier']);

        $this->getJson("/api/v1/users/{$withSeller->id}")->assertOk()->assertJsonPath('artist.code', $a->code);
        $ids = collect($this->getJson("/api/v1/users?artist_id={$a->id}")->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$withSeller->id], $ids);
    }

    public function test_create_with_a_seller(): void
    {
        $this->owner();
        $a = Artist::factory()->create();
        $role = Role::where('name', 'Kasir')->first();

        $this->postJson('/api/v1/users', [
            'name' => 'Kasir Seller', 'username' => 'kasir.seller', 'password' => 'password123',
            'role_id' => $role->id, 'artist_id' => $a->id,
        ])->assertCreated()->assertJsonPath('artist.id', $a->id);
    }

    public function test_unknown_inactive_or_deleted_seller_is_refused(): void
    {
        $this->owner();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $inactive = Artist::factory()->create(['is_active' => false]);
        $deleted = Artist::factory()->create();
        $deleted->delete();

        foreach ([999999, $inactive->id, $deleted->id] as $bad) {
            $this->putJson("/api/v1/users/{$cashier->id}", ['artist_id' => $bad])->assertStatus(422)->assertJsonValidationErrors('artist_id');
        }
    }

    public function test_user_already_linked_may_resave_while_their_seller_is_inactive(): void
    {
        $this->owner();
        $a = Artist::factory()->create(['is_active' => false]);
        $cashier = User::factory()->create(['role' => 'cashier', 'artist_id' => $a->id]);

        $this->putJson("/api/v1/users/{$cashier->id}", ['name' => 'Baru', 'artist_id' => $a->id])->assertOk();
    }

    public function test_a_user_without_the_users_menu_cannot_assign_a_seller(): void
    {
        $a = Artist::factory()->create();
        $target = User::factory()->create(['role' => 'cashier']);
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->putJson("/api/v1/users/{$target->id}", ['artist_id' => $a->id])->assertForbidden();
        $this->assertNull($target->fresh()->artist_id);
    }

    public function test_owner_role_can_never_hold_a_seller(): void
    {
        $this->owner();
        $a = Artist::factory()->create();
        $ownerRole = Role::where('name', 'Owner')->first();

        // Buat pengguna Owner dengan seller -> 422.
        $this->postJson('/api/v1/users', [
            'name' => 'Owner 2', 'username' => 'owner.two', 'password' => 'password123',
            'role_id' => $ownerRole->id, 'artist_id' => $a->id,
        ])->assertStatus(422)->assertJsonValidationErrors('artist_id');

        // Memberi seller kepada pengguna yang sudah Owner -> 422.
        $other = User::factory()->create(['role' => 'owner']);
        $this->putJson("/api/v1/users/{$other->id}", ['artist_id' => $a->id])->assertStatus(422)->assertJsonValidationErrors('artist_id');
    }

    public function test_changing_a_scoped_user_to_owner_clears_the_seller(): void
    {
        $this->owner();
        $a = Artist::factory()->create();
        $user = User::factory()->create(['role' => 'cashier', 'artist_id' => $a->id]);
        $ownerRole = Role::where('name', 'Owner')->first();

        $this->putJson("/api/v1/users/{$user->id}", ['role_id' => $ownerRole->id])->assertOk()->assertJsonPath('artist', null);
        $this->assertNull($user->fresh()->artist_id);
    }

    public function test_an_owner_with_a_stale_seller_link_is_still_never_scoped(): void
    {
        $w = $this->buildSellerWorld();
        $owner = User::factory()->create(['role' => 'owner']);
        $owner->forceFill(['artist_id' => $w['a']->id])->save(); // ikatan basi, jalur data lama
        $this->actingAs($owner->fresh(), 'sanctum');

        $this->assertNull(SellerGate::current());
        $this->assertSame(2, \App\Models\Product::count());
    }

    public function test_a_seller_with_linked_users_cannot_be_deleted(): void
    {
        $this->owner();
        $a = Artist::factory()->create();
        User::factory()->create(['role' => 'cashier', 'artist_id' => $a->id]);

        $this->deleteJson("/api/v1/artists/{$a->id}")->assertStatus(409);
        $this->assertNotNull(Artist::find($a->id));
    }
}
