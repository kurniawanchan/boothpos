package com.warkop.pos.ui

import org.junit.Assert.assertEquals
import org.junit.Test

/** 077 / US3 — dua kolom hanya bila lebarnya cukup; selain itu bertumpuk dengan urutan yang sama. */
class PinLayoutRuleTest {
    @Test fun `tablet lanskap memakai dua kolom`() {
        assertEquals(PinLayout.TWO_COLUMN, pinLayoutFor(1280f))
        assertEquals(PinLayout.TWO_COLUMN, pinLayoutFor(840f))
    }

    @Test fun `tepat di batas minimum masih dua kolom`() {
        assertEquals(PinLayout.TWO_COLUMN, pinLayoutFor(PIN_TWO_COLUMN_MIN_DP))
    }

    @Test fun `ponsel dan layar sempit bertumpuk`() {
        assertEquals(PinLayout.STACKED, pinLayoutFor(411f))
        assertEquals(PinLayout.STACKED, pinLayoutFor(719f))
    }
}
