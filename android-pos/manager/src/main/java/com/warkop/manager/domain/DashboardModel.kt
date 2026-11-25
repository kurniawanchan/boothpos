package com.warkop.manager.domain

import com.warkop.manager.data.dto.DashboardSummaryDto
import com.warkop.manager.data.dto.KpiDto
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.longOrNull
import kotlin.math.abs
import kotlin.math.roundToLong

/** Periode Dashboard; nilai `query` sama dengan `DashboardService::PERIODS` di server. */
enum class Period(val query: String, val label: String) { TODAY("today", "Hari ini"), D7("7d", "7 hari"), D30("30d", "30 hari") }

enum class Tone { GOOD, BAD, NEUTRAL }

/** Chip perubahan: arah tidak pernah hanya lewat warna (panah + angka bertanda), cermin `ChangeChip.vue`. */
data class Change(val text: String, val tone: Tone)

data class KpiCard(val key: String, val label: String, val note: String, val value: String, val change: Change, val orgScope: Boolean)

/** Rupiah yang bisa disembunyikan server: [Hidden] = kunci tidak dikirim (tanpa menu `reports`), [Unknown] = null. */
sealed interface MoneyField {
    data object Hidden : MoneyField
    data object Unknown : MoneyField
    data class Shown(val text: String) : MoneyField
}

/**
 * 094 / T052 — penerjemah DTO Dashboard ke kartu tampilan. HANYA memformat; tidak ada angka yang dihitung di sini
 * (Konstitusi IV). Definisi kartu = `CELLS` di `resources/js/components/dashboard/DashboardKpis.vue`.
 */
object DashboardModel {
    private data class Def(val key: String, val label: String, val note: String, val money: Boolean, val percent: Boolean, val goodWhenUp: Boolean)

    private val DEFS = listOf(
        Def("net_sales", "Total penjualan", "Net · IDR", money = true, percent = true, goodWhenUp = true),
        Def("orders", "Total order", "Semua status", money = false, percent = true, goodWhenUp = true),
        Def("avg_order_value", "Rata-rata nilai order", "Net ÷ order", money = true, percent = true, goodWhenUp = true),
        Def("low_stock", "Item stok menipis", "Di bawah reorder point", money = false, percent = false, goodWhenUp = false),
    )

    fun cards(summary: DashboardSummaryDto): List<KpiCard> = DEFS.mapNotNull { d ->
        val k: KpiDto = summary.kpis[d.key] ?: return@mapNotNull null
        KpiCard(
            key = d.key,
            label = d.label,
            note = d.note,
            value = if (d.money) rupiahOrDash(k.value) else countOrDash(k.value),
            change = change(if (d.percent) k.changePct else k.changeAbs?.toDouble(), d.percent, d.goodWhenUp),
            orgScope = k.scope == "organisation",
        )
    }

    /**
     * Cermin `ChangeChip.vue`: null = "tidak ada pembanding" (bukan 0%); yang diwarnai adalah angka SETELAH dibulatkan,
     * jadi 0,04% tampil "0,0%" netral, bukan naik hijau.
     */
    fun change(value: Double?, percent: Boolean, goodWhenUp: Boolean): Change {
        if (value == null || !value.isFinite()) return Change("Tidak ada pembanding", Tone.NEUTRAL)
        val rounded = if (percent) (value * 10).roundToLong() / 10.0 else value.roundToLong().toDouble()
        if (rounded == 0.0) return Change(if (percent) "→ 0,0%" else "→ 0 item", Tone.NEUTRAL)
        val up = rounded > 0
        val arrow = if (up) "↑" else "↓"
        val sign = if (up) "+" else "−"
        val body = if (percent) Format.percent1(abs(rounded)) + "%" else "${count(abs(rounded).toLong())} item"
        return Change("$arrow $sign$body", if (up == goodWhenUp) Tone.GOOD else Tone.BAD)
    }

    fun rupiah(value: String?): String = Format.rupiah(value)

    fun count(n: Long): String = Format.count(n)

    private fun rupiahOrDash(p: JsonPrimitive?): String = if (p == null || p.content == "null") "—" else rupiah(p.content)

    private fun countOrDash(p: JsonPrimitive?): String = p?.longOrNull?.let(::count) ?: "—"
}
