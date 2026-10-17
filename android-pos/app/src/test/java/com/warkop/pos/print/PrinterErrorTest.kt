package com.warkop.pos.print

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

/** 050 / T049 — setiap kode galat punya pesan Indonesia yang tetap (contracts Part C) dan tidak pernah memuat teks eksepsi. */
class PrinterErrorTest {

    @Test fun `pesan tiap galat sesuai kontrak`() {
        assertEquals("Printer belum dipasang", printErrorMessage(PrintError.NOT_CONFIGURED))
        assertEquals("Izin Bluetooth diperlukan", printErrorMessage(PrintError.PERMISSION_DENIED))
        assertEquals("Bluetooth mati", printErrorMessage(PrintError.BLUETOOTH_OFF))
        assertEquals("Printer di luar jangkauan", printErrorMessage(PrintError.OUT_OF_RANGE))
        assertEquals("Kertas habis", printErrorMessage(PrintError.PAPER_OUT))
        assertEquals("Gagal mencetak, coba lagi", printErrorMessage(PrintError.WRITE_ERROR))
    }

    @Test fun `setiap kode galat punya pesan`() {
        PrintError.entries.forEach { error ->
            assertFalse("pesan kosong untuk $error", printErrorMessage(error).isBlank())
        }
    }

    @Test fun `pesan tidak memuat istilah teknis`() {
        PrintError.entries.map { printErrorMessage(it) }.forEach { msg ->
            listOf("Exception", "IOException", "socket", "null", "UUID", "RFCOMM").forEach { term ->
                assertFalse("pesan memuat istilah teknis '$term': $msg", msg.contains(term, ignoreCase = true))
            }
        }
    }
}
