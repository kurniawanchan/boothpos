package com.boothpos.installer

import android.content.Context
import android.net.Uri
import java.io.File
import java.util.zip.ZipEntry
import java.util.zip.ZipInputStream
import java.util.zip.ZipOutputStream

/**
 * 008-android-installer (US2) — T022/T024. Invokes the EXISTING,
 * UNMODIFIED `php artisan app:backup` / `app:restore` commands
 * (BackupPos.php/RestorePos.php) against the on-device MariaDB via
 * RuntimeForegroundService.runArtisan(), per research.md R5 — this class
 * adds no new backup/restore *logic*, only the Android-side plumbing
 * (zipping the result into one file, handing it to the Storage Access
 * Framework) that reuse alone can't cover, since a bare directory isn't
 * a shareable "file" on Android and `BACKUP_EXTERNAL_PATH` doesn't map
 * to this platform's storage model.
 *
 * Archive shape MUST match contracts/backup-format.md exactly, since a
 * backup taken here must remain restorable on desktop and vice versa.
 */
class BackupRestoreBridge(private val context: Context, private val service: RuntimeForegroundService) {

    class InvalidBackupException(message: String) : Exception(message)

    /**
     * Runs app:backup, then zips the resulting <timestamp>/ directory
     * (database.sql + payment-proofs.tar.gz, contracts/backup-format.md)
     * into a single file under the app's cache dir, ready to be handed
     * to a Storage Access Framework "create document" intent by the
     * caller (T023's UI trigger) — this method does not itself prompt
     * the user for a save location; that's an Activity-level concern.
     */
    fun createBackupArchive(): File {
        val output = service.runArtisan("app:backup")

        // BackupPos.php prints "Selesai: {$backupDir}" as its last line on
        // success — parsed here rather than changing the command's output
        // format, since that command is reused verbatim (research.md R5).
        val backupDirPath = output.lineSequence()
            .lastOrNull { it.startsWith("Selesai:") }
            ?.substringAfter("Selesai:")
            ?.trim()
            ?: throw RuntimeException("Tidak dapat menemukan lokasi cadangan dari keluaran app:backup.")

        val backupDir = File(backupDirPath)
        if (!backupDir.isDirectory) {
            throw RuntimeException("Direktori cadangan tidak ditemukan: $backupDirPath")
        }

        val zipFile = File(context.cacheDir, "boothpos-backup-${backupDir.name}.zip")
        ZipOutputStream(zipFile.outputStream()).use { zip ->
            backupDir.walkTopDown().filter { it.isFile }.forEach { file ->
                val entryName = file.relativeTo(backupDir).path
                zip.putNextEntry(ZipEntry(entryName))
                file.inputStream().use { it.copyTo(zip) }
                zip.closeEntry()
            }
        }
        return zipFile
    }

    /**
     * Validates the picked file matches contracts/backup-format.md's
     * shape (spec FR-010 — reject before applying, don't partially
     * apply), extracts it, then invokes app:restore --force. Caller
     * (T024's UI) is responsible for the explicit confirmation step
     * (spec FR-009) BEFORE calling this — this method assumes
     * confirmation has already happened.
     */
    fun restoreFromArchive(uri: Uri) {
        val extractDir = File(context.cacheDir, "boothpos-restore-${System.currentTimeMillis()}")
        extractDir.mkdirs()

        val resolver = context.contentResolver
        val input = resolver.openInputStream(uri) ?: throw InvalidBackupException(
            context.getString(R.string.restore_invalid_file)
        )

        var hasDatabaseSql = false
        // Zip Slip guard — a crafted "backup" file could otherwise use a
        // `../` entry name (or an absolute path) to write outside
        // extractDir, onto arbitrary app-private storage. Every entry's
        // resolved, canonicalized path is verified to stay inside
        // extractRoot before anything is written (spec FR-010 — reject
        // invalid input outright, never partially apply it).
        val extractRoot = extractDir.canonicalFile
        ZipInputStream(input).use { zip ->
            var entry: ZipEntry?
            while (zip.nextEntry.also { entry = it } != null) {
                val name = entry!!.name
                val outFile = File(extractRoot, name).canonicalFile
                if (!outFile.toPath().startsWith(extractRoot.toPath())) {
                    throw InvalidBackupException(context.getString(R.string.restore_invalid_file))
                }
                if (entry!!.isDirectory) {
                    outFile.mkdirs()
                    continue
                }
                if (name == "database.sql") hasDatabaseSql = true
                outFile.parentFile?.mkdirs()
                outFile.outputStream().use { out -> zip.copyTo(out) }
            }
        }

        if (!hasDatabaseSql) {
            extractDir.deleteRecursively()
            throw InvalidBackupException(context.getString(R.string.restore_invalid_file))
        }

        try {
            service.runArtisan("app:restore", extractDir.absolutePath, "--force")
        } finally {
            extractDir.deleteRecursively()
        }
    }
}
