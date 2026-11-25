package com.warkop.manager.data

import com.warkop.core.network.ApiClient
import com.warkop.core.network.SecretStore

/** 094 — dua profil alamat server (research D5): lokal (jaringan toko) dan online (lewat internet). */
enum class ServerKind { LOCAL, ONLINE }

data class ServerProfile(val kind: ServerKind, val url: String?) {
    val configured: Boolean get() = !url.isNullOrBlank()

    /** Profil online lewat HTTP polos: kata sandi terlihat di jaringan; layar Pengaturan memperingatkan sebelum login. */
    val insecureOnline: Boolean get() = kind == ServerKind.ONLINE && url?.trim()?.startsWith("https://", ignoreCase = true) == false
}

/**
 * 094 / T034 — sumber tunggal profil server di perangkat. Mengganti profil aktif atau URL profil aktif MENGHAPUS sesi
 * (token milik server lama tidak berlaku di server lain), lewat [onActiveChanged].
 */
class ServerProfiles(private val store: SecretStore, private val onActiveChanged: () -> Unit = {}) {
    fun profile(kind: ServerKind): ServerProfile = ServerProfile(kind, store.get(urlKey(kind)))

    val active: ServerKind
        get() = store.get(SecureStore.ACTIVE_PROFILE)?.let { runCatching { ServerKind.valueOf(it) }.getOrNull() } ?: ServerKind.LOCAL

    fun activeProfile(): ServerProfile = profile(active)

    /** URL dasar `/api/v1/` profil aktif, atau null bila belum diatur. */
    fun activeBaseUrl(): String? = activeProfile().url?.takeIf { it.isNotBlank() }?.let(ApiClient::normalise)

    fun save(kind: ServerKind, url: String?) {
        val cleaned = url?.trim()?.trimEnd('/')?.takeIf { it.isNotEmpty() }
        val changed = cleaned != store.get(urlKey(kind))
        store.put(urlKey(kind), cleaned)
        if (changed && kind == active) onActiveChanged()
    }

    fun activate(kind: ServerKind) {
        if (kind == active) return
        store.put(SecureStore.ACTIVE_PROFILE, kind.name)
        onActiveChanged()
    }

    private fun urlKey(kind: ServerKind) = if (kind == ServerKind.LOCAL) SecureStore.LOCAL_URL else SecureStore.ONLINE_URL
}
