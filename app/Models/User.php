<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    /**
     * Mass assignment dibatasi eksplisit. 'role' sengaja tidak dibuka
     * lewat endpoint publik mana pun pada Increment 1 — hanya diisi via
     * seeder/tinker oleh admin sistem, karena belum ada modul User
     * Management (F13) pada increment ini.
     */
    protected $fillable = [
        'name',
        'username',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isOwnerOrAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public function canManageMasterData(): bool
    {
        return in_array($this->role, ['owner', 'admin', 'inventory'], true);
    }
}
