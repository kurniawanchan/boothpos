package com.warkop.pos.state

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import com.warkop.pos.model.*
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

enum class Dest { PIN, OPEN_SHIFT, TABLES, EDITOR, TENDER, DONE, ORDERS, SHIFT, XREPORT, CLOSE_SHIFT, CLOSE_RESULT, SYNC }
enum class Conn(val label: String) { ONLINE("Online"), OFFLINE("Offline"), OFFLINE_LIMITED("Offline terbatas") }

/** Lembar/dialog di atas layar. Satu pada satu waktu — sama seperti prototipe (satu `sheet` aktif). */
sealed interface Sheet {
    data class Pax(val table: String) : Sheet
    data class Modifier(val product: Product, val replaceLineId: Int? = null) : Sheet
    data class LineActions(val lineId: Int) : Sheet
    data object OrderActions : Sheet
    data object Split : Sheet
    data object Discount : Sheet
    data class Void(val lineId: Int?) : Sheet // null = batalkan seluruh order
    data class Override(val summary: String, val minDiscount: Int? = null, val onApproved: () -> Unit) : Sheet
    data object Edc : Sheet
    data object QrisRecord : Sheet
    data class QrisVerify(val tenderId: Int) : Sheet
    data class Receipt(val orderId: String) : Sheet
    data object CashOut : Sheet
    data class Confirm(val title: String, val body: String, val confirm: String, val onConfirm: () -> Unit) : Sheet
}

class PosStore : ViewModel() {
    var dest by mutableStateOf(Dest.PIN); private set
    var user by mutableStateOf<StaffUser?>(null); private set
    var shift by mutableStateOf<Shift?>(null); private set
    var conn by mutableStateOf(Conn.ONLINE)
    /** null = otomatis (malam hari gelap); dipakai preset debug untuk memaksa tema. */
    var darkOverride by mutableStateOf<Boolean?>(null)
    var ordersTab by mutableIntStateOf(0)
    var orders by mutableStateOf<List<Order>>(seedOrders()); private set
    var activeId by mutableStateOf<String?>(null); private set
    var sheet by mutableStateOf<Sheet?>(null); private set
    var toast by mutableStateOf<String?>(null); private set
    var pinFails by mutableIntStateOf(0); private set
    var lockedUntil by mutableStateOf<Long?>(null); private set
    var cashMovements by mutableStateOf<List<CashMovement>>(emptyList()); private set
    var closeResult by mutableStateOf<CloseResult?>(null); private set
    // Antrean sinkron & cetak (status perangkat). Saat online, event langsung dianggap terkirim; saat offline menumpuk
    // di antrean — sampai outbox sungguhan (api-gap B16) dibangun, ini hanya cermin tampilan, bukan penyimpan data.
    data class OutboxEvent(val title: String, val type: String, val at: String, val synced: Boolean)
    data class PrintJob(val title: String, val at: String, val ok: Boolean)
    var outbox by mutableStateOf<List<OutboxEvent>>(emptyList()); private set
    var printJobs by mutableStateOf<List<PrintJob>>(emptyList()); private set
    private fun emit(title: String, type: String) { outbox = outbox + OutboxEvent(title, type, clock(), conn == Conn.ONLINE) }
    private fun printed(title: String) { printJobs = printJobs + PrintJob(title, clock(), true) }
    fun syncNow(): Boolean {
        if (conn != Conn.ONLINE) return false
        outbox = outbox.map { it.copy(synced = true) }; return true
    }
    val pendingEvents: List<OutboxEvent> get() = outbox.filter { !it.synced }

    private var lineSeq = 100
    private var tenderSeq = 1
    private var counterSeq = 23
    private var receiptSeq = 124
    private var closedCount = 0

    val active: Order? get() = orders.firstOrNull { it.id == activeId }
    val openOrders: List<Order> get() = orders.filter { !it.closed }
    val closedOrders: List<Order> get() = orders.filter { it.closed }.sortedByDescending { it.closedAt }

    // ---------- navigasi & umpan balik ----------
    fun go(d: Dest) { dest = d; sheet = null }
    fun show(s: Sheet?) { sheet = s }
    fun say(msg: String) { toast = msg }
    fun clearToast() { toast = null }
    fun lock() { user = null; activeId = null; dest = Dest.PIN; sheet = null }

