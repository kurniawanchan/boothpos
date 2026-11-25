package com.warkop.manager

import android.app.Application
import android.util.Log
import com.warkop.core.network.ApiClient
import com.warkop.core.network.RequestContext
import com.warkop.manager.data.Connectivity
import com.warkop.manager.data.ManagerApi
import com.warkop.manager.data.SecureStore
import com.warkop.manager.data.ServerProfiles
import com.warkop.manager.data.SessionRepository

/**
 * Kontainer manual (research D3): objek berumur-aplikasi dibuat sekali di sini, tanpa framework DI.
 *
 * Klien API dibuat ulang bila URL server aktif berubah; header token dan X-Outlet-Id dibaca TIAP permintaan
 * ([RequestContext]), jadi login/ganti outlet tidak perlu membuat klien baru.
 */
class AppContainer(val app: Application) {
    /** Log HTTP (metode + path + status saja) hanya pada build debuggable — pola :app, tanpa BuildConfig. */
    private val debuggable = (app.applicationInfo.flags and android.content.pm.ApplicationInfo.FLAG_DEBUGGABLE) != 0

    val store = SecureStore(app)
    val connectivity = Connectivity(app)
    val profiles: ServerProfiles = ServerProfiles(store) { session.endSession() }
    val session: SessionRepository = SessionRepository(store, profiles, ::api)

    private var cached: Pair<String, ManagerApi>? = null

    /** Klien untuk profil aktif. Melempar bila server belum diatur — alur awal mencegah layar lain dibuka sebelum itu. */
    @Synchronized
    fun api(): ManagerApi {
        val base = profiles.activeBaseUrl() ?: error("Alamat server belum diatur.")
        cached?.takeIf { it.first == base }?.let { return it.second }
        val created = ApiClient.create(
            ManagerApi::class.java,
            base,
            RequestContext(token = { session.token() }, outletId = { session.selectedOutlet()?.id }, deviceId = { null }),
        ) { line -> if (debuggable) Log.d("ManagerApi", line) }
        cached = base to created
        return created
    }

    /** Klien sekali pakai untuk menguji alamat yang BELUM disimpan (layar Pengaturan server). */
    fun probeApi(url: String): ManagerApi =
        ApiClient.create(ManagerApi::class.java, url, RequestContext(token = { null }, outletId = { null }, deviceId = { null }))
}
