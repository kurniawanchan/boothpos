package com.warkop.pos.ui

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Test

/** 065 / US5 — teks lembar QRIS dan keadaan kotak QR. */
class QrisSheetTextTest {
    @Test fun `instruksi persis sesuai spesifikasi`() {
        assertEquals("Cek bukti transaksi di aplikasi QRIS, lalu catat kode verifikasi di menu Order", QrisTexts.INSTRUCTION)
    }

    @Test fun `tidak ada teks peringatan layar ponsel pelanggan di mana pun`() {
        val all = listOf(QrisTexts.INSTRUCTION, QrisTexts.QR_NOT_SET, QrisTexts.QR_LOAD_FAILED, QrisTexts.RETRY)
        assertFalse(all.any { it.contains("ponsel pelanggan", ignoreCase = true) || it.contains("Jangan terima", ignoreCase = true) })
        assertFalse(QrisTexts.INSTRUCTION.contains("menunggu verifikasi", ignoreCase = true))
    }

    @Test fun `tanpa url berarti belum diatur`() {
        assertEquals(QrBoxState.NOT_SET, QrBox.stateFor(null, null))
        assertEquals(QrBoxState.NOT_SET, QrBox.stateFor("  ", false))
    }

    @Test fun `url ada dan unduhan berjalan tampil memuat`() {
        assertEquals(QrBoxState.LOADING, QrBox.stateFor("http://s/storage/qr.jpg", null))
    }

    @Test fun `url ada dan unduhan gagal berbeda dari belum diatur`() {
        val failed = QrBox.stateFor("http://s/storage/qr.jpg", false)
        assertEquals(QrBoxState.LOAD_FAILED, failed)
        assertNotEquals(QrBoxState.NOT_SET, failed)
        assertNotEquals(QrisTexts.QR_NOT_SET, QrisTexts.QR_LOAD_FAILED)
    }

    @Test fun `url ada dan unduhan berhasil menampilkan gambar`() {
        assertEquals(QrBoxState.SHOWN, QrBox.stateFor("http://s/storage/qr.jpg", true))
    }
}
