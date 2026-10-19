package com.warkop.pos.print

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import java.io.File

/**
 * 050 / T018, T048 — antrean cetak: "cetak sekali" (FR-007, FR-019), bertahan restart (FR-021), dan file rusak tidak
 * menghentikan aplikasi. Dua salinan berbeda dari dokumen yang sama adalah dua kunci berbeda (cetak ulang disengaja).
 */
class PrintQueueTest {
    @get:Rule val tmp = TemporaryFolder()

    private fun queueAt(file: File, clock: () -> Long = { 1_000L }) = PrintQueue(file, clock)

    @Test fun `kunci yang sama tidak masuk dua kali`() {
        val q = queueAt(File(tmp.root, "q.json"))
        assertTrue(q.enqueue(PrintKind.KOT, "2201", 1))
        assertFalse(q.enqueue(PrintKind.KOT, "2201", 1))
        assertEquals(1, q.all().size)
    }

    @Test fun `job yang sudah tercetak tidak diterima ulang`() {
        val q = queueAt(File(tmp.root, "q.json"))
        q.enqueue(PrintKind.KOT, "2201", 1)
        q.markPrinted(jobKey(PrintKind.KOT, "2201", 1))
        assertFalse("KOT yang sudah tercetak tidak boleh tercetak lagi", q.enqueue(PrintKind.KOT, "2201", 1))
        assertTrue(q.open().isEmpty())
    }

    @Test fun `job yang ditutup tidak diterima ulang`() {
        val q = queueAt(File(tmp.root, "q.json"))
        q.enqueue(PrintKind.STRUK, "118", 2)
        q.dismiss(jobKey(PrintKind.STRUK, "118", 2), null)
        assertFalse(q.enqueue(PrintKind.STRUK, "118", 2))
    }

    @Test fun `salinan berikutnya adalah kunci baru`() {
        val q = queueAt(File(tmp.root, "q.json"))
        assertTrue(q.enqueue(PrintKind.STRUK, "118", 1))
        assertTrue("salinan 2 sengaja dicetak ulang", q.enqueue(PrintKind.STRUK, "118", 2))
    }

    @Test fun `job gagal tetap ada dengan alasannya`() {
        val q = queueAt(File(tmp.root, "q.json"))
        q.enqueue(PrintKind.STRUK, "118", 1)
        q.markFailed(jobKey(PrintKind.STRUK, "118", 1), PrintError.OUT_OF_RANGE)
        val job = q.get(jobKey(PrintKind.STRUK, "118", 1))!!
        assertEquals(PrintStatus.FAILED, job.status)
        assertEquals(PrintError.OUT_OF_RANGE, job.lastError)
        assertEquals(1, q.open().size)
    }

    @Test fun `job belum tercetak bertahan setelah aplikasi dibuka ulang`() {
        val file = File(tmp.root, "q.json")
        queueAt(file).apply {
            enqueue(PrintKind.KOT, "2201", 1, listOf(PrintLine("KOT")))
        }
        // Simulasi restart: instance baru membaca file yang sama.
        val reopened = queueAt(file)
        val job = reopened.get(jobKey(PrintKind.KOT, "2201", 1))
        assertEquals(PrintStatus.PENDING, job?.status)
        assertEquals(listOf(PrintLine("KOT")), job?.lines)
    }

    @Test fun `job di tengah cetak saat mati tetap pending`() {
        val file = File(tmp.root, "q.json")
        queueAt(file).apply {
            enqueue(PrintKind.KOT, "2201", 1)
            markAttempt(jobKey(PrintKind.KOT, "2201", 1))
        }
        val reopened = queueAt(file)
        assertEquals(PrintStatus.PENDING, reopened.get(jobKey(PrintKind.KOT, "2201", 1))?.status)
        assertEquals(1, reopened.get(jobKey(PrintKind.KOT, "2201", 1))?.attempts)
    }

    @Test fun `file rusak dipindahkan dan antrean mulai kosong`() {
        val file = File(tmp.root, "q.json")
        file.writeText("{ bukan json")
        val q = queueAt(file)
        assertTrue(q.all().isEmpty())
        assertFalse("file rusak tidak dihapus diam-diam", file.exists())
        assertTrue(tmp.root.listFiles()!!.any { it.name.startsWith("print-queue.corrupt-") })
    }

    @Test fun `open hanya mengembalikan pending dan gagal, terurut dari yang lama`() {
        var t = 0L
        val q = PrintQueue(File(tmp.root, "q.json")) { t }
        t = 1; q.enqueue(PrintKind.KOT, "1", 1)
        t = 2; q.enqueue(PrintKind.KOT, "2", 1)
        t = 3; q.enqueue(PrintKind.KOT, "3", 1)
        q.markPrinted(jobKey(PrintKind.KOT, "2", 1))
        q.markFailed(jobKey(PrintKind.KOT, "3", 1), PrintError.WRITE_ERROR)
        assertEquals(listOf("1", "3"), q.open().map { it.refId })
    }

    @Test fun `alasan tutup tersimpan`() {
        val q = queueAt(File(tmp.root, "q.json"))
        q.enqueue(PrintKind.KOT, "2201", 1)
        q.dismiss(jobKey(PrintKind.KOT, "2201", 1), "sudah disampaikan ke dapur")
        assertEquals("sudah disampaikan ke dapur", q.get(jobKey(PrintKind.KOT, "2201", 1))?.dismissReason)
        assertNull(q.get("tidak-ada"))
    }

    /** 059 / T023 — baris raster (logo, atau baris lain yang memakainya) tetap utuh setelah antrean dibaca ulang. */
    @Test fun `baris raster bertahan lewat simpan dan baca ulang`() {
        val file = File(tmp.root, "q.json")
        val logo = PrintLine(align = PrintLine.Align.CENTER, raster = "ff00", rasterWidthDots = 8, rasterHeightDots = 2)
        queueAt(file).enqueue(PrintKind.KOT, "2201", 1, lines = listOf(logo, PrintLine(text = "x")))
        val saved = queueAt(file).get(jobKey(PrintKind.KOT, "2201", 1))?.lines
        assertEquals(logo, saved?.first())
    }
}
