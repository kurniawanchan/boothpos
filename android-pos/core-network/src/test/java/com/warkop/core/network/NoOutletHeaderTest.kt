package com.warkop.core.network

import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import retrofit2.http.GET
import java.net.ServerSocket
import kotlin.concurrent.thread

/** 094 — `@NoOutletHeader` menghapus X-Outlet-Id hanya untuk endpoint bertanda; endpoint lain tetap membawanya. */
class NoOutletHeaderTest {
    interface Api {
        @GET("with") suspend fun with(): Map<String, String>
        @NoOutletHeader @GET("without") suspend fun without(): Map<String, String>
    }

    /** Server HTTP satu-koneksi-per-permintaan; mengembalikan baris header yang diterima. */
    private fun serve(requests: Int, captured: MutableList<List<String>>): Int {
        val socket = ServerSocket(0)
        thread(isDaemon = true) {
            repeat(requests) {
                socket.accept().use { c ->
                    val reader = c.getInputStream().bufferedReader()
                    val lines = generateSequence { reader.readLine() }.takeWhile { it.isNotEmpty() }.toList()
                    synchronized(captured) { captured += lines }
                    c.getOutputStream().write("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}".toByteArray())
                }
            }
            socket.close()
        }
        return socket.localPort
    }

    @Test fun `header outlet dilewati hanya pada endpoint bertanda`() = runBlocking {
        val captured = mutableListOf<List<String>>()
        val port = serve(2, captured)
        val api = ApiClient.create(Api::class.java, "http://127.0.0.1:$port", RequestContext(token = { "t" }, outletId = { 9 }, deviceId = { null }))
        api.with()
        api.without()
        val (first, second) = captured
        assertTrue(first.any { it.equals("X-Outlet-Id: 9", ignoreCase = true) })
        assertFalse(second.any { it.startsWith("X-Outlet-Id", ignoreCase = true) })
        // Token tetap terkirim di keduanya.
        assertTrue(second.any { it.startsWith("Authorization", ignoreCase = true) })
    }
}
