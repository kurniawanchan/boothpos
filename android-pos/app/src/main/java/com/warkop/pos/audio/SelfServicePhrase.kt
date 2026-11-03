package com.warkop.pos.audio

/**
 * 090 / US8 — kalimat yang diucapkan saat pesanan mandiri masuk: "Pesanan dari meja 5". Satu tempat untuk aturan ini.
 * HANYA nama meja (FR-039): tidak ada nama, telepon, atau surel pelanggan, dan tidak ada nomor pesanan yang dibaca keras.
 */
object SelfServicePhrase {
    fun spoken(tableName: String): String {
        val table = tableName.trim().ifEmpty { "tidak diketahui" }
        return "Pesanan dari meja $table"
    }
}
