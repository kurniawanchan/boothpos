<?php

namespace Tests\Unit;

use App\Support\ProductSegment;
use PHPUnit\Framework\TestCase;

class ProductSegmentTest extends TestCase
{
    public function test_accepts_one_to_three_letters(): void
    {
        foreach (['S', 'SH', 'SPF'] as $segment) {
            $this->assertMatchesRegularExpression(ProductSegment::PATTERN, $segment);
        }
    }

    public function test_rejects_longer_segments_and_non_letters(): void
    {
        foreach (['ABCD', 'S1', 'S-', 'S H'] as $segment) {
            $this->assertDoesNotMatchRegularExpression(ProductSegment::PATTERN, $segment);
        }
    }

    public function test_normalise_trims_and_uppercases_and_treats_blank_as_null(): void
    {
        $this->assertSame('SPF', ProductSegment::normalise(' spf '));
        $this->assertNull(ProductSegment::normalise(null));
        $this->assertNull(ProductSegment::normalise('   '));
    }

    public function test_code_pattern_accepts_short_segment_and_rejects_empty_or_long(): void
    {
        $this->assertMatchesRegularExpression(ProductSegment::CODE_PATTERN, 'SPF-SH-S');
        $this->assertMatchesRegularExpression(ProductSegment::CODE_PATTERN, 'SPF-SH-SPF');
        $this->assertDoesNotMatchRegularExpression(ProductSegment::CODE_PATTERN, 'SPF-SH-');
        $this->assertDoesNotMatchRegularExpression(ProductSegment::CODE_PATTERN, 'SPF-SH-ABCD');
    }
}
