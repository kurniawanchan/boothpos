<?php

namespace Tests\Support;

use App\Models\DiningTable;
use App\Models\PaymentChannel;
use App\Models\ItemAvailabilityMark;
use App\Models\PaymentProof;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\SelfServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use App\Support\SelfServiceQrToken;
use Illuminate\Support\Str;

/**
 * 090 — fixture bersama tes pesan mandiri: meja + token QR, kanal QRIS aktif bergambar, bukti bayar siap pakai, dan
 * pembentuk payload kiriman. Memakai RestaurantFixtures (menu, shift, stasiun) supaya tidak ada rangkaian kedua.
 */
trait SelfServiceFixtures
{
    use RestaurantFixtures;

    protected DiningTable $ssTable;

    protected string $ssToken;

    protected PaymentChannel $ssChannel;

    protected function ssSetUp(bool $withChannel = true): void
    {
        $this->ssTable = DiningTable::factory()->create(['name' => 'Meja 5']);
        $this->ssToken = SelfServiceQrToken::issue($this->ssTable);
        if ($withChannel) {
            $this->ssChannel = PaymentChannel::factory()->create(['type' => 'qr_ewallet', 'provider' => 'QRIS', 'qr_image_path' => 'payment-channels/qris-test.png']);
        }
    }

    protected function ssUrl(string $path = ''): string
    {
        return '/api/v1/self-service/'.$this->ssToken.$path;
    }

    /** Tanda 86 aktif (global, 3 jam) untuk sebuah produk, memakai alasan "habis" bawaan seed. */
    protected function ssMarkSoldOut(Product $product): void
    {
        ItemAvailabilityMark::create([
            'product_id' => $product->id, 'outlet_id' => null,
            'reason_code_id' => (int) ReasonCode::where('category', 'sold_out')->orderBy('sort')->value('id'),
            'marked_at' => now(), 'expires_at' => now()->addHours(3),
        ]);
    }

    /** Bukti bayar yang belum terpakai (tanpa `uploaded_by`, seperti unggahan pelanggan publik). */
    protected function ssProofToken(): string
    {
        $token = (string) Str::uuid();
        PaymentProof::create([
            'proof_token' => $token, 'file_path' => 'payment-proofs/test-'.$token.'.jpg', 'original_name' => 'bukti.jpg',
            'mime_type' => 'image/jpeg', 'file_size' => 1234, 'captured_via' => 'upload', 'uploaded_by' => null, 'created_at' => now(),
        ]);

        return $token;
    }

    /**
     * @param  list<array<string, mixed>>  $items  [{variant_id, qty, note?, modifier_selections?}]
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function ssPayload(array $items, array $extra = []): array
    {
        return array_merge([
            'local_ref' => (string) Str::uuid(),
            'service_type' => 'dine_in',
            'proof_token' => $this->ssProofToken(),
            'items' => $items,
        ], $extra);
    }

    /**
     * Pelanggan mengirim pesanan lewat rute PUBLIK (tanpa login), lalu mengembalikan barisnya. Hitungan pembatas laju
     * dikosongkan dulu: tes staf mengirim banyak kiriman beruntun dan bukan itu yang diuji.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    protected function ssPlaceOrder(array $items, array $extra = [], ?string $token = null): SelfServiceOrder
    {
        Cache::flush();
        $url = '/api/v1/self-service/'.($token ?? $this->ssToken).'/orders';
        $res = $this->postJson($url, $this->ssPayload($items, $extra))->assertCreated();

        return SelfServiceOrder::withoutGlobalScopes()->where('receipt_token', $res->json('data.receipt_token'))->firstOrFail();
    }

    /** Pemilik (memegang semua izin, termasuk verifikasi QRIS) dan menu `self_service`. */
    protected function ssOwner(): User
    {
        return User::factory()->create(['role' => 'owner']);
    }

    /** Kasir bawaan: menu `self_service` tetapi TANPA izin `payment.qris.verify`. */
    protected function ssCashier(): User
    {
        return User::factory()->create(['role' => 'cashier']);
    }
}
