package com.boothpos.installer

import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.ServiceConnection
import android.graphics.Color
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.IBinder
import android.view.Gravity
import android.view.Menu
import android.view.MenuItem
import android.view.ViewGroup
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat

/**
 * 008-android-installer — T010/T016. Hosts the WebView pointed at the
 * on-device Laravel app (research.md R1) and shows a branded loading
 * state (research.md R6) while RuntimeForegroundService starts MariaDB +
 * PHP. Deliberately thin: no BoothPOS business logic lives here — once
 * the WebView loads, the existing Vue SPA takes over exactly as it does
 * on desktop (spec FR-003).
 */
class MainActivity : AppCompatActivity(), RuntimeForegroundService.StateListener {

    private var service: RuntimeForegroundService? = null
    private lateinit var webView: WebView
    private lateinit var loadingOverlay: FrameLayout
    private lateinit var loadingLabel: TextView
    private lateinit var retryButton: Button
    private var webViewLoaded = false

    // T023 — Storage Access Framework pickers for backup (save) / restore
    // (open), the Android-native counterpart to the desktop version's
    // manually-triggered `php artisan app:backup`/`app:restore` CLI calls.
    private val createBackupDocument = registerForActivityResult(
        ActivityResultContracts.CreateDocument("application/zip")
    ) { uri -> uri?.let { onBackupDestinationChosen(it) } }

    private val openRestoreDocument = registerForActivityResult(
        ActivityResultContracts.OpenDocument()
    ) { uri -> uri?.let { onRestoreFileChosen(it) } }

