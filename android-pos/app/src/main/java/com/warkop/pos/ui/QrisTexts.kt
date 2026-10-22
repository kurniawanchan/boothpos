package com.warkop.pos.ui

/**
 * 065 / US5 — teks dan keadaan lembar QRIS di SATU tempat, supaya bisa diuji tanpa perangkat.
 * Lembar tidak lagi memuat peringatan "Jangan terima bukti dari layar ponsel pelanggan" (dihapus atas permintaan
 * pemilik), dan instruksinya mengarahkan ke menu Order tempat kode verifikasi dicatat.
 */
object QrisTexts {
    /** Instruksi di lembar QRIS setelah "Bayar" (spec FR-018, persis). */
    const val INSTRUCTION = "Cek bukti transaksi di aplikasi QRIS, lalu catat kode verifikasi di menu Order"

    const val QR_NOT_SET = "QR belum diatur"
    const val QR_LOAD_FAILED = "QR tidak dapat dimuat"
    const val RETRY = "Muat ulang"
}

/**
 * Keadaan kotak QR. Dulu "tidak ada URL" dan "unduhan gagal" sama-sama tampil sebagai "QR belum diatur", sehingga QR
 * yang SUDAH diunggah di web tetapi gagal dimuat (alamat tak terjangkau) terlihat seperti belum diatur.
 */
enum class QrBoxState { LOADING, NOT_SET, LOAD_FAILED, SHOWN }

object QrBox {
    /** [loaded]: null = unduhan berjalan, true = berhasil, false = gagal. Tanpa [url] → belum diatur (tak ada yang diunduh). */
    fun stateFor(url: String?, loaded: Boolean?): QrBoxState = when {
        url.isNullOrBlank() -> QrBoxState.NOT_SET
        loaded == null -> QrBoxState.LOADING
        loaded -> QrBoxState.SHOWN
        else -> QrBoxState.LOAD_FAILED
    }
}
