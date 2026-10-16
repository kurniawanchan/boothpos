package com.warkop.core.network

import java.util.UUID

/**
 * Kunci Idempotency-Key per NIAT pengguna (contracts/android-client.md). Niat yang sama selama belum terkonfirmasi
 * memakai kunci yang sama, sehingga coba-ulang tidak pernah menggandakan baris/tiket/pembayaran; niat yang sudah
 * terkonfirmasi dilepas dan niat berikutnya mendapat kunci baru. Kunci disimpan di memori: kunci hilang bila aplikasi
 * mati, dan itu aman karena server menolak ganda dengan `client_ref`/`local_ref` pada tiap jenis tulis.
 */
class IntentKeys(private val newKey: () -> String = { UUID.randomUUID().toString() }) {
    private val pending = HashMap<String, String>()

    fun keyFor(intent: String): String = pending.getOrPut(intent) { newKey() }

    fun settled(intent: String) {
        pending.remove(intent)
    }

    fun clear() = pending.clear()
}
