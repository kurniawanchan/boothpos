package com.boothpos.customer.core.data.testing

/**
 * Pemuat berkas jawaban backend yang DIREKAM dari server sungguhan (tes kontrak). Hanya JDK:
 * pembuatan `MockResponse` ada di tes masing-masing, supaya modul ini tidak menarik MockWebServer.
 *
 * Berkas ada di `src/test/resources` modul pemanggil (classpath tes modul itu):
 * - `real/<nama>.json` + `real/<nama>.meta`: badan dan status/header yang direkam apa adanya
 *   (data pribadi sudah disamarkan; lihat `real/README.md` di modul tersebut);
 * - `derived/<nama>.json` + `.meta`: SATU-SATUNYA tempat bentuk yang tidak pernah terekam, disusun
 *   dari kode PHP backend dan ditandai begitu (`derived/README.md`).
 *
 * Format `.meta`: baris pertama kode status HTTP, baris berikutnya `nama-header: nilai`.
 */
object RealPayloads {

    /** Jawaban HTTP yang direkam: [body] kosong untuk 204. */
    class Recorded(val status: Int, val headers: List<Pair<String, String>>, val body: String) {
        fun header(name: String): String? = headers.firstOrNull { it.first.equals(name, ignoreCase = true) }?.second
    }

    /** Jawaban dari `real/` (rekaman server sungguhan). */
    fun recorded(name: String): Recorded = load("real", name)

    /** Jawaban dari `derived/` (disusun dari kode PHP; BUKAN rekaman). */
    fun derived(name: String): Recorded = load("derived", name)

    /** Badan permintaan yang direkam (`real/<nama>.json`), mis. untuk membandingkan dengan yang dikirim aplikasi. */
    fun recordedRequest(name: String): String = text("real/$name.json")

    private fun load(dir: String, name: String): Recorded {
        val meta = text("$dir/$name.meta").lineSequence().filter { it.isNotBlank() }.toList()
        val headers = meta.drop(1).map { line ->
            val separator = line.indexOf(':')
            require(separator > 0) { "Baris header tidak sah di $dir/$name.meta" }
            line.substring(0, separator).trim() to line.substring(separator + 1).trim()
        }
        return Recorded(
            status = meta.first().trim().toInt(),
            headers = headers,
            body = if (exists("$dir/$name.json")) text("$dir/$name.json") else "",
        )
    }

    private fun exists(path: String): Boolean = RealPayloads::class.java.classLoader!!.getResource(path) != null

    private fun text(path: String): String {
        val stream = RealPayloads::class.java.classLoader!!.getResourceAsStream(path)
            ?: error("Berkas uji tidak ditemukan di classpath: $path")
        return stream.use { it.readBytes().toString(Charsets.UTF_8) }
    }
}
