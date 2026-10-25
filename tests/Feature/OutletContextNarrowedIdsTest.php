<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OutletContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 071 — OutletContext::narrowedIds(): permintaan outlet (`outlet_id[]`) hanya boleh MENYEMPITKAN di dalam outlet yang
 * berlaku (Konstitusi IV), tidak pernah memperluas.
 */
class OutletContextNarrowedIdsTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private function context(User $user, ?int $selected = null): OutletContext
    {
        $context = app(OutletContext::class);
        $context->bind($user, $selected);

        return $context;
    }

    public function test_the_request_is_cut_to_the_outlets_the_user_may_see(): void
    {
        [$a, $b, $c] = $this->makeOutlets(3)->all();
        $user = $this->assignUser(User::factory()->create(['role' => 'inventory']), [$a, $b]);

        $ids = $this->context($user)->narrowedIds([$b->id, $c->id, $a->id]);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
    }

    public function test_an_owner_may_narrow_to_any_outlet(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $owner = User::factory()->create(['role' => 'owner']);

        $this->assertSame([$b->id], $this->context($owner)->narrowedIds([$b->id]));
    }

    public function test_the_selected_header_outlet_narrows_further(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $owner = User::factory()->create(['role' => 'owner']);

        $this->assertSame([$a->id], $this->context($owner, $a->id)->narrowedIds([$a->id, $b->id]));
    }

    public function test_nothing_allowed_gives_an_empty_list_never_everything(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $user = $this->assignUser(User::factory()->create(['role' => 'inventory']), [$a]);

        $this->assertSame([], $this->context($user)->narrowedIds([$b->id]));
        $this->assertSame([], $this->context($user)->narrowedIds([]));
    }

    public function test_the_result_has_no_duplicates_and_keeps_integers(): void
    {
        [$a] = $this->makeOutlets(1)->all();
        $owner = User::factory()->create(['role' => 'owner']);

        $this->assertSame([$a->id], $this->context($owner)->narrowedIds([(string) $a->id, $a->id]));
    }

    public function test_narrowed_or_null_means_no_filter_when_nothing_was_requested(): void
    {
        [$a] = $this->makeOutlets(1)->all();
        $owner = User::factory()->create(['role' => 'owner']);
        $context = $this->context($owner);

        $this->assertNull($context->narrowedOrNull([]));
        $this->assertNull($context->narrowedOrNull(['', null]));
        $this->assertSame([$a->id], $context->narrowedOrNull([$a->id, '']));
        $this->assertSame([], $context->narrowedOrNull([9999]), 'diminta tetapi tak boleh -> [], bukan null');
    }
}
