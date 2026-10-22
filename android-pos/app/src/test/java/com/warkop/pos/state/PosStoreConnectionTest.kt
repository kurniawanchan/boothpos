package com.warkop.pos.state

import com.warkop.core.domain.ApiError
import com.warkop.pos.data.ServerEndpoint
import com.warkop.pos.data.TerminalConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * 054 / US2 — keadaan koneksi di layar PIN (FR-003–006). Akar masalah yang diuji: chip dulu default "Online" dan
 * roster tidak pernah mengubahnya, jadi layar masuk selalu menampilkan "Online" walau Wi-Fi mati.
 */
@OptIn(ExperimentalCoroutinesApi::class)
class PosStoreConnectionTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend

    @Before fun setUp() {
        Dispatchers.setMain(dispatcher)
        backend = FakePosBackend()
    }

    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun `koneksi belum diperiksa tidak tampil sebagai Online`() {
        val store = PosStore(backend = backend)
        assertEquals(Conn.UNKNOWN, store.conn)
    }

    @Test fun `roster berhasil menandai Online`() = runTest(dispatcher) {
        val store = PosStore(backend = backend)
        store.loadRoster()
        advanceUntilIdle()
        assertEquals(Conn.ONLINE, store.conn)
        assertTrue(store.roster is Load.Ready)
    }

    @Test fun `galat jaringan menandai Offline dan menampilkan galat`() = runTest(dispatcher) {
        backend.rosterResult = { throw ApiError.Network() }
        val store = PosStore(backend = backend)
        store.loadRoster()
        advanceUntilIdle()
        assertEquals(Conn.OFFLINE, store.conn)
        assertTrue(store.roster is Load.Failed)
    }

    @Test fun `galat non-jaringan pada pemuatan pertama tetap menandai Online (regresi status koneksi jujur di layar PIN)`() = runTest(dispatcher) {
        backend.rosterResult = { throw ApiError.Unauthorized("Sesi berakhir") }
        val store = PosStore(backend = backend)
        store.loadRoster()
        advanceUntilIdle()
        assertEquals("server menjawab, jadi harus Online sejak pemuatan pertama", Conn.ONLINE, store.conn)
        assertTrue(store.roster is Load.Failed)
    }

    @Test fun `probe PIN memperbarui chip saat Wi-Fi mati lalu hidup lagi`() = runTest(dispatcher) {
        var online = false
        backend.rosterResult = { if (online) emptyList() else throw ApiError.Network() }
        val store = PosStore(backend = backend)

        // Probe berulang tanpa henti, jadi advanceUntilIdle() tidak pernah selesai: maju per detik virtual saja.
        store.startPinProbe()
        runCurrent()
        assertEquals(Conn.OFFLINE, store.conn)

        online = true
        advanceTimeBy(10_000)
        runCurrent()
        assertEquals(Conn.ONLINE, store.conn)

        store.stopPinProbe()
    }

    @Test fun `muat ulang diam-diam tidak mengosongkan daftar staf yang sudah tampil`() = runTest(dispatcher) {
        val store = PosStore(backend = backend)
        store.loadRoster()
        advanceUntilIdle()
        assertTrue(store.roster is Load.Ready)

        store.loadRoster(silent = true)
        assertTrue("tidak boleh berkedip ke 'memuat'", store.roster is Load.Ready)
        advanceUntilIdle()
        assertTrue(store.roster is Load.Ready)
    }

    private fun terminal(url: String) = TerminalConfig(serverUrl = url, outletId = 1, outletName = "Warkop WJM 3", deviceId = 7, deviceName = "Kasir Belakang")

    @Test fun `alamat yang dipilih admin dipindah setelah roster berhasil (054 US3)`() = runTest(dispatcher) {
        val adopted = mutableListOf<String>()
        backend.endpoint = ServerEndpoint("online", "https://pos.example.com")
        val store = PosStore(backend = backend, onServerUrl = { adopted += it })
        store.setConfig(terminal("http://192.168.1.10:8010"))

        store.startPinProbe()
        runCurrent()
        assertEquals(listOf("https://pos.example.com"), adopted)
        assertEquals("https://pos.example.com", store.config?.serverUrl)
        store.stopPinProbe()
    }

    @Test fun `url kosong dari server tidak memindahkan tablet dari alamat pairing (054 US3)`() = runTest(dispatcher) {
        val adopted = mutableListOf<String>()
        backend.endpoint = ServerEndpoint("local", null)
        val store = PosStore(backend = backend, onServerUrl = { adopted += it })
        store.setConfig(terminal("http://192.168.1.10:8010"))

        store.startPinProbe()
        runCurrent()
        assertTrue(adopted.isEmpty())
        assertEquals("http://192.168.1.10:8010", store.config?.serverUrl)
        store.stopPinProbe()
    }

    @Test fun `alamat yang sama tidak menulis ulang penyimpanan (054 US3)`() = runTest(dispatcher) {
        val adopted = mutableListOf<String>()
        backend.endpoint = ServerEndpoint("local", "http://192.168.1.10:8010")
        val store = PosStore(backend = backend, onServerUrl = { adopted += it })
        store.setConfig(terminal("http://192.168.1.10:8010"))

        store.startPinProbe()
        runCurrent()
        assertTrue(adopted.isEmpty())
        store.stopPinProbe()
    }

    @Test fun `tanpa koneksi tidak ada alamat yang dipindah (054 US3)`() = runTest(dispatcher) {
        val adopted = mutableListOf<String>()
        backend.rosterResult = { throw ApiError.Network() }
        backend.endpoint = ServerEndpoint("online", "https://pos.example.com")
        val store = PosStore(backend = backend, onServerUrl = { adopted += it })
        store.setConfig(terminal("http://192.168.1.10:8010"))

        store.startPinProbe()
        runCurrent()
        assertTrue("tidak boleh pindah saat server tidak terjangkau", adopted.isEmpty())
        store.stopPinProbe()
    }

    @Test fun `galat non-jaringan setelah sebelumnya Offline menandai Online (regresi FR-006)`() = runTest(dispatcher) {
        var jaringanGagal = true
        backend.rosterResult = { if (jaringanGagal) throw ApiError.Network() else throw ApiError.Unauthorized("Sesi berakhir") }
        val store = PosStore(backend = backend)
        store.loadRoster()
        advanceUntilIdle()
        assertEquals(Conn.OFFLINE, store.conn)

        jaringanGagal = false
        store.loadRoster()
        advanceUntilIdle()
        assertEquals("server kini menjawab (401), jadi harus Online bukan OFFLINE", Conn.ONLINE, store.conn)
        assertTrue(store.roster is Load.Failed)
    }
}
