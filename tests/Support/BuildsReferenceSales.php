<?php

namespace Tests\Support;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Event;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * 055 — penolong bersama untuk test referensi transaksi (catatan + gambar). Dipakai OrderReferenceTest
 * dan OrderReferenceImagesTest supaya keduanya membuat penjualan dan mengunggah berkas dengan cara yang sama.
 *
 * Nama metode sengaja tidak memakai `put`/`get`/`call`/`seed` (bentrok fatal dengan TestCase Laravel — 054).
 */
trait BuildsReferenceSales
{
    protected User $cashier;

    protected User $owner;

    protected ProductVariant $variant;

    protected CashierSession $session;

    protected function setUpReferenceSales(): void
    {
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $product = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id]);
        $this->variant = $product->variants()->create(['sku' => 'REF0001', 'sell_price' => 25000, 'cost_price' => 10000, 'current_stock' => 100]);
        $this->session = CashierSession::factory()->create([
            'event_id' => Event::factory()->create(['status' => 'active'])->id, 'user_id' => $this->cashier->id, 'status' => 'open',
        ]);
    }

    protected function as(User $user): static
    {
        $this->actingAs($user, 'sanctum');

        return $this;
    }

    protected function sale(): Order
    {
        $this->as($this->cashier);
        $id = $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]], 'payments' => [['method' => 'cash', 'amount' => 50000]],
        ])->assertCreated()->json('id');

        return Order::find($id);
    }

    protected function png(string $name = 'wa.png', int $kb = 20): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40)->size($kb);
    }

    /** @return list<UploadedFile> */
    protected function pngs(int $count): array
    {
        return array_map(fn (int $i) => $this->png("wa-{$i}.png"), range(1, $count));
    }

    /**
     * Berkas yang hanya diganti ekstensinya (isinya teks). UploadedFile::fake() melaporkan jenis dari EKSTENSI,
     * jadi untuk benar-benar menguji pembacaan ISI dipakai berkas sungguhan di disk.
     */
    protected function fakePngWithTextContent(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ref');
        file_put_contents($path, 'ini bukan gambar sama sekali');

        return new UploadedFile($path, 'palsu.png', 'image/png', null, true);
    }

    protected function saveRef(Order $order, array $body)
    {
        // PUT multipart: Laravel hanya mengurai multipart pada POST, jadi dikirim sebagai POST + _method.
        return $this->post("/api/v1/orders/{$order->id}/reference", $body + ['_method' => 'PUT'], ['Accept' => 'application/json']);
    }

    protected function imageUrl(Order $order, int $imageId): string
    {
        return "/api/v1/orders/{$order->id}/reference/images/{$imageId}";
    }
}