    // ---------- PIN ----------
    /** Demo desain: 6 digit apa saja kecuali 000000. Login sungguhan memakai POST /auth/pin-sessions (lihat PinAuthController). */
    sealed interface PinResult { data object Ok : PinResult; data class Wrong(val left: Int) : PinResult; data object Locked : PinResult }

    fun submitPin(u: StaffUser, pin: String): PinResult {
        val now = System.currentTimeMillis()
        if ((lockedUntil ?: 0) > now) return PinResult.Locked
        if (pin == "000000") {
            pinFails++
            if (pinFails >= 5) { lockedUntil = now + 15 * 60_000; pinFails = 0; return PinResult.Locked }
            return PinResult.Wrong(5 - pinFails)
        }
        pinFails = 0
        user = u
        dest = when {
            u.code == "CSH" && shift == null -> Dest.OPEN_SHIFT
            u.code == "CSH" -> { newCounter(); Dest.EDITOR }
            u.code == "WTR" -> Dest.TABLES
            else -> Dest.ORDERS
        }
        return PinResult.Ok
    }

    // ---------- shift ----------
    fun openShift(float: Int) {
        shift = Shift("Shift 1", clock(), "07:00-15:00", float, user?.id ?: "")
        say("Shift 1 dibuka dengan modal ${rp(float)}.")
        go(Dest.TABLES)
    }

    // ---------- order ----------
    fun newCounter() {
        counterSeq++
        val o = Order("c$counterSeq", OrderKind.COUNTER, null, 0, "A-${counterSeq.toString().padStart(3, '0')}", user?.name ?: "", clock())
        orders = orders + o; activeId = o.id; dest = Dest.EDITOR
    }

    fun openTable(table: String, pax: Int) {
        val o = Order("t$table-${System.nanoTime()}", OrderKind.DINE_IN, table, pax, null, user?.name ?: "", clock())
        orders = orders + o; activeId = o.id; sheet = null; dest = Dest.EDITOR
    }

    fun openOrder(id: String) {
        val o = orders.first { it.id == id }
        if (o.otherDevice != null && conn != Conn.ONLINE) { say("Meja ${o.table} dikelola perangkat ${o.otherDevice}. Tidak dapat dibuka saat offline."); return }
        activeId = id
        dest = if (o.hasPendingQris) Dest.TENDER else Dest.EDITOR
    }

    fun orderAtTable(table: String) = orders.firstOrNull { !it.closed && it.table == table }

    private fun update(id: String, f: (Order) -> Order) { orders = orders.map { if (it.id == id) f(it) else it } }
    private fun updateActive(f: (Order) -> Order) { activeId?.let { update(it, f) } }

    fun tapProduct(p: Product) {
        if (p.is86) { say("${p.name} sedang habis. Tahan untuk mengubah ketersediaan."); return }
        if (p.groups.isEmpty()) addLine(p, emptyList(), "") else sheet = Sheet.Modifier(p)
    }

    /** Baris yang sama (item, modifier, belum terkirim, tanpa catatan/diskon) digabung — aturan prototipe. */
    fun addLine(p: Product, mods: List<ModOption>, note: String, qty: Int = 1) {
        updateActive { o ->
            val twin = o.lines.firstOrNull { !it.voided && !it.fired && it.itemId == p.id && it.mods == mods && it.note.isBlank() && note.isBlank() && it.discPct == 0 }
            if (twin != null) o.copy(lines = o.lines.map { if (it.id == twin.id) it.copy(qty = it.qty + qty) else it })
            else o.copy(lines = o.lines + OrderLine(++lineSeq, p.id, p.name, p.price, qty, mods, note.trim()))
        }
        sheet = null
    }

    fun qtyDelta(lineId: Int, d: Int) {
        val line = active?.lines?.firstOrNull { it.id == lineId } ?: return
        if (d < 0 && line.fired) { say("Mengurangi jumlah dilakukan lewat void."); return }
        if (d < 0 && line.qty == 1) { removeLine(lineId); return }
        updateActive { o -> o.copy(lines = o.lines.map { if (it.id == lineId) it.copy(qty = it.qty + d) else it }) }
    }

    fun removeLine(lineId: Int) { updateActive { o -> o.copy(lines = o.lines.filter { it.id != lineId }) }; sheet = null }

