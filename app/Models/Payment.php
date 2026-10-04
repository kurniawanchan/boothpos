<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasDataMode;

    protected $fillable = [
        'order_id', 'preorder_id', 'purchase_order_id', 'channel_id', 'method', 'purpose', 'amount',
        'verification', 'verified_by', 'verified_at', 'reject_reason', 'paid_at', 'notes',
        'reference', 'client_ref', 'session_id', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'verified_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function preorder(): BelongsTo { return $this->belongsTo(Preorder::class); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function channel(): BelongsTo { return $this->belongsTo(PaymentChannel::class, 'channel_id'); }
    public function proofs(): HasMany { return $this->hasMany(PaymentProof::class); }

    /** 028 — siapa yang mencatat pembayaran ini (NULL untuk baris lama). */
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }

    /** 028 — shift kasir tempat uang ini DITERIMA (NULL untuk pembayaran pre-order). */
    public function session(): BelongsTo { return $this->belongsTo(CashierSession::class, 'session_id'); }

    /**
     * 031-optional-payment-proof — bukti yang BERLAKU pada pembayaran ini: baris
     * `payment_proofs` yang belum ter-supersede (yang terbaru bila, secara
     * defensif, ada lebih dari satu). Memakai relasi `proofs` bila sudah dimuat
     * supaya presenter daftar tidak memicu query per baris (N+1).
     */
    public function currentProof(): ?PaymentProof
    {
        $proofs = $this->relationLoaded('proofs') ? $this->proofs : $this->proofs()->get();

        return $proofs->whereNull('superseded_at')->sortByDesc('id')->first();
    }

    /**
     * 031 (spec FR-009, jawaban klarifikasi Q1=C) — siapa boleh menambah/mengubah/
     * mengganti konfirmasi (bukti, referensi, catatan) pembayaran ini: owner/admin,
     * atau user yang mencatat pembayaran ini. Pembayaran lama tanpa pencatat
     * (`recorded_by` NULL) hanya bisa diubah owner/admin. Tunai tak punya
     * konfirmasi, jadi tak pernah bisa diubah. SATU-SATUNYA definisi aturan ini:
     * dipakai service, guard controller, dan flag di presenter.
     */
    public function confirmationEditableBy(User $user): bool
    {
        return $this->method !== 'cash' && $this->mayManageConfirmation($user);
    }

    /**
     * Bagian "siapa"-nya saja (tanpa mempertimbangkan metode): owner/admin atau
     * pencatat pembayaran. Dipisah supaya guard controller bisa membedakan 403
     * (bukan pihak yang berhak) dari 422 (pembayaran tunai tak punya konfirmasi).
     */
    public function mayManageConfirmation(User $user): bool
    {
        return $user->isOwnerOrAdmin()
            || ($this->recorded_by !== null && (int) $this->recorded_by === (int) $user->id);
    }

    /**
     * 031 (FR-014) — siapa boleh MEMBUKA file bukti: owner/admin dan pengunggah
     * (aturan lama PaymentProofController, perlindungan BOLA agar kasir A tak bisa
     * membaca bukti kasir B), ditambah pencatat pembayaran untuk bukti yang
     * BERLAKU — supaya bukti yang ditambahkan owner belakangan tetap bisa dibuka
     * kasir pemilik pembayaran itu. Bukti yang sudah ter-supersede hanya untuk
     * owner/admin dan pengunggahnya (akses audit).
     */
    public function proofViewableBy(User $user, ?PaymentProof $proof = null): bool
    {
        $proof ??= $this->currentProof();

        if ($proof === null) {
            return false;
        }

        if ($user->isOwnerOrAdmin() || (int) $proof->uploaded_by === (int) $user->id) {
            return true;
        }

        return $proof->superseded_at === null
            && $this->recorded_by !== null
            && (int) $this->recorded_by === (int) $user->id;
    }
}
