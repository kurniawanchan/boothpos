package com.warkop.pos.print

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * 050 / T017 — encoder ESC/POS: lebar kolom, pembungkusan, karakter aman, dan urutan byte perintah.
 * [decode] membaca byte kembali menjadi baris teks seperti yang akan tampak di kertas (perintah dilewati).
 */
class EscPosTest {

    /** Membuang urutan perintah ESC/GS dan mengembalikan baris teks yang tercetak. */
    private fun decode(bytes: ByteArray): List<String> {
        val text = StringBuilder()
        var i = 0
        while (i < bytes.size) {
            val b = bytes[i].toInt() and 0xFF
            when {
                b == 0x1B -> { // ESC @ = 2 byte; ESC a/E/d n = 3 byte
                    val cmd = bytes.getOrNull(i + 1)?.toInt()?.toChar()
                    i += if (cmd == '@') 2 else 3
                }
                b == 0x1D -> { // GS V m n = 4 byte; GS ! n = 3 byte
                    val cmd = bytes.getOrNull(i + 1)?.toInt()?.toChar()
                    i += if (cmd == 'V') 4 else 3
                }
                b == 0x0A -> { text.append('\n'); i++ }
                else -> { text.append(b.toChar()); i++ }
            }
        }
        return text.toString().split('\n').dropLast(1)
    }

    private fun render(vararg lines: PrintLine): ByteArray = EscPos.render(lines.toList(), cut = false)

    @Test fun `dokumen dimulai dengan inisialisasi printer`() {
        val bytes = render(PrintLine("A"))
        assertEquals(0x1B, bytes[0].toInt() and 0xFF)
        assertEquals('@'.code, bytes[1].toInt())
    }

    @Test fun `dokumen diakhiri umpan 4 baris sebelum potong`() {
        val bytes = EscPos.render(listOf(PrintLine("A")), cut = true)
        val tail = bytes.takeLast(7).map { it.toInt() and 0xFF }
        // ESC d 4 (umpan) lalu GS V 66 0 (potong sebagian)
        assertEquals(listOf(0x1B, 'd'.code, 4, 0x1D, 'V'.code, 66, 0), tail)
    }

    @Test fun `baris tepat 32 karakter tidak terpotong`() {
        val line = "ABCDEFGHIJKLMNOPQRSTUVWXYZ012345"
        assertEquals(32, line.length)
        assertEquals(listOf(line), decode(render(PrintLine(line))))
    }

    @Test fun `baris 40 karakter dibungkus pada batas kata`() {
        val wrapped = EscPos.wrap("satu dua tiga empat lima enam tujuh delapan sembilan", EscPos.COLUMNS)
        assertTrue(wrapped.all { it.length <= EscPos.COLUMNS })
        assertEquals("satu dua tiga empat lima enam", wrapped.first())
    }

    @Test fun `kata lebih panjang dari satu baris dipecah keras`() {
        val wrapped = EscPos.wrap("A".repeat(40), EscPos.COLUMNS)
        assertEquals(listOf("A".repeat(32), "A".repeat(8)), wrapped)
    }

    @Test fun `huruf beraksen diganti huruf dasar bukan tanda tanya`() {
        assertEquals("Kopi e", EscPos.sanitize("Kopi é"))
    }

    @Test fun `baris kanan-kiri mengisi tepat satu baris kertas`() {
        val lines = decode(render(PrintLine(text = "Subtotal", right = "Rp 25.000")))
        assertEquals(1, lines.size)
        assertEquals(EscPos.COLUMNS, lines[0].length)
        assertTrue(lines[0].startsWith("Subtotal"))
        assertTrue(lines[0].endsWith("Rp 25.000"))
    }

    @Test fun `tebal menyala lalu dimatikan lagi`() {
        val bytes = render(PrintLine("TOTAL", bold = true)).map { it.toInt() and 0xFF }
        assertTrue(containsSeq(bytes, listOf(0x1B, 'E'.code, 1)))
        assertTrue(containsSeq(bytes, listOf(0x1B, 'E'.code, 0)))
    }

    @Test fun `ukuran ganda memakai judul besar lalu kembali normal`() {
        val bytes = render(PrintLine("KOT", big = true)).map { it.toInt() and 0xFF }
        assertTrue(containsSeq(bytes, listOf(0x1D, '!'.code, 0x11)))
        assertTrue(containsSeq(bytes, listOf(0x1D, '!'.code, 0)))
    }

    @Test fun `baris besar hanya setengah kolom`() {
        val lines = decode(render(PrintLine("A".repeat(20), big = true)))
        // 20 karakter tidak muat dalam 16 kolom besar: harus dibungkus
        assertEquals(listOf("A".repeat(16), "A".repeat(4)), lines)
    }

    private fun containsSeq(haystack: List<Int>, needle: List<Int>): Boolean =
        (0..haystack.size - needle.size).any { start -> needle.indices.all { haystack[start + it] == needle[it] } }
}
