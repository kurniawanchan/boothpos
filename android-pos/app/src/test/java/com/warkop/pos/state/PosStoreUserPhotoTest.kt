package com.warkop.pos.state

import com.warkop.core.domain.ApiError
import com.warkop.pos.model.Branding
import com.warkop.pos.model.MenuCatalog
import com.warkop.pos.model.StaffUser
import com.warkop.pos.model.SystemMode
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test

/** 078 / US3 — foto/nama pengguna yang masuk mengikuti server pada penyegaran berikutnya (bukan hanya saat masuk). */
@OptIn(ExperimentalCoroutinesApi::class)
class PosStoreUserPhotoTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend
    private lateinit var store: PosStore
    private val sari = StaffUser("5", "Sari", "Kasir", "CSH", photoUrl = "http://x/storage/users/lama.jpg")

    @Before fun setUp() {
        Dispatchers.setMain(dispatcher)
        backend = FakePosBackend()
        backend.brandingResult = { Branding(SystemMode.LIVE, "Toko", null) }
        backend.menuResult = { MenuCatalog(emptyList(), emptyList(), emptyMap(), emptyList()) }
        store = PosStore(backend = backend)
        store.debugInstall(sari, null, emptyList(), null, Dest.SYNC, null, store.conn, null, 0, null)
    }
    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun `penyegaran Counter mengganti foto yang berubah di server`() = runTest(dispatcher) {
        backend.meResult = { sari.copy(photoUrl = "http://x/storage/users/baru.jpg") }
        store.refreshCounter(); advanceUntilIdle()
        assertEquals("http://x/storage/users/baru.jpg", store.user?.photoUrl)
        assertEquals("CSH", store.user?.code)
    }

    @Test fun `penyegaran layar lain juga membaca ulang pengguna`() = runTest(dispatcher) {
        backend.meResult = { sari.copy(photoUrl = "http://x/storage/users/baru.jpg", name = "Sari Dewi") }
        store.refreshCurrent(); advanceUntilIdle()
        assertEquals("http://x/storage/users/baru.jpg", store.user?.photoUrl)
        assertEquals("Sari Dewi", store.user?.name)
    }

    @Test fun `foto dihapus di server kembali ke inisial`() = runTest(dispatcher) {
        backend.meResult = { sari.copy(photoUrl = null) }
        store.refreshCounter(); advanceUntilIdle()
        assertNull(store.user?.photoUrl)
    }

    @Test fun `gagal membaca mempertahankan pengguna terakhir`() = runTest(dispatcher) {
        backend.meResult = { throw ApiError.Network("offline") }
        store.refreshCounter(); advanceUntilIdle()
        assertEquals(sari.photoUrl, store.user?.photoUrl)
    }

    @Test fun `id yang berbeda diabaikan dan peran tidak diubah`() = runTest(dispatcher) {
        backend.meResult = { StaffUser("99", "Orang Lain", "Owner", "OWN", photoUrl = "http://x/lain.jpg") }
        store.refreshCounter(); advanceUntilIdle()
        assertEquals("5", store.user?.id)
        assertEquals(sari.photoUrl, store.user?.photoUrl)
    }
}
