package com.warkop.pos.state

import com.warkop.core.domain.ApiError
import com.warkop.pos.model.ReasonCode
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

/** 057 / T035 — daftar alasan "Tandai habis" selalu berakhir Ready atau Failed, tidak pernah "Memuat" selamanya (FR-008, FR-009). */
@OptIn(ExperimentalCoroutinesApi::class)
class SoldOutReasonsTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend

    @Before fun setUp() {
        Dispatchers.setMain(dispatcher)
        backend = FakePosBackend()
    }

    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun `alasan dari server tampil`() = runTest(dispatcher) {
        backend.reasons = { listOf(ReasonCode(1L, "Habis", "sold")) }
        val store = PosStore(backend = backend)
        store.loadSoldOutReasons()
        advanceUntilIdle()
        val ready = store.soldOutReasons as? Load.Ready ?: error("harus Ready, bukan ${store.soldOutReasons}")
        assertEquals(listOf("Habis"), ready.value.map { it.label })
    }

    @Test fun `galat memberi Failed, bukan Memuat selamanya`() = runTest(dispatcher) {
        backend.reasons = { throw ApiError.Unauthorized("Sesi berakhir") }
        val store = PosStore(backend = backend)
        store.loadSoldOutReasons()
        advanceUntilIdle()
        assertTrue("harus Failed, bukan ${store.soldOutReasons}", store.soldOutReasons is Load.Failed)
    }

    @Test fun `daftar kosong adalah Ready dengan nol alasan`() = runTest(dispatcher) {
        backend.reasons = { emptyList() }
        val store = PosStore(backend = backend)
        store.loadSoldOutReasons()
        advanceUntilIdle()
        assertTrue(store.soldOutReasons is Load.Ready && (store.soldOutReasons as Load.Ready).value.isEmpty())
    }
}
