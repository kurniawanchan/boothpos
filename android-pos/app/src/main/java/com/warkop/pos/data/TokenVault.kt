package com.warkop.pos.data

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
 * Penyimpanan rahasia terminal: URL server, token penyiapan, token sesi PIN. AES-GCM dengan kunci Android Keystore
 * (tidak dapat diekspor); ciphertext di SharedPreferences. PIN TIDAK PERNAH disimpan di sini atau di mana pun.
 *
 * Mengapa bukan androidx.security-crypto: pustaka itu ditandai usang; pembungkus ini ±40 baris tanpa dependensi baru
 * (research.md D4). Bila nilai tak dapat didekripsi (kunci dihapus, data dari cadangan lain) nilainya dianggap TIDAK ADA
 * dan dibuang — pengguna diminta masuk lagi, bukan crash.
 */
class TokenVault(context: Context) {
    private val prefs = context.getSharedPreferences("wjm_vault", Context.MODE_PRIVATE)

    fun get(key: String): String? {
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

    fun put(key: String, value: String?) {
        if (value == null) {
            prefs.edit().remove(key).apply()
            return
        }
        val cipher = Cipher.getInstance(TRANSFORM).apply { init(Cipher.ENCRYPT_MODE, secretKey()) }
        val encrypted = cipher.iv + cipher.doFinal(value.toByteArray(Charsets.UTF_8))
        prefs.edit().putString(key, Base64.encodeToString(encrypted, Base64.NO_WRAP)).apply()
    }

    fun clear(vararg keys: String) = prefs.edit().apply { keys.forEach { remove(it) } }.apply()

    private fun secretKey(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (store.getKey(ALIAS, null) as? SecretKey)?.let { return it }
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").apply {
            init(
                KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                    .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                    .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                    .setKeySize(256)
                    .build(),
            )
        }.generateKey()
    }

    companion object {
        private const val ALIAS = "wjm_pos_vault"
        private const val TRANSFORM = "AES/GCM/NoPadding"
        private const val IV_BYTES = 12
        private const val TAG_BITS = 128

        const val SERVER_URL = "server_url"
        const val ADMIN_TOKEN = "admin_token"
        const val SESSION_TOKEN = "session_token"
        const val SESSION_EXPIRES = "session_expires_ms"
        const val OUTLET_ID = "outlet_id"
        const val OUTLET_NAME = "outlet_name"
        const val DEVICE_ID = "device_id"
        const val DEVICE_NAME = "device_name"

        /** 064 — kode terminal (T01…) sudah tidak dipakai; konstanta ini hanya untuk MEMBERSIHKAN nilai lama saat reset. */
        const val DEVICE_CODE = "device_code"
    }
}
