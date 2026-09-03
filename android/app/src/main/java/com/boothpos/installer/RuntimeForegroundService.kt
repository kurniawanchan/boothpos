package com.boothpos.installer

import android.app.Notification
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Intent
import android.os.Binder
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import java.io.File
import java.net.HttpURLConnection
import java.net.URL

/**
 * 008-android-installer — T008/T009. Foreground service (research.md R3):
 * Android aggressively suspends bare background processes, so the
 * bundled PHP/MariaDB processes this app depends on run under a
 * foreground service with a persistent, visible notification — the
 * standard, expected pattern for "a local server this app depends on",
 * not a workaround.
 *
 * Starts MariaDB first, waits for it to accept connections, THEN starts
 * PHP (research.md R6 — sequenced, not raced) — MainActivity's WebView
 * must never be pointed at a not-yet-listening 127.0.0.1.
 *
 * Contains NO BoothPOS business logic (research.md R1) — it only starts/
 * stops two external processes and reports their status.
 */
class RuntimeForegroundService : Service() {

    private val binder = LocalBinder()
    private lateinit var paths: RuntimePaths
    private lateinit var firstRunSetup: FirstRunSetup
    private var mariaDbProcess: Process? = null
    private var phpProcess: Process? = null
    private var state = RuntimeState()
    private var listener: StateListener? = null

    interface StateListener {
        fun onStateChanged(state: RuntimeState)
    }

    inner class LocalBinder : Binder() {
        fun getService(): RuntimeForegroundService = this@RuntimeForegroundService
    }

    override fun onCreate() {
        super.onCreate()
        paths = RuntimePaths(this)
        firstRunSetup = FirstRunSetup(this, paths)
    }

    override fun onBind(intent: Intent?): IBinder = binder

    fun setStateListener(listener: StateListener?) {
        this.listener = listener
        listener?.onStateChanged(state)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startForeground(NOTIFICATION_ID, buildNotification())
        if (state.mariaDbStatus == ProcessStatus.NOT_STARTED) {
            Thread(::startRuntime, "boothpos-runtime-boot").start()
        }
        return START_STICKY
    }

    private fun updateState(transform: (RuntimeState) -> RuntimeState) {
        state = transform(state)
        listener?.onStateChanged(state)
    }

    private fun startRuntime() {
        val isFirstRun = firstRunSetup.isFirstRun()
        updateState { it.copy(isFirstRun = isFirstRun) }

        if (isFirstRun) {
            firstRunSetup.prepareEnvironment()
        }

        // --- MariaDB -------------------------------------------------
        updateState { it.copy(mariaDbStatus = ProcessStatus.STARTING) }
        try {
            if (isFirstRun) {
                runProcess(
                    binary("mariadb-install-db"),
                    "--datadir=${paths.mariaDbDataDir.absolutePath}",
                    "--auth-root-authentication-method=normal",
                )
            }
            mariaDbProcess = startProcess(
                binary("mariadbd"),
                "--datadir=${paths.mariaDbDataDir.absolutePath}",
                "--port=${paths.mariaDbPort}",
                "--bind-address=127.0.0.1",
                "--socket=${paths.mariaDbSocket.absolutePath}",
                "--skip-networking=0",
            )
            waitForTcpReady(paths.mariaDbPort, timeoutMs = 20_000)
            updateState { it.copy(mariaDbStatus = ProcessStatus.READY) }
        } catch (e: Exception) {
            updateState { it.copy(mariaDbStatus = ProcessStatus.FAILED, errorMessage = e.message) }
            return
        }

        // --- First-run migration (only after MariaDB is confirmed ready) ---
        if (isFirstRun) {
            try {
                runArtisan("migrate", "--force")
            } catch (e: Exception) {
                updateState { it.copy(mariaDbStatus = ProcessStatus.FAILED, errorMessage = "Migrasi database gagal: ${e.message}") }
                return
            }
        }

        // --- PHP -------------------------------------------------------
        updateState { it.copy(phpStatus = ProcessStatus.STARTING) }
        try {
            phpProcess = startProcess(
                binary("php"),
                "-S", "127.0.0.1:${paths.phpPort}",
                "-t", File(paths.laravelAppDir, "public").absolutePath,
                workingDir = paths.laravelAppDir,
            )
            waitForHttpReady(paths.phpPort, timeoutMs = 20_000)
            updateState { it.copy(phpStatus = ProcessStatus.READY, localPort = paths.phpPort) }
        } catch (e: Exception) {
            updateState { it.copy(phpStatus = ProcessStatus.FAILED, errorMessage = e.message) }
        }
    }

