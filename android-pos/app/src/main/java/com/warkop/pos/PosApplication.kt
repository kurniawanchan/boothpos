package com.warkop.pos

import android.app.Application
import android.util.Log
import com.warkop.pos.data.RemoteBackend
import com.warkop.pos.data.TokenVault

/** Kontainer manual (research.md D2): objek berumur-aplikasi dibuat sekali di sini. */
class AppContainer(app: Application) {
    val vault = TokenVault(app)
    // Log HTTP (metode + path + status; tanpa header/badan) hanya pada build debuggable. BuildConfig sengaja tidak dipakai:
    // menyalakannya membuat tugas javac berjalan dan jlink JDK 24 gagal pada AGP 8.2 (lihat research D2).
    private val debuggable = (app.applicationInfo.flags and android.content.pm.ApplicationInfo.FLAG_DEBUGGABLE) != 0
    val backend = RemoteBackend(vault) { line -> if (debuggable) Log.d("PosApi", line) }
}

class PosApplication : Application() {
    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
    }
}
