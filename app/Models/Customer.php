<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CATATAN PERLINDUNGAN DATA: phone, email, dan social_handle adalah data
 * pribadi. Resource untuk model ini TIDAK boleh disertakan pada ekspor
 * atau laporan yang diserahkan ke artist (lihat CustomerResource).
 */
class Customer extends Model
{
    use HasDataMode, HasFactory, SoftDeletes;

    protected $fillable = ['name', 'phone', 'email', 'social_handle', 'notes'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function preorders(): HasMany
    {
        return $this->hasMany(Preorder::class);
    }
}
