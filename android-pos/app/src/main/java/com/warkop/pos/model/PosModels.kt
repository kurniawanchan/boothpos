package com.warkop.pos.model

import java.text.NumberFormat
import java.util.Locale
import kotlin.math.ceil
import kotlin.math.roundToInt

/** Bentuk data layar POS. Uang selalu Int rupiah (tanpa pecahan), sama seperti prototipe desain. */
data class MenuCategory(val id: String, val name: String, val code: String, val tone: Tone)

enum class Tone { INK, TINT, ACCENT, NEUTRAL, OUTLINE }

data class ModOption(val name: String, val delta: Int)
data class ModGroup(val id: String, val name: String, val min: Int, val max: Int, val options: List<ModOption>)

data class Product(
    val id: String, val icon: String, val cat: String, val name: String, val price: Int,
    val groups: List<String> = emptyList(), val is86: Boolean = false,
)

data class StaffUser(val id: String, val name: String, val role: String, val code: String) {
    val initials: String get() = name.take(2).uppercase()
    val isSupervisor: Boolean get() = code == "SPV" || code == "OMG"
}

data class OrderLine(
    val id: Int, val itemId: String, val name: String, val basePrice: Int, val qty: Int,
    val mods: List<ModOption> = emptyList(), val note: String = "", val discPct: Int = 0,
    val fired: Boolean = false, val firedAt: String? = null, val voided: Boolean = false,
) {
    val unit: Int get() = basePrice + mods.sumOf { it.delta }
    val gross: Int get() = unit * qty
    val net: Int get() = (gross * (100 - discPct) / 100.0).roundToInt()
    val modLabel: String get() = mods.joinToString(", ") { it.name }
}

enum class TenderMethod(val label: String) { CASH("Tunai"), EDC("EDC BCA"), QRIS("QRIS GoPay") }
enum class TenderStatus { OK, PENDING, FAIL }

data class Tender(
    val id: Int, val method: TenderMethod, val amount: Int, val status: TenderStatus,
    val ref: String? = null, val received: Int? = null, val atMillis: Long = System.currentTimeMillis(),
)

enum class OrderKind { COUNTER, DINE_IN }

data class Order(
    val id: String, val kind: OrderKind, val table: String?, val pax: Int, val queue: String?,
    val waiter: String, val openedAt: String, val lines: List<OrderLine> = emptyList(),
    val tenders: List<Tender> = emptyList(), val discPct: Int = 0, val discReason: String? = null,
    val billPrinted: Boolean = false, val closed: Boolean = false, val cancelledReason: String? = null,
    val receiptNo: String? = null, val otherDevice: String? = null, val reprints: Int = 0,
    val closedAt: String? = null, val refunded: Boolean = false,
) {
    val label: String get() = if (kind == OrderKind.COUNTER) "Counter ${queue ?: ""}".trim() else "Meja $table"
    val live: List<OrderLine> get() = lines.filter { !it.voided }
    val gross: Int get() = live.sumOf { it.net }
    val discount: Int get() = (gross * discPct / 100.0).roundToInt()
    val base: Int get() = gross - discount
    val tax: Int get() = (base * TAX_RATE).roundToInt()
    val total: Int get() = base + tax
    val paid: Int get() = tenders.filter { it.status == TenderStatus.OK }.sumOf { it.amount }
    val remaining: Int get() = (total - paid).coerceAtLeast(0)
    val unsent: List<OrderLine> get() = live.filter { !it.fired }
    val sent: List<OrderLine> get() = live.filter { it.fired }
    val hasPendingQris: Boolean get() = tenders.any { it.method == TenderMethod.QRIS && it.status == TenderStatus.PENDING }

    companion object {
        const val TAX_RATE = 0.10
    }
}

data class Shift(val label: String, val openedAt: String, val schedule: String, val float: Int, val userId: String)

private val rpFormat = NumberFormat.getIntegerInstance(Locale("id", "ID"))

fun rp(n: Int): String = (if (n < 0) "-Rp " else "Rp ") + rpFormat.format(kotlin.math.abs(n))
fun num(n: Int): String = rpFormat.format(n)

/** Pecahan uang cepat: uang pas, lalu pembulatan ke atas ke 10rb/50rb/100rb (unik, maksimal 4) — aturan prototipe. */
fun quickCash(target: Int): List<Int> {
    if (target <= 0) return emptyList()
    val up = listOf(10_000, 50_000, 100_000).map { ceil(target / it.toDouble()).toInt() * it }
    return (listOf(target) + up).distinct().take(4)
}
