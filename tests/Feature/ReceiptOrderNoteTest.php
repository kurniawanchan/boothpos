<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** 078 / US4 — struk membawa catatan kasir untuk seluruh order (`notes`); null bila tidak ada. Catatan per baris tetap di `items[].note`. */
class ReceiptOrderNoteTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    private int $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->tab = $this->apiOpenTab($this->makeShift($this->cashier)->id)->json('id');
        $this->apiAddLine($this->tab, $this->makeVariant(stock: 5, price: 10_000)->id, 1, ['note' => 'tanpa gula']);
    }

    private function pay(): void
    {
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 10_000]])->assertOk();
    }

    public function test_the_receipt_carries_the_order_note(): void
    {
        $this->putJson("/api/v1/tabs/{$this->tab}/note", ['notes' => 'Ambil jam 3, es dipisah'])->assertOk();
        $this->pay();

        $this->getJson("/api/v1/orders/{$this->tab}/receipt")->assertOk()
            ->assertJsonPath('notes', 'Ambil jam 3, es dipisah')
            ->assertJsonPath('items.0.note', 'tanpa gula');
    }

    public function test_a_receipt_without_a_note_returns_null_not_an_empty_string(): void
    {
        $this->pay();

        $this->getJson("/api/v1/orders/{$this->tab}/receipt")->assertOk()->assertJsonPath('notes', null);
    }

    public function test_a_cleared_note_is_null_on_the_receipt(): void
    {
        $this->putJson("/api/v1/tabs/{$this->tab}/note", ['notes' => 'sementara'])->assertOk();
        $this->putJson("/api/v1/tabs/{$this->tab}/note", ['notes' => ''])->assertOk();
        $this->pay();

        $this->getJson("/api/v1/orders/{$this->tab}/receipt")->assertOk()->assertJsonPath('notes', null);
    }

    public function test_an_order_of_the_other_data_mode_has_no_receipt(): void
    {
        $this->pay();
        $demo = ModeGate::runAs('demo', fn () => $this->makeOpenOrder($this->makeShift($this->cashier), $this->cashier, ['service_type' => 'takeaway', 'notes' => 'rahasia demo']));

        $this->getJson("/api/v1/orders/{$demo->id}/receipt")->assertNotFound();
    }
}
