<?php

namespace Tests\Unit;

use App\Models\CustomerOrder;
use App\Services\CustomerCommerce\CustomerOrderTransitionService as Transitions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Customer commerce (CONTRACT §9) — tabel transisi status pesanan pelanggan.
 *
 * Tabel ini satu-satunya definisi "dari status mana ke status mana"; test ini
 * menguncinya sebagai DATA (tanpa basis data) supaya perubahan alur selalu
 * disengaja: awaiting_confirmation → confirmed → packing →
 * ready_for_pickup → picked_up (ambil di tempat) atau → shipped → delivered
 * (kurir); pembatalan hanya sebelum barang keluar.
 */
class CustomerOrderTransitionTableTest extends TestCase
{
    public function test_the_table_is_exactly_the_approved_flow(): void
    {
        $this->assertSame([
            'confirm' => ['from' => ['awaiting_confirmation'], 'to' => 'confirmed', 'fulfilment' => null],
            'pack' => ['from' => ['confirmed'], 'to' => 'packing', 'fulfilment' => null],
            'ready' => ['from' => ['packing'], 'to' => 'ready_for_pickup', 'fulfilment' => 'pickup'],
            'pickup' => ['from' => ['ready_for_pickup'], 'to' => 'picked_up', 'fulfilment' => 'pickup'],
            'ship' => ['from' => ['packing'], 'to' => 'shipped', 'fulfilment' => 'courier'],
            'deliver' => ['from' => ['shipped'], 'to' => 'delivered', 'fulfilment' => 'courier'],
            'cancel' => ['from' => ['awaiting_confirmation', 'confirmed', 'packing', 'ready_for_pickup'], 'to' => 'cancelled', 'fulfilment' => null],
        ], Transitions::TRANSITIONS);
    }

    public function test_every_status_in_the_table_is_a_real_status(): void
    {
        foreach (Transitions::TRANSITIONS as $action => $rule) {
            $this->assertContains($rule['to'], CustomerOrder::STATUSES, $action);
            foreach ($rule['from'] as $from) {
                $this->assertContains($from, CustomerOrder::STATUSES, $action);
            }
            if ($rule['fulfilment'] !== null) {
                $this->assertContains($rule['fulfilment'], CustomerOrder::FULFILMENT_METHODS, $action);
            }
        }
    }

    public function test_the_initial_status_has_no_incoming_transition_and_every_other_status_is_reachable(): void
    {
        $targets = array_column(Transitions::TRANSITIONS, 'to');

        $this->assertSame(CustomerOrder::STATUS_AWAITING_CONFIRMATION, Transitions::INITIAL_STATUS);
        $this->assertNotContains(Transitions::INITIAL_STATUS, $targets);
        $this->assertEqualsCanonicalizing(
            array_values(array_diff(CustomerOrder::STATUSES, [Transitions::INITIAL_STATUS])),
            array_values(array_unique($targets)),
        );
    }

    public function test_terminal_statuses_have_no_way_out(): void
    {
        $sources = array_merge(...array_column(Transitions::TRANSITIONS, 'from'));

        $this->assertSame(['delivered', 'picked_up', 'cancelled'], Transitions::TERMINAL_STATUSES);
        foreach (Transitions::TERMINAL_STATUSES as $status) {
            $this->assertNotContains($status, $sources, $status);
        }

        // Barang yang sudah keluar (dikirim / diambil) tidak bisa dibatalkan: tidak ada alur retur di v1.
        $this->assertNotContains('shipped', Transitions::TRANSITIONS['cancel']['from']);
    }

    public function test_every_non_terminal_status_can_move_on(): void
    {
        $sources = array_merge(...array_column(Transitions::TRANSITIONS, 'from'));

        foreach (array_diff(CustomerOrder::STATUSES, Transitions::TERMINAL_STATUSES) as $status) {
            $this->assertContains($status, $sources, $status);
        }
    }

