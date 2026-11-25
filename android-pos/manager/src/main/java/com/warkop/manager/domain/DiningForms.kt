package com.warkop.manager.domain

import com.warkop.manager.data.dto.DiningTableDto
import com.warkop.manager.data.dto.ModifierGroupDto
import com.warkop.manager.data.dto.StationBody
import com.warkop.manager.data.dto.StationDto
import com.warkop.manager.data.dto.TableBody
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.addJsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonArray
import java.math.BigDecimal
import java.math.RoundingMode
import java.util.UUID

/** 094 / T090 — formulir Meja, cermin `TablesAdmin.vue` + `Store/UpdateDiningTableRequest`. */
@Serializable data class TableForm(
    val id: Long? = null,
    val name: String = "",
    val area: String = "",
    val seats: String = "4",
    val displayOrder: String = "0",
    val isActive: Boolean = true,
    val outletId: Long? = null,
    /** Outlet saat formulir dibuka; `outlet_id` dikirim saat ubah HANYA bila berbeda (pindah outlet). */
    val originalOutletId: Long? = null,
) {
    fun localErrors(): Map<String, String> = buildMap {
        if (name.trim().isEmpty()) put("name", "Wajib diisi.")
        val s = seats.toIntOrNull()
        if (s == null || s !in 1..99) put("seats", "Jumlah kursi 1–99.")
    }

    fun toBody() = TableBody(
        name = name.trim(), area = area.trim().ifEmpty { null }, seats = seats.toIntOrNull() ?: 1,
        displayOrder = displayOrder.toIntOrNull() ?: 0, isActive = isActive,
        outletId = if (id == null) outletId else outletId?.takeIf { it != originalOutletId },
    )

    val moving: Boolean get() = id != null && outletId != null && outletId != originalOutletId

    companion object {
        fun of(t: DiningTableDto) = TableForm(t.id, t.name, t.area, (t.seats ?: 4).toString(), t.displayOrder.toString(), t.isActive, t.outletId, t.outletId)
    }
}

/** 094 / T091 — formulir Stasiun, cermin `StationsAdmin.vue`. */
@Serializable data class StationForm(
    val id: Long? = null,
    val name: String = "",
    val displayOrder: String = "0",
    val isActive: Boolean = true,
    val outletId: Long? = null,
    val originalOutletId: Long? = null,
) {
    fun localErrors(): Map<String, String> = buildMap { if (name.trim().isEmpty()) put("name", "Wajib diisi.") }

    fun toBody() = StationBody(name.trim(), displayOrder.toIntOrNull() ?: 0, isActive, if (id == null) outletId else outletId?.takeIf { it != originalOutletId })

    val moving: Boolean get() = id != null && outletId != null && outletId != originalOutletId

    companion object {
        fun of(s: StationDto) = StationForm(s.id, s.name, s.displayOrder.toString(), s.isActive, s.outletId, s.outletId)
    }
}

@Serializable data class OptionMaterial(val materialId: Long, val name: String, val unit: String? = null, val quantity: String = "1")

@Serializable data class OptionRow(
    val key: String = UUID.randomUUID().toString(),
    val id: Long? = null,
    val name: String = "",
    val priceDelta: String = "0",
    val minQty: String = "1",
    val maxQty: String = "1",
    val materials: List<OptionMaterial> = emptyList(),
    /** Bahan saat dibuka (pembanding 4 desimal) — `materials` dikirim untuk opsi lama HANYA bila berubah. */
    val originalMaterials: List<OptionMaterial> = emptyList(),
)

/**
 * 094 / T092 — formulir grup modifier, cermin `ModifierGroupsAdmin.vue` + `StoreModifierGroupRequest` +
 * `ModifierGroupService::syncOptions`. Opsi yang tidak dikirim DINONAKTIFKAN server (tidak dihapus).
 */
