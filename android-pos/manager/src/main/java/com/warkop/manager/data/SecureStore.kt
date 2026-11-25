package com.warkop.manager.data

import android.content.Context
import com.warkop.core.network.KeystoreSecretStore

/**
 * 094 / T033 — rahasia aplikasi manajemen (profil server, outlet terpilih, token sesi). Implementasi enkripsi SAMA dengan
 * aplikasi kasir ([KeystoreSecretStore]); berkas dan alias kunci sendiri supaya dua aplikasi tidak saling menimpa.
 * Kata sandi TIDAK PERNAH disimpan. Nilai tidak pernah dicatat ke log.
 */
class SecureStore(context: Context) : KeystoreSecretStore(context, "wjm_manager_vault", "wjm_manager_vault_key") {
    companion object {
        const val LOCAL_URL = "server_local_url"
        const val ONLINE_URL = "server_online_url"
        const val ACTIVE_PROFILE = "server_active"
        const val OUTLET_ID = "outlet_id"
        const val OUTLET_NAME = "outlet_name"
        const val OUTLET_MODE = "outlet_mode"
        const val TOKEN = "session_token"
        /** Outlet terpilih disimpan per profil server: id outlet tidak sama antar-server. */
        fun perProfile(key: String, profile: ServerKind): String = "${key}_${profile.name.lowercase()}"
    }
}
