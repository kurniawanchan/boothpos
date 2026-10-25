package com.warkop.pos.data

import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import java.io.IOException

/** 075 / T019 — aturan cache foto: sekali unduh per URL, gagal tidak disimpan, dibatasi byte, bisa dikosongkan. */
class MenuImageLoaderTest {
    private var fetches = 0
    private fun cache(max: Long = 100, fetch: suspend (String) -> ByteArray? = { ByteArray(40) }) =
        BoundedImageCache<ByteArray>(max, { it.size }, { fetches++; fetch(it) }, { it })

    @Test fun `URL yang sama hanya diunduh sekali`() = runTest {
        val c = cache()
        c.load("a"); c.load("a")
        assertEquals(1, fetches)
    }

    @Test fun `galat jaringan menghasilkan null dan tidak disimpan`() = runTest {
        var fail = true
        val c = cache(fetch = { if (fail) throw IOException("mati") else ByteArray(10) })
        assertNull(c.load("a"))
        assertEquals(0, c.size())
        fail = false
        assertEquals(10, c.load("a")?.size)
        assertEquals(2, fetches)
    }

    @Test fun `bukan gambar (decode null) menghasilkan null`() = runTest {
        val c = BoundedImageCache<ByteArray>(100, { it.size }, { ByteArray(5) }, { null })
        assertNull(c.load("a"))
        assertEquals(0, c.size())
    }

    @Test fun `melewati batas byte membuang yang paling lama dipakai`() = runTest {
        val c = cache(max = 100)
        c.load("a"); c.load("b")      // 80 byte
        c.peek("a")                    // a jadi yang terbaru, b yang terlama
        c.load("c")                    // 120 > 100 → b dibuang
        assertEquals(null, c.peek("b"))
        assertEquals(40, c.peek("a")?.size)
        assertEquals(40, c.peek("c")?.size)
    }

    @Test fun `entri baru yang lebih besar dari batas tetap disimpan sendirian`() = runTest {
        val c = cache(max = 10)
        c.load("a")
        assertEquals(1, c.size())
    }

    @Test fun `clear mengosongkan cache sehingga unduh ulang`() = runTest {
        val c = cache()
        c.load("a"); c.clear(); c.load("a")
        assertEquals(2, fetches)
    }
}
