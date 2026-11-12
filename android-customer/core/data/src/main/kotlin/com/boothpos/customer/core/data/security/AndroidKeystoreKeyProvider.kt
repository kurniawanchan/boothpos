package com.boothpos.customer.core.data.security

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import java.security.KeyStore
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey

/**
 * Kunci AES-256 dibuat dan disimpan di Android Keystore: materi kuncinya tidak pernah keluar dari
 * perangkat dan tidak ikut cadangan. AndroidKeyStore tidak ada di JVM/Robolectric, jadi kelas ini
 * hanya diuji oleh tes instrumentasi (`AndroidKeystoreTokenStoreTest`, belum pernah dijalankan);
 * keputusan pemulihannya ada di [KeyRecovery] dan diuji di JVM.
 *
 * Bila kunci tersimpan tak dapat dibaca (`UnrecoverableKeyException`, `KeyStoreException`), entri
 * dibuang dan satu kunci baru dibuat SEKALI. Akibatnya: sesi yang terenkripsi dengan kunci lama tak
 * dapat dibuka lagi dan pelanggan harus masuk ulang. Itu lebih baik daripada gagal menyimpan sesi
 * selamanya.
 */
class AndroidKeystoreKeyProvider : SecretKeyProvider {

    override fun getOrCreate(): SecretKey = KeyRecovery.withRecovery(discardKey = ::discard) { loadOrGenerate() }

    override fun discard() {
        try {
            KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }.deleteEntry(ALIAS)
        } catch (_: Exception) {
            // Upaya terbaik: membuat kunci baru dengan alias yang sama menimpa entri lama.
        }
    }

    private fun loadOrGenerate(): SecretKey {
        val keyStore = KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }
        (keyStore.getKey(ALIAS, null) as? SecretKey)?.let { return it }
        val spec = KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setKeySize(256)
            .build()
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEYSTORE)
            .apply { init(spec) }
            .generateKey()
    }

    private companion object {
        const val ANDROID_KEYSTORE = "AndroidKeyStore"
        const val ALIAS = "boothpos_customer_session_key"
    }
}
