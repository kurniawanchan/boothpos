package com.warkop.manager.domain

import com.warkop.manager.data.dto.MaterialBody
import com.warkop.manager.data.dto.MaterialDto
import com.warkop.manager.data.dto.VendorBody
import com.warkop.manager.data.dto.VendorDto
import com.warkop.manager.data.dto.VendorPriceBody
import com.warkop.manager.data.dto.VendorPriceDto
import kotlinx.serialization.Serializable
import java.math.BigDecimal

/** Kode vendor/bahan: `alpha_dash` ≤ 20, huruf besar (web `toUpperCase()`), permanen setelah dibuat. */
fun cleanCode20(raw: String) = raw.filter { (it.isLetterOrDigit() && it.code < 128) || it == '-' || it == '_' }.take(20).uppercase()

private val EMAIL = Regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$")

/** 094 / T078 — formulir Vendor, cermin `VendorsView.vue` + `Store/UpdateVendorRequest`. */
@Serializable data class VendorForm(
    val id: Long? = null,
    val code: String = "",
    val name: String = "",
    val phone: String = "",
    val email: String = "",
    val notes: String = "",
    val isActive: Boolean = true,
) {
    fun localErrors(): Map<String, String> = buildMap {
        if (id == null && code.isBlank()) put("code", "Wajib diisi.")
        if (name.isBlank()) put("name", "Wajib diisi.")
        if (email.isNotBlank() && !EMAIL.matches(email.trim())) put("contact_email", "Format surel tidak valid.")
    }

    fun toBody() = VendorBody(
        code = if (id == null) code.uppercase() else null,
        name = name, contactPhone = phone.trim().ifEmpty { null }, contactEmail = email.trim().ifEmpty { null },
        notes = notes.trim().ifEmpty { null }, isActive = isActive,
    )

    companion object {
        fun of(v: VendorDto) = VendorForm(v.id, v.code, v.name, v.contactPhone.orEmpty(), v.contactEmail.orEmpty(), v.notes.orEmpty(), v.isActive)
    }
}

/** Satuan bahan, cermin `utils/materialUnits.js` (urutan sama) + "Lainnya…" = teks bebas ≤ 20. */
object MaterialUnits {
    val PRESETS = listOf("pcs", "butir", "bungkus", "sachet", "porsi", "lembar", "ikat", "pack", "box", "botol", "kaleng", "gram", "kg", "ml", "liter", "meter")
    const val OTHER = "__other__"
}

object MaterialKinds {
    val LABELS = linkedMapOf("ingredient" to "Bahan baku", "semi_finished" to "Setengah jadi", "packaging" to "Kemasan")
}

/** 094 / T079 — formulir Bahan, cermin `MaterialsTable.vue` + `Store/UpdateMaterialRequest`. */
@Serializable data class MaterialForm(
    val id: Long? = null,
    val code: String = "",
    val name: String = "",
    val unit: String = "",
    /** Mode "Lainnya…": satuan diketik bebas. Satuan lama di luar daftar membuka mode ini (`needsCustomUnit`). */
    val customUnit: Boolean = false,
    val kind: String = "ingredient",
    val standardCost: String = "",
    val notes: String = "",
    val isActive: Boolean = true,
) {
    fun localErrors(): Map<String, String> = buildMap {
        if (id == null && code.isBlank()) put("code", "Wajib diisi.")
        if (name.isBlank()) put("name", "Wajib diisi.")
        if (unit.isBlank()) put("unit", "Wajib diisi.")
        if (kind == "semi_finished" && standardCost.isNotBlank() && (standardCost.toBigDecimalOrNull()?.signum() ?: -1) < 0) put("standard_cost", "Minimal 0.")
    }

    fun toBody() = MaterialBody(
        code = if (id == null) code.uppercase() else null,
        name = name, unit = unit.trim(), kind = kind,
        standardCost = if (kind == "semi_finished") standardCost.toDoubleOrNull() else null,
        notes = notes.trim().ifEmpty { null }, isActive = isActive,
    )

    companion object {
        fun of(m: MaterialDto) = MaterialForm(
            m.id, m.code, m.name, m.unit, customUnit = m.unit.isNotEmpty() && m.unit !in MaterialUnits.PRESETS, kind = m.kind,
            standardCost = m.standardCost?.toBigDecimalOrNull()?.stripTrailingZeros()?.toPlainString().orEmpty(), notes = m.notes.orEmpty(), isActive = m.isActive,
        )
    }
}

/**
 * Saldo bahan untuk outlet terpilih, cermin `utils/materialStock.js`: baris outlet itu, atau 0 bila belum ada —
 * TIDAK PERNAH jatuh ke `current_stock` total (bug 2026-10-31: "15 di WJ3" tampak tersedia di WDM).
 */
object MaterialBalance {
    fun of(m: MaterialDto, outletId: Long): BigDecimal = m.outletStock?.firstOrNull { it.outletId == outletId }?.onHand?.toBigDecimalOrNull() ?: BigDecimal.ZERO

    /** "−2,5 kg" dengan tanda minus U+2212 seperti web. */
    fun text(m: MaterialDto, outletId: Long): String {
        val v = of(m, outletId)
        val abs = Format.qty(v.abs().toPlainString())
        return (if (v.signum() < 0) "−" else "") + abs + if (m.unit.isNotBlank()) " ${m.unit}" else ""
    }
}

/** 094 / T080 — koreksi stok bahan: selisih ≠ 0 dalam ±999.999.999, alasan wajib ≤ 255, outlet terpilih. */
@Serializable data class MaterialAdjustForm(val qty: String = "", val reason: String = "") {
    fun localErrors(): Map<String, String> = buildMap {
        val q = qty.toBigDecimalOrNull()
        if (q == null || q.signum() == 0 || q.abs() > BigDecimal("999999999")) put("qty_change", "Isi selisih bukan nol (maks ±999.999.999).")
        if (reason.isBlank()) put("reason", "Alasan wajib diisi.")
    }
}

/** Label jenis pergerakan bahan, cermin `materialStock.js:41`. */
object MaterialMovementTypes {
    val LABELS = linkedMapOf(
        "sale" to "Penjualan", "return" to "Pengembalian", "waste" to "Terbuang", "purchase" to "Pembelian",
        "adjustment" to "Koreksi", "transfer_out" to "Transfer keluar", "transfer_in" to "Transfer masuk", "transfer_cancel" to "Transfer batal",
    )
}

/** 094 / T081 — harga vendor per bahan: vendor hanya saat buat (unik per bahan, server), harga 0..999999999999,99. */
@Serializable data class VendorPriceForm(val id: Long? = null, val vendorId: Long? = null, val price: String = "0", val preferred: Boolean = false, val notes: String = "") {
    fun localErrors(): Map<String, String> = buildMap {
        if (id == null && vendorId == null) put("vendor_id", "Pilih vendor.")
        val p = price.toBigDecimalOrNull()
        if (p == null || p.signum() < 0 || p > BigDecimal("999999999999.99")) put("price", "Harga 0 sampai 999.999.999.999,99.")
    }

    fun toBody() = VendorPriceBody(if (id == null) vendorId else null, ProductForm.money(price), preferred, notes.trim().ifEmpty { null })

    companion object {
        fun of(p: VendorPriceDto) = VendorPriceForm(p.id, p.vendorId, ProductForm.moneyInput(p.price), p.isPreferred, p.notes.orEmpty())
    }
}
