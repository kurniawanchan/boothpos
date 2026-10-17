<?php

namespace Tests\Unit;

use App\Services\ProductCodeGenerator;
use PHPUnit\Framework\TestCase;

class ProductCodeGeneratorTest extends TestCase
{
    public function test_variant_sku_has_no_leading_zeros(): void
    {
        $generator = new ProductCodeGenerator;

        $this->assertSame('SPF-SH-S-1', $generator->variantSku('SPF-SH-S', 1));
        $this->assertSame('SPF-SH-S-11', $generator->variantSku('SPF-SH-S', 11));
    }

    public function test_sequence_is_read_only_from_skus_that_belong_to_the_prefix(): void
    {
        $generator = new ProductCodeGenerator;

        $this->assertSame(1, $generator->sequenceOf('SPF-SH-S', 'SPF-SH-S-001'));
        $this->assertSame(11, $generator->sequenceOf('SPF-SH-S', 'SPF-SH-S-11'));
        $this->assertSame(1, $generator->sequenceOf('RYUKYSAK', 'RYUKYSAK0001'));
        $this->assertNull($generator->sequenceOf('SPF-SH-S', 'SPF-SH-S'));
        // SKU asing (bukan kode ini) tidak boleh dibaca sebagai nomor urut.
        $this->assertNull($generator->sequenceOf('SPF-SH-S', 'VB12345678'));
    }

    public function test_normalise_sequence_accepts_digits_and_drops_leading_zeros(): void
    {
        $generator = new ProductCodeGenerator;

        $this->assertSame(7, $generator->normaliseSequence('007'));
        $this->assertSame(12, $generator->normaliseSequence(' 12 '));
        $this->assertNull($generator->normaliseSequence('0'));
        $this->assertNull($generator->normaliseSequence('A7'));
        $this->assertNull($generator->normaliseSequence('7-1'));
        $this->assertNull($generator->normaliseSequence(''));
    }

    public function test_trailing_number_reads_the_digits_after_the_last_hyphen(): void
    {
        $generator = new ProductCodeGenerator;

        $this->assertSame(7, $generator->trailingNumber('SPF-PI-S-7'));
        $this->assertNull($generator->trailingNumber('RYUKYSAK0001'));
        $this->assertNull($generator->trailingNumber('SPF-PI-S-MUG'));
    }
}
