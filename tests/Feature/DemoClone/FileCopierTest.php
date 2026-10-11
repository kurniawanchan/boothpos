<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\CloneException;
use App\Services\DemoClone\FileCopier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileCopierTest extends TestCase
{
    // TestCase::setUp() menulis baris aktivasi lisensi, jadi DB harus termigrasi
    // dan dibersihkan per test walau test ini sendiri tidak memakai DB.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_it_copies_to_a_new_random_name_in_the_same_directory(): void
    {
        Storage::disk('public')->put('products/a.jpg', 'AAAA');
        $copier = new FileCopier;

        $new = $copier->copy('public', 'products/a.jpg');

        $this->assertNotSame('products/a.jpg', $new);
        $this->assertStringStartsWith('products/', $new);
        $this->assertStringEndsWith('.jpg', $new);
        $this->assertSame('AAAA', Storage::disk('public')->get($new));
        $this->assertSame('AAAA', Storage::disk('public')->get('products/a.jpg'));
        $this->assertSame(1, $copier->copied);
        $this->assertSame(4, $copier->bytes);
    }

    public function test_rows_pointing_at_the_same_source_share_one_copy(): void
    {
        Storage::disk('public')->put('products/a.jpg', 'AAAA');
        $copier = new FileCopier;

        $first = $copier->copy('public', 'products/a.jpg');
        $second = $copier->copy('public', 'products/a.jpg');

        $this->assertSame($first, $second);
        $this->assertSame(1, $copier->copied);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_the_same_path_on_another_disk_is_a_different_file(): void
    {
        Storage::disk('public')->put('x/a.jpg', 'PUB');
        Storage::disk('local')->put('x/a.jpg', 'PRIV');
        $copier = new FileCopier;

        $this->assertSame('PUB', Storage::disk('public')->get($copier->copy('public', 'x/a.jpg')));
        $this->assertSame('PRIV', Storage::disk('local')->get($copier->copy('local', 'x/a.jpg')));
    }

    public function test_null_empty_and_missing_sources_yield_null_without_failing(): void
    {
        $copier = new FileCopier;

        $this->assertNull($copier->copy('public', null));
        $this->assertNull($copier->copy('public', ''));
        $this->assertNull($copier->copy('public', 'products/gone.jpg'));
        $this->assertNull($copier->copy('public', 'products/gone.jpg'));
        $this->assertSame(0, $copier->copied);
        $this->assertSame(1, $copier->missing);
    }

    public function test_a_failed_copy_throws_a_clear_exception_without_path_or_values(): void
    {
        $fs = \Mockery::mock();
        $fs->shouldReceive('exists')->with('products/secret-name.jpg')->andReturn(true);
        $fs->shouldReceive('copy')->once()->andReturn(false);
        // Ukuran "kebetulan sama": tanpa pemeriksaan hasil copy(), kegagalan itu lolos
        // diam-diam dan baris DEMO akan menunjuk berkas yang tidak pernah ada.
        $fs->shouldReceive('size')->andReturn(10);
        Storage::shouldReceive('disk')->with('public')->andReturn($fs);

        $copier = new FileCopier;

        try {
            $copier->copy('public', 'products/secret-name.jpg');
            $this->fail('Seharusnya CloneException');
        } catch (CloneException $e) {
            $this->assertSame('Gagal menyalin berkas di disk public.', $e->getMessage());
            $this->assertStringNotContainsString('secret-name', $e->getMessage());
        }

        $this->assertSame(0, $copier->copied);
    }

    public function test_discard_removes_only_the_copies(): void
    {
        Storage::disk('public')->put('products/a.jpg', 'AAAA');
        $copier = new FileCopier;
        $new = $copier->copy('public', 'products/a.jpg');

        $copier->discardCopies();

        Storage::disk('public')->assertMissing($new);
        Storage::disk('public')->assertExists('products/a.jpg');
    }

    public function test_measure_counts_distinct_existing_files_and_missing_ones(): void
    {
        Storage::disk('public')->put('products/a.jpg', 'AAAA');
        Storage::disk('public')->put('products/b.jpg', 'BB');

        $this->assertSame(
            ['files' => 2, 'bytes' => 6, 'missing' => 1],
            FileCopier::measure('public', ['products/a.jpg', 'products/b.jpg', 'products/gone.jpg']),
        );
    }
}
