package com.warkop.pos.state

import org.junit.Assert.assertEquals
import org.junit.Test

/** 077 / US5 — lebar Counter: bawaan 0,6; batas 0,35–0,75 dan ≥ 320 dp per sisi; nilai tersimpan dipotong lagi sesuai layar. */
class SplitPreferenceTest {
    @Test fun `tanpa data tersimpan memakai bawaan 60 persen`() {
        assertEquals(SPLIT_DEFAULT, SplitPreference({ null }, {}).fraction, 0f)
    }

    @Test fun `pilihan disimpan lalu terbaca kembali`() {
        var stored: Float? = null
        val pref = SplitPreference({ stored }, { stored = it })
        pref.fraction = 0.5f
        assertEquals(0.5f, pref.fraction, 0f)
    }

    @Test fun `nilai rusak dianggap tidak ada`() {
        assertEquals(SPLIT_DEFAULT, SplitPreference({ Float.NaN }, {}).fraction, 0f)
        assertEquals(SPLIT_DEFAULT, SplitPreference({ 1.5f }, {}).fraction, 0f)
        assertEquals(SPLIT_DEFAULT, SplitPreference({ 0f }, {}).fraction, 0f)
    }

    @Test fun `nilai dalam batas tidak diubah pada layar lebar`() {
        assertEquals(0.5f, clampSplit(0.5f, 1280f), 0.0001f)
    }

    @Test fun `batas persentase berlaku pada layar lebar`() {
        assertEquals(0.35f, clampSplit(0.1f, 1600f), 0.0001f)
        assertEquals(0.75f, clampSplit(0.95f, 1600f), 0.0001f)
    }

    @Test fun `lebar minimum 320 dp per sisi menang atas persentase pada layar yang lebih sempit`() {
        // 900 dp: 320/900 = 0,356 → sisi produk tak boleh di bawah itu; sisi order tak boleh di bawah 1 - 0,356.
        assertEquals(320f / 900f, clampSplit(0.35f, 900f), 0.0001f)
        assertEquals(1f - 320f / 900f, clampSplit(0.75f, 900f), 0.0001f)
    }

    @Test fun `nilai tersimpan dari layar lebar dipotong lagi pada layar sempit`() {
        val onWide = clampSplit(0.75f, 1600f)
        val onNarrow = clampSplit(onWide, 900f)
        assertEquals(1f - 320f / 900f, onNarrow, 0.0001f)
    }

    @Test fun `layar terlalu sempit untuk dua sisi kembali ke bagi dua`() {
        assertEquals(0.5f, clampSplit(0.7f, 600f), 0f)
    }
}
