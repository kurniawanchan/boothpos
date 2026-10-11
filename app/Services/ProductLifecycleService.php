<?php

namespace App\Services;

use App\Models\MenuChange;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\User;
use App\Support\MenuSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 043-products-page-redesign — siklus hidup item: terbit (draft → aktif), diskontinu, aktifkan lagi.
 * SATU-SATUNYA penulis `is_active` untuk tujuan ini (selain impor/form lama yang tetap dijaga hook model
 * Product agar status turunan selalu selaras; research.md D5).
 *
 * Dua mode (research.md D2): rilis MATI → berlaku seketika; rilis AKTIF → membuat `menu_change` yang
 * menunggu rilis (diterapkan ReleaseService lewat `applyNow()` yang sama, jadi satu logika).
 */
class ProductLifecycleService
{
    public function __construct(private ActivityLogger $log, private AvailabilityService $availability) {}

    /**
     * @return array{product: Product, change: MenuChange|null}
     *
     * @throws ValidationException
     */
    public function discontinue(Product $product, User $by, int $reasonCodeId, ?string $note = null): array
    {
        $reason = $this->reason(ReasonCode::DISCONTINUE, $reasonCodeId);

        return DB::transaction(function () use ($product, $by, $reason, $note) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            if ($product->discontinued_at !== null) {
                throw ValidationException::withMessages(['product' => __('products.already_discontinued')])->status(409);
            }

            if (MenuSettings::releaseEnabled()) {
                return ['product' => $product, 'change' => $this->queue($product, $by, MenuChange::KIND_ITEM_DISCONTINUE, ['reason_code_id' => $reason->id, 'note' => $note])];
            }

            $this->applyDiscontinue($product, $by, $reason->id, $note);

            return ['product' => $product->fresh(), 'change' => null];
        });
    }

    /**
     * @return array{product: Product, change: MenuChange|null}
     *
     * @throws ValidationException
     */
    public function reactivate(Product $product, User $by): array
    {
        return DB::transaction(function () use ($product, $by) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            if ($product->discontinued_at === null) {
                throw ValidationException::withMessages(['product' => __('products.not_discontinued')])->status(409);
            }

            if (MenuSettings::releaseEnabled()) {
                return ['product' => $product, 'change' => $this->queue($product, $by, MenuChange::KIND_ITEM_REACTIVATE, [])];
            }

            $this->applyReactivate($product, $by);

            return ['product' => $product->fresh(), 'change' => null];
        });
    }

    /** Mengajukan penerbitan item baru (draft) ke rilis berikutnya. Hanya dipakai saat rilis AKTIF. */
    public function requestPublish(Product $product, User $by): MenuChange
    {
        return $this->queue($product, $by, MenuChange::KIND_ITEM_PUBLISH, []);
    }

    // --- penerapan (dipakai juga oleh ReleaseService) ------------------------------------------------

    public function applyDiscontinue(Product $product, User $by, ?int $reasonCodeId, ?string $note): void
    {
        $product->update(['is_active' => false]);   // hook model: discontinued_at
        $this->availability->clearForDiscontinue($product, $by);
        $reason = $reasonCodeId ? ReasonCode::find($reasonCodeId) : null;

        $this->log->log(
            userId: $by->id, action: 'item_discontinued', entityType: 'Product', entityId: $product->id,
            description: "Item {$product->name} didiskontinu".($reason ? " ({$reason->code})" : '').'.',
            newValues: ['reason_code_id' => $reasonCodeId, 'note' => $note],
        );
    }

    public function applyReactivate(Product $product, User $by): void
    {
        $product->update(['is_active' => true]);    // hook model: diskontinu dicabut

        $this->log->log(userId: $by->id, action: 'item_reactivated', entityType: 'Product', entityId: $product->id, description: "Item {$product->name} diaktifkan lagi.");
    }

    public function applyPublish(Product $product, User $by): void
    {
        $product->update(['is_active' => true]);
        if ($product->published_at === null) {
            $product->forceFill(['published_at' => now()])->save();
        }

        $this->log->log(userId: $by->id, action: 'item_published', entityType: 'Product', entityId: $product->id, description: "Item {$product->name} diterbitkan.");
    }

    // --- internal -----------------------------------------------------------------------------------

    private function reason(string $category, int $id): ReasonCode
    {
        $reason = ReasonCode::query()->active()->where('category', $category)->find($id);
        if ($reason === null) {
            throw ValidationException::withMessages(['reason_code_id' => __('products.reason_invalid')]);
        }

        return $reason;
    }

    private function queue(Product $product, User $by, string $kind, array $extra): MenuChange
    {
        $change = MenuChange::create([
            'kind' => $kind, 'subject_type' => 'product', 'subject_id' => $product->id,
            'payload' => array_merge(['product_name' => $product->name], $extra),
            'status' => MenuChange::PENDING, 'requested_by' => $by->id,
        ]);

        $this->log->log(
            userId: $by->id, action: 'menu_change_requested', entityType: 'Product', entityId: $product->id,
            description: "Perubahan menu diajukan: {$kind} untuk {$product->name}.", newValues: ['change_id' => $change->id, 'kind' => $kind],
        );

        return $change;
    }
}
