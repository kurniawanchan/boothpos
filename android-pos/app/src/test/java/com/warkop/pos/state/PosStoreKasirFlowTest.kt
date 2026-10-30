package com.warkop.pos.state

import com.warkop.pos.model.FloorSnapshot
import com.warkop.pos.model.Order
import com.warkop.pos.model.OrderKind
import com.warkop.pos.model.OrderLine
import com.warkop.pos.model.Shift
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
 * 084 — alur Kasir: layar awal memakai/membuka tab counter, tab kosong dibuang sebelum tutup shift, daftar Selesai milik
 * shift terbuka saja dan dikosongkan saat shift ditutup, dan ubah meja memanggil server lalu menyegarkan denah.
 */
@OptIn(ExperimentalCoroutinesApi::class)
class PosStoreKasirFlowTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend
    private lateinit var store: PosStore
    private val shift = Shift("Shift", "08:00", "", 0, "1", id = 7)
    private val emptyCounter = Order("T1", OrderKind.COUNTER, null, 0, "12", "kasir", "10:00")
    private val line = OrderLine(1, "10", "Kopi", 20000, 1)
    private val filledCounter = Order("T2", OrderKind.COUNTER, null, 0, "13", "kasir", "10:05", lines = listOf(line))

    @Before fun setUp() {
        Dispatchers.setMain(dispatcher)
        backend = FakePosBackend()
        store = PosStore(backend = backend)
    }
    @After fun tearDown() { Dispatchers.resetMain() }

    private fun install(orders: List<Order>, active: String? = null, dest: Dest = Dest.TABLES, withShift: Boolean = true) =
        store.debugInstall(null, if (withShift) shift else null, orders, active, dest, null, store.conn, null, 0, null)

    // ---- layar awal ----

    @Test fun `layar awal memakai tab counter kosong yang ada tanpa membuka yang baru`() = runTest(dispatcher) {
        install(listOf(emptyCounter))

        store.landOnKasir(); advanceUntilIdle()

        assertEquals("T1", store.activeId)
        assertEquals(Dest.EDITOR, store.dest)
        // openTab tidak diatur di fake: bila dipanggil, uji ini melempar.
    }

    @Test fun `layar awal tanpa tab kosong membuka Kasir sebagai draf tanpa membuat pesanan di server`() = runTest(dispatcher) {
        install(listOf(filledCounter))

        store.landOnKasir(); advanceUntilIdle()

        assertEquals(Dest.EDITOR, store.dest)
        assertNull(store.activeId)
        assertEquals("", store.kasirDraft.id)
        assertTrue(store.kasirDraft.live.isEmpty())
        // openTab tidak diatur di fake: bila dipanggil, uji ini melempar.
    }

    @Test fun `item pertama membuat tab counter lalu menambahkan barisnya`() = runTest(dispatcher) {
        install(listOf(filledCounter))
        store.landOnKasir()
        val created = emptyCounter.copy(id = "T9")
        backend.openTabResult = { created }
        backend.addLineResult = { created.copy(lines = listOf(line)) }

        store.addLine(com.warkop.pos.model.Product("10", "", "k", "Kopi", 20_000), emptyList(), ""); advanceUntilIdle()

        assertEquals("T9", store.activeId)
        assertEquals(listOf("10"), backend.addLineCalls)
        assertEquals(1, store.active?.live?.size)
        assertEquals(Dest.EDITOR, store.dest)
    }

    @Test fun `memilih meja saat Kasir masih draf membuat tab lalu memindahkannya`() = runTest(dispatcher) {
        install(listOf(filledCounter))
        store.landOnKasir()
        backend.openTabResult = { emptyCounter.copy(id = "T9") }
        backend.moveTabResult = { emptyCounter.copy(id = "T9", kind = OrderKind.DINE_IN, table = "5") }
        backend.floorResult = { FloorSnapshot("v2", emptyList(), emptyList()) }

        store.changeTable("", 55L); advanceUntilIdle()

        assertEquals(listOf("T9" to 55L), backend.moveCalls)
        assertEquals("5", store.active?.table)
    }

    @Test fun `kembali ke Kasir sesudah bayar memakai tab kosong yang ada dan tidak membuka pesanan baru`() = runTest(dispatcher) {
        val paid = filledCounter.copy(id = "P1", closed = true)
        install(listOf(paid, emptyCounter), active = "P1", dest = Dest.DONE)

        store.openKasir(); advanceUntilIdle()

        assertEquals("T1", store.activeId)
        assertEquals(Dest.EDITOR, store.dest)
        // openTab tidak diatur di fake: bila dipanggil, uji ini melempar.
    }

    @Test fun `kembali ke Kasir tidak membuka lagi pesanan yang sudah selesai dan tidak membuat pesanan baru`() = runTest(dispatcher) {
        val paid = filledCounter.copy(id = "P1", closed = true)
        install(listOf(paid), active = "P1", dest = Dest.DONE)

        store.openKasir(); advanceUntilIdle()

        assertNull(store.activeId) // bukan "P1"; draf
        assertEquals(Dest.EDITOR, store.dest)
    }

    @Test fun `kembali ke Kasir saat tab counter terbuka masih dikerjakan melanjutkannya`() = runTest(dispatcher) {
        install(listOf(filledCounter), active = "T2", dest = Dest.TABLES)

        store.openKasir(); advanceUntilIdle()

        assertEquals("T2", store.activeId)
        assertEquals(Dest.EDITOR, store.dest)
    }

    // ---- tab kosong dan tutup shift ----

    @Test fun `tutup shift membuang tab kosong dan membiarkan yang berisi`() = runTest(dispatcher) {
        install(listOf(emptyCounter, filledCounter), active = "T1")

        store.submitCount(com.warkop.pos.model.ShiftCount.Typed(250_000)); advanceUntilIdle()

        assertEquals(listOf("T1"), backend.emptyClosed)
        assertEquals(1, backend.closedWith.size)
        assertEquals(Dest.CLOSE_RESULT, store.dest)
    }

    @Test fun `setelah shift ditutup pesanan shift itu hilang dari daftar aktif dan selesai`() = runTest(dispatcher) {
        install(listOf(filledCounter, emptyCounter.copy(id = "C1", closed = true)))

        store.submitCount(com.warkop.pos.model.ShiftCount.Typed(250_000)); advanceUntilIdle()

        assertTrue(store.openOrders.isEmpty())
        assertTrue(store.closedOrders.isEmpty())
        assertNull(store.activeId)
    }

    // ---- daftar Selesai per shift ----

    @Test fun `daftar selesai diminta untuk shift yang sedang terbuka`() = runTest(dispatcher) {
        install(emptyList())
        backend.closedOrdersResult = { listOf(emptyCounter.copy(id = "C1", closed = true)) }

        store.loadClosed(); advanceUntilIdle()

        assertEquals(listOf<Long?>(7L), backend.closedSessionRequests)
        assertEquals(listOf("C1"), store.closedOrders.map { it.id })
    }

    @Test fun `tanpa shift daftar selesai kosong dan server tidak ditanya`() = runTest(dispatcher) {
        install(emptyList(), withShift = false)

        store.loadClosed(); advanceUntilIdle()

        assertTrue(backend.closedSessionRequests.isEmpty())
        assertTrue(store.closedOrders.isEmpty())
    }

    // ---- ubah meja ----

    @Test fun `ubah meja memanggil server lalu menyegarkan denah`() = runTest(dispatcher) {
        install(listOf(emptyCounter), active = "T1")
        backend.moveTabResult = { tableId -> emptyCounter.copy(kind = OrderKind.DINE_IN, table = "5") }
        backend.floorResult = { FloorSnapshot("v2", emptyList(), emptyList()) }

        store.changeTable("T1", 55L); advanceUntilIdle()

        assertEquals(listOf("T1" to 55L), backend.moveCalls)
        assertEquals("5", store.active?.table)
        assertEquals("Meja diubah.", store.toast)
    }

    // ---- rincian pesanan Selesai ----

    private val summary = Order("C1", OrderKind.COUNTER, null, 0, null, "", "10:00", closed = true, receiptNo = "000014", serverTotal = 46_000)
    private val full = Order(
        "C1", OrderKind.DINE_IN, "5", 2, "12", "Budi", "10:00", closed = true, receiptNo = "000014",
        lines = listOf(OrderLine(1, "10", "Kopi Susu", 20_000, 2, mods = listOf(com.warkop.pos.model.ModOption("Boba", 3_000)))),
        serverSubtotal = 46_000, serverTax = 0, serverTotal = 46_000, serverPaid = 50_000, serverChange = 4_000,
    )

    @Test fun `daftar selesai awalnya hanya ringkasan tanpa rincian lengkap`() = runTest(dispatcher) {
        install(emptyList())
        backend.closedOrdersResult = { listOf(summary) }

        store.loadClosed(); advanceUntilIdle()

        assertTrue(store.closedOrders.single().live.isEmpty())
        assertTrue(!store.hasFullDetail("C1"))
    }

    @Test fun `memilih pesanan selesai memuat item meja kasir dan subtotal`() = runTest(dispatcher) {
        install(emptyList())
        backend.closedOrdersResult = { listOf(summary) }
        backend.tabResult = { full }
        store.loadClosed(); advanceUntilIdle()

        store.loadClosedDetail("C1"); advanceUntilIdle()

        val o = store.closedOrders.single()
        assertEquals(listOf("C1"), backend.tabCalls)
        assertEquals("Kopi Susu", o.live.single().name)
        assertEquals("Boba", o.live.single().mods.single().name)
        assertEquals("5", o.table)
        assertEquals("Budi", o.waiter)
        assertEquals(46_000, o.gross)
        assertTrue(store.hasFullDetail("C1"))
    }

    @Test fun `rincian yang sudah lengkap tidak diminta lagi`() = runTest(dispatcher) {
        install(listOf(full))

        store.loadClosedDetail("C1"); advanceUntilIdle()

        assertTrue(backend.tabCalls.isEmpty())
    }

    @Test fun `gagal memuat rincian ditandai gagal dan bisa dicoba lagi`() = runTest(dispatcher) {
        install(emptyList())
        backend.closedOrdersResult = { listOf(summary) }
        backend.tabResult = { throw com.warkop.core.domain.ApiError.NotFound("tidak ada") }
        store.loadClosed(); advanceUntilIdle()

        store.loadClosedDetail("C1"); advanceUntilIdle()
        assertTrue(store.closedDetailLoad["C1"] is Load.Failed)

        backend.tabResult = { full }
        store.loadClosedDetail("C1", force = true); advanceUntilIdle()
        assertTrue(store.closedDetailLoad["C1"] is Load.Ready)
        assertTrue(store.hasFullDetail("C1"))
    }

    @Test fun `pesanan batal tidak meminta rincian tab`() = runTest(dispatcher) {
        install(emptyList())
        backend.closedOrdersResult = { listOf(summary.copy(cancelledReason = "Dibatalkan: salah input")) }
        store.loadClosed(); advanceUntilIdle()

        store.loadClosedDetail("C1"); advanceUntilIdle()

        assertTrue(backend.tabCalls.isEmpty())
    }

    @Test fun `tutup shift membuang rincian yang sudah dimuat`() = runTest(dispatcher) {
        install(listOf(full))

        store.submitCount(com.warkop.pos.model.ShiftCount.Typed(250_000)); advanceUntilIdle()

        assertTrue(store.closedOrders.isEmpty())
        assertTrue(store.closedDetailLoad.isEmpty())
    }
}
