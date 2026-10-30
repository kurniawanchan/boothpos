package com.warkop.pos.state

import com.warkop.core.domain.ApiError
import com.warkop.pos.model.Order
import com.warkop.pos.model.OrderKind
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

/** 077 / US4 — catatan order disimpan lewat jalur tulis yang sama; teks tidak hilang bila gagal; batas 1000; order tertutup tak bisa diubah. */
@OptIn(ExperimentalCoroutinesApi::class)
class PosStoreOrderNoteTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend
    private lateinit var store: PosStore
    private val order = Order("T1", OrderKind.COUNTER, null, 0, "A1", "kasir", "10:00")

    @Before fun setUp() {
        Dispatchers.setMain(dispatcher)
        backend = FakePosBackend()
        backend.setNoteResult = { text -> order.copy(note = text) }
        store = PosStore(backend = backend)
        store.debugInstall(null, null, listOf(order), "T1", Dest.EDITOR, null, store.conn, null, 0, null)
    }
    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun `catatan dikirim ke server dan tersimpan pada order aktif`() = runTest(dispatcher) {
        var saved = false
        store.saveOrderNote("  Es sedikit  ") { saved = true }
        advanceUntilIdle()
        assertEquals(listOf("T1" to "Es sedikit"), backend.noteCalls)
        assertEquals("Es sedikit", store.active?.note)
        assertTrue(saved)
    }

    @Test fun `catatan kosong menghapus catatan`() = runTest(dispatcher) {
        store.saveOrderNote("ada"); advanceUntilIdle()
        store.saveOrderNote("   "); advanceUntilIdle()
        assertEquals(listOf("T1" to "ada", "T1" to ""), backend.noteCalls)
        assertEquals("", store.active?.note)
    }

    @Test fun `lebih dari 1000 karakter ditolak sebelum dikirim dengan pesan`() = runTest(dispatcher) {
        var saved = false
        store.saveOrderNote("x".repeat(ORDER_NOTE_MAX + 1)) { saved = true }
        advanceUntilIdle()
        assertTrue(backend.noteCalls.isEmpty())
        assertEquals("Catatan maksimal 1000 karakter.", store.toast)
        assertTrue(!saved)
    }

    @Test fun `tepat 1000 karakter diterima`() = runTest(dispatcher) {
        store.saveOrderNote("x".repeat(ORDER_NOTE_MAX)); advanceUntilIdle()
        assertEquals(1, backend.noteCalls.size)
    }

    @Test fun `gagal server menampilkan pesan dan tidak memanggil onSaved sehingga teks ketikan tetap`() = runTest(dispatcher) {
        backend.setNoteResult = { throw ApiError.Conflict("Tab sudah ditutup", "tab_not_open") }
        var saved = false
        store.saveOrderNote("penting") { saved = true }
        advanceUntilIdle()
        assertTrue(!saved)
        assertEquals("Tab sudah ditutup", store.toast)
        assertEquals("", store.active?.note)
    }

    @Test fun `order tertutup tidak bisa diberi catatan`() = runTest(dispatcher) {
        store.debugInstall(null, null, listOf(order.copy(closed = true)), "T1", Dest.EDITOR, null, store.conn, null, 0, null)
        store.saveOrderNote("terlambat"); advanceUntilIdle()
        assertTrue(backend.noteCalls.isEmpty())
        assertEquals("Pesanan sudah ditutup. Catatan tidak bisa diubah.", store.toast)
    }

    @Test fun `catatan tidak diblokir bila mode sama dan diblokir saat mode berubah`() = runTest(dispatcher) {
        backend.brandingResult = { com.warkop.pos.model.Branding(com.warkop.pos.model.SystemMode.LIVE, "T", null) }
        backend.sessionBrandingResult = { com.warkop.pos.model.Branding(com.warkop.pos.model.SystemMode.DEMO, "T", null) }
        backend.menuResult = { com.warkop.pos.model.MenuCatalog(emptyList(), emptyList(), emptyMap(), emptyList()) }
        store.refreshCounter(); advanceUntilIdle()
        assertEquals(com.warkop.pos.model.SystemMode.DEMO, store.modeChanged)
        store.saveOrderNote("x"); advanceUntilIdle()
        assertTrue(backend.noteCalls.isEmpty())
        assertNull(store.toast)
    }
}
