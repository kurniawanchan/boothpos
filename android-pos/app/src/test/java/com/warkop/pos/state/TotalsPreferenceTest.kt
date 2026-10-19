package com.warkop.pos.state

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** 055 / T035 — pilihan total tampil/sembunyi: default tampil, pilihan tersimpan dan terbaca kembali. */
class TotalsPreferenceTest {
    @Test fun `tanpa data tersimpan area total tampil`() {
        assertTrue(TotalsPreference({ null }, {}).visible)
    }

    @Test fun `sembunyi disimpan lalu terbaca kembali`() {
        var stored: Boolean? = null
        val pref = TotalsPreference({ stored }, { stored = it })
        pref.visible = false
        assertEquals(false, stored)
        assertEquals(false, pref.visible)
    }

    @Test fun `tampil lagi menimpa pilihan sembunyi`() {
        var stored: Boolean? = false
        val pref = TotalsPreference({ stored }, { stored = it })
        pref.visible = true
        assertTrue(pref.visible)
    }
}
