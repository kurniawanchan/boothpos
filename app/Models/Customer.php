<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CATATAN PERLINDUNGAN DATA: phone, email, dan social_handle adalah data
 * pribadi. Resource untuk model ini TIDAK boleh disertakan pada ekspor
 * atau laporan yang diserahkan ke artist (lihat CustomerResource).
 */
class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'phone', 'email', 'social_handle', 'notes'];
}
