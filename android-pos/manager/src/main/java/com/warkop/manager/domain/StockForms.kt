package com.warkop.manager.domain

import com.warkop.manager.data.dto.AdjustBody
import com.warkop.manager.data.dto.AdjustItem
import com.warkop.manager.data.dto.NewTransferBody
import com.warkop.manager.data.dto.ReceiveBody
import com.warkop.manager.data.dto.ReceiveLineBody
import com.warkop.manager.data.dto.TransferLineBody
import com.warkop.manager.data.dto.TransferLineDto
import kotlinx.serialization.Serializable
import java.math.BigDecimal
import java.util.UUID

/**
 * 094 / T060 — koreksi stok, cermin dialog koreksi `StockView.vue` + `StockAdjustmentRequest`: alasan wajib (≤ 255,
 * dipangkas), baris varian dengan selisih BULAT; baris nol disaring sebelum kirim, minimal satu baris tidak nol;
 * outlet = outlet terpilih (bukan dari isian).
 */
@Serializable data class AdjustRow(val variantId: Long, val sku: String, val label: String, val onHand: Int?, val delta: String = "")

@Serializable data class AdjustForm(val reason: String = "", val rows: List<AdjustRow> = emptyList()) {
    fun deltas(): List<Pair<AdjustRow, Int>> = rows.mapNotNull { r -> r.delta.toIntOrNull()?.takeIf { it != 0 }?.let { r to it } }

    val canSubmit: Boolean get() = reason.isNotBlank() && deltas().isNotEmpty()

    fun add(row: AdjustRow): AdjustForm = if (rows.any { it.variantId == row.variantId }) this else copy(rows = rows + row)

    fun toBody(outletId: Long) = AdjustBody(reason.trim(), outletId, deltas().map { (r, d) -> AdjustItem(r.variantId, d) })

    companion object { const val REASON_MAX = 255 }
}

/**
 * 094 / T061 — transfer baru, cermin `NewTransferDrawer.vue`: tujuan ≠ asal, ≥ 1 baris, qty > 0 dan ≤ tersedia di
 * asal, BULAT untuk item (desimal ≤ 3 angka untuk bahan), catatan ≤ 255 (kosong → null). `clientRef` dibuat sekali per
 * formulir dan ikut tersimpan, jadi kirim ulang setelah jaringan putus tidak menggandakan transfer (server idempoten).
 */
@Serializable data class TransferRow(val kind: String, val itemId: Long, val label: String, val sku: String, val available: String, val qty: String = "") {
    fun valid(): Boolean {
        val q = qty.toBigDecimalOrNull() ?: return false
        val avail = available.toBigDecimalOrNull() ?: BigDecimal.ZERO
        if (q.signum() <= 0 || q > avail) return false
        return kind == "material" || q.stripTrailingZeros().scale() <= 0
    }
}

@Serializable data class TransferForm(
    val toOutletId: Long? = null,
    val note: String = "",
    val rows: List<TransferRow> = emptyList(),
    val clientRef: String = UUID.randomUUID().toString(),
) {
    fun canSubmit(fromOutletId: Long): Boolean = toOutletId != null && toOutletId != fromOutletId && rows.isNotEmpty() && rows.all { it.valid() }

    fun add(row: TransferRow): TransferForm = if (rows.any { it.kind == row.kind && it.itemId == row.itemId }) this else copy(rows = rows + row)

    fun toBody(fromOutletId: Long) = NewTransferBody(
        fromOutletId = fromOutletId,
        toOutletId = toOutletId ?: error("tujuan belum dipilih"),
        note = note.trim().ifEmpty { null },
        clientRef = clientRef,
        lines = rows.map { TransferLineBody(it.kind, it.itemId, it.qty.toDouble()) },
    )

    companion object { const val NOTE_MAX = 255 }
}

/**
 * 094 / T061 — terima transfer, cermin `ReceiveTransferDialog.vue`: bawaan jumlah diterima = dikirim; tidak boleh lebih
 * dari dikirim; KURANG dari dikirim → alasan wajib (dikirim hanya untuk baris yang kurang).
 */
@Serializable data class ReceiveRow(val lineId: Long, val label: String, val kind: String, val qtySent: String, val qty: String, val reason: String = "") {
    val short: Boolean get() = (qty.toBigDecimalOrNull() ?: BigDecimal.ZERO) < (qtySent.toBigDecimalOrNull() ?: BigDecimal.ZERO)

    fun valid(): Boolean {
        val q = qty.toBigDecimalOrNull() ?: return false
        val sent = qtySent.toBigDecimalOrNull() ?: return false
        return q.signum() >= 0 && q <= sent && (!short || reason.isNotBlank())
    }

    companion object {
        fun of(l: TransferLineDto) = ReceiveRow(l.id, l.item?.name ?: "#${l.id}", l.kind, l.qtySent ?: "0", l.qtySent ?: "0")
    }
}

@Serializable data class ReceiveForm(val rows: List<ReceiveRow> = emptyList()) {
    val canSubmit: Boolean get() = rows.isNotEmpty() && rows.all { it.valid() }

    fun toBody() = ReceiveBody(rows.map { r -> ReceiveLineBody(r.lineId, r.qty.toDouble(), if (r.short) r.reason.trim() else null) })
}

/** Label tipe pergerakan, cermin `utils/stockMovements.js` + kunci `master_data.type_*`. Tipe tak dikenal → nama mentah. */
object MovementTypes {
    val LABELS = linkedMapOf(
        "purchase" to "Pembelian",
        "sale" to "Penjualan",
        "adjustment" to "Penyesuaian",
        "return" to "Retur",
        "initial" to "Stok awal",
        "recipe_sale" to "Dipakai sebagai bahan",
        "recipe_return" to "Bahan kembali",
        "recipe_waste" to "Bahan terbuang",
    )

    fun label(type: String): String = when (type) {
        "preorder_handover" -> "Stok keluar (lama)"
        else -> LABELS[type] ?: type
    }
}
