package com.warkop.pos

import android.app.Application
import android.util.Log
import com.warkop.pos.data.RemoteBackend
import com.warkop.pos.data.TokenVault
import com.warkop.pos.print.PrintQueue
import com.warkop.pos.print.PrinterService
import com.warkop.pos.print.PrinterSettings
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import java.io.File

/** Kontainer manual (research.md D2): objek berumur-aplikasi dibuat sekali di sini. */
class AppContainer(app: Application) {
    val vault = TokenVault(app)
    // Log HTTP (metode + path + status; tanpa header/badan) hanya pada build debuggable. BuildConfig sengaja tidak dipakai:
    // menyalakannya membuat tugas javac berjalan dan jlink JDK 24 gagal pada AGP 8.2 (lihat research D2).
    private val debuggable = (app.applicationInfo.flags and android.content.pm.ApplicationInfo.FLAG_DEBUGGABLE) != 0
    val backend = RemoteBackend(vault) { line -> if (debuggable) Log.d("PosApi", line) }

    /** 050 / T016 — lingkup koroutin berumur aplikasi (worker cetak dan pengecekan koneksi). */
    val appScope = CoroutineScope(SupervisorJob() + Dispatchers.Default)

    /** 050 / T016 — satu-satunya printer service. Antrean dan pilihan printer disimpan di filesDir (tidak di-backup). */
    val printer = PrinterService(
        context = app,
        queue = PrintQueue(File(app.filesDir, "print-queue.json")),
        settings = PrinterSettings(File(app.filesDir, "printer.json")),
        fetchReceipt = backend::receipt,
        scope = appScope,
    )
}

class PosApplication : Application() {
    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
        // 050 / T026 — pengecekan koneksi sekali saat dibuka; tidak mencetak apa pun dan tidak menunda aplikasi.
        container.appScope.launch { container.printer.checkConnection() }
    }
}
