package com.warkop.pos.model

import java.text.NumberFormat
import java.util.Locale
import kotlin.math.ceil
import kotlin.math.roundToInt

/** Bentuk data layar POS. Uang selalu Int rupiah (tanpa pecahan). Angka uang dari server diutamakan (Konstitusi IV). */
data class MenuCategory(val id: String, val name: String, val code: String, val tone: Tone)

enum class Tone { INK, TINT, ACCENT, NEUTRAL, OUTLINE }

data class ModOption(val name: String, val delta: Int, val id: Long = 0)
data class ModGroup(val id: String, val name: String, val min: Int, val max: Int, val options: List<ModOption>)

data class Product(
    val id: String, val icon: String, val cat: String, val name: String, val price: Int,
    val groups: List<String> = emptyList(), val is86: Boolean = false, val imageUrl: String? = null,
)

data class StaffUser(
    val id: String, val name: String, val role: String, val code: String,
    val hasPin: Boolean = true, val locked: Boolean = false,
) {
    val initials: String get() = name.take(2).uppercase()
    val isSupervisor: Boolean get() = code == "SPV" || code == "OMG"
}

data class OrderLine(
    val id: Int, val itemId: String, val name: String, val basePrice: Int, val qty: Int,
    val mods: List<ModOption> = emptyList(), val note: String = "", val discPct: Int = 0,
    val fired: Boolean = false, val firedAt: String? = null, val voided: Boolean = false,
    /** Total baris dari server (sudah termasuk modifier). Bila ada, dipakai apa adanya. */
    val lineTotal: Int? = null,
) {
    val unit: Int get() = basePrice + mods.sumOf { it.delta }
    val gross: Int get() = unit * qty
    val net: Int get() = lineTotal ?: (gross * (100 - discPct) / 100.0).roundToInt()
    val modLabel: String get() = mods.joinToString(", ") { it.name }
}

enum class TenderMethod(val label: String) { CASH("Tunai"), EDC("EDC BCA"), QRIS("QRIS"), TRANSFER("Transfer bank") }
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
    // Angka dari server (null = belum ada; dihitung lokal hanya sebagai cadangan tampilan).
    val serverSubtotal: Int? = null, val serverDiscount: Int? = null, val serverTax: Int? = null,
    val serverTotal: Int? = null, val serverPaid: Int? = null, val serverChange: Int? = null,
) {
    val label: String get() = if (kind == OrderKind.COUNTER) "Counter ${queue ?: ""}".trim() else "Meja ${table ?: ""}"
    val live: List<OrderLine> get() = lines.filter { !it.voided }
    val gross: Int get() = serverSubtotal ?: live.sumOf { it.net }
    val discount: Int get() = serverDiscount ?: (gross * discPct / 100.0).roundToInt()
    val base: Int get() = gross - discount
    val tax: Int get() = serverTax ?: (base * TAX_RATE).roundToInt()
    val total: Int get() = serverTotal ?: (base + tax)
    val paid: Int get() = serverPaid ?: tenders.filter { it.status == TenderStatus.OK }.sumOf { it.amount }
    val remaining: Int get() = (total - paid).coerceAtLeast(0)
    val unsent: List<OrderLine> get() = live.filter { !it.fired }
    val sent: List<OrderLine> get() = live.filter { it.fired }
    val hasPendingQris: Boolean get() = tenders.any { it.method == TenderMethod.QRIS && it.status == TenderStatus.PENDING }

    companion object {
        /** Cadangan tampilan saja; pajak yang tersimpan selalu dari server. */
        const val TAX_RATE = 0.10
    }
}

data class Shift(
    val label: String, val openedAt: String, val schedule: String, val float: Int, val userId: String,
    val id: Long = 0,
)

