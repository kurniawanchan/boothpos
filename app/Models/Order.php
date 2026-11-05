<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use App\Models\Concerns\HasSellerScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasDataMode, HasSellerScope;

    protected $fillable = [
        'order_number', 'event_id', 'session_id', 'customer_id', 'user_id', 'channel',
        'subtotal', 'discount_amount', 'total_amount', 'total_cost', 'paid_amount',
        'change_amount', 'status', 'void_reason', 'local_ref', 'notes',
        'reference_note', 'reference_updated_by', 'reference_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2', 'total_cost' => 'decimal:2',
            'paid_amount' => 'decimal:2', 'change_amount' => 'decimal:2',
            'reference_updated_at' => 'datetime',
        ];
    }

    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function event(): BelongsTo { return $this->belongsTo(Event::class); }
    public function session(): BelongsTo { return $this->belongsTo(CashierSession::class, 'session_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function cashier(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }

    /** 054 — siapa terakhir mengubah referensi transaksi (NULL bila belum pernah / penggunanya sudah dihapus). */
    public function referenceEditor(): BelongsTo { return $this->belongsTo(User::class, 'reference_updated_by'); }

    /** 055 — gambar referensi transaksi (0..10), urut tampil. Satu-satunya penulis: OrderReferenceService. */
    public function referenceImages(): HasMany
    {
        return $this->hasMany(OrderReferenceImage::class)->orderBy('position')->orderBy('id');
    }

    /**
     * 055 — jumlah gambar referensi tanpa kueri per baris di daftar: pakai agregat `withCount('referenceImages')`
     * bila sudah dipilih (daftar Sales), lalu relasi yang sudah dimuat, baru terakhir satu COUNT.
     */
    public function referenceImageCount(): int
    {
        if (array_key_exists('reference_images_count', $this->attributes)) {
            return (int) $this->attributes['reference_images_count'];
        }

        return $this->relationLoaded('referenceImages')
            ? $this->referenceImages->count()
            : $this->referenceImages()->count();
    }

    /** 054 — ada referensi bila ada catatan ATAU gambar (satu definisi; daftar Sales memakai ini juga). */
    public function hasReference(): bool
    {
        return $this->reference_note !== null || $this->referenceImageCount() > 0;
    }

    /** 028 — ringkasan pembayaran turunan (lihat App\Support\PaymentSummary). */
    public function paymentSummary(): array
    {
        return \App\Support\PaymentSummary::for($this);
    }
}