    private val connection = object : ServiceConnection {
        override fun onServiceConnected(name: ComponentName?, binder: IBinder?) {
            service = (binder as RuntimeForegroundService.LocalBinder).getService()
            service?.setStateListener(this@MainActivity)
        }

        override fun onServiceDisconnected(name: ComponentName?) {
            service = null
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        buildLayout()

        val intent = Intent(this, RuntimeForegroundService::class.java)
        ContextCompat.startForegroundService(this, intent)
        bindService(intent, connection, Context.BIND_AUTO_CREATE)
    }

    override fun onDestroy() {
        service?.setStateListener(null)
        unbindService(connection)
        super.onDestroy()
    }

    // T023 — native menu, the lower-friction option chosen over a web-UI
    // bridge: backup/restore are device-lifecycle actions (this device's
    // only copy of its data), conceptually closer to "app settings" than
    // to a BoothPOS business screen, and this keeps BackupRestoreBridge
    // entirely out of the Vue SPA/JS-bridge surface.
    override fun onCreateOptionsMenu(menu: Menu): Boolean {
        menuInflater.inflate(R.menu.main_menu, menu)
        return true
    }

    override fun onOptionsItemSelected(item: MenuItem): Boolean = when (item.itemId) {
        R.id.action_backup -> { triggerBackup(); true }
        R.id.action_restore -> { triggerRestore(); true }
        else -> super.onOptionsItemSelected(item)
    }

    private fun triggerBackup() {
        val timestamp = java.text.SimpleDateFormat("yyyy-MM-dd_HHmmss", java.util.Locale.US).format(java.util.Date())
        createBackupDocument.launch("boothpos-backup-$timestamp.zip")
    }

    private fun onBackupDestinationChosen(destination: Uri) {
        val bridge = service?.let { BackupRestoreBridge(this, it) } ?: return
        Toast.makeText(this, R.string.backup_in_progress, Toast.LENGTH_SHORT).show()
        Thread {
            try {
                val archive = bridge.createBackupArchive()
                contentResolver.openOutputStream(destination)?.use { out ->
                    archive.inputStream().use { it.copyTo(out) }
                }
                archive.delete()
                runOnUiThread { Toast.makeText(this, R.string.backup_success, Toast.LENGTH_LONG).show() }
            } catch (e: Exception) {
                runOnUiThread { Toast.makeText(this, getString(R.string.backup_failed) + " (${e.message})", Toast.LENGTH_LONG).show() }
            }
        }.start()
    }

    private fun triggerRestore() {
        openRestoreDocument.launch(arrayOf("application/zip"))
    }

    /** spec FR-009 — explicit confirmation BEFORE any overwrite. */
    private fun onRestoreFileChosen(uri: Uri) {
        AlertDialog.Builder(this)
            .setTitle(R.string.restore_confirm_title)
            .setMessage(R.string.restore_confirm_message)
            .setPositiveButton(R.string.restore_confirm_proceed) { _, _ -> performRestore(uri) }
            .setNegativeButton(R.string.restore_confirm_cancel, null)
            .show()
    }

    private fun performRestore(uri: Uri) {
        val bridge = service?.let { BackupRestoreBridge(this, it) } ?: return
        Thread {
            try {
                bridge.restoreFromArchive(uri)
                runOnUiThread { Toast.makeText(this, R.string.restore_success, Toast.LENGTH_LONG).show() }
            } catch (e: BackupRestoreBridge.InvalidBackupException) {
                // spec FR-010 — clear rejection, not a partial apply.
                runOnUiThread { Toast.makeText(this, R.string.restore_invalid_file, Toast.LENGTH_LONG).show() }
            } catch (e: Exception) {
                runOnUiThread { Toast.makeText(this, getString(R.string.restore_failed) + " (${e.message})", Toast.LENGTH_LONG).show() }
            }
        }.start()
    }

    /**
     * spec FR-011 — shown once, the first time this device is ever
     * confirmed first-run-initialized (not nagged on every launch).
     * Persisted via SharedPreferences, an Android-side-only flag — never
     * written to the Laravel app's own data, since it has nothing to do
     * with BoothPOS business state.
     */
    private fun maybeShowOnlyCopyWarning() {
        val prefs = getSharedPreferences("boothpos_installer", MODE_PRIVATE)
        if (prefs.getBoolean("only_copy_warning_shown", false)) return

        AlertDialog.Builder(this)
            .setTitle(R.string.only_copy_warning_title)
            .setMessage(R.string.only_copy_warning_message)
            .setPositiveButton(R.string.only_copy_warning_dismiss) { _, _ ->
                prefs.edit().putBoolean("only_copy_warning_shown", true).apply()
            }
            .setCancelable(false)
            .show()
    }

    override fun onStateChanged(state: RuntimeState) {
        runOnUiThread {
            when {
                state.hasFailed -> showFailure(state.errorMessage)
                state.isReady && !webViewLoaded -> {
                    loadWebView(state.localPort!!)
                    if (state.isFirstRun) maybeShowOnlyCopyWarning()
                }
                state.isFirstRun -> loadingLabel.text = getString(R.string.splash_first_run)
                state.phpStatus == ProcessStatus.STARTING -> loadingLabel.text = getString(R.string.splash_starting_server)
                else -> loadingLabel.text = getString(R.string.splash_starting_database)
            }
        }
    }

    private fun loadWebView(port: Int) {
        webViewLoaded = true
        webView.webViewClient = object : WebViewClient() {
            override fun onPageFinished(view: WebView?, url: String?) {
                loadingOverlay.visibility = android.view.View.GONE
            }
        }
        // 127.0.0.1 only (research.md R1) — this WebView never navigates
        // to anything outside the bundled, on-device app.
        webView.loadUrl("http://127.0.0.1:$port/")
    }

    private fun showFailure(message: String?) {
        loadingLabel.text = getString(R.string.splash_failed)
        retryButton.visibility = android.view.View.VISIBLE
    }

    /**
     * Built programmatically rather than via an XML layout resource —
     * keeps this scaffold self-contained in one file for now; a real
     * implementation would likely move this to res/layout/activity_main.xml
     * once brand visual design (T012's placeholder note) is finalized.
     */
    private fun buildLayout() {
        val root = FrameLayout(this)

        webView = WebView(this).apply {
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true // required — Pinia/localStorage-backed auth token storage
            layoutParams = FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)
        }
        root.addView(webView)

        loadingOverlay = FrameLayout(this).apply {
            setBackgroundColor(Color.parseColor("#2F9E6E"))
            layoutParams = FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)
        }
        val column = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            layoutParams = FrameLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER)
        }
        val spinner = ProgressBar(this)
        loadingLabel = TextView(this).apply {
            setTextColor(Color.WHITE)
            textSize = 16f
            setPadding(0, 32, 0, 0)
            text = getString(R.string.splash_starting_database)
        }
        retryButton = Button(this).apply {
            text = getString(R.string.splash_retry)
            visibility = android.view.View.GONE
            setOnClickListener { recreate() }
        }
        column.addView(spinner)
        column.addView(loadingLabel)
        column.addView(retryButton)
        loadingOverlay.addView(column)
        root.addView(loadingOverlay)

        setContentView(root)
    }
}
