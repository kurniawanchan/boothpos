package com.warkop.pos.print

import android.graphics.Bitmap
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

/**
 * 059 / T008 — logo toko menjadi baris raster 1-bit untuk printer 58 mm (TM-58V, 384 titik per baris). Inti konversi
 * memakai piksel ARGB mentah agar bisa diuji di JVM tanpa Android; [toRaster] untuk Bitmap hanya pembungkus tipis.
 *
 * Aturan: skala turun bila lebih lebar dari 384 titik atau lebih tinggi dari 96 titik (aspek dijaga); piksel
 * transparan dihitung putih; luminansi di bawah [THRESHOLD] dicetak hitam. Ambang 128 adalah titik awal, dan dapat
 * disetel setelah uji cetak di perangkat (quickstart step 2).
 */
object Logo {
    const val MAX_WIDTH_DOTS = 384
    const val MAX_HEIGHT_DOTS = 96
    const val THRESHOLD = 128

    /** Gambar yang boleh dicetak: ukuran positif dan tidak melampaui batas kertas. */
    fun isRasterFit(width: Int, height: Int): Boolean = width in 1..MAX_WIDTH_DOTS && height in 1..MAX_HEIGHT_DOTS

    /**
     * Piksel ARGB baris demi baris. Mengembalikan null bila ukuran atau jumlah piksel tidak valid; pemanggil lalu
     * mencetak struk tanpa logo (FR-005).
     */
    fun toRaster(width: Int, height: Int, argb: IntArray, maxWidthDots: Int = MAX_WIDTH_DOTS): PrintLine? {
        if (width <= 0 || height <= 0 || argb.size < width * height) return null
        val scale = min(1.0, min(maxWidthDots.toDouble() / width, MAX_HEIGHT_DOTS.toDouble() / height))
        val w = max(1, (width * scale).roundToInt())
        val h = max(1, (height * scale).roundToInt())
        val bytesPerRow = (w + 7) / 8
        val bits = ByteArray(bytesPerRow * h)
        for (y in 0 until h) {
            val sy = y * height / h
            for (x in 0 until w) {
                val sx = x * width / w
                if (isDark(argb[sy * width + sx])) {
                    bits[y * bytesPerRow + x / 8] = (bits[y * bytesPerRow + x / 8].toInt() or (0x80 ushr (x % 8))).toByte()
                }
            }
        }
        return PrintLine(
            align = PrintLine.Align.CENTER,
            raster = toHex(bits),
            rasterWidthDots = w,
            rasterHeightDots = h,
        )
    }

    /** Pembungkus untuk Bitmap Android: diperkecil dulu ke lebar kertas agar memori tidak membengkak. */
    fun toRaster(bitmap: Bitmap): PrintLine? {
        val source = if (bitmap.width > MAX_WIDTH_DOTS) {
            val h = max(1, bitmap.height * MAX_WIDTH_DOTS / bitmap.width)
            Bitmap.createScaledBitmap(bitmap, MAX_WIDTH_DOTS, h, true)
        } else {
            bitmap
        }
        val pixels = IntArray(source.width * source.height)
        source.getPixels(pixels, 0, source.width, 0, 0, source.width, source.height)
        return toRaster(source.width, source.height, pixels)
    }

    /** Luminansi dengan transparansi dikomposisi di atas putih; gelap bila di bawah [THRESHOLD]. */
    private fun isDark(argb: Int): Boolean {
        val alpha = (argb ushr 24) and 0xFF
        val r = (argb shr 16) and 0xFF
        val g = (argb shr 8) and 0xFF
        val b = argb and 0xFF
        val lum = 0.299 * r + 0.587 * g + 0.114 * b
        val composed = lum * alpha / 255.0 + 255.0 * (1 - alpha / 255.0)
        return composed < THRESHOLD
    }

    private fun toHex(bytes: ByteArray): String = buildString(bytes.size * 2) {
        for (b in bytes) append("%02x".format(b.toInt() and 0xFF))
    }
}
