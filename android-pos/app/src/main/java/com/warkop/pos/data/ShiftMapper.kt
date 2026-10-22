package com.warkop.pos.data

import com.warkop.core.domain.Money
import com.warkop.core.network.dto.CashMovementDto
import com.warkop.core.network.dto.ClosedSessionDto
import com.warkop.core.network.dto.FeaturesDto
import com.warkop.core.network.dto.XReportDto
import com.warkop.pos.model.CashMovement
import com.warkop.pos.model.CloseOutcome
import com.warkop.pos.model.HourlyTotal
import com.warkop.pos.model.MethodTotal
import com.warkop.pos.model.ShiftLimits
import com.warkop.pos.model.ShiftReport
import com.warkop.pos.model.PosCatalog
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.jsonPrimitive
import java.time.OffsetDateTime

/**
 * 048 / S2 — dari bentuk kawat (string desimal, angka pengaturan) ke model layar (rupiah bulat). Satu-satunya
 * tempat aturan pembulatan shift: [Money.parse] (HALF_UP ke rupiah). Tidak ada rumus uang di sini.
 */
object ShiftMapper {
    fun money(value: String?): Int = Money.parse(value).toInt()

    fun report(d: XReportDto): ShiftReport = ShiftReport(
        openingCash = money(d.openingCash),
        orderCount = d.orderCount,
        totalSales = money(d.totalSales),
        voidCount = d.voidCount,
        voidTotal = money(d.voidTotal),
        changeGiven = money(d.changeGiven),
        cashReceived = money(d.cashReceived),
        cashIn = money(d.cashIn),
        cashOut = money(d.cashOut),
        byMethod = d.byMethod.map { MethodTotal(it.method, it.count, money(it.amount)) },
        hourly = d.hourly.map { HourlyTotal(hourOf(it.hour), it.orderCount, money(it.total)) },
        expectedCash = d.expectedCash?.let(::money),
    )

    fun movement(d: CashMovementDto): CashMovement = CashMovement(
        id = d.id,
        inflow = d.direction == "in",
        reasonCode = d.reasonCode,
        note = d.reasonNote,
        amount = money(d.amount),
        by = d.createdByName,
        at = hhmm(d.createdAt),
    )

    fun close(d: ClosedSessionDto): CloseOutcome = CloseOutcome(
        expected = money(d.expectedCash),
        counted = money(d.countedTotal),
        variance = money(d.variance),
        tolerance = d.zReport?.varianceTolerance?.let(::money),
    )

    fun limits(d: FeaturesDto): ShiftLimits = ShiftLimits(
        varianceTolerance = d.varianceTolerance?.let(::number),
        cashOutThreshold = d.cashOutApprovalThreshold?.let(::number),
    )

    /** Label alasan untuk layar; kode yang tidak dikenal ditampilkan apa adanya (bukan dihapus diam-diam). */
    fun reasonLabel(code: String): String =
        (PosCatalog.cashInReasons + PosCatalog.cashOutReasons).firstOrNull { it.first == code }?.second ?: code

    /** "09" atau 9 → 9. Nilai yang tak terbaca menjadi 0 (jam tak diketahui), bukan galat yang menjatuhkan seluruh X-report. */
    private fun hourOf(e: JsonElement?): Int = e?.jsonPrimitive?.content?.toIntOrNull() ?: 0

    private fun number(e: JsonElement): Int = runCatching { Money.parse(e.jsonPrimitive.content).toInt() }.getOrDefault(0)

    private fun hhmm(iso: String?): String =
        iso?.let { runCatching { OffsetDateTime.parse(it).toLocalTime().toString().take(5) }.getOrNull() } ?: "--:--"
}
