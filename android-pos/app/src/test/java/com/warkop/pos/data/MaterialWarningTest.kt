package com.warkop.pos.data

import com.warkop.core.network.dto.MaterialWarningDto
import com.warkop.core.network.dto.TabDto
import com.warkop.pos.model.MaterialWarning
import org.junit.Assert.*
import org.junit.Test

/**
 * 087 (2026-11-01) — peringatan "bahan menjadi minus" dari pelunasan/split tampil di tablet. Penjualan tetap tercatat;
 * ini hanya pemberitahuan supaya pemilik stok opname atau membeli bahan. Nama outlet ikut karena saldo bahan per outlet.
 */
class MaterialWarningTest {
    private val dto = MaterialWarningDto(10, "Indomie Ayam Bawang", "pcs", 14, "Warkop WJM Demo", "0.000", "1.000", "-1.000")

    @Test fun `tab hasil pelunasan membawa peringatan bahan`() {
        val order = OrderMapper.tab(TabDto(id = 7, status = "completed", materialWarnings = listOf(dto)), null)
        assertEquals(listOf(MaterialWarning("Indomie Ayam Bawang", "pcs", "Warkop WJM Demo", 0.0, 1.0, -1.0)), order.materialWarnings)
        assertTrue(OrderMapper.tab(TabDto(id = 7), null).materialWarnings.isEmpty())
    }

    @Test fun `teks peringatan menyebut bahan, outlet, saldo, kebutuhan, dan saldo baru`() {
        val w = MaterialWarning("Indomie Ayam Bawang", "pcs", "Warkop WJM Demo", 0.0, 1.0, -1.0)
        assertEquals("Indomie Ayam Bawang di Warkop WJM Demo: stok 0 pcs, dibutuhkan 1, kini −1. Periksa stok bahan.", w.message())
        assertEquals("Telur: stok 0,5 butir, dibutuhkan 1,25, kini −0,75. Periksa stok bahan.", MaterialWarning("Telur", "butir", null, 0.5, 1.25, -0.75).message())
    }
}
