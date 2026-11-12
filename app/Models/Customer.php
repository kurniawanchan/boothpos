<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use App\Models\Concerns\HasSellerScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CATATAN PERLINDUNGAN DATA: phone, email, dan social_handle adalah data
 * pribadi. Resource untuk model ini TIDAK boleh disertakan pada ekspor
 * atau laporan yang diserahkan ke artist (lihat CustomerResource).
 */
class Customer extends Model
{
    use HasDataMode, HasSellerScope, HasFactory, SoftDeletes;

    protected $fillable = ['name', 'phone', 'email', 'social_handle', 'notes', 'address', 'artist_id'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function preorders(): HasMany
    {
        return $this->hasMany(Preorder::class);
    }

    /**
     * Customer commerce — akun aplikasi pelanggan (paling banyak satu). Kontak
     * yang dibuat staf TIDAK punya akun; hanya registrasi yang membuatnya.
     */
    public function account(): HasOne
    {
        return $this->hasOne(CustomerAccount::class);
    }

    /** Customer commerce — pesanan dari aplikasi pelanggan (bukan penjualan POS / pre-order). */
    public function customerOrders(): HasMany
    {
        return $this->hasMany(CustomerOrder::class);
    }
}