    fun saveLine(lineId: Int, qty: Int, note: String, discPct: Int) {
        val me = user ?: return
        val limit = PosCatalog.discountLimit[me.code] ?: 0
        val apply = {
            updateActive { o -> o.copy(lines = o.lines.map { if (it.id == lineId) it.copy(qty = qty, note = note.trim(), discPct = discPct) else it }) }
            sheet = null
        }
        if (discPct > limit) sheet = Sheet.Override("Diskon $discPct% pada item", discPct, apply) else apply()
    }

    fun voidLine(lineId: Int, reason: String, made: Boolean) {
        val me = user ?: return
        val go = {
            updateActive { o -> o.copy(lines = o.lines.map { if (it.id == lineId) it.copy(voided = true) else it }) }
            sheet = null; say("Item di-void · $reason${if (made) " · wastage" else ""}. Slip BATAL dicetak ke station.")
        }
        val ln = active?.lines?.firstOrNull { it.id == lineId }
        val tail = if (made) "sudah dibuat" else "belum dibuat"
        if (me.isSupervisor) go() else sheet = Sheet.Override("Void ${ln?.qty ?: 1}× ${ln?.name ?: "item"} · ${rp(ln?.net ?: 0)} · Alasan: $reason · $tail", null, go)
    }

    fun cancelOrder(reason: String) {
        val o = active ?: return
        if (o.paid > 0) { say("Order dengan pembayaran tidak dapat dibatalkan. Gunakan refund setelah lunas."); sheet = null; return }
        val go = {
            update(o.id) { it.copy(closed = true, cancelledReason = reason, closedAt = clock()) }
            sheet = null; activeId = null; dest = if (o.kind == OrderKind.DINE_IN) Dest.TABLES else Dest.ORDERS
            say("Order dibatalkan · $reason.")
        }
        if (user?.isSupervisor == true) go() else sheet = Sheet.Override("Batalkan ${o.label}: $reason", null, go)
    }

    fun fire() {
        val o = active ?: return
        val n = o.unsent.size
        if (n == 0) return
        val t = clock()
        update(o.id) { it.copy(lines = it.lines.map { l -> if (!l.voided && !l.fired) l.copy(fired = true, firedAt = t) else l }) }
        emit("${o.label} · $n item", "order.fired"); printed("KOT ${o.label} · Dapur"); say("$n item dikirim ke dapur. KOT tercetak.")
    }

    fun printPrebill() { updateActive { it.copy(billPrinted = true) }; sheet = null; say("Pre-bill tercetak · BELUM LUNAS.") }

    fun applyOrderDiscount(p: DiscountPreset?) {
        val me = user ?: return
        val pct = p?.pct ?: 0
        val apply = { updateActive { it.copy(discPct = pct, discReason = p?.reason) }; sheet = null }
        if (pct > (PosCatalog.discountLimit[me.code] ?: 0)) sheet = Sheet.Override("Diskon order $pct% · ${p?.label}", pct, apply) else apply()
    }

    fun splitByItem(lineIds: Set<Int>) {
        val o = active ?: return
        if (o.paid > 0) { say("Bill dengan pembayaran tidak dapat dibagi per item."); return }
        if (lineIds.isEmpty() || lineIds.size >= o.live.size) { say("Pilih minimal 1 item dan sisakan minimal 1 item."); return }
        val moved = o.lines.filter { it.id in lineIds }
        val child = Order("${o.id}-b", o.kind, o.table, o.pax, o.queue, o.waiter, o.openedAt, lines = moved)
        orders = orders.map { if (it.id == o.id) it.copy(lines = it.lines.filter { l -> l.id !in lineIds }) else it } + child
        sheet = null; say("${moved.size} item dipindahkan ke Bill B.")
    }

    // ---------- pembayaran ----------
    fun goPay() {
        val o = active ?: return
        if (user?.code == "WTR") { printPrebill(); return }
        if (o.live.isEmpty()) return
        if (shift == null) {
            sheet = Sheet.Confirm("Belum ada shift terbuka", "Pembayaran hanya bisa diterima setelah shift terbuka. Order tetap tersimpan.", "Buka shift") { dest = Dest.OPEN_SHIFT; sheet = null }
            return
        }
        dest = Dest.TENDER
    }

