package com.warkop.pos.audio

/**
 * 061 / T004 — teks yang diucapkan untuk nomor antrean. Huruf dibaca apa adanya, angka dibaca satu per satu dalam
 * bahasa Indonesia: `A-012` → "A, nol, satu, dua". Satu tempat untuk aturan ini; tidak ada di layar.
 */
object QueuePhrase {
    private val DIGITS = listOf("nol", "satu", "dua", "tiga", "empat", "lima", "enam", "tujuh", "delapan", "sembilan")

    fun spoken(queue: String): String {
        val parts = queue.filter { it.isLetterOrDigit() }.map { c ->
            if (c.isDigit()) DIGITS[c - '0'] else c.uppercaseChar().toString()
        }
        return parts.joinToString(", ")
    }
}