@Serializable data class ModifierGroupForm(
    val id: Long? = null,
    val name: String = "",
    val selectionType: String = "single",
    val isRequired: Boolean = false,
    val minSelect: String = "0",
    val maxSelect: String = "",
    val portionPerOption: Boolean = false,
    val isActive: Boolean = true,
    val options: List<OptionRow> = listOf(OptionRow()),
) {
    val multiple: Boolean get() = selectionType == "multiple"

    private fun named() = options.filter { it.name.isNotBlank() }

    fun localErrors(): Map<String, String> = buildMap {
        if (name.isBlank()) put("name", "Wajib diisi.")
        if (named().isEmpty()) put("options", "Tambahkan minimal satu opsi.")
        if (multiple) {
            val mn = minSelect.toIntOrNull(); val mx = maxSelect.takeIf { it.isNotBlank() }?.toIntOrNull()
            if (mn == null || mn !in 0..50) put("min_select", "Minimum 0–50.")
            if (maxSelect.isNotBlank() && (mx == null || mx !in 1..50)) put("max_select", "Maksimum 1–50, atau kosongkan.")
            if (mn != null && mx != null && mn > mx) put("min_select", "Pilihan minimum tidak boleh lebih besar dari maksimum.")
        }
        named().forEachIndexed { i, o ->
            if (o.priceDelta.toBigDecimalOrNull()?.let { it.abs() <= BigDecimal("9999999.99") } != true) put("options.$i.price_delta", "Selisih harga tidak valid.")
            val mn = o.minQty.toIntOrNull(); val mx = o.maxQty.takeIf { it.isNotBlank() }?.toIntOrNull()
            if (mn == null || mn !in 1..50) put("options.$i.min_qty", "Jumlah minimum 1–50.")
            if (o.maxQty.isNotBlank() && (mx == null || mx !in 1..50)) put("options.$i.max_qty", "Jumlah maksimum 1–50, atau kosongkan.")
            if (mn != null && mx != null && mx < mn) put("options.$i.max_qty", "Jumlah minimum tidak boleh lebih besar dari maksimum.")
            val seen = mutableSetOf<Long>()
            o.materials.forEachIndexed { j, m ->
                if (!seen.add(m.materialId)) put("options.$i.materials.$j.material_id", "Bahan yang sama tidak boleh dipilih dua kali pada opsi \"${o.name}\".")
                if ((m.quantity.toBigDecimalOrNull()?.signum() ?: 0) <= 0) put("options.$i.materials.$j.quantity", "Jumlah bahan harus lebih dari 0.")
            }
        }
    }

    /** Badan JSON persis seperti web (FD ModifierGroupsAdmin:129-145): kunci yang ada/tidak ada bermakna. */
    fun toJson(): JsonObject = buildJsonObject {
        put("name", name.trim())
        put("selection_type", selectionType)
        put("is_required", isRequired)
        if (multiple) {
            put("min_select", minSelect.toIntOrNull() ?: 0)
            maxSelect.takeIf { it.isNotBlank() }?.toIntOrNull()?.let { put("max_select", it) } ?: put("max_select", JsonNull)
        }
        put("portion_per_option", multiple && portionPerOption)
        put("is_active", isActive)
        putJsonArray("options") {
            named().forEachIndexed { i, o ->
                addJsonObject {
                    o.id?.let { put("id", it) }
                    put("name", o.name.trim())
                    put("price_delta", o.priceDelta.toBigDecimalOrNull()?.toDouble() ?: 0.0)
                    put("display_order", i)
                    put("min_qty", maxOf(1, o.minQty.toIntOrNull() ?: 1))
                    o.maxQty.takeIf { it.isNotBlank() }?.toIntOrNull()?.let { put("max_qty", it) } ?: put("max_qty", JsonNull)
                    val clean = o.materials.filter { (it.quantity.toBigDecimalOrNull()?.signum() ?: 0) > 0 }
                    val changed = o.id == null && clean.isNotEmpty() || o.id != null && norm(clean) != norm(o.originalMaterials)
                    if (changed) putJsonArray("materials") { clean.forEach { m -> addJsonObject { put("material_id", m.materialId); put("quantity", m.quantity.toDouble()) } } }
                }
            }
        }
    }

    companion object {
        const val NAME_MAX = 100

        private fun norm(list: List<OptionMaterial>) = list.map { it.materialId to (it.quantity.toBigDecimalOrNull()?.setScale(4, RoundingMode.HALF_UP)?.toPlainString() ?: it.quantity) }

        /** Formulir ubah hanya memuat opsi AKTIF (web line 110); opsi nonaktif tetap nonaktif. */
        fun of(g: ModifierGroupDto) = ModifierGroupForm(
            id = g.id, name = g.name, selectionType = g.selectionType, isRequired = g.isRequired,
            minSelect = g.minSelect.toString(), maxSelect = g.maxSelect?.toString().orEmpty(), portionPerOption = g.portionPerOption, isActive = g.isActive,
            options = g.options.filter { it.isActive }.map { o ->
                val mats = o.materials.map { OptionMaterial(it.materialId, it.name ?: "#${it.materialId}", it.unit, it.quantity.toBigDecimalOrNull()?.stripTrailingZeros()?.toPlainString() ?: it.quantity) }
                OptionRow(id = o.id, name = o.name, priceDelta = o.priceDelta.toBigDecimalOrNull()?.stripTrailingZeros()?.toPlainString() ?: o.priceDelta,
                    minQty = o.minQty.toString(), maxQty = o.maxQty?.toString().orEmpty(), materials = mats, originalMaterials = mats)
            },
        )

        /** Aturan grup untuk daftar, cermin `describeRange`: "Pilih satu · Wajib · 1". */
        fun rule(g: ModifierGroupDto): String {
            val type = if (g.selectionType == "multiple") "Pilih beberapa" else "Pilih satu"
            val range = when { g.maxSelect == null -> "≥${g.minSelect}"; g.minSelect == g.maxSelect -> "${g.maxSelect}"; else -> "${g.minSelect}–${g.maxSelect}" }
            return "$type · ${if (g.isRequired) "Wajib" else "Opsional"} · $range"
        }
    }
}

/** Ringkasan nama terpilih di dialog Attach (pola 091): hanya bila ≥ 2 dipilih; urutan daftar sumber. */
fun attachSummary(selected: Collection<Long>, source: List<Pair<Long, String>>): String? =
    if (selected.size < 2) null else "Dipilih: " + source.filter { it.first in selected }.joinToString(", ") { it.second }