    /**
     * Daftar ini SENGAJA ditulis tangan (bukan diturunkan dari TRANSITIONS):
     * kalau diturunkan dari tabel yang sama, test hanya membuktikan tabel sama
     * dengan dirinya sendiri. Format: [status asal, aksi, metode] => status tujuan.
     *
     * @return array<string, string>
     */
    private static function allowedMoves(): array
    {
        return [
            'awaiting_confirmation|confirm|pickup' => 'confirmed',
            'awaiting_confirmation|confirm|courier' => 'confirmed',
            'confirmed|pack|pickup' => 'packing',
            'confirmed|pack|courier' => 'packing',
            'packing|ready|pickup' => 'ready_for_pickup',
            'ready_for_pickup|pickup|pickup' => 'picked_up',
            'packing|ship|courier' => 'shipped',
            'shipped|deliver|courier' => 'delivered',
            'awaiting_confirmation|cancel|pickup' => 'cancelled',
            'awaiting_confirmation|cancel|courier' => 'cancelled',
            'confirmed|cancel|pickup' => 'cancelled',
            'confirmed|cancel|courier' => 'cancelled',
            'packing|cancel|pickup' => 'cancelled',
            'packing|cancel|courier' => 'cancelled',
            // Pesanan kurir tidak pernah bisa MENCAPAI ready_for_pickup; barisnya ada hanya karena
            // pembatalan tidak bergantung pada metode.
            'ready_for_pickup|cancel|pickup' => 'cancelled',
            'ready_for_pickup|cancel|courier' => 'cancelled',
        ];
    }

    /** Aksi yang hanya milik satu metode; dipakai pada metode lain = `wrong_fulfilment_method`, apa pun statusnya. */
    private const METHOD_ONLY = [
        'ready' => 'pickup',
        'pickup' => 'pickup',
        'ship' => 'courier',
        'deliver' => 'courier',
    ];

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: string}> SETIAP (status, aksi, metode): 8 × 7 × 2 = 112 kasus */
    public static function everyStatusActionMethod(): iterable
    {
        $statuses = ['awaiting_confirmation', 'confirmed', 'packing', 'ready_for_pickup', 'shipped', 'delivered', 'picked_up', 'cancelled'];
        $actions = ['confirm', 'pack', 'ready', 'pickup', 'ship', 'deliver', 'cancel'];
        $allowed = self::allowedMoves();

        foreach ($statuses as $status) {
            foreach ($actions as $action) {
                foreach (['pickup', 'courier'] as $method) {
                    $key = "{$status}|{$action}|{$method}";

                    $expected = match (true) {
                        isset(self::METHOD_ONLY[$action]) && self::METHOD_ONLY[$action] !== $method => '!wrong_fulfilment_method',
                        isset($allowed[$key]) => $allowed[$key],
                        default => '!invalid_transition',
                    };

                    yield $key => [$status, $action, $method, $expected];
                }
            }
        }
    }

    #[DataProvider('everyStatusActionMethod')]
    public function test_every_status_action_and_method_resolves_to_the_approved_outcome(string $status, string $action, string $method, string $expected): void
    {
        // evaluate() murni: tanpa basis data dan tanpa terjemahan, jadi bisa diuji tanpa aplikasi.
        $result = Transitions::evaluate($action, $status, $method);
        $outcome = $result['error'] !== null ? '!'.$result['error'] : $result['to'];

        $this->assertTrue(($result['to'] === null) !== ($result['error'] === null), 'Tepat satu dari to/error terisi.');
        $this->assertSame($expected, $outcome, "{$status}|{$action}|{$method}");
    }

    public function test_the_provider_really_covers_every_combination_once(): void
    {
        $cases = iterator_to_array(self::everyStatusActionMethod());

        $this->assertCount(count(CustomerOrder::STATUSES) * count(Transitions::TRANSITIONS) * count(CustomerOrder::FULFILMENT_METHODS), $cases);
        $this->assertCount(16, array_filter($cases, fn (array $case) => $case[3][0] !== '!'), 'Tepat 16 langkah yang sah.');
    }

    public function test_an_unknown_action_is_a_programming_error_not_a_business_conflict(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Transitions::evaluate('refund', 'confirmed', 'pickup');
    }
}
