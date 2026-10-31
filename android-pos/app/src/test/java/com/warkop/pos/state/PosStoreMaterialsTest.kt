package com.warkop.pos.state

import com.warkop.core.domain.ApiError
import com.warkop.pos.model.MaterialMove
import com.warkop.pos.model.MaterialStock
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/** 087 lanjutan (2026-11-01) — layar "Stok Bahan": memuat saldo outlet tablet dan riwayat satu bahan (hanya baca). */
@OptIn(ExperimentalCoroutinesApi::class)
class PosStoreMaterialsTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend
    private val soto = MaterialStock(9, "INDM-ST", "Indomie Soto", "pcs", "Bahan baku", 17.0)

    @Before fun setUp() { Dispatchers.setMain(dispatcher); backend = FakePosBackend() }
    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun `memuat daftar bahan dan riwayat bahan yang dipilih`() = runTest(dispatcher) {
        backend.materialStockResult = { listOf(soto) }
        backend.materialMovesResult = { id -> listOf(MaterialMove("Penjualan", "−1", "18", "17", false, "TRX-1", "Kasir A", null)).also { assertEquals(9L, id) } }
        val store = PosStore(backend = backend)

        store.loadMaterials(); advanceUntilIdle()
        assertEquals(listOf(soto), (store.materials as Load.Ready).value)

        store.openMaterial(soto); advanceUntilIdle()
        assertEquals(soto, store.materialSelected)
        assertEquals("TRX-1", (store.materialMoves as Load.Ready).value.single().note)

        store.closeMaterial()
        assertNull(store.materialSelected)
        assertEquals(Load.Idle, store.materialMoves)
    }

    @Test fun `galat memuat bahan tampil sebagai gagal, bukan daftar kosong`() = runTest(dispatcher) {
        backend.materialStockResult = { throw ApiError.Forbidden("Tidak boleh") }
        val store = PosStore(backend = backend)
        store.loadMaterials(); advanceUntilIdle()
        assertTrue(store.materials is Load.Failed)
    }
}
