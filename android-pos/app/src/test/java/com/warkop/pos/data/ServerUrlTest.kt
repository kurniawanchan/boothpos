package com.warkop.pos.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 059 / T005 — aturan URL server dipakai bersama oleh QR kanal dan logo struk. */
class ServerUrlTest {
    @Test fun `localhost dipasang ulang ke server LAN dengan jalur dan query tetap`() {
        assertEquals(
            "http://192.168.1.219:8010/storage/qr.png?v=2",
            ServerUrl.resolve("http://192.168.1.219:8010", "http://localhost/storage/qr.png?v=2"),
        )
    }

    @Test fun `basis online menggantikan host dan mempertahankan query`() {
        assertEquals(
            "https://pos.example.com/storage/logo.png?v=7",
            ServerUrl.resolve("https://pos.example.com/", "http://localhost:8000/storage/logo.png?v=7"),
        )
    }

    @Test fun `tanpa basis URL dikembalikan apa adanya`() {
        assertEquals("http://localhost/storage/qr.png", ServerUrl.resolve(null, "http://localhost/storage/qr.png"))
        assertEquals("http://localhost/storage/qr.png", ServerUrl.resolve("  ", "http://localhost/storage/qr.png"))
    }

    @Test fun `URL kosong menghasilkan null`() {
        assertNull(ServerUrl.resolve("http://192.168.1.219:8010", null))
        assertNull(ServerUrl.resolve("http://192.168.1.219:8010", "   "))
    }
}
