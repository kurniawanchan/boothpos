package com.boothpos.customer.feature.orders.domain

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** Aturan "tautan pelacakan mana yang boleh dibuka": hanya https yang wajar. */
class TrackingLinkTest {

    @Test
    fun `tautan https yang wajar diloloskan apa adanya`() {
        listOf(
            "https://kurir.example.test/lacak?resi=UJI0000000001",
            "https://kurir.example.test/lacak/UJI%200001",
            "https://lacak.kurir.example.test:8443/r/UJI0000000001#status",
        ).forEach { url -> assertEquals(url, TrackingLink.safeOrNull(url)) }
    }

    @Test
    fun `null dan teks kosong tidak pernah menjadi tautan`() {
        assertNull(TrackingLink.safeOrNull(null))
        assertNull(TrackingLink.safeOrNull(""))
        assertNull(TrackingLink.safeOrNull("   "))
    }

    @Test
    fun `http dan skema lain ditolak`() {
        listOf(
            "http://kurir.example.test/lacak?resi=UJI0000000001",
            "HTTP://kurir.example.test/",
            // Hanya awalan "https://" huruf kecil persis yang diterima (bentuk yang dijanjikan kontrak).
            "HTTPS://kurir.example.test/",
            "Https://kurir.example.test/",
            "javascript:alert(1)",
            "intent://kurir.example.test/#Intent;scheme=https;end",
            "file:///data/data/com.boothpos.customer/files/sesi",
            "content://com.boothpos.customer/sesi",
            "market://details?id=contoh",
            "tel:0800000000",
            "ftp://kurir.example.test/",
            "kurir.example.test/lacak",
            "//kurir.example.test/lacak",
            "/lacak/UJI0000000001",
        ).forEach { url -> assertNull("harus ditolak: $url", TrackingLink.safeOrNull(url)) }
    }

    @Test
    fun `tautan https yang rusak atau dimanipulasi ditolak`() {
        listOf(
            "https://",
            "https:///lacak",
            "https://?resi=1",
            " https://kurir.example.test/",
            "https://kurir.example.test/ lacak",
            "https://kurir.example.test/lacak\n",
            "https://kurir.example.test/\tlacak",
            "https://kurir.example.test/lacak\u0000",
            // Menampilkan satu nama, membuka host lain.
            "https://kurir.example.test@lain.example.test/lacak",
            "https://pengguna:sandi@kurir.example.test/lacak",
            "https://kurir.example.test/{tracking_number}",
            "https://kurir .example.test/",
            "https://[::1",
            "https://kurir.example.test/lacak?resi=" + "9".repeat(2100),
        ).forEach { url -> assertNull("harus ditolak: $url", TrackingLink.safeOrNull(url)) }
    }

    @Test
    fun `pengiriman hanya menawarkan tautan yang lolos aturan`() {
        fun shipment(url: String?) = Shipment(
            courierName = "JNE",
            serviceName = null,
            trackingNumber = "UJI0000000001",
            trackingUrl = url,
            dispatchedAt = null,
            deliveredAt = null,
            shippingCost = null,
        )

        assertEquals("https://kurir.example.test/r/1", shipment("https://kurir.example.test/r/1").trackingLink)
        assertNull(shipment("http://kurir.example.test/r/1").trackingLink)
        assertNull(shipment("bukan tautan").trackingLink)
        assertNull(shipment(null).trackingLink)
    }
}
