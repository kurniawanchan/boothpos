package com.warkop.pos.ui

import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

/**
 * 084 / US5 — istilah baku di SEMUA teks yang tampil/tercetak: Counter→Kasir, Order→Pesanan, Perangkat→Koneksi,
 * Transfer→EDC, "Tunai bersih"→"Tunai". Pemindai sumber (pola uji pemindai repo): membaca string literal dari kode produksi
 * dan gagal bila istilah lama muncul sebagai TEKS. Yang tidak ikut: komentar, pengenal/kunci (huruf kecil dengan `_`/`:`
 * seperti "cancel_order", "open:counter", "bank_transfer"), dan "Transfer bank" (metode pembayaran lain, bukan EDC).
 */
class TerminologyScanTest {
    private val root = listOf("src/main/java/com/warkop/pos", "app/src/main/java/com/warkop/pos").map(::File).first { it.isDirectory }
    private val dirs = listOf("ui", "print", "model", "state", "data", "setup")
    private val literal = Regex("\"((?:[^\"\\\\\\n]|\\\\.)*)\"")
    private val identifier = Regex("[a-z_:.\\-/]+")

    private val forbidden = listOf(
        "Counter" to Regex("\\b[Cc]ounter\\b"),
        "Order" to Regex("\\b[Oo][Rr][Dd][Ee][Rr]\\b"),
        "Perangkat" to Regex("\\b[Pp]erangkat\\b"),
        "Transfer" to Regex("\\bTransfer\\b(?! bank)"),
        "Tunai bersih" to Regex("Tunai bersih", RegexOption.IGNORE_CASE),
    )

    private fun productionSources(): List<File> =
        dirs.flatMap { d -> File(root, d).walkTopDown().filter { it.isFile && it.extension == "kt" }.toList() }

    private fun userVisibleLiterals(f: File): List<Pair<Int, String>> = f.readLines().withIndex().flatMap { (i, line) ->
        val t = line.trimStart()
        if (t.startsWith("//") || t.startsWith("*") || t.startsWith("/*")) emptyList()
        else literal.findAll(line)
            // Ekspresi templat ${...} berisi nama variabel (mis. order.live), bukan teks yang tampil.
            .map { i + 1 to it.groupValues[1].replace(Regex("\\$\\{[^}]*\\}"), "") }
            .filterNot { identifier.matches(it.second) }.toList()
    }

    @Test fun `sumber produksi ditemukan`() {
        assertTrue("direktori sumber tidak ditemukan dari ${File(".").absolutePath}", productionSources().size > 20)
    }

    @Test fun `tidak ada istilah lama di teks yang tampil atau tercetak`() {
        val hits = productionSources().flatMap { f ->
            userVisibleLiterals(f).flatMap { (line, text) ->
                forbidden.filter { (_, re) -> re.containsMatchIn(text) }.map { (name, _) -> "${f.name}:$line [$name] \"$text\"" }
            }
        }
        assertTrue("Istilah lama masih ada:\n" + hits.joinToString("\n"), hits.isEmpty())
    }

    @Test fun `istilah baru dipakai di tempat yang diminta`() {
        val all = productionSources().joinToString("\n") { it.readText() }
        assertTrue(all.contains("\"Kasir\""))
        assertTrue(all.contains("\"Pesanan\""))
        assertTrue(all.contains("\"Koneksi\""))
        assertTrue(all.contains("\"EDC\""))
    }
}
