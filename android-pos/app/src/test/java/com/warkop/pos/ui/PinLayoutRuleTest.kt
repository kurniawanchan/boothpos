package com.warkop.pos.ui

import org.junit.Assert.assertEquals
import org.junit.Test

/** 077/078 / US2 — tiga kolom hanya bila lebarnya cukup; di bawahnya dua kolom, lalu bertumpuk, dengan urutan isi yang sama. */
class PinLayoutRuleTest {
    @Test fun `tablet lanskap lebar memakai tiga kolom`() {
        assertEquals(PinLayout.THREE_COLUMN, pinLayoutFor(1280f))
        assertEquals(PinLayout.THREE_COLUMN, pinLayoutFor(1024f))
    }

    @Test fun `tepat di batas tiga kolom`() {
        assertEquals(PinLayout.TWO_COLUMN, pinLayoutFor(PIN_THREE_COLUMN_MIN_DP - 1f))
        assertEquals(PinLayout.THREE_COLUMN, pinLayoutFor(PIN_THREE_COLUMN_MIN_DP))
        assertEquals(960f, PIN_THREE_COLUMN_MIN_DP, 0f)
    }

    @Test fun `lebar menengah memakai dua kolom`() {
        assertEquals(PinLayout.TWO_COLUMN, pinLayoutFor(840f))
        assertEquals(PinLayout.TWO_COLUMN, pinLayoutFor(720f))
        assertEquals(PinLayout.TWO_COLUMN, pinLayoutFor(959f))
    }

    @Test fun `ponsel dan layar sempit bertumpuk`() {
        assertEquals(PinLayout.STACKED, pinLayoutFor(411f))
        assertEquals(PinLayout.STACKED, pinLayoutFor(719f))
        assertEquals(PinLayout.STACKED, pinLayoutFor(PIN_TWO_COLUMN_MIN_DP - 0.5f))
    }
}
