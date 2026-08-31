<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\LicenseGate;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    /**
     * Dibaca UI untuk sembunyikan/tampilkan tombol "Tambah Artist", BUKAN
     * sumber otorisasi. Penegakan sesungguhnya tetap di ArtistPolicy —
     * lihat komentar di endpoint ini pada openapi-pos-mvp.yaml.
     */
    public function features(): JsonResponse
    {
        return response()->json([
            'multi_artist_enabled' => LicenseGate::multiArtistEnabled(),
            'artist_count' => LicenseGate::activeArtistCount(),
            'artist_limit_reached' => LicenseGate::artistLimitReached(),
        ]);
    }
}
