package com.warkop.pos.state

import com.warkop.core.domain.ApiError
import com.warkop.pos.data.TerminalConfig
import com.warkop.pos.model.StaffUser
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** 073 — tablet tanpa outlet/terminal mulai di penyiapan; terminal yang ditolak server mengembalikan tablet ke sana. */
@OptIn(ExperimentalCoroutinesApi::class)
class PosStoreSetupTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend

    @Before fun setUp() { Dispatchers.setMain(dispatcher); backend = FakePosBackend() }
    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun `tablet tanpa konfigurasi mulai dari layar penyiapan dan belum memuat staf`() = runTest(dispatcher) {
        val store = PosStore(backend = backend)
        store.bootstrap()
        advanceUntilIdle()
        assertEquals(Dest.SETUP, store.dest)
        assertTrue(store.roster is Load.Idle)
    }

    @Test fun `selesai penyiapan membuka layar PIN dan menghapus pesan lama`() = runTest(dispatcher) {
        val store = PosStore(backend = backend)
        store.setConfig(TerminalConfig("http://x", 1, "WJM 3", 7, "Kasir Depan"))
        advanceUntilIdle()
        assertEquals(Dest.PIN, store.dest)
        assertNull(store.setupNotice)
        assertFalse(store.terminalRevoked)
        assertEquals("Kasir Depan", store.config?.deviceName)
    }

    @Test fun `login PIN ditolak 422 device_id membuang pilihan dan kembali ke penyiapan dengan pesan`() = runTest(dispatcher) {
        backend.pinLoginResult = { _, _ -> throw ApiError.Validation("Terminal dicabut", mapOf("device_id" to listOf("dicabut"))) }
        val store = PosStore(backend = backend)
        store.setConfig(TerminalConfig("http://x", 1, "WJM 3", 7, "Kasir Depan"))
        advanceUntilIdle()

        var failure: String? = null
        store.loginPin(StaffUser("5", "Sari", "Kasir", "CSH"), "482916") { failure = it }
        advanceUntilIdle()

        assertNotNull(failure)
        assertEquals(Dest.SETUP, store.dest)
        assertNull(store.config)
        assertNotNull(store.setupNotice)
        assertEquals(1, backend.forgotten)
    }

    @Test fun `PIN salah biasa tidak mengembalikan ke penyiapan`() = runTest(dispatcher) {
        backend.pinLoginResult = { _, _ -> throw ApiError.Unauthorized("PIN salah.", "invalid_pin") }
        val store = PosStore(backend = backend)
        store.setConfig(TerminalConfig("http://x", 1, "WJM 3", 7, "Kasir Depan"))
        advanceUntilIdle()

        store.loginPin(StaffUser("5", "Sari", "Kasir", "CSH"), "000111") { }
        advanceUntilIdle()

        assertEquals(Dest.PIN, store.dest)
        assertNotNull(store.config)
        assertEquals(0, backend.forgotten)
    }
}
