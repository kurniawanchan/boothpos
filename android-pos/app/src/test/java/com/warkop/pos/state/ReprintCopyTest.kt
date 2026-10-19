package com.warkop.pos.state

import org.junit.Assert.assertEquals
import org.junit.Test

/** 057 / T023 — nomor salinan = cetak ulang yang diterima server + 2 (salinan asli = 1, FR-004). */
class ReprintCopyTest {
    @Test fun `tanpa cetak ulang salinan berikutnya adalah 2`() {
        assertEquals(2, ReprintRules.nextCopyNo(0))
    }

    @Test fun `setelah satu cetak ulang salinan berikutnya adalah 3`() {
        assertEquals(3, ReprintRules.nextCopyNo(1))
    }
}
