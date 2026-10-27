package com.warkop.pos.state

import com.warkop.core.domain.ApiError
import com.warkop.pos.data.TerminalChoice
import com.warkop.pos.data.TerminalConfig
import com.warkop.pos.model.Branding
import com.warkop.pos.model.MenuCatalog
import com.warkop.pos.model.Order
import com.warkop.pos.model.OrderKind
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
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * 077 / US1 — tablet mengikuti mode data dari web. Outlet dan terminal berbeda per mode (server: HasDataMode), jadi pergantian
 * mode memilih pilihan tersimpan mode itu atau penyiapan, tanpa membuang pilihan mode lama dan tanpa menulis order ke mode lain.
 */
@OptIn(ExperimentalCoroutinesApi::class)
class PosStoreModeTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend

    private val live = TerminalConfig("http://x", 9, "WJM 3", 4, "Kasir Depan")
    private val demo = TerminalConfig("http://x", 21, "Outlet Demo", 33, "Tablet Demo")
    private fun branding(mode: SystemMode) = Branding(mode, "Warkop WJM", null)

    @Before fun setUp() {
        Dispatchers.setMain(dispatcher)
        backend = FakePosBackend()
        backend.rosterResult = { listOf(StaffUser("5", "Sari", "Kasir", "CSH")) }
        backend.terminalsResult = { o -> listOf(TerminalChoice(if (o.id == 9L) 4 else 33, "t")) }
    }
    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun `mode server berbeda tanpa pilihan terminal mode itu membuka penyiapan dan menyimpan pilihan LIVE`() = runTest(dispatcher) {
        backend.configs[SystemMode.LIVE] = live
        backend.brandingResult = { branding(SystemMode.DEMO) }
        val store = PosStore(backend = backend)
        store.bootstrap(); advanceUntilIdle()

        assertEquals(SystemMode.DEMO, store.mode)
        assertEquals(Dest.SETUP, store.dest)
        assertTrue(store.setupNotice.orEmpty().contains("DEMO"))
        assertEquals(0, backend.forgotten)
        assertEquals(live, backend.configs[SystemMode.LIVE])
    }

    @Test fun `mode server berbeda dengan pilihan tersimpan langsung ke PIN memakai terminal mode itu`() = runTest(dispatcher) {
        backend.configs[SystemMode.LIVE] = live
        backend.configs[SystemMode.DEMO] = demo
        backend.brandingResult = { branding(SystemMode.DEMO) }
        val store = PosStore(backend = backend)
        store.bootstrap(); advanceUntilIdle()

        assertEquals(SystemMode.DEMO, store.mode)
        assertEquals(Dest.PIN, store.dest)
        assertEquals(demo, store.config)
        assertTrue(store.roster is Load.Ready)
    }

    @Test fun `mode kembali ke LIVE memulihkan pilihan pertama tanpa penyiapan`() = runTest(dispatcher) {
        backend.configs[SystemMode.LIVE] = live
        backend.configs[SystemMode.DEMO] = demo
        backend.mode = SystemMode.DEMO
        backend.brandingResult = { branding(SystemMode.LIVE) }
        val store = PosStore(backend = backend)
        store.bootstrap(); advanceUntilIdle()

        assertEquals(SystemMode.LIVE, store.mode)
        assertEquals(Dest.PIN, store.dest)
        assertEquals(live, store.config)
    }

    @Test fun `branding gagal jaringan tetap pada mode terakhir dan memuat staf`() = runTest(dispatcher) {
        backend.configs[SystemMode.LIVE] = live
        backend.brandingResult = null
        val store = PosStore(backend = backend)
        store.bootstrap(); advanceUntilIdle()

        assertEquals(SystemMode.LIVE, store.mode)
        assertEquals(Dest.PIN, store.dest)
        assertTrue(store.roster is Load.Ready)
    }

    @Test fun `mode berubah saat sesi berjalan menampilkan dialog dan memblokir tulis order`() = runTest(dispatcher) {
        backend.configs[SystemMode.LIVE] = live
        backend.brandingResult = { branding(SystemMode.LIVE) }
        val store = PosStore(backend = backend)
        store.bootstrap(); advanceUntilIdle()
        store.debugInstall(null, null, listOf(Order("T1", OrderKind.COUNTER, null, 0, "A1", "kasir", "10:00")), "T1", Dest.EDITOR, null, store.conn, null, 0, null)

        backend.sessionBrandingResult = { branding(SystemMode.DEMO) }
        store.refreshCounter(); advanceUntilIdle()
        assertEquals(SystemMode.DEMO, store.modeChanged)

        // openTab/pay tidak diatur di fake: bila guard bocor, uji ini melempar UnsupportedOperationException.
        store.newCounter(); store.fire(); store.goPay(); store.payCash(10_000); advanceUntilIdle()
        assertNull(store.toast)
        assertEquals(Dest.EDITOR, store.dest)
    }

    @Test fun `keluar dari dialog mode berubah menutup sesi dan memakai mode baru tanpa melupakan terminal LIVE`() = runTest(dispatcher) {
        backend.configs[SystemMode.LIVE] = live
        backend.brandingResult = { branding(SystemMode.LIVE) }
        val store = PosStore(backend = backend)
        store.bootstrap(); advanceUntilIdle()
        store.debugInstall(null, null, emptyList(), null, Dest.TABLES, null, store.conn, null, 0, null)
        backend.sessionBrandingResult = { branding(SystemMode.DEMO) }
        store.refreshCounter(); advanceUntilIdle()

        store.leaveForNewMode(); advanceUntilIdle()

        assertNull(store.modeChanged)
        assertEquals(SystemMode.DEMO, store.mode)
        assertEquals(1, backend.logouts)
        assertEquals(Dest.SETUP, store.dest)
        assertEquals(0, backend.forgotten)
        assertEquals(live, backend.configs[SystemMode.LIVE])
    }

    @Test fun `mode yang sama tidak memunculkan dialog`() = runTest(dispatcher) {
        backend.configs[SystemMode.LIVE] = live
        backend.brandingResult = { branding(SystemMode.LIVE) }
        val store = PosStore(backend = backend)
        store.bootstrap(); advanceUntilIdle()
        backend.menuResult = { MenuCatalog(emptyList(), emptyList(), emptyMap(), emptyList()) }
        store.refreshCounter(); advanceUntilIdle()
        assertNull(store.modeChanged)
    }

    private fun revoked422() = ApiError.Validation("Terminal dicabut", mapOf("device_id" to listOf("dicabut")))

    @Test fun `422 device_id karena mode berganti menjadi dialog mode berubah bukan terminal dicabut`() = runTest(dispatcher) {
        backend.configs[SystemMode.LIVE] = live
        backend.brandingResult = { branding(SystemMode.LIVE) }
        val store = PosStore(backend = backend)
        store.bootstrap(); advanceUntilIdle()
        store.debugInstall(null, com.warkop.pos.model.Shift("S", "08:00", "", 0, "1", id = 7), emptyList(), null, Dest.TABLES, null, store.conn, null, 0, null)
        backend.openTabResult = { throw revoked422() }
        backend.sessionBrandingResult = { branding(SystemMode.DEMO) }

        store.newCounter(); advanceUntilIdle()

        assertEquals(SystemMode.DEMO, store.modeChanged)
        assertTrue(!store.terminalRevoked)
        assertNull(store.toast)
    }

    @Test fun `422 device_id dengan mode yang sama tetap berarti terminal dicabut`() = runTest(dispatcher) {
        backend.configs[SystemMode.LIVE] = live
        backend.brandingResult = { branding(SystemMode.LIVE) }
        val store = PosStore(backend = backend)
        store.bootstrap(); advanceUntilIdle()
        store.debugInstall(null, com.warkop.pos.model.Shift("S", "08:00", "", 0, "1", id = 7), emptyList(), null, Dest.TABLES, null, store.conn, null, 0, null)
        backend.openTabResult = { throw revoked422() }
        backend.sessionBrandingResult = { branding(SystemMode.LIVE) }

        store.newCounter(); advanceUntilIdle()

        assertNull(store.modeChanged)
        assertEquals(Dest.SETUP, store.dest)
    }

    @Test fun `nama dan logo toko dari server tampil setelah segar dan gagal membaca mempertahankan nilai terakhir`() = runTest(dispatcher) {
        backend.configs[SystemMode.LIVE] = live
        backend.brandingResult = { Branding(SystemMode.LIVE, "Warkop WJM", "http://x/logo-lama.png") }
        backend.menuResult = { MenuCatalog(emptyList(), emptyList(), emptyMap(), emptyList()) }
        val store = PosStore(backend = backend)
        store.bootstrap(); advanceUntilIdle()
        assertEquals("Warkop WJM", store.branding?.storeName)

        store.debugInstall(null, null, emptyList(), null, Dest.TABLES, null, store.conn, null, 0, null)
        backend.sessionBrandingResult = { Branding(SystemMode.LIVE, "Warkop Baru", "http://x/logo-baru.png", outletCount = 2) }
        store.refreshCounter(); advanceUntilIdle()
        assertEquals("Warkop Baru", store.branding?.storeName)
        assertEquals("http://x/logo-baru.png", store.branding?.logoUrl)
        assertEquals(2, store.branding?.outletCount)

        backend.sessionBrandingResult = { throw ApiError.Network("offline") }
        store.refreshCounter(); advanceUntilIdle()
        assertEquals("Warkop Baru", store.branding?.storeName)
    }
}