/** 048 / S2 — angka shift dari server (X-report). Semua rupiah bilangan bulat; tidak ada angka yang dihitung ulang di klien. */
data class ShiftReport(
    val openingCash: Int, val orderCount: Int, val totalSales: Int, val voidCount: Int, val voidTotal: Int,
    val changeGiven: Int, val cashReceived: Int, val cashIn: Int, val cashOut: Int,
    val byMethod: List<MethodTotal>, val hourly: List<HourlyTotal>,
) {
    /** Tunai bersih di laci = tunai diterima dikurangi kembalian. */
    val netCash: Int get() = cashReceived - changeGiven
    fun amountOf(method: String): Int = byMethod.firstOrNull { it.method == method }?.amount ?: 0
}

data class MethodTotal(val method: String, val count: Int, val amount: Int)

/** Penjualan per jam dinding toko (jam dengan penjualan saja). `hour` 0–23. */
data class HourlyTotal(val hour: Int, val orderCount: Int, val total: Int)

/** Kas masuk/keluar yang sudah tercatat di server. */
data class CashMovement(
    val id: Long, val inflow: Boolean, val reasonCode: String, val note: String?, val amount: Int, val by: String?, val at: String,
)

/** Hasil tutup shift dari server: hanya angka yang dikembalikan server, tidak ada yang diisi dari layar. */
data class CloseOutcome(
    val expected: Int, val counted: Int, val variance: Int, val tolerance: Int?, val edcBatch: Int?,
)

/** 048 / S3 — alasan dari server (id untuk 86). */
data class ReasonCode(val id: Long, val label: String)

/** 048 — kanal bank dari `GET /payment-channels` (type bank_transfer). EDC wajib membawa id-nya; nama dari server. */
data class BankChannel(val id: Long, val name: String)

/** Kanal QRIS dari `GET /payment-channels` (type qr_ewallet). [name] = provider dari server, atau "QRIS" dari tipe kanal. */
data class QrisChannel(val id: Long, val name: String)

/** 048 / S3 — penyetuju yang memenuhi syarat untuk satu aksi (tanpa peminta). */
data class Approver(val id: Long, val name: String, val role: String)

/** Batas dari pengaturan server. null = belum terbaca; layar memakai teks umum, bukan angka tebakan. */
data class ShiftLimits(val varianceTolerance: Int?, val cashOutThreshold: Int?)

/** Katalog menu dari server untuk outlet aktif: kategori, item (satu kartu per varian), dan grup modifier. */
data class MenuCatalog(val categories: List<MenuCategory>, val products: List<Product>, val groups: Map<String, ModGroup>)

/** Item yang sedang habis (86), berisi id varian. `etag` dipakai untuk poll berikutnya. */
data class Availability(val etag: String, val soldOutVariantIds: Set<String>)

/** Tab di denah. `otherDevice` terisi bila tab dibuka terminal lain. */
data class FloorTab(
    val id: Long, val label: String?, val tableName: String?, val total: Int, val paid: Int, val pax: Int,
    val billPrinted: Boolean, val otherDevice: String?, val queue: String?, val unsent: Int, val ready: Int, val openedAt: String,
)

data class FloorTable(val id: Long, val name: String, val area: String, val seats: Int, val active: Boolean, val tab: FloorTab?)

data class FloorSnapshot(val version: String, val tables: List<FloorTable>, val takeaway: List<FloorTab>)

private val rpFormat = NumberFormat.getIntegerInstance(Locale("id", "ID"))

fun rp(n: Int): String = (if (n < 0) "-Rp " else "Rp ") + rpFormat.format(kotlin.math.abs(n))
fun num(n: Int): String = rpFormat.format(n)

/** Pecahan uang cepat: uang pas, lalu pembulatan ke atas ke 10rb/50rb/100rb (unik, maksimal 4) — aturan prototipe. */
fun quickCash(target: Int): List<Int> {
    if (target <= 0) return emptyList()
    val up = listOf(10_000, 50_000, 100_000).map { ceil(target / it.toDouble()).toInt() * it }
    return (listOf(target) + up).distinct().take(4)
}
