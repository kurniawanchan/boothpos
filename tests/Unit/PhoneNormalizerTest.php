<?php

namespace Tests\Unit;

use App\Support\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

/** 090 / US4 — satu bentuk kanonik nomor telepon untuk mencocokkan pelanggan tanpa membuat duplikat. */
class PhoneNormalizerTest extends TestCase
{
    public function test_every_common_way_of_writing_the_same_number_gives_one_canonical_form(): void
    {
        foreach (['081234567890', '+6281234567890', '6281234567890', '0812-3456-7890', '+62 812 3456 7890', '812 3456 7890', ' (0812) 3456 7890 '] as $raw) {
            $this->assertSame('081234567890', PhoneNormalizer::canonical($raw), $raw);
        }
    }

    public function test_rubbish_and_out_of_range_numbers_are_refused(): void
    {
        foreach ([null, '', 'abc', '+++', '0812', '12345', '08123456789012345678'] as $raw) {
            $this->assertNull(PhoneNormalizer::canonical($raw), (string) $raw);
        }
    }

    public function test_a_list_shows_only_the_ends_of_the_number(): void
    {
        $this->assertSame('0812****7890', PhoneNormalizer::masked('+62 812 3456 7890'));
        $this->assertNull(PhoneNormalizer::masked(null));
        $this->assertNull(PhoneNormalizer::masked(''));
        $this->assertSame('••••', PhoneNormalizer::masked('abc'));
        $this->assertStringNotContainsString('3456', (string) PhoneNormalizer::masked('081234567890'));
    }
}
