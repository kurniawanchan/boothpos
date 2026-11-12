package com.boothpos.customer.feature.checkout.data

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import androidx.datastore.preferences.core.Preferences
import com.boothpos.customer.core.data.domain.session.SignOutReason
import java.io.File
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.test.runTest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder

class DataStorePendingOrderIntentStoreTest {

    @get:Rule
    val folder = TemporaryFolder()

    private val scope = CoroutineScope(Dispatchers.IO + SupervisorJob())
    private val file get() = File(folder.root, "uji.preferences_pb")

    private fun dataStore(): DataStore<Preferences> = PreferenceDataStoreFactory.create(scope = scope) { file }

    private val fingerprint = "a".repeat(64)
    private val key = "kunci-uji-0001-abcdefgh"

    @After
    fun tearDown() = scope.cancel()

    @Test
    fun `kunci hanya kembali untuk akun dan sidik yang sama lalu hilang setelah dilupakan`() = runTest {
        val store = DataStorePendingOrderIntentStore(dataStore())
        assertNull(store.keyFor(12, fingerprint))

        store.remember(12, fingerprint, key)

        assertEquals(key, store.keyFor(12, fingerprint))
        assertNull(store.keyFor(13, fingerprint))
        assertNull(store.keyFor(12, "b".repeat(64)))

        store.forget()
        assertNull(store.keyFor(12, fingerprint))
    }

    @Test
    fun `hanya satu niat disimpan dan berkasnya tidak memuat data pribadi`() = runTest {
        val store = DataStorePendingOrderIntentStore(dataStore())
        store.remember(12, fingerprint, key)
        store.remember(12, "c".repeat(64), "kunci-uji-0002-abcdefgh")

        assertNull(store.keyFor(12, fingerprint))
        assertEquals("kunci-uji-0002-abcdefgh", store.keyFor(12, "c".repeat(64)))
        // Hanya id akun, sidik heksadesimal, dan kunci acak yang tersimpan.
        val raw = file.readBytes().toString(Charsets.ISO_8859_1)
        assertFalse(raw.contains("@"))
    }

    @Test
    fun `keluar atas permintaan melupakan niat sedangkan sesi berakhir mempertahankannya`() = runTest {
        val store = DataStorePendingOrderIntentStore(dataStore())
        val cleaner = PendingOrderIntentCleaner(store)
        store.remember(12, fingerprint, key)

        cleaner.clear(SignOutReason.SessionExpired)
        assertEquals(key, store.keyFor(12, fingerprint))

        cleaner.clear(SignOutReason.UserRequested)
        assertNull(store.keyFor(12, fingerprint))
    }
}
