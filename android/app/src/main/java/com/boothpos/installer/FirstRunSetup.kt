package com.boothpos.installer

import android.content.Context
import java.io.File
import java.security.SecureRandom
import java.util.Base64

/**
 * 008-android-installer (US1) — T013/T014/T015. Runs, once, the first
 * time this app is ever opened on a given device. Detected by the
 * absence of an initialized MariaDB data directory (data-model.md's
 * First-Run Marker), not a separate flag — the marker IS the fact.
 *
 * Deliberately reuses what already exists rather than inventing a new
 * setup flow (research.md R4): the same `php artisan migrate` every
 * desktop install already runs, and the same owner-account-creation
 * screen the existing Vue SPA already has for a freshly-migrated,
 * unseeded database — this class's job ends at "the database is ready
 * and the app server can start normally"; it does not render any UI
 * itself.
 */
class FirstRunSetup(private val context: Context, private val runtime: RuntimePaths) {

    /** data-model.md's First-Run Marker — presence of an initialized MariaDB data dir. */
    fun isFirstRun(): Boolean = !runtime.mariaDbDataDir.resolve("mysql").exists()

    /**
     * Initializes a fresh MariaDB data directory and generates a
     * per-installation Laravel `.env` (T015) — never a value baked into
     * the APK, since every installation needs its own APP_KEY (so one
     * installation's encrypted data, e.g. sessions, can never be
     * decrypted by another) and its own local-only DB credentials.
     *
     * Actual process invocation (mariadb-install-db, then `php artisan
     * migrate` against the bundled PHP CLI) happens in
     * RuntimeForegroundService, which calls this only to prepare the
     * data directory and .env BEFORE starting MariaDB for the first
     * time — kept separate from process-lifecycle concerns so this
     * class stays testable without spawning real subprocesses.
     */
    fun prepareEnvironment() {
        extractRuntimeBinaries()
        runtime.mariaDbDataDir.mkdirs()
        runtime.laravelStorageDir.mkdirs()
        runtime.laravelStorageDir.resolve("app/private/payment-proofs").mkdirs()
        runtime.laravelStorageDir.resolve("logs").mkdirs()
        runtime.laravelStorageDir.resolve("framework/cache").mkdirs()
        runtime.laravelStorageDir.resolve("framework/sessions").mkdirs()
        runtime.laravelStorageDir.resolve("framework/views").mkdirs()

        val envFile = runtime.laravelAppDir.resolve(".env")
        if (!envFile.exists()) {
            envFile.writeText(buildEnvFile())
        }
    }

    /**
     * assets/ is read-only and not directly executable — the bundled PHP/
     * MariaDB/mysqldump/tar binaries (T002-T004, packaged into
     * assets/runtime/ by the Gradle build) must be copied to app-private
     * storage with the executable bit set before RuntimeForegroundService
     * can invoke them as subprocesses.
     */
    private fun extractRuntimeBinaries() {
        val binDir = runtime.mariaDbDataDir.parentFile!!.resolve("bin")
        if (binDir.exists() && binDir.listFiles()?.isNotEmpty() == true) return // already extracted on a prior (partial) first run

        binDir.mkdirs()
        val assetManager = context.assets
        val runtimeAssets = assetManager.list("runtime") ?: emptyArray()
        for (name in runtimeAssets) {
            val outFile = binDir.resolve(name)
            assetManager.open("runtime/$name").use { input ->
                outFile.outputStream().use { output -> input.copyTo(output) }
            }
            outFile.setExecutable(true, /* ownerOnly = */ true)
        }
    }

    private fun buildEnvFile(): String {
        // Fresh, random 32-byte key per installation, base64-encoded to
        // match Laravel's own `php artisan key:generate` output shape
        // (`base64:` prefix) — generated here rather than by shelling out
        // to `key:generate` so it exists before the app's first ever PHP
        // invocation needs it.
        val keyBytes = ByteArray(32)
        SecureRandom().nextBytes(keyBytes)
        val appKey = "base64:" + Base64.getEncoder().encodeToString(keyBytes)

        return """
            APP_NAME=BoothPOS
            APP_ENV=production
            APP_KEY=$appKey
            APP_DEBUG=false
            APP_URL=http://127.0.0.1

            # research.md R2 — MariaDB, embedded, on-device. Same DDL/schema
            # as desktop's real MySQL 8; only the engine differs for this
            # one deployment target.
            DB_CONNECTION=mysql
            DB_HOST=127.0.0.1
            DB_PORT=${runtime.mariaDbPort}
            DB_DATABASE=boothpos
            DB_USERNAME=root
            DB_PASSWORD=

            SESSION_DRIVER=file
            QUEUE_CONNECTION=sync
            CACHE_STORE=file

            # T037 — .env.testing is untouched; this file is generated only
            # inside the packaged Android runtime, never committed, never
            # affects desktop dev or CI (plan.md Constitution Check, II).
            MAIL_MAILER=log
        """.trimIndent() + "\n"
    }
}

/** Filesystem layout for the bundled runtime, all under app-private storage — never shared/external storage (spec's scoped-storage constraint). */
class RuntimePaths(context: Context) {
    private val root = context.filesDir.resolve("boothpos-runtime")
    val mariaDbDataDir: File = root.resolve("mariadb-data")
    val laravelAppDir: File = root.resolve("laravel")
    val laravelStorageDir: File get() = laravelAppDir.resolve("storage")
    val mariaDbSocket: File = root.resolve("mariadb.sock")
    val mariaDbPort: Int = 33060 // loopback-only, arbitrary non-conflicting local port
    val phpPort: Int = 8000
}
