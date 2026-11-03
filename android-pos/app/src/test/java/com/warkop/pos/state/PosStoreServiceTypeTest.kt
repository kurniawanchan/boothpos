package com.warkop.pos.state

import com.warkop.core.domain.ApiError
import com.warkop.pos.model.Order
import com.warkop.pos.model.OrderKind
import com.warkop.pos.model.SERVICE_DINE_IN
import com.warkop.pos.model.SERVICE_TAKEAWAY
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

/** 090 / US5 — Dine-In ↔ Take Away pada order aktif: lewat server, tanpa panggilan bila nilainya sama, tertutup = tak bisa diubah. */
@OptIn(ExperimentalCoroutinesApi::class)
class PosStoreServiceTypeTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend
    private lateinit var store: PosStore
    private val order = Order("T1", OrderKind.DINE_IN, "5", 2, null, "kasir", "10:00", serviceType = SERVICE_DINE_IN)

    @Before fun setUp() {
        Dispatchers.setMain(dispatcher)
        backend = FakePosBackend()
        backend.setServiceTypeResult = { type -> order.copy(serviceType = type) }
        store = PosStore(backend = backend)
        store.debugInstall(null, null, listOf(order), "T1", Dest.EDITOR, null, store.conn, null, 0, null)
    }
    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun `memilih Take Away dikirim ke server dan tercermin pada order aktif`() = runTest(dispatcher) {
        store.setServiceType(SERVICE_TAKEAWAY)
        advanceUntilIdle()
        assertEquals(listOf("T1" to SERVICE_TAKEAWAY), backend.serviceTypeCalls)
        assertEquals(SERVICE_TAKEAWAY, store.active?.serviceType)
        assertTrue(store.active!!.isTakeaway)
    }

    @Test fun `memilih nilai yang sudah sama tidak memanggil server`() = runTest(dispatcher) {
        store.setServiceType(SERVICE_DINE_IN)
        advanceUntilIdle()
        assertTrue(backend.serviceTypeCalls.isEmpty())
    }

    @Test fun `bolak-balik dikirim berurutan`() = runTest(dispatcher) {
        store.setServiceType(SERVICE_TAKEAWAY); advanceUntilIdle()
        store.setServiceType(SERVICE_DINE_IN); advanceUntilIdle()
        assertEquals(listOf("T1" to SERVICE_TAKEAWAY, "T1" to SERVICE_DINE_IN), backend.serviceTypeCalls)
        assertEquals(SERVICE_DINE_IN, store.active?.serviceType)
        assertFalse(store.active!!.isTakeaway)
    }

    @Test fun `order tertutup tidak bisa diubah dan diberi tahu`() = runTest(dispatcher) {
        store.debugInstall(null, null, listOf(order.copy(closed = true)), "T1", Dest.EDITOR, null, store.conn, null, 0, null)
        store.setServiceType(SERVICE_TAKEAWAY)
        advanceUntilIdle()
        assertTrue(backend.serviceTypeCalls.isEmpty())
        assertEquals("Pesanan sudah ditutup. Cara layanan tidak bisa diubah.", store.toast)
    }

    @Test fun `bila server menolak nilai lama tetap`() = runTest(dispatcher) {
        backend.setServiceTypeResult = { throw ApiError.Conflict("Tab sudah ditutup", "tab_not_open") }
        store.setServiceType(SERVICE_TAKEAWAY)
        advanceUntilIdle()
        assertEquals(SERVICE_DINE_IN, store.active?.serviceType)
    }
}
