package com.warkop.pos.print

import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Test
import java.io.ByteArrayOutputStream

/** Potongan kirim job: isi utuh dan tidak ada potongan melebihi batas (tidak ada byte hilang di tengah logo). */
class WriteChunkedTest {
    @Test fun `isi job sama persis setelah dipotong`() = runBlocking {
        val bytes = ByteArray(7_681) { (it % 251).toByte() }
        val out = ByteArrayOutputStream()
        writeChunked(out, bytes, chunkBytes = 512, pauseMs = 0)
        assertArrayEquals(bytes, out.toByteArray())
    }

    @Test fun `tidak ada potongan yang melebihi batas`() = runBlocking {
        val sizes = mutableListOf<Int>()
        val out = object : java.io.OutputStream() {
            override fun write(b: Int) { sizes += 1 }
            override fun write(b: ByteArray, off: Int, len: Int) { sizes += len }
        }
        writeChunked(out, ByteArray(1_300), chunkBytes = 512, pauseMs = 0)
        assertEquals(listOf(512, 512, 276), sizes)
    }

    @Test fun `job kosong tidak menulis apa pun`() = runBlocking {
        val out = ByteArrayOutputStream()
        writeChunked(out, ByteArray(0), pauseMs = 0)
        assertEquals(0, out.size())
    }
}
