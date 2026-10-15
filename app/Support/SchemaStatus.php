<?php

namespace App\Support;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * 035-po-row-actions — apakah struktur database TERTINGGAL dari versi aplikasi?
 *
 * Kasus nyata yang melatarinya: sebuah cabang bermigrasi di-merge, tetapi
 * container `app` sudah berjalan — entrypoint hanya menjalankan
 * `php artisan migrate` saat container STARTED — sehingga kolom baru belum
 * ada dan layar yang membacanya gagal dengan galat SQL mentah. Class ini
 * satu-satunya yang menjawab pertanyaan itu (dipakai GET /settings/features
 * untuk banner owner/admin); aplikasi TIDAK PERNAH menjalankan migrasi dari
 * sebuah request web (perubahan skema di bawah lalu lintas hidup, hak akses,
 * dan kunci tabel) — admin yang menerapkannya.
 */
final class SchemaStatus
{
    /**
     * Nama migrasi yang ada di disk tetapi belum tercatat di tabel `migrations`.
     * Tabel `migrations` belum ada (instalasi kosong) = tidak ada yang dilaporkan.
     * Tidak pernah melempar: pengecekan ini tidak boleh merusak endpoint yang
     * memakainya.
     *
     * @return array<int, string>
     */
    public static function pendingMigrations(?Migrator $migrator = null): array
    {
        try {
            $migrator ??= app('migrator');
            $repository = $migrator->getRepository();

            if (! $repository->repositoryExists()) {
                return [];
            }

            $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));

            return array_values(array_diff(array_keys($files), $repository->getRan()));
        } catch (Throwable) {
            return [];
        }
    }
}
