<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pola unggah defensif yang dipakai bersama oleh gambar produk, kategori,
 * QR channel pembayaran, dan gambar hasil impor massal (Task 5 & 6):
 * pemeriksaan MIME ulang di luar rule 'mimes' Laravel (baca isi berkas,
 * bukan ekstensi/nama), batas ukuran, dan nama berkas ACAK — meniru
 * PaymentProofController::store(), tapi disatukan di sini supaya empat
 * pemakai tidak menduplikasi aturan yang sama dan berisiko melenceng.
 *
 * Berbeda dari bukti pembayaran, gambar-gambar ini WAJIB bisa dirender
 * langsung oleh UI POS tanpa endpoint otorisasi terpisah, jadi selalu
 * disimpan di disk 'public' (storage/app/public, dilayani lewat
 * /storage/... setelah `php artisan storage:link`).
 */
class ImageUploadService
{
    public const ALLOWED_MIME = ['image/jpeg', 'image/png'];

    public const MAX_KILOBYTES = 5120; // 5 MB, sama dengan PaymentProofController.

    /**
     * @throws \InvalidArgumentException bila tipe berkas tidak didukung.
     */
    public function store(UploadedFile $file, string $directory): string
    {
        $actualMime = $file->getMimeType();

        if (! in_array($actualMime, self::ALLOWED_MIME, true)) {
            throw new \InvalidArgumentException('Tipe berkas tidak didukung. Hanya JPEG atau PNG.');
        }

        if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
            throw new \InvalidArgumentException('Ukuran berkas melebihi batas maksimal.');
        }

        $randomName = Str::uuid()->toString().'.'.($actualMime === 'image/png' ? 'png' : 'jpg');

        return $file->storeAs($directory, $randomName, 'public');
    }

    /**
     * 068 — berkas lokal (mis. foto katalog WJM yang dibawa seeder), bukan unggahan HTTP. Dibungkus sebagai
     * UploadedFile lalu lewat store(), supaya aturan MIME-dari-isi, batas ukuran, dan nama acak tetap satu.
     * `$test = true` milik Symfony hanya melewati pemeriksaan is_uploaded_file() (CLI tidak punya unggahan);
     * storeAs() menyalin lewat stream, jadi berkas sumber tidak dipindah.
     *
     * @throws \InvalidArgumentException bila tipe atau ukuran berkas tidak didukung.
     */
    public function storeLocalFile(string $absolutePath, string $directory): string
    {
        return $this->store(new UploadedFile($absolutePath, basename($absolutePath), null, null, true), $directory);
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk('public')->delete($path);
        }
    }

    public function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