    /** Tunai memberi kembalian; jumlah tercatat dibatasi sisa tagihan (aturan prototipe). */
    fun payCash(received: Int) {
        val o = active ?: return
        if (conn == Conn.OFFLINE_LIMITED) { say("Batas tunai offline tercapai. Gunakan EDC atau tunggu sinkron."); return }
        val amount = minOf(received, o.remaining)
        if (amount <= 0) return
        record(Tender(tenderSeq++, TenderMethod.CASH, amount, TenderStatus.OK, received = received))
    }

    fun payEdc(code: String): Boolean {
        val o = active ?: return false
        val amount = o.remaining
        val dup = o.tenders.any { it.method == TenderMethod.EDC && it.amount == amount && System.currentTimeMillis() - it.atMillis < 120_000 }
        val save = { record(Tender(tenderSeq++, TenderMethod.EDC, amount, TenderStatus.OK, ref = code.uppercase())); sheet = null }
        if (dup) { sheet = Sheet.Confirm("Pembayaran mungkin ganda", "Pembayaran EDC ${rp(amount)} sudah dicatat kurang dari 2 menit lalu. Catat lagi?", "Catat lagi", save); return false }
        save(); return true
    }

    fun recordQris() {
        val o = active ?: return
        if (conn != Conn.ONLINE) { say("QRIS tidak tersedia saat offline. Tunai dan EDC tetap bisa dipakai."); return }
        val t = Tender(tenderSeq++, TenderMethod.QRIS, o.remaining, TenderStatus.PENDING)
        updateActive { it.copy(tenders = it.tenders + t) }
        sheet = null; say("QRIS dicatat · menunggu verifikasi.")
    }

    fun verifyQris(tenderId: Int, ref: String) {
        val go = {
            updateActive { o -> o.copy(tenders = o.tenders.map { if (it.id == tenderId) it.copy(status = TenderStatus.OK, ref = ref) else it }) }
            sheet = null; checkClosed(); say("QRIS terverifikasi.")
        }
        if (user?.isSupervisor == true) go() else sheet = Sheet.Override("Verifikasi QRIS · ref $ref", null, go)
    }

    fun failQris(tenderId: Int) {
        updateActive { o -> o.copy(tenders = o.tenders.map { if (it.id == tenderId) it.copy(status = TenderStatus.FAIL) else it }) }
        sheet = null; say("QRIS ditandai gagal. Pilih metode lain.")
    }

    private fun record(t: Tender) { updateActive { it.copy(tenders = it.tenders + t) }; emit("${t.method.label} ${rp(t.amount)}", "payment.recorded"); checkClosed() }

    private fun checkClosed() {
        val o = active ?: return
        if (o.remaining > 0 || o.total == 0) return
        val no = "T01-" + (receiptSeq++).toString().padStart(6, '0')
        update(o.id) { cur ->
            // Order counter menembak baris yang belum terkirim otomatis saat lunas (KOT) — aturan prototipe.
            val t = clock()
            cur.copy(closed = true, receiptNo = no, closedAt = t, lines = cur.lines.map { if (!it.voided && !it.fired) it.copy(fired = true, firedAt = t) else it })
        }
        closedCount++
        emit("$no · ${rp(o.total)}", "order.closed"); printed("Struk $no")
        dest = Dest.DONE
    }

    fun reprint(orderId: String) {
        val o = orders.first { it.id == orderId }
        val free = 1
        val go = { update(orderId) { it.copy(reprints = it.reprints + 1) }; sheet = Sheet.Receipt(orderId) }
        if (o.reprints >= free && user?.isSupervisor != true) sheet = Sheet.Override("Cetak ulang struk ${o.receiptNo} (salinan ke-${o.reprints + 1})", null, go) else go()
    }

    fun refund(orderId: String) {
        if (conn != Conn.ONLINE) { say("Refund tidak tersedia saat offline."); return }
        val go = { update(orderId) { it.copy(refunded = true) }; sheet = null; say("Refund dicatat.") }
        if (user?.isSupervisor == true) go() else sheet = Sheet.Override("Refund penuh order", null, go)
    }

    fun approve(pin: String, approver: StaffUser): String? {
        val s = sheet as? Sheet.Override ?: return null
        if (pin == "000000") return "PIN salah. Sisa 4 percobaan."
        val limit = PosCatalog.discountLimit[approver.code] ?: 0
        if (s.minDiscount != null && limit < s.minDiscount) return "Batas diskon ${approver.name} hanya $limit%."
        sheet = null; s.onApproved(); return null
    }

