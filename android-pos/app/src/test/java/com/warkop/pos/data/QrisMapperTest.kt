package com.warkop.pos.data

import com.warkop.core.network.dto.PaymentChannelDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 056 / T007 — judul lembar QRIS dan pemetaan kanal dari server (FR-001, FR-002, FR-007). */
class QrisMapperTest {
    @Test fun `judul memuat penyedia dan nama pemilik`() {
        assertEquals("QRIS Warkop WJM 3", QrisMapper.sheetTitle("QRIS", "Warkop WJM 3"))
    }

    @Test fun `tanpa nama pemilik judul hanya penyedia`() {
        assertEquals("QRIS", QrisMapper.sheetTitle("QRIS", "   "))
        assertEquals("QRIS", QrisMapper.sheetTitle("QRIS", null))
    }

    @Test fun `penyedia kosong menjadi QRIS`() {
        assertEquals("QRIS Warkop WJM 3", QrisMapper.sheetTitle("  ", "Warkop WJM 3"))
    }

    @Test fun `spasi di sekitar nama dibuang`() {
        assertEquals("QRIS Warkop WJM 3", QrisMapper.sheetTitle(" QRIS ", " Warkop WJM 3 "))
    }

    @Test fun `pemetaan membersihkan kosong dan mempertahankan URL QR`() {
        val ch = QrisMapper.toChannel(PaymentChannelDto(id = 3, type = "qr_ewallet", provider = "QRIS", accountName = "  ", qrImageUrl = "https://x/qr.png"))
        assertEquals(3L, ch.id)
        assertEquals("QRIS", ch.provider)
        assertNull(ch.accountName)
        assertEquals("https://x/qr.png", ch.qrImageUrl)
    }

    @Test fun `URL QR kosong menjadi null`() {
        val ch = QrisMapper.toChannel(PaymentChannelDto(id = 4, type = "qr_ewallet", provider = null, accountName = "Warkop WJM 3", qrImageUrl = " "))
        assertNull(ch.qrImageUrl)
        assertEquals("QRIS", ch.provider)
    }

    @Test fun `pemetaan membawa izin ganti QR dari server`() {
        val ch = QrisMapper.toChannel(PaymentChannelDto(id = 5, type = "qr_ewallet", provider = "QRIS", canManageQr = true))
        assertEquals(true, ch.canManageQr)
    }

    @Test fun `hanya JPEG dan PNG yang diterima untuk unggahan`() {
        assertEquals(true, QrisMapper.isAllowedQrMime("image/jpeg"))
        assertEquals(true, QrisMapper.isAllowedQrMime("image/png"))
        assertEquals(false, QrisMapper.isAllowedQrMime("image/webp"))
        assertEquals(false, QrisMapper.isAllowedQrMime(null))
    }

    @Test fun `URL gambar localhost diarahkan ke alamat server yang dipakai tablet`() {
        assertEquals(
            "http://192.168.1.10:8010/storage/payment-channels/qr.jpg",
            QrisMapper.resolveQrUrl("http://192.168.1.10:8010/", "http://localhost:8010/storage/payment-channels/qr.jpg"),
        )
    }

    @Test fun `alamat online mengganti host dan mempertahankan query`() {
        assertEquals(
            "https://pos.example.com/storage/a.png?v=2",
            QrisMapper.resolveQrUrl("https://pos.example.com", "http://localhost:8010/storage/a.png?v=2"),
        )
    }

    @Test fun `tanpa alamat server URL asli dipakai apa adanya, dan kosong tetap null`() {
        assertEquals("http://localhost:8010/storage/a.png", QrisMapper.resolveQrUrl(null, "http://localhost:8010/storage/a.png"))
        assertNull(QrisMapper.resolveQrUrl("http://192.168.1.10:8010", null))
    }
}
