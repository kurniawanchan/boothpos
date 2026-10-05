<?php

namespace Tests\Unit;

use App\Support\PreorderSellerSubtotals;
use PHPUnit\Framework\TestCase;

/**
 * 039 — subtotal per penjual pada laporan pre-order: jumlah dari baris yang
 * ditampilkan, dihitung dalam sen. Satu-satunya implementasi (dipakai API dan
 * ekspor Excel), jadi layar, file, dan Grand Total tidak bisa berbeda.
 */
class PreorderSellerSubtotalsTest extends TestCase
{
    private function row(int $artistId, string $name, string $status, string $completeness, int $count, string $value, string $collected, string $outstanding): array
    {
        return [
            'artist_id' => $artistId, 'artist_name' => $name, 'status' => $status, 'payment_completeness' => $completeness,
            'preorder_count' => $count, 'total_order_value' => $value, 'total_collected' => $collected, 'total_outstanding' => $outstanding,
        ];
    }

    private function sapphire(): array
    {
        // Angka dari layar yang melatari fitur ini (baris "Paid" punya collected > order value).
        return [
            $this->row(3, 'sapphirefiless', 'ordered', 'unpaid', 13, '930000.00', '0.00', '930000.00'),
            $this->row(3, 'sapphirefiless', 'dp_paid', 'paid', 14, '845000.00', '1099000.00', '0.00'),
            $this->row(3, 'sapphirefiless', 'dp_paid', 'partially_paid', 1, '145000.00', '140000.00', '5000.00'),
        ];
    }

    public function test_it_sums_each_sellers_rows(): void
    {
        $subtotals = PreorderSellerSubtotals::fromRows($this->sapphire());

        $this->assertSame([[
            'artist_id' => 3, 'artist_name' => 'sapphirefiless', 'preorder_count' => 28,
            'total_order_value' => '1920000.00', 'total_collected' => '1239000.00', 'total_outstanding' => '935000.00',
        ]], $subtotals);
    }

    public function test_outstanding_is_the_sum_of_the_row_values_not_value_minus_collected(): void
    {
        $subtotals = PreorderSellerSubtotals::fromRows($this->sapphire())[0];

        // 930000 + 0 + 5000 = 935000; (value - collected) would be 1920000 - 1239000 = 681000.
        $this->assertSame('935000.00', $subtotals['total_outstanding']);
        $this->assertNotSame(
            number_format((float) $subtotals['total_order_value'] - (float) $subtotals['total_collected'], 2, '.', ''),
            $subtotals['total_outstanding']
        );
    }

    public function test_sellers_keep_the_order_in_which_they_first_appear_and_a_single_row_seller_gets_one(): void
    {
        $rows = array_merge($this->sapphire(), [
            $this->row(9, 'tofynx', 'ordered', 'unpaid', 4, '140000.00', '0.00', '140000.00'),
            $this->row(12, 'aaa-first-alphabetically', 'ordered', 'unpaid', 1, '15000.00', '0.00', '15000.00'),
        ]);

        $subtotals = PreorderSellerSubtotals::fromRows($rows);

        $this->assertSame([3, 9, 12], array_column($subtotals, 'artist_id'));
        $this->assertSame(4, $subtotals[1]['preorder_count']);
        $this->assertSame('140000.00', $subtotals[1]['total_order_value']);
    }

    public function test_money_is_summed_in_cents_without_floating_point_drift(): void
    {
        $rows = [
            $this->row(1, 'A', 'ordered', 'unpaid', 1, '0.10', '0.10', '0.10'),
            $this->row(1, 'A', 'dp_paid', 'paid', 1, '0.20', '0.20', '0.20'),
            $this->row(1, 'A', 'arrived', 'paid', 1, '0.30', '0.30', '0.30'),
            $this->row(1, 'A', 'settled', 'paid', 1, '33333.33', '33333.33', '33333.33'),
        ];

        $subtotal = PreorderSellerSubtotals::fromRows($rows)[0];

        $this->assertSame('33333.93', $subtotal['total_order_value']); // 0.10+0.20+0.30+33333.33
        $this->assertSame('33333.93', $subtotal['total_collected']);
        $this->assertSame('33333.93', $subtotal['total_outstanding']);
        $this->assertSame(4, $subtotal['preorder_count']);
        $this->assertIsInt($subtotal['preorder_count']);
    }

    public function test_non_contiguous_rows_of_the_same_seller_are_still_grouped_by_artist_id(): void
    {
        $rows = [
            $this->row(1, 'Same Name', 'ordered', 'unpaid', 2, '100.00', '0.00', '100.00'),
            $this->row(2, 'Same Name', 'ordered', 'unpaid', 5, '500.00', '0.00', '500.00'),
            $this->row(1, 'Same Name', 'dp_paid', 'paid', 3, '300.00', '300.00', '0.00'),
        ];

        $subtotals = PreorderSellerSubtotals::fromRows($rows);

        $this->assertCount(2, $subtotals);
        $this->assertSame(5, $subtotals[0]['preorder_count']); // artis 1: 2 + 3
        $this->assertSame('400.00', $subtotals[0]['total_order_value']);
        $this->assertSame(5, $subtotals[1]['preorder_count']);
    }

    public function test_no_rows_means_no_subtotals_and_the_shape_is_exact(): void
    {
        $this->assertSame([], PreorderSellerSubtotals::fromRows([]));

        $keys = array_keys(PreorderSellerSubtotals::fromRows($this->sapphire())[0]);
        $this->assertSame(['artist_id', 'artist_name', 'preorder_count', 'total_order_value', 'total_collected', 'total_outstanding'], $keys);
    }

    public function test_the_subtotals_add_up_to_the_sum_of_all_rows(): void
    {
        $rows = array_merge($this->sapphire(), [$this->row(9, 'tofynx', 'ordered', 'unpaid', 4, '140000.00', '0.00', '140000.00')]);

        $subtotals = PreorderSellerSubtotals::fromRows($rows);

        foreach (['total_order_value', 'total_collected', 'total_outstanding'] as $field) {
            $this->assertEqualsWithDelta(
                array_sum(array_map(fn ($r) => (float) $r[$field], $rows)),
                array_sum(array_map(fn ($r) => (float) $r[$field], $subtotals)),
                0.0001,
                $field
            );
        }
        $this->assertSame(array_sum(array_column($rows, 'preorder_count')), array_sum(array_column($subtotals, 'preorder_count')));
    }
}
