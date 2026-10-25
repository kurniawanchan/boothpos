package com.warkop.pos.state

import com.warkop.pos.model.MenuCatalog
import com.warkop.pos.model.MenuCategory
import com.warkop.pos.model.ModGroup
import com.warkop.pos.model.ModOption
import com.warkop.pos.model.Order
import com.warkop.pos.model.OrderKind
import com.warkop.pos.model.Product
import com.warkop.pos.model.ProductEntry
import com.warkop.pos.model.Tone
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

/** 075 / T017 — ketuk kartu produk: satu varian langsung, banyak varian membuka daftar; varian habis tidak masuk order. */
@OptIn(ExperimentalCoroutinesApi::class)
class PosStoreVariantPickerTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend
    private lateinit var store: PosStore

    private val hot = Product("10", "coffee", "kopi", "Klepon — Hot", 7000, variantName = "Hot")
    private val ice = Product("11", "coffee", "kopi", "Klepon — Ice", 8000, variantName = "Ice")
    private val roti = Product("20", "sandwich", "kopi", "Roti", 5000)
    private val opsi = Product("30", "coffee", "kopi", "Latte", 9000, groups = listOf("g1"))
    private val klepon = ProductEntry("1", "Klepon", "kopi", "Kopi", null, listOf(hot, ice))
    private val rotiEntry = ProductEntry("2", "Roti", "kopi", "Kopi", null, listOf(roti))
    private val latteEntry = ProductEntry("3", "Latte", "kopi", "Kopi", null, listOf(opsi))

    private val order = Order("T1", OrderKind.COUNTER, null, 0, "A1", "kasir", "10:00")

    @Before fun setUp() {
        Dispatchers.setMain(dispatcher)
        backend = FakePosBackend()
        backend.menuResult = {
            MenuCatalog(listOf(MenuCategory("kopi", "Kopi", "K", Tone.INK)), listOf(hot, ice, roti, opsi),
                mapOf("g1" to ModGroup("g1", "Gula", 0, 1, listOf(ModOption("Normal", 0, 1)))), listOf(klepon, rotiEntry, latteEntry))
        }
        backend.addLineResult = { order }
        store = PosStore(backend = backend)
        store.debugInstall(null, null, listOf(order), "T1", Dest.EDITOR, null, store.conn, null, 0, null)
    }

    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun `produk satu varian langsung ditambahkan tanpa daftar`() = runTest(dispatcher) {
        store.tapEntry(rotiEntry)
        advanceUntilIdle()
        assertNull(store.sheet)
        assertEquals(listOf("20"), backend.addLineCalls)
    }

    @Test fun `produk banyak varian membuka daftar dan belum menambah apa pun`() = runTest(dispatcher) {
        store.tapEntry(klepon)
        advanceUntilIdle()
        assertEquals(Sheet.VariantPicker("1"), store.sheet)
        assertTrue(backend.addLineCalls.isEmpty())
    }

    @Test fun `memilih varian menambah satu baris dan menutup daftar`() = runTest(dispatcher) {
        store.tapEntry(klepon)
        store.pickVariant(ice)
        advanceUntilIdle()
        assertNull(store.sheet)
        assertEquals(listOf("11"), backend.addLineCalls)
    }

    @Test fun `menutup daftar tanpa memilih tidak menambah apa pun`() = runTest(dispatcher) {
        store.tapEntry(klepon)
        store.show(null)
        advanceUntilIdle()
        assertNull(store.sheet)
        assertTrue(backend.addLineCalls.isEmpty())
    }

    @Test fun `varian habis tidak ditambahkan`() = runTest(dispatcher) {
        store.soldOut = setOf("11")
        store.tapEntry(klepon)
        store.pickVariant(ice)
        advanceUntilIdle()
        assertTrue(backend.addLineCalls.isEmpty())
        assertEquals("Klepon — Ice sedang habis.", store.toast)
    }

    @Test fun `produk yang semua variannya habis tidak membuka daftar`() = runTest(dispatcher) {
        store.soldOut = setOf("10", "11")
        store.tapEntry(klepon)
        assertNull(store.sheet)
        assertEquals("Klepon sedang habis.", store.toast)
    }

    @Test fun `varian dengan grup opsi membuka langkah opsi setelah dipilih`() = runTest(dispatcher) {
        store.tapEntry(latteEntry)
        advanceUntilIdle()
        assertEquals(Sheet.Modifier(opsi), store.sheet)
        assertTrue(backend.addLineCalls.isEmpty())
    }

    @Test fun `memuat ulang menu diam-diam mempertahankan daftar pada produk yang sama`() = runTest(dispatcher) {
        store.loadMenu(); advanceUntilIdle()
        store.tapEntry(klepon)
        store.loadMenu(silent = true); advanceUntilIdle()
        assertEquals(Sheet.VariantPicker("1"), store.sheet)
        assertEquals("Klepon", store.menu?.entries?.first { it.id == "1" }?.name)
    }
}
