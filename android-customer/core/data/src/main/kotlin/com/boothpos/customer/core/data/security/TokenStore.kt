package com.boothpos.customer.core.data.security

import com.boothpos.customer.core.data.domain.model.Account
import java.time.Instant

/** Yang disimpan untuk satu sesi: token, masa berlakunya, dan akun (nama/e-mail/telepon). */
data class StoredSession(val token: String, val expiresAt: Instant?, val account: Account) {
    // Token dan data akun tidak boleh muncul bila objek ini tercetak di log atau laporan crash.
    override fun toString(): String = "StoredSession(accountId=${account.id})"
}

/**
 * Penyimpan sesi pelanggan. Token (dan data akun, karena itu data pribadi) TIDAK PERNAH masuk
 * log, cadangan, SharedPreferences, atau berkas tak terenkripsi. Implementasi produksi:
 * [AesGcmTokenStore] dengan kunci Android Keystore; tes memakai fake di memori (testFixtures).
 */
interface TokenStore {
    /** Sinkron karena dipanggil interseptor di thread OkHttp. Boleh membaca disk pada panggilan pertama. */
    fun peekToken(): String?

    suspend fun load(): StoredSession?

    suspend fun save(session: StoredSession)

    suspend fun clear()
}
