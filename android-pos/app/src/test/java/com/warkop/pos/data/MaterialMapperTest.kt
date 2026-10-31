package com.warkop.pos.data

import com.warkop.core.network.dto.MaterialDto
import com.warkop.core.network.dto.MaterialMovementDto
import com.warkop.core.network.dto.MaterialMovementRefDto
import com.warkop.core.network.dto.MaterialMovementUserDto
import com.warkop.core.network.dto.MaterialOutletStockDto
import org.junit.Assert.*
import org.junit.Test

/**
 * 087 lanjutan (2026-11-01) — layar "Stok Bahan" tablet (hanya baca). Saldo = saldo OUTLET TABLET saja (0 bila
 * belum ada baris), TIDAK PERNAH total semua outlet: fallback ke total dulu membuat "15 di WJ3" tampak ada di WDM.
 */
class MaterialMapperTest {
    private val ab = MaterialDto(
        id = 10, code = "INDM-AB", name = "Indomie Ayam Bawang", unit = "pcs", kind = "packaging", currentStock = "14.000",
        outletStock = listOf(MaterialOutletStockDto(4, "WJ3", "Warkop WJM 3", "15.000"), MaterialOutletStockDto(14, "WDM", "Warkop WJM Demo", "-1.000")),
    )

    @Test fun `saldo diambil dari outlet tablet, nol bila belum ada baris, tidak pernah total`() {
        assertEquals(-1.0, MaterialMapper.stock(ab, outletId = 14).onHand, 0.0)
        assertEquals(15.0, MaterialMapper.stock(ab, outletId = 4).onHand, 0.0)
        assertEquals(0.0, MaterialMapper.stock(ab, outletId = 99).onHand, 0.0)
        assertTrue(MaterialMapper.stock(ab, outletId = 14).negative)
    }

    @Test fun `daftar diurutkan minus dulu lalu nama, bahan nonaktif dibuang`() {
        val telur = ab.copy(id = 11, code = "TLR", name = "Telur", outletStock = listOf(MaterialOutletStockDto(14, "WDM", null, "30.000")))
        val mati = ab.copy(id = 12, name = "Lama", isActive = false)
        val list = MaterialMapper.stocks(listOf(telur, mati, ab), outletId = 14)
        assertEquals(listOf("Indomie Ayam Bawang", "Telur"), list.map { it.name })
    }

    @Test fun `jenis bahan diberi label Indonesia`() {
        assertEquals("Kemasan", MaterialMapper.stock(ab, 14).kindLabel)
        assertEquals("Bahan baku", MaterialMapper.stock(ab.copy(kind = "ingredient"), 14).kindLabel)
        assertEquals("Setengah jadi", MaterialMapper.stock(ab.copy(kind = "semi_finished"), 14).kindLabel)
    }

    @Test fun `pergerakan membawa jenis, jumlah bertanda, nomor pesanan, alasan, dan pengguna`() {
        val m = MaterialMapper.move(MaterialMovementDto(
            id = 7, type = "sale", qtyChange = "-1.000", stockBefore = "0.000", stockAfter = "-1.000",
            reference = MaterialMovementRefDto("order_item", 6174, 3, "TRX-20261031-0003"), user = MaterialMovementUserDto(1, "Kasir A"),
            createdAt = "2026-10-31T13:58:33+00:00",
        ))
        assertEquals("Penjualan", m.typeLabel)
        assertEquals("−1", m.qtyText)
        assertEquals("TRX-20261031-0003", m.note)
        assertEquals("Kasir A", m.user)
        assertEquals("31/10 20:58", m.at) // zona toko (WIB), bukan UTC
        val adj = MaterialMapper.move(MaterialMovementDto(id = 8, type = "adjustment", qtyChange = "20.000", reason = "Opname pagi"))
        assertEquals("Koreksi", adj.typeLabel)
        assertEquals("+20", adj.qtyText)
        assertEquals("Opname pagi", adj.note)
    }
}
