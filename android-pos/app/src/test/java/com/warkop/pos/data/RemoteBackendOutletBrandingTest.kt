package com.warkop.pos.data

import java.net.ServerSocket
import kotlin.concurrent.thread
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * 079 / US4 — nama toko di layar PIN mengikuti outlet tablet: bila ada outlet tersimpan dipakai rute per outlet; 404 (outlet
 * tak ada di mode itu) dan tanpa outlet jatuh ke rute bersama. Server uji = soket JDK mentah (modul jdk.httpserver tidak ada di classpath modul Android), tanpa dependensi baru.
 */
class RemoteBackendOutletBrandingTest {
    private val paths = mutableListOf<String>()
    private var server: ServerSocket? = null

    @After fun stop() { server?.close() }

    /** HTTP/1.1 paling sederhana: satu respons per sambungan, jalur dicatat dari baris permintaan. */
    private fun serve(outletRouteStatus: Int): String {
        val socket = ServerSocket(0, 10, java.net.InetAddress.getByName("127.0.0.1"))
        server = socket
        thread(isDaemon = true) {
            while (!socket.isClosed) {
                val client = try { socket.accept() } catch (_: Exception) { return@thread }
                client.use { c ->
                    val reader = c.getInputStream().bufferedReader()
                    val path = reader.readLine().split(" ")[1]
                    while (reader.readLine().orEmpty().isNotEmpty()) { /* buang header */ }
                    synchronized(paths) { paths += path }
                    val keyed = path.contains("/outlets/") && path.endsWith("/branding")
                    val (status, body) = when {
                        keyed && outletRouteStatus != 200 -> outletRouteStatus to """{"message":"x"}"""
                        keyed -> 200 to """{"system_mode":"live","store_name":"Kedai Satu"}"""
                        else -> 200 to """{"system_mode":"live","store_name":"Toko Bersama"}"""
                    }
                    val bytes = body.toByteArray()
                    c.getOutputStream().apply {
                        write("HTTP/1.1 $status X\r\nContent-Type: application/json\r\nContent-Length: ${bytes.size}\r\nConnection: close\r\n\r\n".toByteArray())
                        write(bytes)
                        flush()
                    }
                }
            }
        }
        return "http://127.0.0.1:${socket.localPort}"
    }

    private fun backend(url: String, outlet: Long?): RemoteBackend {
        val vault = InMemoryStore().apply {
            put(TokenVault.SERVER_URL, url)
            if (outlet != null) {
                put(TokenVault.terminalKey(TokenVault.OUTLET_ID, false), outlet.toString())
                put(TokenVault.terminalKey(TokenVault.OUTLET_NAME, false), "Kedai Satu")
                put(TokenVault.terminalKey(TokenVault.DEVICE_ID, false), "4")
                put(TokenVault.terminalKey(TokenVault.DEVICE_NAME, false), "Kasir Depan")
            }
        }
        return RemoteBackend(vault)
    }

    @Test fun `outlet tersimpan memakai rute per outlet dan nama outlet`() = runBlocking {
        val b = backend(serve(200), outlet = 7)
        assertEquals("Kedai Satu", b.branding().storeName)
        assertEquals(listOf("/api/v1/pos-setup/outlets/7/branding"), paths)
    }

    @Test fun `outlet tidak ada di mode itu (404) jatuh ke rute bersama`() = runBlocking {
        val b = backend(serve(404), outlet = 7)
        assertEquals("Toko Bersama", b.branding().storeName)
        assertEquals(listOf("/api/v1/pos-setup/outlets/7/branding", "/api/v1/pos-setup/branding"), paths)
    }

    @Test fun `tanpa outlet tersimpan hanya rute bersama dipanggil`() = runBlocking {
        val b = backend(serve(200), outlet = null)
        assertEquals("Toko Bersama", b.branding().storeName)
        assertEquals(listOf("/api/v1/pos-setup/branding"), paths)
    }
}
