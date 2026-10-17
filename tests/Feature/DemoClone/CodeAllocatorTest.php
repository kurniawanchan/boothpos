<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\CloneException;
use App\Services\DemoClone\CodeAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCloneFixtures;
use Tests\TestCase;

class CodeAllocatorTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    public function test_artist_code_is_z_plus_the_first_two_letters(): void
    {
        $this->assertSame('ZVL', (new CodeAllocator)->artist('VLC'));
    }

    public function test_taken_candidates_are_skipped(): void
    {
        $this->row('artists', ['code' => 'ZVL', 'name' => 'x', 'data_mode' => 'live']);
        $this->row('artists', ['code' => 'YVL', 'name' => 'x', 'data_mode' => 'demo']);

        $this->assertSame('XVL', (new CodeAllocator)->artist('VLC'));
    }

    public function test_soft_deleted_codes_count_as_taken(): void
    {
        $this->row('artists', ['code' => 'ZVL', 'name' => 'x', 'data_mode' => 'live', 'deleted_at' => now()]);

        $this->assertSame('YVL', (new CodeAllocator)->artist('VLC'));
    }

    public function test_two_live_artists_sharing_two_letters_get_different_codes(): void
    {
        $allocator = new CodeAllocator;

        $this->assertSame('ZVL', $allocator->artist('VLC'));
        $this->assertSame('YVL', $allocator->artist('VLD'));
    }

    public function test_mapping_is_memoized_and_case_insensitive(): void
    {
        $allocator = new CodeAllocator;

        $this->assertSame('ZVL', $allocator->artist('VLC'));
        $this->assertSame('ZVL', $allocator->artist('vlc'));
        $this->assertSame(['VLC' => 'ZVL'], $allocator->mapping()['artists']);
    }

    public function test_category_code_is_z_plus_the_first_letter(): void
    {
        $this->assertSame('ZK', (new CodeAllocator)->category('KC'));
    }

    public function test_product_prefix_is_rebuilt_from_the_two_maps(): void
    {
        $allocator = new CodeAllocator;

        $this->assertSame('ZVL-ZK-LOV', $allocator->productPrefix('VLC-KC-LOV', 7));
        $this->assertSame('ZVL', $allocator->artist('VLC'));
        $this->assertSame('ZK', $allocator->category('KC'));
    }

    /** Spec 042 (T013): segmen 1–3 huruf diterima dan dipertahankan panjangnya. */
    public function test_product_prefix_accepts_a_short_segment_and_keeps_its_length(): void
    {
        $allocator = new CodeAllocator;

        $this->assertSame('ZVL-ZK-L', $allocator->productPrefix('VLC-KC-L', 7));
        $this->assertSame('ZVL-ZK-LO', $allocator->productPrefix('VLC-KC-LO', 7));
    }

    /** Spec 046 (T009): segmen dengan tanda hubung tetap utuh (pemisah dibatasi tiga bagian). */
    public function test_a_hyphenated_segment_is_kept_whole(): void
    {
        $this->assertSame('ZVL-ZK-SH-K', (new CodeAllocator)->productPrefix('VLC-KC-SH-K', 7));
    }

    public function test_a_segment_longer_than_six_characters_still_aborts(): void
    {
        $this->expectException(CloneException::class);
        $this->expectExceptionMessage('products id 9');

        (new CodeAllocator)->productPrefix('VLC-KC-ABCDEFG', 9);
    }

    public function test_a_prefix_with_the_wrong_shape_aborts_with_the_product_id(): void
    {
        $this->expectException(CloneException::class);
        $this->expectExceptionMessage('products id 9');

        (new CodeAllocator)->productPrefix('VLCKCLOV', 9);
    }

    public function test_sku_keeps_its_numeric_tail_under_the_new_prefix(): void
    {
        $this->assertSame('ZVL-ZK-LOV-001', (new CodeAllocator)->sku('VLC-KC-LOV-001', 'VLC-KC-LOV', 'ZVL-ZK-LOV', 3));
    }

    public function test_a_sku_that_does_not_start_with_its_product_prefix_aborts(): void
    {
        $this->expectException(CloneException::class);
        $this->expectExceptionMessage('product_variants id 3');

        (new CodeAllocator)->sku('OTHER-SKU-001', 'VLC-KC-LOV', 'ZVL-ZK-LOV', 3);
    }

    public function test_suffix_is_appended_and_overflow_aborts_instead_of_truncating(): void
    {
        $allocator = new CodeAllocator;

        $this->assertSame('BC-001-DM', $allocator->suffixed('BC-001', 20, 'materials.code id 1'));

        $this->expectException(CloneException::class);
        $this->expectExceptionMessage('materials.code id 2');
        $allocator->suffixed('ABCDEFGHIJKLMNOPQR', 20, 'materials.code id 2');
    }

    public function test_it_aborts_when_all_26_candidates_are_taken(): void
    {
        foreach (range('A', 'Z') as $letter) {
            $this->row('artists', ['code' => $letter.'VL', 'name' => 'x', 'data_mode' => 'live']);
        }

        $this->expectException(CloneException::class);
        (new CodeAllocator)->artist('VLC');
    }
}
