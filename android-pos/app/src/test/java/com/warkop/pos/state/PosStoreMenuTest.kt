package com.warkop.pos.state

import com.warkop.core.domain.ApiError
import com.warkop.pos.model.MenuCatalog
import com.warkop.pos.model.MenuCategory
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
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** 055 / T023 — katalog counter: data server atau keadaan galat, tanpa data contoh pengganti (FR-012, FR-013a, FR-014). */
@OptIn(ExperimentalCoroutinesApi::class)
class PosStoreMenuTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend

    @Before fun setUp() {
        Dispatchers.setMain(dispatcher)
        backend = FakePosBackend()
    }

    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun `katalog dari server dimuat apa adanya`() = runTest(dispatcher) {
        val kopi = MenuCategory("2", "Kopi", "K", Tone.INK)
        backend.menuResult = { MenuCatalog(listOf(kopi), emptyList(), emptyMap()) }
        val store = PosStore(backend = backend)

        store.loadMenu()
        advanceUntilIdle()

        val ready = store.catalog as? Load.Ready ?: error("harus Ready, bukan ${store.catalog}")
        assertEquals(listOf("Kopi"), ready.value.categories.map { it.name })
    }

    @Test fun `galat jaringan menjadi keadaan galat, bukan data contoh`() = runTest(dispatcher) {
        backend.menuResult = { throw ApiError.Network() }
        val store = PosStore(backend = backend)

        store.loadMenu()
        advanceUntilIdle()

        assertTrue("harus Failed, bukan ${store.catalog}", store.catalog is Load.Failed)
    }

    @Test fun `tanpa backend katalog tetap Idle`() = runTest(dispatcher) {
        val store = PosStore(backend = null)
        store.loadMenu()
        advanceUntilIdle()
        assertTrue(store.catalog is Load.Idle)
    }
}
