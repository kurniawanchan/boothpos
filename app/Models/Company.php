<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasDataMode, HasFactory, SoftDeletes;

    protected $fillable = [
        'business_type_id',
        'license_id',
        'name',
        'address',
        'contact_name',
        'contact_email',
        'contact_phone',
        'owner_user_id',
        'status',
        'activation_code_hash',
        'activation_code_expires_at',
        'activated_at',
    ];

    protected $hidden = [
        'activation_code_hash',
    ];

    protected function casts(): array
    {
        return [
            'activation_code_expires_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function businessType(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function activationNotifications(): HasMany
    {
        return $this->hasMany(CompanyActivationNotification::class);
    }

    // 019-billing-system (second expansion, research.md R10) — dipakai
    // sebagai delete guard: company dengan riwayat invoice tidak boleh
    // dihapus, meski license_id-nya sendiri TIDAK dijadikan guard (setiap
    // company selalu punya satu license by design, itu bukan guard, itu
    // no-op).
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
