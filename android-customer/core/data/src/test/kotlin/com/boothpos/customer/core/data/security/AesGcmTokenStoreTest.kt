package com.boothpos.customer.core.data.security

import com.boothpos.customer.core.data.testing.TestData
import com.boothpos.customer.core.network.NetworkJson
import java.io.File
import java.time.Instant
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.SecretKeySpec
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder

/**
 * Format berkas, enkripsi, dan pemulihan dari berkas rusak diuji di JVM dengan kunci AES perangkat
 * lunak. Sumber kunci AndroidKeyStore sendiri hanya dapat diuji lewat tes instrumentasi.
 */
class AesGcmTokenStoreTest {

    @get:Rule
    val folder = TemporaryFolder()

    private val key: SecretKey = KeyGenerator.getInstance("AES").apply { init(256) }.generateKey()
    private val session = StoredSession(
        token = "tes-token-palsu-123",
        expiresAt = Instant.parse("2026-11-25T03:00:00Z"),
        account = TestData.account(),
    )

    private fun store(file: File, key: SecretKey = this.key, scope: kotlinx.coroutines.test.TestScope) =
        AesGcmTokenStore(file, { key }, NetworkJson.create(), StandardTestDispatcher(scope.testScheduler))

    /** Kunci AES 5 byte: `Cipher.init` menolaknya dengan `InvalidKeyException`, seperti kunci Keystore yang dibatalkan. */
    private val unusableKey: SecretKey = SecretKeySpec(ByteArray(5), "AES")

    /** Memulai dengan kunci yang tak dapat dipakai; [discard] menggantinya dengan kunci sehat (kecuali [stayBroken]). */
    private inner class BrokenThenHealthyKeyProvider(private val stayBroken: Boolean = false) : SecretKeyProvider {
        var discards = 0
        private var current: SecretKey = unusableKey

        override fun getOrCreate(): SecretKey = current

        override fun discard() {
            discards++
            if (!stayBroken) current = key
        }
    }

    @Test
    fun `sesi tersimpan terbaca kembali oleh instance baru`() = runTest {
        val file = File(folder.root, "session.bin")
        store(file, scope = this).save(session)

        val reopened = store(file, scope = this)

        assertEquals(session, reopened.load())
        assertEquals("tes-token-palsu-123", reopened.peekToken())
    }

    @Test
    fun `isi berkas terenkripsi dan tidak memuat token maupun data akun`() = runTest {
        val file = File(folder.root, "session.bin")
        store(file, scope = this).save(session)

        val raw = String(file.readBytes(), Charsets.ISO_8859_1)

        assertFalse(raw.contains("tes-token-palsu-123"))
        assertFalse(raw.contains("pelanggan.uji@example.test"))
        assertFalse(raw.contains("Pelanggan Uji"))
    }

    @Test
    fun `clear menghapus berkas dan token di memori`() = runTest {
        val file = File(folder.root, "session.bin")
        val store = store(file, scope = this)
        store.save(session)

        store.clear()

        assertNull(store.peekToken())
        assertFalse(file.exists())
        assertNull(store(file, scope = this).load())
    }

    @Test
    fun `berkas rusak dianggap tidak ada sesi dan dihapus`() = runTest {
        val file = File(folder.root, "session.bin")
        store(file, scope = this).save(session)
        val bytes = file.readBytes()
        bytes[bytes.lastIndex] = (bytes[bytes.lastIndex] + 1).toByte()
        file.writeBytes(bytes)

        assertNull("Tag GCM tidak cocok: data tidak boleh dipakai", store(file, scope = this).load())
        assertFalse(file.exists())
    }

    @Test
    fun `kunci yang berbeda tidak dapat membuka sesi`() = runTest {
        val file = File(folder.root, "session.bin")
        store(file, scope = this).save(session)
        val otherKey = KeyGenerator.getInstance("AES").apply { init(256) }.generateKey()

        assertNull(store(file, key = otherKey, scope = this).peekToken())
    }

    @Test
    fun `berkas sampah atau versi tak dikenal tidak membuat crash`() = runTest {
        val file = File(folder.root, "session.bin")
        file.writeBytes(byteArrayOf(9, 12, 1, 2, 3))

        assertNull(store(file, scope = this).load())

        file.writeBytes(ByteArray(0))
        assertNull(store(file, scope = this).load())
    }

    @Test
    fun `kunci yang tak dapat dipakai dibuang dan dibuat ulang sekali sehingga sesi tetap tersimpan`() = runTest {
        val file = File(folder.root, "session.bin")
        val provider = BrokenThenHealthyKeyProvider()
        val broken = AesGcmTokenStore(file, provider, NetworkJson.create(), StandardTestDispatcher(testScheduler))

        broken.save(session)

        assertEquals(1, provider.discards)
        assertEquals("Sesi baru dapat dibuka dengan kunci pengganti", session, store(file, scope = this).load())
        assertEquals(session.token, broken.peekToken())
    }

    @Test
    fun `kunci yang tetap rusak setelah dibuat ulang menggagalkan penyimpanan tanpa berkas setengah jadi`() = runTest {
        val file = File(folder.root, "session.bin")
        val provider = BrokenThenHealthyKeyProvider(stayBroken = true)
        val broken = AesGcmTokenStore(file, provider, NetworkJson.create(), StandardTestDispatcher(testScheduler))

        try {
            broken.save(session)
            fail("Penyimpanan harus gagal bila kunci pengganti pun tak dapat dipakai")
        } catch (_: java.security.InvalidKeyException) {
            // diharapkan: pemanggil (AuthRepositoryImpl) mengubahnya menjadi galat tenang
        }

        assertEquals("Hanya satu percobaan ulang", 1, provider.discards)
        assertFalse(file.exists())
        assertFalse(File(folder.root, "session.bin.tmp").exists())
        assertNull("Tidak ada sesi di memori untuk penyimpanan yang gagal", broken.peekToken())
    }

    @Test
    fun `kegagalan menulis berkas tidak memicu pembuangan kunci yang sehat`() = runTest {
        // Induk berkas adalah sebuah berkas biasa, jadi penulisan gagal dengan IOException.
        val blocker = File(folder.root, "bukan-direktori").apply { writeText("x") }
        var discards = 0
        val healthy = object : SecretKeyProvider {
            override fun getOrCreate(): SecretKey = key

            override fun discard() {
                discards++
            }
        }
        val store = AesGcmTokenStore(File(blocker, "session.bin"), healthy, NetworkJson.create(), StandardTestDispatcher(testScheduler))

        try {
            store.save(session)
            fail("Penulisan ke jalur yang tidak valid harus gagal")
        } catch (_: java.io.IOException) {
            // diharapkan
        }

        assertEquals("IOException bukan alasan membuang kunci yang sehat", 0, discards)
        assertTrue(blocker.isFile)
    }
}
