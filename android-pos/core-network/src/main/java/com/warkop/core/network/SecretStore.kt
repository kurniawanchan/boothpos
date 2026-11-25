package com.warkop.core.network

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * 077 — antarmuka tipis penyimpanan rahasia. [KeystoreSecretStore] adalah satu-satunya implementasi produksi; uji memakai
 * versi di memori supaya aturan yang memakainya bisa diuji tanpa Context.
 */
interface SecretStore {
    fun get(key: String): String?
    fun put(key: String, value: String?)
    fun clear(vararg keys: String)
}

/**
 * 094 / research D1 — dipindah dari `TokenVault` aplikasi kasir supaya aplikasi manajemen memakai implementasi yang SAMA,
 * bukan salinan. AES-GCM dengan kunci Android Keystore (tidak dapat diekspor); ciphertext di SharedPreferences. Tiap
 * aplikasi memberi nama berkas dan alias kuncinya sendiri.
 *
 * Mengapa bukan androidx.security-crypto: pustaka itu ditandai usang; pembungkus ini ±40 baris tanpa dependensi baru.
 * Bila nilai tak dapat didekripsi (kunci dihapus, data dari cadangan lain) nilainya dianggap TIDAK ADA dan dibuang —
 * pengguna diminta masuk lagi, bukan crash.
 */
open class KeystoreSecretStore(context: Context, prefsName: String, private val alias: String) : SecretStore {
    private val prefs = context.getSharedPreferences(prefsName, Context.MODE_PRIVATE)

    override fun get(key: String): String? {
        val stored = prefs.getString(key, null) ?: return null
        return try {
            val raw = Base64.decode(stored, Base64.NO_WRAP)
            val iv = raw.copyOfRange(0, IV_BYTES)
            val cipher = Cipher.getInstance(TRANSFORM).apply { init(Cipher.DECRYPT_MODE, secretKey(), GCMParameterSpec(TAG_BITS, iv)) }
            String(cipher.doFinal(raw, IV_BYTES, raw.size - IV_BYTES), Charsets.UTF_8)
        } catch (_: Exception) {
            prefs.edit().remove(key).apply()
            null
        }
    }

    override fun put(key: String, value: String?) {
        if (value == null) {
            prefs.edit().remove(key).apply()
            return
        }
        val cipher = Cipher.getInstance(TRANSFORM).apply { init(Cipher.ENCRYPT_MODE, secretKey()) }
        val encrypted = cipher.iv + cipher.doFinal(value.toByteArray(Charsets.UTF_8))
        prefs.edit().putString(key, Base64.encodeToString(encrypted, Base64.NO_WRAP)).apply()
    }

    override fun clear(vararg keys: String) = prefs.edit().apply { keys.forEach { remove(it) } }.apply()

    private fun secretKey(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (store.getKey(alias, null) as? SecretKey)?.let { return it }
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").apply {
            init(
                KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                    .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                    .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                    .setKeySize(256)
                    .build(),
            )
        }.generateKey()
    }

    private companion object {
        const val TRANSFORM = "AES/GCM/NoPadding"
        const val IV_BYTES = 12
        const val TAG_BITS = 128
    }
}