    // ---------- kas & shift ----------
    data class CashMovement(val inflow: Boolean, val reason: String, val amount: Int, val at: String)
    data class CloseResult(val expected: Int, val counted: Int, val edcSystem: Int, val edcBatch: Int) {
        val variance get() = counted - expected
        val withinTolerance get() = kotlin.math.abs(variance) <= PosCatalog.VARIANCE_TOLERANCE
    }

    fun cashMove(inflow: Boolean, reason: String, amount: Int) {
        val go = { cashMovements = cashMovements + CashMovement(inflow, reason, amount, clock()); sheet = null; say("${if (inflow) "Kas masuk" else "Kas keluar"} ${rp(amount)} dicatat.") }
        if (!inflow && amount > PosCatalog.CASH_OUT_THRESHOLD && user?.isSupervisor != true) sheet = Sheet.Override("Kas keluar ${rp(amount)} · $reason", null, go) else go()
    }

    val salesOrders: List<Order> get() = orders.filter { it.closed && it.cancelledReason == null }
    fun cashSales() = salesOrders.sumOf { o -> o.tenders.filter { it.method == TenderMethod.CASH && it.status == TenderStatus.OK }.sumOf { it.amount } }
    fun edcSales() = salesOrders.sumOf { o -> o.tenders.filter { it.method == TenderMethod.EDC && it.status == TenderStatus.OK }.sumOf { it.amount } }
    fun qrisSales() = salesOrders.sumOf { o -> o.tenders.filter { it.method == TenderMethod.QRIS && it.status == TenderStatus.OK }.sumOf { it.amount } }
    fun cashIn() = cashMovements.filter { it.inflow }.sumOf { it.amount }
    fun cashOut() = cashMovements.filter { !it.inflow }.sumOf { it.amount }
    fun expectedCash() = (shift?.float ?: 0) + cashSales() + cashIn() - cashOut()
    fun hasPendingPayment() = orders.any { !it.closed && it.hasPendingQris }

    fun submitCount(counted: Int, edcBatch: Int) {
        closeResult = CloseResult(expectedCash(), counted, edcSales(), edcBatch)
        dest = Dest.CLOSE_RESULT; sheet = null
    }

    fun finishShift() { shift = null; closeResult = null; cashMovements = emptyList(); lock() }

