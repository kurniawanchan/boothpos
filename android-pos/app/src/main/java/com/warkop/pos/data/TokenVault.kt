package com.warkop.pos.data

import android.content.Context
import com.warkop.core.network.KeystoreSecretStore

/**
 * Penyimpanan rahasia terminal: URL server, token penyiapan, token sesi PIN. PIN TIDAK PERNAH disimpan di sini atau di mana pun.
 * 094 — enkripsinya (AES-GCM + Android Keystore) kini di [KeystoreSecretStore] (:core-network), dipakai juga aplikasi
 * manajemen. Nama berkas `wjm_vault` dan alias `wjm_pos_vault` TIDAK BOLEH berubah: tablet yang sudah terpasang akan
 * kehilangan konfigurasi dan token-nya.
 */
class TokenVault(context: Context) : KeystoreSecretStore(context, "wjm_vault", "wjm_pos_vault") {
    companion object {
        /** 077 — mode data terakhir yang diketahui, supaya mulai tanpa jaringan memakai pilihan terminal mode yang benar. */
        const val SYSTEM_MODE = "system_mode"
        const val SERVER_URL = "server_url"
        const val ADMIN_TOKEN = "admin_token"
        const val SESSION_TOKEN = "session_token"
        const val SESSION_EXPIRES = "session_expires_ms"
        /**
         * 077 — outlet dan terminal berbeda per mode data (server: HasDataMode), jadi pilihan tersimpan per mode. Kunci LIVE
         * tetap tanpa akhiran supaya tablet yang sudah terpasang sebelum 077 tidak kehilangan pilihannya; bila ternyata
         * dipasang di DEMO, pemeriksaan terminal saat mulai (`validateSavedTerminal`) membuangnya dan penyiapan diulang.
         */
        fun terminalKey(base: String, demo: Boolean): String = if (demo) "${base}_demo" else base

        const val OUTLET_ID = "outlet_id"
        const val OUTLET_NAME = "outlet_name"
        const val DEVICE_ID = "device_id"
        const val DEVICE_NAME = "device_name"

        /** 064 — kode terminal (T01…) sudah tidak dipakai; konstanta ini hanya untuk MEMBERSIHKAN nilai lama saat reset. */
        const val DEVICE_CODE = "device_code"
    }
}