    /** T022 — invoked by BackupRestoreBridge; runs `php artisan {args}` against the already-running on-device app, reusing BackupPos/RestorePos unmodified. */
    fun runArtisan(vararg args: String): String {
        val process = startProcess(
            binary("php"),
            File(paths.laravelAppDir, "artisan").absolutePath,
            *args,
            workingDir = paths.laravelAppDir,
        )
        val output = process.inputStream.bufferedReader().readText()
        val exitCode = process.waitFor()
        if (exitCode != 0) {
            val stderr = process.errorStream.bufferedReader().readText()
            throw RuntimeException("artisan ${args.joinToString(" ")} exited $exitCode: $stderr")
        }
        return output
    }

    private fun binary(name: String): String =
        File(filesDir, "boothpos-runtime/bin/$name").absolutePath

    private fun startProcess(vararg command: String, workingDir: File? = null): Process =
        ProcessBuilder(*command).apply {
            workingDir?.let { directory(it) }
            redirectErrorStream(false)
        }.start()

    private fun runProcess(vararg command: String) {
        val process = startProcess(*command)
        val exitCode = process.waitFor()
        if (exitCode != 0) {
            val stderr = process.errorStream.bufferedReader().readText()
            throw RuntimeException("${command.first()} exited $exitCode: $stderr")
        }
    }

    /** T009 — readiness polling. Plain TCP connect for MariaDB (no HTTP layer to probe). */
    private fun waitForTcpReady(port: Int, timeoutMs: Long) {
        val deadline = System.currentTimeMillis() + timeoutMs
        while (System.currentTimeMillis() < deadline) {
            try {
                java.net.Socket("127.0.0.1", port).close()
                return
            } catch (e: Exception) {
                Thread.sleep(200)
            }
        }
        throw RuntimeException("MariaDB tidak merespons dalam ${timeoutMs}ms")
    }

    /**
     * T009 — reuses the existing app's own catch-all SPA route (`GET /`,
     * routes/web.php) as the health check, rather than adding a new
     * dedicated endpoint: once PHP's built-in server can serve that
     * route with a 200, the app is genuinely ready to be shown in the
     * WebView (research.md R3's "don't race the WebView load").
     */
    private fun waitForHttpReady(port: Int, timeoutMs: Long) {
        val deadline = System.currentTimeMillis() + timeoutMs
        while (System.currentTimeMillis() < deadline) {
            try {
                val connection = URL("http://127.0.0.1:$port/").openConnection() as HttpURLConnection
                connection.connectTimeout = 500
                connection.readTimeout = 500
                if (connection.responseCode in 200..399) return
            } catch (e: Exception) {
                // not ready yet
            }
            Thread.sleep(200)
        }
        throw RuntimeException("Server PHP tidak merespons dalam ${timeoutMs}ms")
    }

    private fun buildNotification(): Notification {
        val pendingIntent = PendingIntent.getActivity(
            this, 0, Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_IMMUTABLE,
        )
        return NotificationCompat.Builder(this, NOTIFICATION_CHANNEL_ID)
            .setContentTitle(getString(R.string.notification_running_title))
            .setContentText(getString(R.string.notification_running_body))
            .setSmallIcon(android.R.drawable.ic_menu_manage) // placeholder — replace with real brand icon per T012's note
            .setContentIntent(pendingIntent)
            .setOngoing(true)
            .build()
    }

    override fun onDestroy() {
        mariaDbProcess?.destroy()
        phpProcess?.destroy()
        super.onDestroy()
    }

    companion object {
        const val NOTIFICATION_CHANNEL_ID = "boothpos_runtime"
        const val NOTIFICATION_ID = 1
    }
}
