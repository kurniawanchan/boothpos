package com.warkop.pos

import android.app.Application
import android.util.Log
import com.warkop.pos.data.RemoteBackend
import com.warkop.pos.data.TokenVault

/** Kontainer manual (research.md D2): objek berumur-aplikasi dibuat sekali di sini. */
class AppContainer(app: Application) {
    val vault = TokenVault(app)
    val backend = RemoteBackend(vault) { line -> if (BuildConfig.DEBUG) Log.d("PosApi", line) }
}

class PosApplication : Application() {
    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
    }
}
