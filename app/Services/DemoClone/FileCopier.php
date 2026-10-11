<?php

namespace App\Services\DemoClone;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Menyalin berkas tersimpan ke NAMA ACAK BARU (konvensi ImageUploadService).
 * Baris DEMO tidak pernah berbagi path dengan LIVE: kalau berbagi, menghapus/
 * mengganti gambar atau bukti bayar di DEMO akan menghapus berkas LIVE
 * (ImageUploadService::delete, PaymentService::deletePayment).
 *
 * Beberapa baris yang menunjuk SATU berkas sumber berbagi SATU salinan
 * (hanya di antara baris DEMO). Berkas sumber hanya dibaca. Bila transaksi
 * gagal, discardCopies() menghapus semua salinan yang dibuat run ini.
 */
final class FileCopier
{
    public int $copied = 0;

    public int $bytes = 0;

    public int $missing = 0;

    /** @var array<string,?string> "disk|sumber" => path salinan (null = sumber hilang) */
    private array $map = [];

    /** @var list<array{0:string,1:string}> */
    private array $created = [];

    public function copy(string $disk, ?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $key = "{$disk}|{$path}";

        if (array_key_exists($key, $this->map)) {
            return $this->map[$key];
        }

        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            $this->missing++;

            return $this->map[$key] = null;
        }

        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $name = Str::uuid()->toString().($extension !== '' ? ".{$extension}" : '');
        $destination = ($directory === '.' || $directory === '') ? $name : "{$directory}/{$name}";

        $storage->copy($path, $destination);
        $this->created[] = [$disk, $destination];

        if ($storage->size($destination) !== $storage->size($path)) {
            throw new CloneException("Salinan berkas di disk {$disk} tidak sama ukurannya dengan sumber.");
        }

        $this->copied++;
        $this->bytes += $storage->size($destination);

        return $this->map[$key] = $destination;
    }

    public function discardCopies(): void
    {
        foreach ($this->created as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        $this->created = [];
        $this->map = [];
    }

    /**
     * Untuk simulasi (dry run): berapa berkas/byte yang akan disalin.
     *
     * @param  list<string>  $paths  path sumber yang sudah unik
     * @return array{files:int,bytes:int,missing:int}
     */
    public static function measure(string $disk, array $paths): array
    {
        $storage = Storage::disk($disk);
        $out = ['files' => 0, 'bytes' => 0, 'missing' => 0];

        foreach ($paths as $path) {
            if ($storage->exists($path)) {
                $out['files']++;
                $out['bytes'] += $storage->size($path);
            } else {
                $out['missing']++;
            }
        }

        return $out;
    }
}
