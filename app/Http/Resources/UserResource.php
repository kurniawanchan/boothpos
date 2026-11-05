<?php

namespace App\Http\Resources;

use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'role' => $this->role ? [
                'id' => $this->role->id,
                'name' => $this->role->name,
            ] : null,
            'is_active' => $this->is_active,
            // 058 — seller yang membatasi pengguna ini; hanya terisi bila relasi `artist` dimuat (relationLoaded).
            'artist_id' => $this->artist_id,
            'artist' => $this->relationLoaded('artist') && $this->artist ? [
                'id' => $this->artist->id,
                'name' => $this->artist->name,
                'code' => $this->artist->code,
            ] : null,
            // password TIDAK PERNAH disertakan (FR-007) — model sudah
            // menyembunyikannya via $hidden, tapi resource ini eksplisit
            // hanya menyusun kolom yang memang boleh terlihat, bukan
            // mengandalkan array cast model.
            'photo_url' => app(ImageUploadService::class)->url($this->photo_path),
            'last_login_at' => $this->last_login_at,
        ];
    }
}
