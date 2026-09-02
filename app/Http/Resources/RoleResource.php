<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'menu_keys' => $this->menu_keys,
            'is_system_default' => $this->is_system_default,
            // Dihitung di controller lewat withCount('users') dan dibatasi
            // ke pengguna aktif — dipasok siap pakai supaya frontend tidak
            // perlu request kedua, dan supaya pesan penolakan 409 (lihat
            // RoleController::destroy) mengutip angka yang sama persis.
            'user_count' => $this->active_users_count ?? 0,
        ];
    }
}
