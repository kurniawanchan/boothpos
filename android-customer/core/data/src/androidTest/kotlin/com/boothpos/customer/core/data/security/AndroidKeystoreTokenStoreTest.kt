package com.boothpos.customer.core.data.security

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.network.NetworkJson
import java.io.File
import java.time.Instant
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.test.runTest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Satu-satunya tes instrumentasi proyek: AndroidKeyStore hanya ada di perangkat/emulator.
 * Jalankan dengan `./gradlew :core:data:connectedDebugAndroidTest` (butuh emulator atau perangkat).
 */
@RunWith(AndroidJUnit4::class)
class AndroidKeystoreTokenStoreTest {

    private val context = InstrumentationRegistry.getInstrumentation().targetContext
    private val file = File(context.noBackupFilesDir, "session-test.bin")
    private val session = StoredSession(
        token = "tes-token-palsu-123",
        expiresAt = Instant.parse("2026-11-25T03:00:00Z"),
        account = Account(1, "Pelanggan Uji", "pelanggan.uji@example.test", null, false, null),
    )

    private fun store() = AesGcmTokenStore(file, AndroidKeystoreKeyProvider(), NetworkJson.create(), Dispatchers.IO)

    @Before
    @After
    fun cleanUp() {
        file.delete()
    }

    @Test
    fun sesiTersimpanDenganKunciKeystoreTerbacaKembali() = runTest {
        store().save(session)

        assertEquals(session, store().load())
    }

    @Test
    fun berkasTidakMemuatTokenDalamTeksBiasa() = runTest {
        store().save(session)

        assertFalse(String(file.readBytes(), Charsets.ISO_8859_1).contains("tes-token-palsu-123"))
    }

    @Test
    fun clearMenghapusSesi() = runTest {
        val store = store()
        store.save(session)

        store.clear()

        assertNull(store().load())
    }
}
