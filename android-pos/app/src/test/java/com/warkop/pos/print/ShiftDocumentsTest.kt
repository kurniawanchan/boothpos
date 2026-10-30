package com.warkop.pos.print

import com.warkop.pos.model.CloseOutcome
import com.warkop.pos.model.MethodTotal
import com.warkop.pos.model.ShiftReport
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 084 / US3 — isi X-report dan Z-report: angka server, istilah baku (EDC, Tunai), dan Z menambah kas seharusnya/dihitung/selisih. */
class ShiftDocumentsTest {
    private val report = ShiftReport(
        openingCash = 200_000, orderCount = 12, totalSales = 540_000, voidCount = 1, voidTotal = 25_000,
        changeGiven = 10_000, cashReceived = 310_000, cashIn = 0, cashOut = 50_000,
        byMethod = listOf(MethodTotal("cash", 8, 300_000), MethodTotal("edc", 3, 160_000), MethodTotal("qr_ewallet", 1, 80_000)),
        hourly = emptyList(),
    )

    private fun x() = Documents.xReport(report, "Warkop WJM 3 · Tablet 1", "Budi", "08:00", "30/10/2026 17:05")
    private fun z(variance: Int = 0) = Documents.zReport(report, CloseOutcome(500_000, 500_000 + variance, variance, 5_000), "Warkop WJM 3 · Tablet 1", "Budi", "08:00", "30/10/2026 22:00")

    private fun plain(lines: List<PrintLine>) = lines.joinToString("\n") { l -> if (l.right != null) "${l.text} | ${l.right}" else l.text }

    @Test fun `X-report memuat judul identitas dan angka server`() {
        val t = plain(x())
        assertTrue(t.contains("X-REPORT"))
        assertTrue(t.contains("Shift masih terbuka"))
        assertTrue(t.contains("Kasir: Budi"))
        assertTrue(t.contains("Dibuka: 08:00"))
        assertTrue(t.contains("Modal awal | Rp 200.000"))
        assertTrue(t.contains("Penjualan | Rp 540.000"))
        assertTrue(t.contains("Jumlah struk | 12"))
        assertTrue(t.contains("Void / batal | 1 · Rp 25.000"))
    }

    @Test fun `istilah baku EDC dan Tunai dan tanpa istilah lama`() {
        val t = plain(x()) + plain(z())
        assertTrue(t.contains("EDC | Rp 160.000"))
        assertTrue(t.contains("QRIS | Rp 80.000"))
        assertFalse(t.contains("Transfer"))
        assertFalse(t.contains("Tunai bersih"))
        assertFalse(t.contains("Counter"))
    }

    @Test fun `Z-report menambah kas seharusnya dihitung dan selisih dari server`() {
        val t = plain(z())
        assertTrue(t.contains("Z-REPORT"))
        assertTrue(t.contains("Shift ditutup"))
        assertTrue(t.contains("Kas seharusnya | Rp 500.000"))
        assertTrue(t.contains("Kas dihitung | Rp 500.000"))
        assertTrue(t.contains("Selisih | Sesuai"))
        assertTrue(t.contains("Toleransi | Rp 5.000"))
    }

    @Test fun `Z-report menyebut kurang dan lebih dengan nominal`() {
        assertTrue(plain(z(-20_000)).contains("Selisih | Kurang Rp 20.000"))
        assertTrue(plain(z(15_000)).contains("Selisih | Lebih Rp 15.000"))
    }

    @Test fun `X-report tidak memuat bagian penutupan`() {
        assertFalse(plain(x()).contains("Kas seharusnya"))
    }

    @Test fun `kedua laporan sampai ke printer tanpa tanda tanya`() {
        for (doc in listOf(x(), z(-20_000))) {
            val bytes = EscPos.render(doc)
            val text = String(bytes, Charsets.ISO_8859_1)
            assertFalse("tidak boleh ada ? pengganti", text.contains('?'))
        }
    }

    @Test fun `baris tidak melebihi lebar kertas`() {
        for (doc in listOf(x(), z())) {
            val rendered = String(EscPos.render(doc), Charsets.ISO_8859_1).lines()
            assertTrue(rendered.all { it.length <= 80 }) // sisipan kontrol ESC/GS menambah beberapa byte, tetapi tidak menumpuk baris
        }
    }

    @Test fun `EDC dibaca dari metode edc bukan bank_transfer`() {
        val onlyBank = report.copy(byMethod = listOf(MethodTotal("bank_transfer", 2, 99_000)))
        assertEquals(0, onlyBank.edc)
        assertEquals(160_000, report.edc)
    }
}
