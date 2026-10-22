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
import org.junit.Assert.assertNotNull
import org.junit.Before
import org.junit.Test

/**
 * 065 / US3 — tutup shift: total ketikan dan pecahan dikirim sebagai SATU hitungan; tidak ada nilai EDC yang ikut
 * (parameternya sudah tidak ada). Hasil tutup dipegang store untuk layar hasil.
 */
@OptIn(ExperimentalCoroutinesApi::class)
class CloseShiftTypedTotalTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var backend: FakePosBackend
    private lateinit var store: PosStore

    @Before fun setUp() {
        Dispatchers.setMain(dispatcher)
        backend = FakePosBackend()
        store = PosStore(backend = backend)
    }

    @After fun tearDown() { Dispatchers.resetMain() }

    private fun openFirst(count: ShiftCount) {
        store.openShift(count)
    }

    @Test fun `total ketikan dikirim apa adanya sebagai satu hitungan`() = runTest(dispatcher) {
        openFirst(CountInput().type("100000").toCount())
        runCurrent()
        assertNotNull(store.shift)

        store.submitCount(CountInput().type("250000").toCount())
        runCurrent()

        assertEquals(listOf<ShiftCount>(ShiftCount.Typed(250_000)), backend.closedWith)
        assertNotNull(store.closeResult)
        assertEquals(Dest.CLOSE_RESULT, store.dest)
    }

    @Test fun `hitungan per pecahan dikirim sebagai pecahan, bukan total`() = runTest(dispatcher) {
        openFirst(CountInput().type("100000").toCount())
        runCurrent()

        store.submitCount(CountInput().setCount(100_000, 2).setCount(50_000, 1).toCount())
        runCurrent()

        assertEquals(listOf<ShiftCount>(ShiftCount.ByDenomination(mapOf(100_000 to 2, 50_000 to 1))), backend.closedWith)
    }

    @Test fun `mengetik setelah memakai stepper hanya mengirim total ketikan`() = runTest(dispatcher) {
        openFirst(CountInput().type("1000").toCount())
        runCurrent()

        store.submitCount(CountInput().setCount(100_000, 2).type("150000").toCount())
        runCurrent()

        assertEquals(listOf<ShiftCount>(ShiftCount.Typed(150_000)), backend.closedWith)
    }
}
