package com.warkop.pos.print

import kotlinx.serialization.Serializable
import java.io.ByteArrayOutputStream

/**
 * Satu baris dokumen cetak. Layout (Documents) hanya membuat daftar ini; encoder yang mengubahnya ke byte.
 * Serializable: job KOT/BATAL/pra-bill menyimpan barisnya di antrean agar tetap bisa dicetak setelah restart.
 */
@Serializable
data class PrintLine(
    val text: String = "",
    val align: Align = Align.LEFT,
    val bold: Boolean = false,
    /** Tinggi + lebar ganda (judul KOT/BATAL). Kolom per baris menjadi setengahnya. */
    val big: Boolean = false,
    /** Bila diisi, [text] di kiri dan [right] di kanan pada satu baris (mis. "Subtotal ... 25.000"). */
    val right: String? = null,
    /**
     * 059 — baris gambar (logo struk), berupa baris 1-bit dalam heksadesimal, MSB dulu. Null untuk baris teks.
     * Field baru punya default, sehingga job antrean lama (tanpa field ini) tetap terbaca.
     */
    val raster: String? = null,
    val rasterWidthDots: Int = 0,
    val rasterHeightDots: Int = 0,
) {
    @Serializable enum class Align { LEFT, CENTER }
}

/**
 * 050 / T009 — encoder ESC/POS murni untuk kertas 58 mm (research D5). Tanpa dependensi Android, sehingga
 * byte-nya diuji langsung di JVM. Perintah dipilih seminimal mungkin: init, tebal, ukuran ganda, rata kiri/tengah,
 * umpan, dan potong.
 *
 * UNVERIFIED: kolom 32 untuk font bawaan dan dukungan perintah potong (GS V) pada VSC TM-58V. Keduanya dikonfirmasi
 * oleh "Tes cetak" di perangkat (quickstart V1/V2). Bila berbeda, hanya konstanta di sini yang berubah.
 */
object EscPos {
    const val COLUMNS = 32

    private const val ESC = 0x1B
    private const val GS = 0x1D
    private const val LF = 0x0A

    /** Karakter di luar ASCII yang dapat dicetak diganti `?` (bukan byte UTF-8 yang bisa tercetak sebagai sampah). */
    fun sanitize(s: String): String =
        s.replace("\r\n", " ").replace('\n', ' ').map { c -> if (c.code in 0x20..0x7E) c else '?' }.joinToString("")

    /** Pemotongan kata sederhana; kata yang lebih panjang dari [width] dipecah keras. Selalu mengembalikan minimal satu baris. */
    fun wrap(text: String, width: Int): List<String> {
        val clean = sanitize(text).trim()
        if (clean.isEmpty()) return listOf("")
        val out = mutableListOf<String>()
        var current = ""
        for (word in clean.split(' ').filter { it.isNotEmpty() }) {
            var w = word
            // Kata lebih panjang dari satu baris dipecah keras, agar tidak pernah terpotong di tepi kanan.
            while (w.length > width) {
                if (current.isNotEmpty()) { out += current; current = "" }
                out += w.substring(0, width)
                w = w.substring(width)
            }
            current = when {
                current.isEmpty() -> w
                current.length + 1 + w.length <= width -> "$current $w"
                else -> { out += current; w }
            }
        }
        if (current.isNotEmpty()) out += current
        return out
    }

    /** Byte untuk satu dokumen; [cut] = potong kertas di akhir (setelah umpan agar sobekan tidak memotong teks). */
    fun render(lines: List<PrintLine>, cut: Boolean = true): ByteArray {
        val out = ByteArrayOutputStream()
        out.write(byteArrayOf(ESC.toByte(), '@'.code.toByte())) // init
        for (line in lines) renderLine(line, out)
        out.write(byteArrayOf(ESC.toByte(), 'd'.code.toByte(), 4)) // umpan 4 baris sebelum potong
        if (cut) out.write(byteArrayOf(GS.toByte(), 'V'.code.toByte(), 66, 0)) // potong sebagian
        return out.toByteArray()
    }

    private fun renderLine(line: PrintLine, out: ByteArrayOutputStream) {
        val width = if (line.big) COLUMNS / 2 else COLUMNS
        val justify: Byte = if (line.align == PrintLine.Align.CENTER) 1 else 0
        val boldFlag: Byte = if (line.bold) 1 else 0
        out.write(byteArrayOf(ESC.toByte(), 'a'.code.toByte(), justify))
        out.write(byteArrayOf(ESC.toByte(), 'E'.code.toByte(), boldFlag))
        if (line.raster != null) {
            writeRaster(line.raster, line.rasterWidthDots, line.rasterHeightDots, out)
            if (line.bold) out.write(byteArrayOf(ESC.toByte(), 'E'.code.toByte(), 0))
            return
        }
        if (line.big) out.write(byteArrayOf(GS.toByte(), '!'.code.toByte(), 0x11))

        val rows: List<String> = if (line.right != null) {
            val right = sanitize(line.right)
            val leftWidth = (width - right.length - 1).coerceAtLeast(1)
            val left = wrap(line.text, leftWidth)
            // Baris pertama memuat nilai kanan; sisa pembungkus kiri ditulis di bawahnya.
            listOf(left.first().padEnd(width - right.length) + right) + left.drop(1)
        } else {
            wrap(line.text, width).map { if (line.align == PrintLine.Align.CENTER) it.padStart((width + it.length) / 2).padEnd(width) else it }
        }
        for (row in rows) out.write((row + LF.toChar()).toByteArray(Charsets.US_ASCII))

        if (line.big) out.write(byteArrayOf(GS.toByte(), '!'.code.toByte(), 0))
        if (line.bold) out.write(byteArrayOf(ESC.toByte(), 'E'.code.toByte(), 0))
    }

    /**
     * GS v 0 (mode 0): lebar dalam byte (dibulatkan ke atas) dan tinggi dalam titik, little endian, lalu data.
     * Jika jumlah byte data tidak sesuai ukuran, baris dilewati (lebih baik tanpa logo daripada mencetak sampah).
     */
    fun writeRaster(hex: String, widthDots: Int, heightDots: Int, out: ByteArrayOutputStream) {
        if (widthDots <= 0 || heightDots <= 0) return
        val bytesPerRow = (widthDots + 7) / 8
        val data = hexToBytes(hex) ?: return
        if (data.size != bytesPerRow * heightDots) return
        out.write(byteArrayOf(GS.toByte(), 'v'.code.toByte(), '0'.code.toByte(), 0))
        out.write(byteArrayOf((bytesPerRow and 0xFF).toByte(), (bytesPerRow shr 8).toByte()))
        out.write(byteArrayOf((heightDots and 0xFF).toByte(), (heightDots shr 8).toByte()))
        out.write(data)
    }

    private fun hexToBytes(hex: String): ByteArray? {
        if (hex.length % 2 != 0) return null
        return runCatching { ByteArray(hex.length / 2) { i -> hex.substring(i * 2, i * 2 + 2).toInt(16).toByte() } }.getOrNull()
    }
}
