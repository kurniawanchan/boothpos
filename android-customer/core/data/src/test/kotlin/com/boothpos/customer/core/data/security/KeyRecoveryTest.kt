package com.boothpos.customer.core.data.security

import java.io.IOException
import java.security.InvalidKeyException
import java.security.KeyStoreException
import java.security.UnrecoverableKeyException
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test

/**
 * Keputusan pemulihan kunci diuji di JVM dengan pengecualian `java.security` yang sama dengan yang
 * dilempar Android Keystore. Yang TIDAK teruji di sini: bahwa AndroidKeyStore sungguhan memang
 * melempar pengecualian itu dan bahwa `deleteEntry` + pembuatan ulang bekerja di perangkat
 * (`AndroidKeystoreKeyProvider`, hanya dapat diuji di perangkat/emulator).
 */
class KeyRecoveryTest {

    private var discards = 0
    private var attempts = 0

    private fun <T> run(failures: List<Exception>, result: T): T =
        KeyRecovery.withRecovery(discardKey = { discards++ }) {
            attempts++
            failures.getOrNull(attempts - 1)?.let { throw it }
            result
        }

    @Test
    fun `tiga jenis kegagalan kunci dapat dipulihkan, yang lain tidak`() {
        assertTrue(KeyRecovery.isRecoverable(UnrecoverableKeyException("x")))
        assertTrue(KeyRecovery.isRecoverable(KeyStoreException("x")))
        assertTrue(KeyRecovery.isRecoverable(InvalidKeyException("x")))
        assertFalse(KeyRecovery.isRecoverable(IOException("disk penuh")))
        assertFalse(KeyRecovery.isRecoverable(IllegalStateException("x")))
        assertFalse(KeyRecovery.isRecoverable(java.security.GeneralSecurityException("x")))
    }

    @Test
    fun `berhasil pada percobaan pertama tidak membuang kunci`() {
        assertEquals("ok", run(emptyList(), "ok"))

        assertEquals(1, attempts)
        assertEquals(0, discards)
    }

    @Test
    fun `kegagalan yang dapat dipulihkan membuang kunci sekali lalu mencoba sekali lagi`() {
        listOf<Exception>(UnrecoverableKeyException("x"), KeyStoreException("x"), InvalidKeyException("x")).forEach { failure ->
            discards = 0
            attempts = 0

            assertEquals("ok", run(listOf(failure), "ok"))

            assertEquals(failure.javaClass.simpleName, 2, attempts)
            assertEquals(failure.javaClass.simpleName, 1, discards)
        }
    }

    @Test
    fun `kegagalan kedua diteruskan apa adanya dan tidak ada putaran tanpa akhir`() {
        val second = InvalidKeyException("kedua")

        try {
            run(listOf(KeyStoreException("pertama"), second), "tidak tercapai")
            fail("Kegagalan kedua harus diteruskan")
        } catch (e: InvalidKeyException) {
            assertSame(second, e)
        }

        assertEquals(2, attempts)
        assertEquals(1, discards)
    }

    @Test
    fun `kegagalan lain diteruskan tanpa membuang kunci`() {
        val disk = IOException("disk penuh")

        try {
            run(listOf(disk), "tidak tercapai")
            fail("IOException tidak boleh dipulihkan")
        } catch (e: IOException) {
            assertSame(disk, e)
        }

        assertEquals(1, attempts)
        assertEquals("Kunci yang sehat tidak boleh dibuang karena disk penuh", 0, discards)
    }
}
