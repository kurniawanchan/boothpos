<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasDataMode, HasFactory, SoftDeletes;

    protected $fillable = [
        'invoice_number', 'company_id', 'license_id', 'subtotal', 'discount',
        'grand_total', 'due_date', 'status', 'paid_at', 'payment_information', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // 019-billing-system (data-model.md) — snapshot referensi lisensi yang
    // ditagih; harga di License boleh berubah kapan saja tanpa memengaruhi
    // subtotal/grand_total invoice yang sudah dibuat (FR-013).
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
