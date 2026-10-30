package com.warkop.pos.ui

import com.warkop.pos.model.StaffUser
import org.junit.Assert.assertEquals
import java.io.File
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 078 / US2 — teks alasan dan aturan pilihan awal layar PIN, dipisah dari Compose supaya bisa diuji. */
class PinScreenCopyTest {
    private val sari = StaffUser("1", "Sari", "Kasir", "CSH", photoUrl = "http://x/lama.jpg")
    private val budi = StaffUser("2", "Budi", "Kasir", "CSH")

    @Test fun `alasan staf tanpa PIN`() {
        assertEquals("Belum ada PIN", NO_PIN_REASON)
    }

    @Test fun `pilihan tetap dan dimuat ulang dengan data terbaru saat staf berubah`() {
        val fresh = sari.copy(photoUrl = "http://x/baru.jpg")
        assertEquals(fresh, selectedAfterRosterChange(sari, listOf(fresh, budi)))
    }

    @Test fun `pilihan hilang dari daftar jatuh ke staf pertama yang bisa masuk`() {
        assertEquals(budi, selectedAfterRosterChange(sari, listOf(budi)))
    }

    @Test fun `tanpa pilihan awal memilih staf pertama`() {
        assertEquals(sari, selectedAfterRosterChange(null, listOf(sari, budi)))
    }

    @Test fun `tidak ada staf yang bisa masuk berarti tidak ada pilihan`() {
        assertNull(selectedAfterRosterChange(sari, emptyList()))
        assertNull(selectedAfterRosterChange(null, emptyList()))
    }

    /** Sumber layar PIN dibaca langsung (pola pemindai sumber 084): Gradle menjalankan tes dari folder modul `app`. */
    private fun pinScreenSource(): String {
        var dir: File? = File(System.getProperty("user.dir")).absoluteFile
        while (dir != null) {
            val f = File(dir, "src/main/java/com/warkop/pos/ui/screens/PinScreen.kt").takeIf { it.exists() }
                ?: File(dir, "app/src/main/java/com/warkop/pos/ui/screens/PinScreen.kt").takeIf { it.exists() }
            if (f != null) return f.readText()
            dir = dir.parentFile
        }
        error("PinScreen.kt tidak ditemukan dari ${System.getProperty("user.dir")}")
    }

    /** 085 / US3 (FR-011) — roda gigi dihapus dari beranda: hanya satu jalan ke penyiapan, yaitu tombol. */
    @Test fun `layar PIN tidak lagi memuat ikon roda gigi`() {
        val src = pinScreenSource()
        assertFalse(src.contains("Icons.Filled.Settings"))
        assertFalse(src.contains("filled.Settings"))
        assertFalse("tombol teks lama (TextButton) sudah diganti OutlinedButton", src.contains("TextButton"))
    }

    /** 085 / US3 (FR-009) — tombol jelas berteks tetap, memanggil penyiapan satu kali. */
    @Test fun `ganti outlet terminal berupa tombol berteks tetap dan satu pemicu`() {
        val src = pinScreenSource()
        assertTrue(src.contains("OutlinedButton("))
        assertTrue(src.contains("\"Ganti outlet / terminal\""))
        assertEquals("hanya satu pemicu goToSetup", 1, Regex("store\\.goToSetup\\(\\)").findAll(src).count())
    }
}
