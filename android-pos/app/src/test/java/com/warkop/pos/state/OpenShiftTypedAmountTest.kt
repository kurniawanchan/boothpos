package com.warkop.pos.state

import com.warkop.pos.model.ShiftCount
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test

/** 065 / US4 — buka shift: total ketikan → `opening_cash` saja; stepper → pecahan saja; keduanya tidak pernah bercampur. */
@OptIn(ExperimentalCoroutinesApi::class)
class OpenShiftTypedAmountTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend
    private lateinit var store: PosStore

    @Before fun setUp() {
        Dispatchers.setMain(dispatcher)
        backend = FakePosBackend()
        store = PosStore(backend = backend)
    }

    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun `total ketikan membuka shift dengan jumlah itu`() = runTest(dispatcher) {
        store.openShift(CountInput().type("100000").toCount())
        runCurrent()

        assertEquals(listOf<ShiftCount>(ShiftCount.Typed(100_000)), backend.openedWith)
        assertEquals(100_000, store.shift?.float)
    }

    @Test fun `stepper membuka shift per pecahan`() = runTest(dispatcher) {
        store.openShift(CountInput().setCount(50_000, 2).toCount())
        runCurrent()

        assertEquals(listOf<ShiftCount>(ShiftCount.ByDenomination(mapOf(50_000 to 2))), backend.openedWith)
        assertEquals(100_000, store.shift?.float)
    }

    @Test fun `berpindah dari stepper ke ketikan hanya mengirim ketikan`() = runTest(dispatcher) {
        store.openShift(CountInput().setCount(50_000, 2).type("75000").toCount())
        runCurrent()

        assertEquals(listOf<ShiftCount>(ShiftCount.Typed(75_000)), backend.openedWith)
    }

    @Test fun `modal nol tetap bisa dibuka lewat hitungan kosong`() = runTest(dispatcher) {
        store.openShift(CountInput().toCount())
        runCurrent()

        assertEquals(0, backend.openedWith.single().total)
    }
}
