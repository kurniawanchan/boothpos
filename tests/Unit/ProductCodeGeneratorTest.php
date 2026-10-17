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
}
