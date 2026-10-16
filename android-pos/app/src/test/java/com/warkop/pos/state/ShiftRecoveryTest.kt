package com.warkop.pos.state

import com.warkop.pos.model.Shift
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** Setelah server menolak "shift sudah ditutup", kasir tanpa shift terbuka harus diarahkan ke layar buka shift. */
class ShiftRecoveryTest {
    private val shift = Shift(label = "Shift 11", openedAt = "04:10", schedule = "", float = 150000, userId = "8", id = 11)

    @Test fun `kasir tanpa shift terbuka diarahkan ke layar buka shift`() {
        assertEquals(Dest.OPEN_SHIFT, shiftRecoveryDest(null, "CSH"))
    }

    @Test fun `shift yang masih terbuka tidak mengubah layar`() {
        assertNull(shiftRecoveryDest(shift, "CSH"))
    }

    @Test fun `peran selain kasir tidak dipaksa ke layar buka shift`() {
        assertNull(shiftRecoveryDest(null, "WTR"))
    }
}
