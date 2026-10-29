<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * 049 — gambar kecil (JPEG) untuk sel "Gambar" pada Excel katalog. Foto produk diunggah APA ADANYA (tanpa
 * pengecilan saat unggah), jadi satu foto ponsel 12 MP bisa memakan ~48 MB saat didekode GD; di sini setiap
 * gambar diperkecil dan dilepas satu per satu, dan gambar yang piksel-nya melebihi batas dilewati (sel kosong)
 * alih-alih menjatuhkan seluruh ekspor. Tidak pernah melempar: gambar hilang/rusak/terlalu besar = null.
 */
class CatalogImageThumbnail
{
    /** @return string|null byte JPEG, atau null bila tidak ada/rusak/terlalu besar */
    public static function jpeg(?string $publicPath, int $maxSide = 160, int $maxPixels = 40_000_000): ?string
    {
        if (! $publicPath || ! Storage::disk('public')->exists($publicPath)) {
            return null;
        }

        $file = Storage::disk('public')->path($publicPath);
        $size = @getimagesize($file);
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > $maxPixels) {
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($file));
        if ($source === false) {
            return null;
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $ratio = min(1, $maxSide / max($width, $height));
        [$newWidth, $newHeight] = [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];

        // Latar putih: PNG/WebP transparan jika dibuang begitu saja menjadi hitam di JPEG.
        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        imagejpeg($canvas, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return $bytes === '' ? null : $bytes;
    }
}
