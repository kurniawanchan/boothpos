<?php

namespace App\Support;

/**
 * 087 — membawa peringatan "bahan menjadi minus" dari ModifierMaterialConsumption ke controller penyelesaian
 * pesanan (checkout, settle, split) tanpa mengubah tipe kembalian service yang sudah ada dan dipakai banyak
 * pemanggil. Didaftarkan `scoped` (per permintaan), jadi tidak bocor antar-permintaan (research D12).
 */
class MaterialWarningBag
{
    /** @var array<int, array<string, mixed>> */
    private array $warnings = [];

    /** @param array<string, mixed> $warning */
    public function push(array $warning): void
    {
        $this->warnings[] = $warning;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->warnings;
    }

    public function isEmpty(): bool
    {
        return $this->warnings === [];
    }

    /**
     * Tambahkan bidang tingkat atas `material_warnings` ke payload respons, HANYA bila ada peringatan.
     * Tanpa peringatan payload dikembalikan apa adanya, jadi bentuk respons lama tidak berubah sama sekali.
     */
    public function attachTo(mixed $payload): mixed
    {
        if ($this->isEmpty()) {
            return $payload;
        }

        $array = $payload instanceof \Illuminate\Http\Resources\Json\JsonResource ? $payload->resolve(request()) : (array) $payload;

        return $array + ['material_warnings' => $this->warnings];
    }
}
