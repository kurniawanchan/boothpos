<?php

namespace Tests\Unit;

use App\Support\ProductSlug;
use PHPUnit\Framework\TestCase;

/**
 * Customer commerce — aturan slug produk (satu definisi untuk hook model DAN
 * backfill migrasi): Str::slug(nama) dipotong 140, cadangan "produk", lalu
 * akhiran -2, -3, … sampai bebas.
 */
class ProductSlugTest extends TestCase
{
    public function test_base_is_a_lowercase_hyphenated_ascii_slug(): void
    {
        $this->assertSame('gantungan-kunci-sakura', ProductSlug::base('  Gantungan Kunci   SAKURA '));
        $this->assertSame('kaos-edisi-2', ProductSlug::base('Kaos (Edisi #2)!'));
    }

    public function test_base_falls_back_to_produk_when_nothing_survives(): void
    {
        $this->assertSame('produk', ProductSlug::base('!!!'));
        $this->assertSame('produk', ProductSlug::base(''));
        $this->assertSame('produk', ProductSlug::base(null));
    }

    public function test_base_is_truncated_to_140_characters_without_a_trailing_hyphen(): void
    {
        // 139 huruf + spasi + huruf: potongan 140 jatuh tepat di tanda hubung.
        $slug = ProductSlug::base(str_repeat('a', 139).' bbbb');

        $this->assertSame(str_repeat('a', 139), $slug);
        $this->assertLessThanOrEqual(ProductSlug::MAX_BASE, strlen(ProductSlug::base(str_repeat('kata ', 60))));
    }

    public function test_dedupe_returns_the_base_when_free_and_the_next_free_suffix_otherwise(): void
    {
        $this->assertSame('poster', ProductSlug::dedupe('poster', []));
        $this->assertSame('poster-2', ProductSlug::dedupe('poster', ['poster' => true]));
        $this->assertSame('poster-4', ProductSlug::dedupe('poster', ['poster' => true, 'poster-2' => true, 'poster-3' => true]));
        // Celah di tengah diisi lebih dulu; slug lain yang hanya berawalan sama tidak dihitung.
        $this->assertSame('poster-2', ProductSlug::dedupe('poster', ['poster' => true, 'poster-3' => true, 'poster-besar' => true]));
    }

    public function test_the_longest_possible_slug_still_fits_the_column(): void
    {
        $slug = ProductSlug::dedupe(str_repeat('a', ProductSlug::MAX_BASE), [str_repeat('a', ProductSlug::MAX_BASE) => true]);

        $this->assertLessThanOrEqual(ProductSlug::COLUMN_LENGTH, strlen($slug));
    }
}
