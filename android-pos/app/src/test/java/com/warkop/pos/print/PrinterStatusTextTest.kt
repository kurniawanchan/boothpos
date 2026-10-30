package com.warkop.pos.print

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 084 / US4 — status printer di layar Koneksi: empat keadaan bernama dan indikator progres hanya saat menyambung. */
class PrinterStatusTextTest {
    @Test fun `empat keadaan punya nama yang diminta`() {
        assertEquals("Menyambung", printerStatusLabel(PrinterStatus.Connecting))
        assertEquals("Tersambung", printerStatusLabel(PrinterStatus.Connected))
        assertEquals("Terputus", printerStatusLabel(PrinterStatus.Disconnected))
        assertEquals("Gagal tersambung", printerStatusLabel(PrinterStatus.Error(PrintError.OUT_OF_RANGE)))
    }

    @Test fun `kegagalan sambungan semuanya disebut gagal tersambung`() {
        listOf(PrintError.OUT_OF_RANGE, PrintError.BLUETOOTH_OFF, PrintError.PERMISSION_DENIED).forEach {
            assertEquals("Gagal tersambung", printerStatusLabel(PrinterStatus.Error(it)))
        }
    }

    @Test fun `kegagalan cetak memakai pesannya sendiri karena sambungan berhasil`() {
        assertEquals("Kertas habis", printerStatusLabel(PrinterStatus.Error(PrintError.PAPER_OUT)))
        assertEquals("Gagal mencetak, coba lagi", printerStatusLabel(PrinterStatus.Error(PrintError.WRITE_ERROR)))
    }

    @Test fun `belum dipasang tetap punya nama`() {
        assertEquals("Belum dipasang", printerStatusLabel(PrinterStatus.NotConfigured))
    }

    @Test fun `indikator progres hanya terlihat selagi menyambung`() {
        assertTrue(printerShowsProgress(PrinterStatus.Connecting))
        listOf(PrinterStatus.Connected, PrinterStatus.Disconnected, PrinterStatus.NotConfigured, PrinterStatus.Error(PrintError.OUT_OF_RANGE))
            .forEach { assertFalse(printerShowsProgress(it)) }
    }

    @Test fun `setiap keadaan punya catatan untuk kasir`() {
        listOf(
            PrinterStatus.NotConfigured, PrinterStatus.Connecting, PrinterStatus.Disconnected, PrinterStatus.Connected,
            PrinterStatus.Error(PrintError.OUT_OF_RANGE), PrinterStatus.Error(PrintError.BLUETOOTH_OFF),
            PrinterStatus.Error(PrintError.PERMISSION_DENIED), PrinterStatus.Error(PrintError.PAPER_OUT),
        ).forEach { assertTrue(printerStatusNote(it).isNotBlank()) }
    }
}
