<?php

namespace Tests\Unit;

use App\Support\ProductSegment;
use PHPUnit\Framework\TestCase;

class ProductSegmentTest extends TestCase
{
    public function test_accepts_one_to_six_characters_of_letters(): void
    {
        foreach (['S', 'SH', 'SPF', 'SHOP', 'ABCDEF'] as $segment) {
            $this->assertMatchesRegularExpression(ProductSegment::PATTERN, $segment);
        }
    }

    /** Spec 046 FR-001: satu tanda hubung di antara kelompok huruf diterima. */
    public function test_accepts_single_hyphens_between_letter_groups(): void
    {
        foreach (['SH-K', 'S-T', 'A-B-C'] as $segment) {
            $this->assertMatchesRegularExpression(ProductSegment::PATTERN, $segment);
        }
    }

    public function test_rejects_longer_than_six_characters_including_hyphens(): void
    {
        foreach (['ABCDEFG', 'SH-KOPIE', 'A-B-C-D-E-F'] as $segment) {
            $this->assertDoesNotMatchRegularExpression(ProductSegment::PATTERN, $segment);
        }
    }

    public function test_rejects_non_letters_and_misplaced_hyphens(): void
    {
        foreach (['S1', '-SH', 'SH-', 'S--K', 'S H', 'SH_K', ''] as $segment) {
            $this->assertDoesNotMatchRegularExpression(ProductSegment::PATTERN, $segment);
        }
    }

    public function test_the_request_rule_matches_the_same_segments(): void
    {
        $this->assertSame(ProductSegment::PATTERN, ProductSegment::PATTERN_RULE);
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
        $this->assertDoesNotMatchRegularExpression(ProductSegment::CODE_PATTERN, 'SPF-SH-ABCDEFG');
    }

    /** Spec 046: segmen dengan tanda hubung masuk utuh ke kode produk. */
    public function test_code_pattern_accepts_a_hyphenated_segment(): void
    {
        $this->assertMatchesRegularExpression(ProductSegment::CODE_PATTERN, 'SPF-SH-SH-K');
        $this->assertDoesNotMatchRegularExpression(ProductSegment::CODE_PATTERN, 'SPF-SH-SH-K-');
    }

    public function test_the_length_constants_match_the_widths_in_the_database(): void
    {
        $this->assertSame(6, ProductSegment::MAX_LENGTH);
        $this->assertSame(13, ProductSegment::MAX_CODE_LENGTH);
        $this->assertSame(20, ProductSegment::MAX_SKU_LENGTH);
        $this->assertSame(ProductSegment::MAX_CODE_LENGTH, strlen('SPF-SH-'.str_repeat('A', ProductSegment::MAX_LENGTH)));
    }
}
