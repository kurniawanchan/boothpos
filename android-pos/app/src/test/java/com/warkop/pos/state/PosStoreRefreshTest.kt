package com.warkop.pos.state

import com.warkop.core.domain.ApiError
import com.warkop.pos.model.MenuCatalog
import com.warkop.pos.model.MenuCategory
import com.warkop.pos.model.Tone
import com.warkop.pos.data.TaxDisplay
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** 055 / T032 — tarik-untuk-segarkan di counter: indikator, data terakhir tetap, pilihan order tidak hilang. */
@OptIn(ExperimentalCoroutinesApi::class)
class PosStoreRefreshTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend
    private val kopi = MenuCategory("2", "Kopi", "K", Tone.INK)

    @Before fun setUp() {
        Dispatchers.setMain(dispatcher)
        backend = FakePosBackend()
    }

    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun `indikator tampil selama menyegarkan lalu hilang`() = runTest(dispatcher) {
        backend.menuResult = { MenuCatalog(listOf(kopi), emptyList(), emptyMap()) }
        val store = PosStore(backend = backend)

        store.refreshCounter()
        assertTrue(store.refreshing)
        advanceUntilIdle()
        assertFalse(store.refreshing)
    }

    @Test fun `galat saat menyegarkan mempertahankan katalog terakhir`() = runTest(dispatcher) {
        backend.menuResult = { MenuCatalog(listOf(kopi), emptyList(), emptyMap()) }
        val store = PosStore(backend = backend)
        store.loadMenu()
        advanceUntilIdle()

        backend.menuResult = { throw ApiError.Network() }
        store.refreshCounter()
        advanceUntilIdle()

        assertFalse(store.refreshing)
        val kept = store.catalog as? Load.Ready ?: error("katalog terakhir harus tetap Ready, bukan ${store.catalog}")
        assertEquals(listOf("Kopi"), kept.value.categories.map { it.name })
    }

    @Test fun `menyegarkan tidak mengosongkan katalog yang sudah tampil`() = runTest(dispatcher) {
        backend.menuResult = { MenuCatalog(listOf(kopi), emptyList(), emptyMap()) }
        val store = PosStore(backend = backend)
        store.loadMenu()
        advanceUntilIdle()

        store.refreshCounter()
        runCurrent()
        assertTrue("selama menyegarkan katalog tetap Ready", store.catalog is Load.Ready)
        advanceUntilIdle()
    }

    @Test fun `tampil pajak dari server ikut dimuat, bawaan tampil`() = runTest(dispatcher) {
        backend.menuResult = { MenuCatalog(listOf(kopi), emptyList(), emptyMap()) }
        backend.tax = TaxDisplay("PPN", 10.0, show = false)
        val store = PosStore(backend = backend)
        store.loadMenu()
        advanceUntilIdle()
        assertEquals(false, store.tax?.show)
        assertEquals(true, TaxDisplay("PPN", 10.0).show)
    }

    @Test fun `pajak dari server ikut dimuat dan galat pajak tidak menggagalkan katalog`() = runTest(dispatcher) {
        backend.menuResult = { MenuCatalog(listOf(kopi), emptyList(), emptyMap()) }
        backend.tax = TaxDisplay("PPN", 11.0)
        val store = PosStore(backend = backend)
        store.loadMenu()
        advanceUntilIdle()
        assertEquals("PPN", store.tax?.label)
        assertEquals(11.0, store.tax?.ratePercent ?: 0.0, 0.0)
        assertTrue(store.catalog is Load.Ready)
    }
}
