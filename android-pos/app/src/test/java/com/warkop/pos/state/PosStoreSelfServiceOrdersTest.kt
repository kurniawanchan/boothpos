package com.warkop.pos.state

import com.warkop.core.domain.ApiError
import com.warkop.pos.model.SelfServiceLineView
import com.warkop.pos.model.SelfServiceOrderView
import com.warkop.pos.model.SelfServiceQueue
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
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** 090 — tab Mandiri di Pesanan: antrean dari server, rincian, verifikasi/tolak/kirim ke dapur lewat satu pintu. */
@OptIn(ExperimentalCoroutinesApi::class)
class PosStoreSelfServiceOrdersTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend
    private lateinit var store: PosStore

    private fun view(id: Long, status: String = "awaiting_verification", detail: Boolean = false) = SelfServiceOrderView(
        id, "SS-$id", status, "5", false, 2, "55000.00", null, null, null, null, null,
        if (detail) listOf(SelfServiceLineView("Kopi", 2, "55000.00", null, emptyList())) else emptyList(), detail,
    )

    @Before fun setUp() {
        Dispatchers.setMain(dispatcher)
        backend = FakePosBackend()
        store = PosStore(backend = backend)
    }
    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun `antrean dimuat dan jumlah menunggu mengikuti server`() = runTest(dispatcher) {
        backend.ssQueueResult = { SelfServiceQueue(listOf(view(1), view(2, "verified")), 2) }
        store.loadSelfServiceQueue(); advanceUntilIdle()
        assertEquals(2, (store.selfServiceQueue as Load.Ready).value.items.size)
        assertEquals(2, store.selfServiceWaiting)
    }

    @Test fun `membuka baris memuat rincian lengkap`() = runTest(dispatcher) {
        backend.ssActionResult = { _, id, _ -> view(id, detail = true) }
        store.openSelfServiceOrder(view(1)); advanceUntilIdle()
        assertTrue(store.selfServiceOpen!!.detailLoaded)
        assertEquals(listOf("show:1"), backend.ssCalls)
    }

    @Test fun `verifikasi mengirim referensi, memperbarui status, dan memuat ulang antrean`() = runTest(dispatcher) {
        backend.ssActionResult = { _, id, _ -> view(id, "verified", true) }
        store.verifySelfService(1, " REF-123 "); advanceUntilIdle()
        assertEquals(listOf("verify:1:REF-123"), backend.ssCalls)
        assertTrue(store.selfServiceOpen!!.verified)
        assertEquals("Pembayaran terverifikasi.", store.toast)
        assertFalse(store.selfServiceBusy)
    }

    @Test fun `kirim ke dapur dan tolak memanggil endpoint masing-masing`() = runTest(dispatcher) {
        backend.ssActionResult = { kind, id, _ -> view(id, if (kind == "dispatch") "dispatched" else "rejected", true) }
        store.dispatchSelfService(3); advanceUntilIdle()
        store.rejectSelfService(4, "Bukti tidak sesuai"); advanceUntilIdle()
        assertEquals(listOf("dispatch:3", "reject:4:Bukti tidak sesuai"), backend.ssCalls)
    }

    @Test fun `galat server (mis 403 tanpa izin) ditampilkan dan status tidak berubah`() = runTest(dispatcher) {
        store.openSelfServiceOrder(view(1)); backend.ssActionResult = { _, _, _ -> throw ApiError.Forbidden("Tidak punya izin verifikasi QRIS.") }
        advanceUntilIdle()
        store.verifySelfService(1, "REF-123"); advanceUntilIdle()
        assertEquals("Tidak punya izin verifikasi QRIS.", store.toast)
        assertTrue(store.selfServiceOpen!!.awaiting)
        assertFalse(store.selfServiceBusy)
    }
}
