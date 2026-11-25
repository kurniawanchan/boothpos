package com.warkop.manager.domain

import com.warkop.manager.data.dto.PoBody
import com.warkop.manager.data.dto.PoItemBody
import com.warkop.manager.data.dto.PurchaseOrderDto
import kotlinx.serialization.Serializable
import java.math.BigDecimal
import java.util.UUID

/** 094 / T082–T083 — status PO dan graf transisinya (SAMA dengan `PurchaseOrder::TRANSITIONS`; server tidak mengirimnya). */
object PoStatus {
    val LABELS = linkedMapOf("draft" to "Draft", "ordered" to "Dipesan", "received" to "Diterima", "paid" to "Dibayar", "cancelled" to "Dibatalkan")
    val ACTIONS = mapOf("ordered" to "Tandai Dipesan", "received" to "Tandai Diterima", "paid" to "Tandai Dibayar", "cancelled" to "Batalkan")
    private val NEXT = mapOf("draft" to listOf("ordered", "cancelled"), "ordered" to listOf("received", "cancelled"), "received" to listOf("paid"))

    fun next(status: String): List<String> = NEXT[status].orEmpty()

    /** Pembayaran hanya setelah diterima (server 422 selain itu). */
    fun canPay(status: String) = status == "received" || status == "paid"
}

@Serializable data class PoLine(
    val key: String = UUID.randomUUID().toString(),
    /** "material" | "service" */
    val type: String = "material",
    val materialId: Long? = null,
    val materialName: String? = null,
    /** Dibawa apa adanya bila sudah ada (web meneruskan nilai lama); tidak diubah di aplikasi. */
    val productId: Long? = null,
    val description: String = "",
    val qty: String = "1",
    val unitPrice: String = "0",
)

/**
 * 094 / T082 — formulir PO, cermin `PurchaseOrdersView.vue` + `Store/UpdatePurchaseOrderRequest`. Outlet = outlet
 * terpilih (task T082); baris terkunci setelah keluar draft (`items` tidak dikirim).
 */
@Serializable data class PurchaseOrderForm(
    val id: Long? = null,
    val status: String = "draft",
    val vendorId: Long? = null,
    val notes: String = "",
    val lines: List<PoLine> = listOf(PoLine()),
) {
    val linesLocked: Boolean get() = id != null && status != "draft"

    fun localErrors(): Map<String, String> = buildMap {
        if (vendorId == null) put("vendor_id", "Pilih vendor.")
        if (linesLocked) return@buildMap
        if (lines.isEmpty()) put("items", "Tambahkan minimal satu baris.")
        lines.forEachIndexed { i, l ->
            if (l.type == "material" && l.materialId == null) put("items.$i.material_id", "Bahan baku wajib dipilih untuk baris bertipe bahan.")
            if (l.type == "service" && l.description.isBlank()) put("items.$i.description", "Deskripsi wajib diisi untuk baris bertipe jasa.")
            if (l.description.length > 255) put("items.$i.description", "Maksimal 255 karakter.")
            if ((l.qty.toBigDecimalOrNull() ?: BigDecimal.ZERO) < BigDecimal("0.001")) put("items.$i.qty", "Jumlah minimal 0,001.")
            if ((l.unitPrice.toBigDecimalOrNull()?.signum() ?: -1) < 0) put("items.$i.unit_price", "Harga satuan minimal 0.")
        }
    }

    fun toBody(outletId: Long) = PoBody(
        vendorId = vendorId ?: error("vendor kosong"),
        outletId = outletId,
        notes = notes.trim().ifEmpty { null },
        items = if (linesLocked) null else lines.map { l ->
            PoItemBody(
                lineType = l.type,
                materialId = if (l.type == "material") l.materialId else null,
                productId = l.productId,
                description = if (l.type == "service") l.description.trim() else null,
                qty = l.qty.toDouble(),
                unitPrice = l.unitPrice.toDouble(),
            )
        },
    )

    companion object {
        fun of(po: PurchaseOrderDto) = PurchaseOrderForm(
            id = po.id, status = po.status, vendorId = po.vendorId, notes = po.notes.orEmpty(),
            lines = po.items.map {
                PoLine(type = it.lineType, materialId = it.materialId, materialName = it.materialName, productId = it.productId, description = it.description.orEmpty(),
                    qty = it.qty.toBigDecimalOrNull()?.stripTrailingZeros()?.toPlainString() ?: it.qty, unitPrice = ProductForm.moneyInput(it.unitPrice))
            },
        )
    }
}
