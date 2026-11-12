package com.boothpos.customer.feature.checkout.data

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import com.boothpos.customer.core.data.domain.session.SignOutReason
import com.boothpos.customer.core.data.domain.session.UserScopedDataCleaner
import com.boothpos.customer.feature.checkout.domain.PendingOrderIntentStore
import javax.inject.Inject
import kotlinx.coroutines.flow.first

/**
 * [PendingOrderIntentStore] di DataStore preferensi aplikasi. Nilainya tidak sensitif: id akun,
 * sidik SHA-256 isi pesanan, dan kunci idempotensi acak (bukan kredensial; server membatasinya
 * per akun). Satu entri saja, ditimpa oleh niat berikutnya.
 */
class DataStorePendingOrderIntentStore @Inject constructor(
    private val dataStore: DataStore<Preferences>,
) : PendingOrderIntentStore {

    override suspend fun keyFor(accountId: Long, fingerprint: String): String? {
        val raw = dataStore.data.first()[KEY] ?: return null
        val parts = raw.split(SEPARATOR)
        if (parts.size != 3) return null
        val (storedAccount, storedFingerprint, key) = parts
        return key.takeIf { storedAccount == accountId.toString() && storedFingerprint == fingerprint && it.isNotBlank() }
    }

    override suspend fun remember(accountId: Long, fingerprint: String, key: String) {
        dataStore.edit { it[KEY] = listOf(accountId.toString(), fingerprint, key).joinToString(SEPARATOR) }
    }

    override suspend fun forget() {
        dataStore.edit { it.remove(KEY) }
    }

    private companion object {
        val KEY = stringPreferencesKey("checkout_pending_intent")

        // Tidak pernah muncul di id, sidik heksadesimal, maupun kunci (pola kontrak: [A-Za-z0-9_-]).
        const val SEPARATOR = "|"
    }
}

/**
 * "Keluar" atas permintaan pelanggan melupakan niat yang tertunda, seperti keranjangnya. Sesi
 * yang berakhir (401/kedaluwarsa) sengaja TIDAK menghapusnya: pelanggan yang sama masuk lagi dan
 * harus tetap memakai kunci yang sama; akun lain tidak pernah cocok karena id akunnya berbeda.
 */
class PendingOrderIntentCleaner @Inject constructor(
    private val store: PendingOrderIntentStore,
) : UserScopedDataCleaner {
    override suspend fun clear(reason: SignOutReason) {
        if (reason == SignOutReason.UserRequested) store.forget()
    }
}
