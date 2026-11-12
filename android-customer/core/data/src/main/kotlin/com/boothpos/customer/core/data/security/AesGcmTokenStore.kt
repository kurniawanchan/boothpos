package com.boothpos.customer.core.data.security

import com.boothpos.customer.core.data.domain.model.Account
import java.io.File
import java.time.Instant
import javax.crypto.Cipher
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.withContext
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json

/** Sumber kunci AES. Produksi: [AndroidKeystoreKeyProvider] (kunci tidak dapat diekspor dari perangkat). */
fun interface SecretKeyProvider {
    fun getOrCreate(): SecretKey

    /**
     * Membuang kunci tersimpan supaya [getOrCreate] berikutnya membuat yang baru. Dipanggil sekali
     * oleh [KeyRecovery] ketika kunci ternyata tak dapat dipakai; bawaannya tidak melakukan apa pun
     * (kunci di memori untuk tes).
     */
    fun discard() = Unit
}

/**
 * Sesi dienkripsi AES-256-GCM lalu disimpan di satu berkas privat aplikasi.
 * Format berkas: `[versi=1][panjang IV][IV][ciphertext+tag]`.
 *
 * Berkas rusak, format tak dikenal, atau kunci yang hilang (mis. setelah pemulihan perangkat)
 * dianggap "tidak ada sesi": pelanggan cukup masuk lagi, aplikasi tidak crash dan tidak pernah
 * memakai data yang gagal diautentikasi GCM.
 */
class AesGcmTokenStore(
    private val file: File,
    private val keyProvider: SecretKeyProvider,
    private val json: Json,
    private val ioDispatcher: CoroutineDispatcher,
) : TokenStore {

    private val lock = Any()

    @Volatile
    private var loaded = false

    @Volatile
    private var cached: StoredSession? = null

    override fun peekToken(): String? = current()?.token

    override suspend fun load(): StoredSession? = withContext(ioDispatcher) { current() }

    override suspend fun save(session: StoredSession) {
        withContext(ioDispatcher) {
            synchronized(lock) {
                writeToDisk(session)
                cached = session
                loaded = true
            }
        }
    }

    override suspend fun clear() {
        withContext(ioDispatcher) {
            synchronized(lock) {
                file.delete()
                cached = null
                loaded = true
            }
        }
    }

    private fun current(): StoredSession? {
        if (!loaded) {
            synchronized(lock) {
                if (!loaded) {
                    cached = readFromDisk()
                    loaded = true
                }
            }
        }
        return cached
    }

    private fun writeToDisk(session: StoredSession) {
        val plain = json.encodeToString(PersistedSession.serializer(), session.toPersisted()).toByteArray()
        // Kunci yang tak dapat dipakai (mis. dibatalkan Keystore) dibuang dan dibuat ulang sekali;
        // tanpa itu pelanggan tidak akan pernah bisa menyimpan sesi lagi.
        val (iv, encrypted) = KeyRecovery.withRecovery(discardKey = keyProvider::discard) {
            val cipher = Cipher.getInstance(TRANSFORMATION).apply { init(Cipher.ENCRYPT_MODE, keyProvider.getOrCreate()) }
            cipher.iv to cipher.doFinal(plain)
        }
        file.parentFile?.mkdirs()
        // Tulis ke berkas sementara lalu ganti nama: berkas sesi tidak pernah setengah tertulis.
        val temp = File(file.parentFile, file.name + ".tmp")
        temp.writeBytes(byteArrayOf(FORMAT_VERSION, iv.size.toByte()) + iv + encrypted)
        if (!temp.renameTo(file)) {
            file.delete()
            check(temp.renameTo(file)) { "Sesi tidak dapat disimpan" }
        }
    }

    private fun readFromDisk(): StoredSession? {
        if (!file.exists()) return null
        return try {
            val bytes = file.readBytes()
            require(bytes.size > HEADER_SIZE && bytes[0] == FORMAT_VERSION)
            val ivLength = bytes[1].toInt()
            require(ivLength in MIN_IV_LENGTH..MAX_IV_LENGTH && bytes.size > HEADER_SIZE + ivLength)
            val iv = bytes.copyOfRange(HEADER_SIZE, HEADER_SIZE + ivLength)
            val cipher = Cipher.getInstance(TRANSFORMATION).apply {
                init(Cipher.DECRYPT_MODE, keyProvider.getOrCreate(), GCMParameterSpec(TAG_BITS, iv))
            }
            val plain = cipher.doFinal(bytes, HEADER_SIZE + ivLength, bytes.size - HEADER_SIZE - ivLength)
            json.decodeFromString(PersistedSession.serializer(), String(plain, Charsets.UTF_8)).toStored()
        } catch (_: Exception) {
            // Sengaja tanpa log: pesan pengecualian kripto/JSON tidak boleh membawa isi berkas.
            file.delete()
            null
        }
    }

    // `internal` (bukan `private`) hanya supaya tes dapat memeriksa bahwa `toString()` tidak memuat token.
    @Serializable
    internal data class PersistedSession(
        val token: String,
        val expiresAtEpochSecond: Long? = null,
        val accountId: Long,
        val name: String,
        val email: String,
        val phone: String? = null,
        val emailVerified: Boolean = false,
        val createdAtEpochSecond: Long? = null,
    ) {
        // Memuat token dan data akun: tidak boleh tercetak di log atau laporan crash.
        override fun toString(): String = "PersistedSession(accountId=$accountId)"

        fun toStored() = StoredSession(
            token = token,
            expiresAt = expiresAtEpochSecond?.let(Instant::ofEpochSecond),
            account = Account(
                id = accountId,
                name = name,
                email = email,
                phone = phone,
                emailVerified = emailVerified,
                createdAt = createdAtEpochSecond?.let(Instant::ofEpochSecond),
            ),
        )
    }

    private fun StoredSession.toPersisted() = PersistedSession(
        token = token,
        expiresAtEpochSecond = expiresAt?.epochSecond,
        accountId = account.id,
        name = account.name,
        email = account.email,
        phone = account.phone,
        emailVerified = account.emailVerified,
        createdAtEpochSecond = account.createdAt?.epochSecond,
    )

    private companion object {
        const val TRANSFORMATION = "AES/GCM/NoPadding"
        const val FORMAT_VERSION: Byte = 1
        const val HEADER_SIZE = 2
        const val MIN_IV_LENGTH = 12
        const val MAX_IV_LENGTH = 16
        const val TAG_BITS = 128
    }
}
