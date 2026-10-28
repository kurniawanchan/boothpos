<?php

namespace Tests\Feature;

use App\Support\CatalogImageThumbnail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 049 — gambar kecil untuk Excel katalog: diperkecil, tidak pernah melempar, tidak menjatuhkan ekspor. */
class CatalogImageThumbnailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function storeImage(string $path, \GdImage $image, string $format = 'jpeg'): void
    {
        ob_start();
        $format === 'png' ? imagepng($image) : imagejpeg($image);
        Storage::disk('public')->put($path, (string) ob_get_clean());
    }

    public function test_a_large_picture_is_scaled_down_to_the_longest_side_as_a_jpeg(): void
    {
        $this->storeImage('variants/big.jpg', imagecreatetruecolor(800, 600));

        $bytes = CatalogImageThumbnail::jpeg('variants/big.jpg');
        [$w, $h, $type] = getimagesizefromstring($bytes);

        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertSame(160, $w);
        $this->assertSame(120, $h); // rasio 4:3 terjaga
    }

    public function test_a_small_picture_is_not_enlarged(): void
    {
        $this->storeImage('variants/small.jpg', imagecreatetruecolor(50, 40));

        [$w, $h] = getimagesizefromstring(CatalogImageThumbnail::jpeg('variants/small.jpg'));

        $this->assertSame([50, 40], [$w, $h]);
    }

    public function test_a_transparent_png_gets_a_white_background_not_black(): void
    {
        $png = imagecreatetruecolor(40, 40);
        imagealphablending($png, false);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127)); // sepenuhnya transparan
        $this->storeImage('variants/alpha.png', $png, 'png');

        $thumb = imagecreatefromstring(CatalogImageThumbnail::jpeg('variants/alpha.png'));
        $pixel = imagecolorat($thumb, 20, 20);

        $this->assertGreaterThan(0xF0, ($pixel >> 16) & 0xFF); // kanal merah ~ putih
        $this->assertGreaterThan(0xF0, $pixel & 0xFF);
    }

    public function test_a_missing_a_corrupt_or_an_empty_path_gives_null_and_never_throws(): void
    {
        Storage::disk('public')->put('variants/corrupt.jpg', 'ini bukan gambar');

        $this->assertNull(CatalogImageThumbnail::jpeg('variants/none.jpg'));
        $this->assertNull(CatalogImageThumbnail::jpeg('variants/corrupt.jpg'));
        $this->assertNull(CatalogImageThumbnail::jpeg(null));
        $this->assertNull(CatalogImageThumbnail::jpeg(''));
    }

    public function test_a_picture_over_the_pixel_limit_is_skipped_instead_of_exhausting_memory(): void
    {
        $this->storeImage('variants/huge.jpg', imagecreatetruecolor(100, 100));

        $this->assertNull(CatalogImageThumbnail::jpeg('variants/huge.jpg', 160, maxPixels: 5_000)); // 10.000 px > 5.000
    }
}
