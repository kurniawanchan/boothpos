<?php

namespace App\Services\Backup;

/**
 * Batas ke proses eksternal (mysqldump / mysql). Dipisah jadi interface karena
 * dua alasan nyata, bukan sekadar kenyamanan test: (1) perintah itu tidak ada
 * di setiap lingkungan (image dev tidak membawanya, image toko membawanya), dan
 * (2) restore MENIMPA seluruh database, jadi test tidak boleh menjalankannya
 * sungguhan. Implementasi nyata: MysqlCliDumper.
 */
interface DatabaseDumper
{
    /**
     * Tulis dump SQL lengkap database aktif ke $toFile.
     *
     * @throws BackupException bila dump gagal atau berkas kosong
     */
    public function dump(string $toFile): void;

    /**
     * Muat $fromFile (dump SQL) ke database aktif — MENIMPA isinya.
     *
     * @throws BackupException bila pemulihan gagal
     */
    public function restore(string $fromFile): void;
}
