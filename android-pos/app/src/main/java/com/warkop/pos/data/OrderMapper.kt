package com.warkop.pos.data

import com.warkop.core.domain.Money
import com.warkop.core.network.dto.ClosedOrderDto
import com.warkop.core.network.dto.FloorTabDto
import com.warkop.core.network.dto.FloorTableDto
import com.warkop.core.network.dto.TabDto
import com.warkop.core.network.dto.TabLineDto
import com.warkop.core.network.dto.TabPaymentDto
import com.warkop.pos.model.FloorTab
import com.warkop.pos.model.FloorTable
import com.warkop.pos.model.ModOption
import com.warkop.pos.model.Order
import com.warkop.pos.model.OrderKind
import com.warkop.pos.model.OrderLine
import com.warkop.pos.model.Tender
import com.warkop.pos.model.TenderMethod
import com.warkop.pos.model.TenderStatus
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter

/**
 * DTO server → model layar. Satu-satunya tempat string uang diubah ke rupiah (Money.parse) dan status server
 * diterjemahkan ke tampilan. Tidak ada perhitungan uang di sini: angka server disimpan apa adanya.
 */
object OrderMapper {
    private val HHMM = DateTimeFormatter.ofPattern("HH:mm")

    fun hhmm(iso: String?): String =
        iso?.let { runCatching { OffsetDateTime.parse(it).format(HHMM) }.getOrNull() } ?: "--:--"

    private fun rp(value: String): Int = Money.parse(value).toInt()

    fun tab(t: TabDto, myDeviceCode: String?): Order {
        val voided = t.status == "voided"
        val completed = t.status == "completed"
        return Order(
            id = t.id.toString(),
            kind = if (t.table != null) OrderKind.DINE_IN else OrderKind.COUNTER,
            table = t.table?.name,
            pax = t.guestCount ?: 0,
            queue = t.queueNo,
            waiter = t.openedBy.orEmpty(),
            openedAt = hhmm(t.createdAt),
            lines = t.lines.map { line(it) },
            tenders = t.payments.map { tender(it) },
            billPrinted = t.prebillAt != null,
            closed = completed || voided,
            cancelledReason = if (voided) "Dibatalkan" else null,
            receiptNo = t.receiptNo,
            otherDevice = t.device?.code?.takeIf { it != myDeviceCode },
            serverSubtotal = rp(t.subtotal),
            serverDiscount = rp(t.discountAmount),
            serverTax = rp(t.taxAmount),
            serverTotal = rp(t.totalAmount),
            serverPaid = rp(t.paidAmount),
            serverChange = rp(t.changeAmount),
        )
    }

    fun line(l: TabLineDto): OrderLine = OrderLine(
        // Id baris server (BIGINT) dipersempit ke Int di layar; auto-increment instalasi ini jauh di bawah 2^31.
        id = l.id.toInt(),
        itemId = l.variantId.toString(),
        name = l.name,
        basePrice = rp(l.unitPrice),
        qty = l.qty,
        mods = l.modifiers.map { ModOption(it.option, rp(it.priceDelta)) },
        note = l.note.orEmpty(),
        fired = l.sent,
        lineTotal = rp(l.lineTotal),
    )

    fun tender(p: TabPaymentDto): Tender {
        val method = when (p.method) {
            "cash" -> TenderMethod.CASH
            "qr_ewallet" -> TenderMethod.QRIS
            "bank_transfer" -> TenderMethod.TRANSFER
            else -> TenderMethod.EDC
        }
        val status = when (p.verification) {
            "rejected" -> TenderStatus.FAIL
            "pending" -> TenderStatus.PENDING
            else -> TenderStatus.OK
        }
        val amount = rp(p.amount)
        // Tunai: jumlah yang diserahkan pembeli; kembalian ada di serverChange.
        return Tender(id = p.id.toInt(), method = method, amount = amount, status = status, received = if (method == TenderMethod.CASH) amount else null)
    }

    fun floorTab(t: FloorTabDto, myDeviceCode: String?, tableName: String?): FloorTab = FloorTab(
        id = t.id,
        label = t.tabLabel,
        tableName = tableName,
        total = rp(t.totalAmount),
        paid = rp(t.paidAmount),
        pax = t.guestCount ?: 0,
        billPrinted = t.billPrinted,
        otherDevice = t.deviceCode?.takeIf { it != myDeviceCode },
        queue = t.queueNo,
        unsent = t.unsentCount,
        ready = t.readyCount,
        openedAt = hhmm(t.openedAt),
    )

    fun floorTable(t: FloorTableDto, area: String, myDeviceCode: String?): FloorTable = FloorTable(
        id = t.id,
        name = t.name,
        area = area,
        seats = t.seats,
        active = t.isActive,
        tab = t.tab?.let { floorTab(it, myDeviceCode, t.name) },
    )

    /** Ringkasan tab dari denah, dipakai di daftar sebelum tab dimuat penuh. */
    fun stub(f: FloorTab): Order = Order(
        id = f.id.toString(),
        kind = if (f.tableName != null) OrderKind.DINE_IN else OrderKind.COUNTER,
        table = f.tableName,
        pax = f.pax,
        queue = f.queue,
        waiter = "",
        openedAt = f.openedAt,
        billPrinted = f.billPrinted,
        otherDevice = f.otherDevice,
        serverTotal = f.total,
        serverPaid = f.paid,
    )

    /** Baris daftar penjualan selesai dari GET /orders. Nomor struk per terminal belum ada di daftar ini. */
    fun closed(c: ClosedOrderDto): Order = Order(
        id = c.id.toString(),
        kind = OrderKind.COUNTER,
        table = null,
        pax = 0,
        queue = null,
        waiter = "",
        openedAt = hhmm(c.createdAt),
        closed = true,
        receiptNo = c.orderNumber,
        closedAt = hhmm(c.createdAt),
        cancelledReason = if (c.status == "voided") (c.voidReason ?: "Dibatalkan") else null,
        serverTotal = rp(c.totalAmount),
        serverPaid = rp(c.paidAmount),
        serverChange = rp(c.changeAmount),
    )
}
