package com.warkop.pos.data

/**
 * 077 — antarmuka tipis penyimpanan rahasia terminal. [TokenVault] (Android Keystore) adalah satu-satunya implementasi
 * produksi; uji memakai versi di memori supaya aturan konfigurasi-per-mode bisa diuji tanpa Context.
 */
interface SecretStore {
    fun get(key: String): String?
    fun put(key: String, value: String?)
    fun clear(vararg keys: String)
}
