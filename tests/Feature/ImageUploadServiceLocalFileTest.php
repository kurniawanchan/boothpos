<?php

namespace Tests\Feature;

use App\Services\ImageUploadService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 068 (research D8) — foto lokal (dibawa seeder katalog, bukan unggahan HTTP) melewati aturan unggah yang sama:
 * MIME dari isi berkas, batas ukuran, nama acak di disk public; berkas sumber tidak dipindah.
 */
class ImageUploadServiceLocalFileTest extends TestCase
{
    private function jpeg(int $extraBytes = 0): string
    {
        $path = sys_get_temp_dir().'/wjm-photo-'.uniqid().'.jpg';
        imagejpeg(imagecreatetruecolor(8, 8), $path);
        if ($extraBytes > 0) {
            file_put_contents($path, str_repeat("\0", $extraBytes), FILE_APPEND);
        }

        return $path;
    }

    public function test_it_stores_a_local_jpeg_through_the_upload_rules_without_moving_the_source(): void
    {
        Storage::fake('public');
        $source = $this->jpeg();

        $stored = app(ImageUploadService::class)->storeLocalFile($source, 'products');

        $this->assertMatchesRegularExpression('#^products/[0-9a-f-]{36}\.jpg$#', $stored);
        Storage::disk('public')->assertExists($stored);
        $this->assertFileExists($source);
    }

    public function test_it_rejects_a_file_that_is_not_an_image(): void
    {
        Storage::fake('public');
        $text = sys_get_temp_dir().'/wjm-text-'.uniqid().'.jpg';
        file_put_contents($text, 'bukan gambar');

        $this->expectException(\InvalidArgumentException::class);
        app(ImageUploadService::class)->storeLocalFile($text, 'products');
    }

    public function test_it_rejects_a_file_over_the_upload_size_limit(): void
    {
        Storage::fake('public');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ukuran berkas melebihi batas maksimal.');
        app(ImageUploadService::class)->storeLocalFile($this->jpeg(ImageUploadService::MAX_KILOBYTES * 1024 + 1), 'products');
    }
}
