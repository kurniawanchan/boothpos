package com.warkop.pos.data

import com.warkop.core.network.dto.MaterialDto
import com.warkop.core.network.dto.MaterialMovementDto
import com.warkop.pos.model.MaterialMove
import com.warkop.pos.model.MaterialStock
import com.warkop.pos.model.ModifierPricing

/**
 * 087 lanjutan (2026-11-01) — DTO bahan → model layar "Stok Bahan" (hanya baca). Label jenis dan pergerakan sama
 * dengan web (`vendors_materials.kind_*` / `movement_*`).
 *
 * Saldo = saldo OUTLET TABLET saja; bahan tanpa baris di outlet itu = 0. JANGAN jatuh ke `current_stock` (total semua
 * outlet): di web itu pernah membuat "15 di WJ3" tampak tersedia di WDM, lalu penjualan di WDM menjadi −1.
 */
object MaterialMapper {
    private val DAY_TIME = java.time.format.DateTimeFormatter.ofPattern("dd/MM HH:mm")
    private val KINDS = mapOf("ingredient" to "Bahan baku", "semi_finished" to "Setengah jadi", "packaging" to "Kemasan")
    private val TYPES = mapOf(
        "sale" to "Penjualan", "return" to "Pengembalian", "waste" to "Terbuang", "purchase" to "Pembelian",
        // Istilah tablet (084, TerminologyScanTest): "Transfer" hanya untuk metode bayar, jadi pindah stok antar outlet = "Pindah".
        "adjustment" to "Koreksi", "transfer_out" to "Pindah keluar", "transfer_in" to "Pindah masuk", "transfer_cancel" to "Pindah batal",
    )

    fun stock(m: MaterialDto, outletId: Long): MaterialStock = MaterialStock(
        id = m.id, code = m.code, name = m.name, unit = m.unit, kindLabel = KINDS[m.kind] ?: m.kind,
        onHand = m.outletStock.firstOrNull { it.outletId == outletId }?.onHand?.toDoubleOrNull() ?: 0.0,
    )

    /** Bahan aktif saja; yang minus di atas (perlu tindakan), lalu menurut nama. */
    fun stocks(list: List<MaterialDto>, outletId: Long): List<MaterialStock> =
        list.filter { it.isActive }.map { stock(it, outletId) }.sortedWith(compareBy<MaterialStock>({ !it.negative }, { it.name.lowercase() }))

    fun move(m: MaterialMovementDto): MaterialMove {
        val q = m.qtyChange.toDoubleOrNull() ?: 0.0
        val after = m.stockAfter.toDoubleOrNull() ?: 0.0
        return MaterialMove(
            typeLabel = TYPES[m.type] ?: m.type,
            qtyText = (if (q > 0) "+" else "") + ModifierPricing.qty(q),
            beforeText = ModifierPricing.qty(m.stockBefore.toDoubleOrNull() ?: 0.0),
            afterText = ModifierPricing.qty(after),
            negativeAfter = after < 0,
            note = m.reference?.orderNumber ?: m.reason,
            user = m.user?.name,
            at = m.createdAt?.let { iso ->
                runCatching { java.time.OffsetDateTime.parse(iso).atZoneSameInstant(OrderMapper.STORE_ZONE).format(DAY_TIME) }.getOrNull()
            },
        )
    }
}