    /**
     * HANYA untuk pembandingan visual dengan design/screens (dipanggil dari MainActivity bila build debuggable lewat
     * `--es preset NN`). Menyusun kondisi awal tiap tangkapan desain; bukan jalur produk.
     */
    fun debugPreset(id: String) {
        val rina = PosCatalog.users[0]
        user = if (id in setOf("08x")) PosCatalog.users[2] else rina
        shift = Shift("Shift 1", "07:02", "07:00-15:00", 200_000, rina.id)
        conn = Conn.ONLINE; darkOverride = null; sheet = null; ordersTab = 0; toast = null
        fun l(i: Int, item: String, qty: Int, vararg m: ModOption, note: String = "", fired: Boolean = false): OrderLine {
            val p = PosCatalog.product(item)
            return OrderLine(i, p.id, p.name, p.price, qty, m.toList(), note, fired = fired, firedAt = if (fired) "10:40" else null)
        }
        fun m(n: String, d: Int = 0) = ModOption(n, d)
        val lines = listOf(l(11, "k3", 2, m("Kurang manis"), m("Sedikit es")), l(12, "m1", 1, m("Pedas"), m("Telur", 4000), note = "Telur setengah matang"), l(13, "k1", 1))
        val counter = Order("c24", OrderKind.COUNTER, null, 0, "A-024", "Rina", "10:38", lines)
        val paid = counter.copy(id = "c24p", closed = true, receiptNo = "T01-000124", closedAt = "10:44", tenders = listOf(Tender(1, TenderMethod.CASH, 66_000, TenderStatus.OK, received = 100_000)))
        val closed1 = Order("z1", OrderKind.DINE_IN, "5", 4, null, "Andi", "09:20", listOf(l(21, "m3", 2, m("Pedas"), fired = true)), closed = true, receiptNo = "T01-000122", closedAt = "09:55", tenders = listOf(Tender(2, TenderMethod.EDC, 48_400, TenderStatus.OK, ref = "448231")))
        val closed2 = Order("z2", OrderKind.DINE_IN, "1", 2, null, "Andi", "08:30", listOf(l(22, "k3", 1, fired = true), l(23, "m4", 1, fired = true)), closed = true, receiptNo = "T01-000123", closedAt = "08:50", tenders = listOf(Tender(3, TenderMethod.CASH, 36_300, TenderStatus.OK, received = 40_000)))
        val seeds = seedOrders().map { if (it.id == "a2") it.copy(otherDevice = "T02") else it }
        orders = seeds + counter + paid + closed1 + closed2
        activeId = "c24"; dest = Dest.EDITOR
        when (id) {
            "05" -> orders = orders.map { if (it.id == "c24") it.copy(lines = emptyList()) else it }
            "06" -> sheet = Sheet.Modifier(PosCatalog.product("m1"))
            "07" -> sheet = Sheet.LineActions(12)
            "08" -> sheet = Sheet.OrderActions
            "09" -> sheet = Sheet.Split
            "10" -> sheet = Sheet.Discount
            "11" -> { activeId = "a1"; sheet = Sheet.Void(1) }
            "12" -> { activeId = "a1"; sheet = Sheet.Override("Void 1× Nasi Goreng Kampung · Rp 22.000 · Alasan: Salah input · belum dibuat") {} }
            "13" -> dest = Dest.TENDER
            "14" -> { dest = Dest.TENDER; sheet = Sheet.Edc }
            "15" -> { dest = Dest.TENDER; sheet = Sheet.QrisRecord }
            "16" -> { dest = Dest.TENDER; orders = orders.map { if (it.id == "c24") it.copy(tenders = listOf(Tender(9, TenderMethod.QRIS, 66_000, TenderStatus.PENDING))) else it }; sheet = Sheet.QrisVerify(9) }
            "17" -> { activeId = "c24p"; dest = Dest.DONE }
            "18" -> { activeId = "c24p"; dest = Dest.DONE; orders = orders.map { if (it.id == "c24p") it.copy(reprints = 1) else it }; sheet = Sheet.Receipt("c24p") }
            "19" -> dest = Dest.ORDERS
            "20" -> { dest = Dest.ORDERS; ordersTab = 1 }
            "21" -> dest = Dest.SHIFT
            "22" -> { dest = Dest.SHIFT; sheet = Sheet.CashOut }
            "23" -> dest = Dest.XREPORT
            "24" -> dest = Dest.CLOSE_SHIFT
            "25" -> { closeResult = CloseResult(362_900, 359_900, 85_800, 85_800); dest = Dest.CLOSE_RESULT }
            "26" -> { conn = Conn.OFFLINE; dest = Dest.SYNC }
            "27" -> darkOverride = true
            "28" -> conn = Conn.OFFLINE_LIMITED
            "03" -> dest = Dest.TABLES
            "35", "36" -> {}
        }
    }

    private fun clock(): String = SimpleDateFormat("HH:mm", Locale.US).format(Date())

    private fun seedOrders(): List<Order> {
        fun l(id: Int, item: String, qty: Int, vararg mods: ModOption): OrderLine {
            val p = PosCatalog.product(item)
            return OrderLine(id, p.id, p.name, p.price, qty, mods.toList(), fired = true, firedAt = "10:12")
        }
        fun m(n: String, d: Int = 0) = ModOption(n, d)
        // Angka mengikuti desain 03: Meja 3 = Rp 37.400 (2 tamu), Meja 5 = Rp 52.800 (4 tamu).
        return listOf(
            Order("a1", OrderKind.DINE_IN, "3", 2, null, "Andi", "18:42", listOf(l(1, "m3", 1, m("Sedang")), l(2, "n2", 1))),
            Order("a2", OrderKind.DINE_IN, "5", 4, null, "Andi", "18:55", listOf(l(3, "m3", 2, m("Pedas")), l(4, "e1", 1))),
            Order("a3", OrderKind.DINE_IN, "T2", 3, null, "Andi", "18:30", listOf(l(5, "m5", 3, m("Sedang")), l(6, "s2", 1)), billPrinted = true),
        )
    }
}
