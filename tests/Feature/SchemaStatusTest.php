<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SchemaStatus;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * 035-po-row-actions (US4) — `SchemaStatus` dan flag `schema_update_required`
 * pada GET /settings/features (hanya untuk pengguna dengan menu `settings`).
 */
class SchemaStatusTest extends TestCase
{
    use RefreshDatabase;

    private const LAST = '2026_11_02_000003_add_bom_complete_to_product_variants_table';

    private function removeMigrationRecord(): void
    {
        $this->assertSame(1, DB::table('migrations')->where('migration', self::LAST)->delete(), 'fixture: migration row must exist');
    }

    private function flagFor(string $role): bool
    {
        $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');

        return $this->getJson('/api/v1/settings/features')->assertOk()->json('schema_update_required');
    }

    public function test_nothing_is_pending_on_a_fully_migrated_database(): void
    {
        $this->assertSame([], SchemaStatus::pendingMigrations());
    }

    public function test_a_migration_that_has_not_been_applied_is_reported_by_name(): void
    {
        $this->removeMigrationRecord();

        $this->assertSame([self::LAST], SchemaStatus::pendingMigrations());
    }

    public function test_a_missing_migrations_table_means_nothing_to_report_not_an_exception(): void
    {
        $repository = Mockery::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('repositoryExists')->andReturn(false);
        $migrator = Mockery::mock(Migrator::class);
        $migrator->shouldReceive('getRepository')->andReturn($repository);

        $this->assertSame([], SchemaStatus::pendingMigrations($migrator));
    }

    public function test_the_flag_is_false_for_everyone_when_nothing_is_pending(): void
    {
        foreach (['owner', 'admin', 'inventory', 'cashier'] as $role) {
            $this->assertFalse($this->flagFor($role), $role);
        }
    }

    public function test_the_flag_is_true_only_for_users_with_the_settings_menu_while_something_is_pending(): void
    {
        $this->removeMigrationRecord();

        $this->assertTrue($this->flagFor('owner'));
        $this->assertTrue($this->flagFor('admin'));
        $this->assertFalse($this->flagFor('inventory'));
        $this->assertFalse($this->flagFor('cashier'));
    }

    public function test_the_response_never_lists_migration_names(): void
    {
        $this->removeMigrationRecord();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->assertStringNotContainsString('add_bom_complete', $this->getJson('/api/v1/settings/features')->getContent());
    }

    public function test_every_existing_feature_key_is_still_returned(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->getJson('/api/v1/settings/features')->assertOk()->assertJsonStructure([
            'multi_artist_enabled', 'artist_count', 'artist_limit_reached', 'system_mode', 'app_name', 'store_name', 'schema_update_required',
        ]);
    }
}
