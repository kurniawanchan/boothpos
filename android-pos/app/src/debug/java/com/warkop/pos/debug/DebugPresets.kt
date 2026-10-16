package com.warkop.pos.debug

import com.warkop.pos.model.*
import com.warkop.pos.state.*

/**
 * HANYA build debug: kondisi awal yang meniru tiap tangkapan design/screens untuk pembandingan visual
 * (`adb shell am start -n com.warkop.pos/.MainActivity --es preset 12`). Data di sini adalah DATA CONTOH dan sengaja
 * tidak ada di build rilis (src/release punya stub kosong) — FR-020.
 */
object DebugPresets {
    fun apply(store: PosStore, id: String) {
        val rina = PosCatalog.users[0]
        val shift = Shift("Shift 1", "07:02", "07:00-15:00", 200_000, rina.id)
        fun m(n: String, d: Int = 0) = ModOption(n, d)
        fun l(i: Int, item: String, qty: Int, vararg mods: ModOption, note: String = "", fired: Boolean = false): OrderLine {
            val p = PosCatalog.product(item)
            return OrderLine(i, p.id, p.name, p.price, qty, mods.toList(), note, fired = fired, firedAt = if (fired) "10:40" else null)
        }
        val seeds = listOf(
            Order("a1", OrderKind.DINE_IN, "3", 2, null, "Andi", "18:42", listOf(l(1, "m3", 1, m("Sedang"), fired = true), l(2, "n2", 1, fired = true))),
            Order("a2", OrderKind.DINE_IN, "5", 4, null, "Andi", "18:55", listOf(l(3, "m3", 2, m("Pedas"), fired = true), l(4, "e1", 1, fired = true)), otherDevice = "T02"),
            Order("a3", OrderKind.DINE_IN, "T2", 3, null, "Andi", "18:30", listOf(l(5, "m5", 3, m("Sedang"), fired = true), l(6, "s2", 1, fired = true)), billPrinted = true),
        )
        val lines = listOf(l(11, "k3", 2, m("Kurang manis"), m("Sedikit es")), l(12, "m1", 1, m("Pedas"), m("Telur", 4000), note = "Telur setengah matang"), l(13, "k1", 1))
        val counter = Order("c24", OrderKind.COUNTER, null, 0, "A-024", "Rina", "10:38", lines)
        val paid = counter.copy(id = "c24p", closed = true, receiptNo = "T01-000124", closedAt = "10:44", tenders = listOf(Tender(1, TenderMethod.CASH, 66_000, TenderStatus.OK, received = 100_000)))
        val closed1 = Order("z1", OrderKind.DINE_IN, "5", 4, null, "Andi", "09:20", listOf(l(21, "m3", 2, m("Pedas"), fired = true)), closed = true, receiptNo = "T01-000122", closedAt = "09:55", tenders = listOf(Tender(2, TenderMethod.EDC, 48_400, TenderStatus.OK, ref = "448231")))
        val closed2 = Order("z2", OrderKind.DINE_IN, "1", 2, null, "Andi", "08:30", listOf(l(22, "k3", 1, fired = true), l(23, "m4", 1, fired = true)), closed = true, receiptNo = "T01-000123", closedAt = "08:50", tenders = listOf(Tender(3, TenderMethod.CASH, 36_300, TenderStatus.OK, received = 40_000)))
        var orders = seeds + counter + paid + closed1 + closed2
        var active: String? = "c24"; var dest = Dest.EDITOR; var sheet: Sheet? = null; var conn = Conn.ONLINE
        var dark: Boolean? = null; var tab = 0; var close: CloseOutcome? = null
        when (id) {
            "05" -> orders = orders.map { if (it.id == "c24") it.copy(lines = emptyList()) else it }
            "06" -> sheet = Sheet.Modifier(PosCatalog.product("m1"))
            "07" -> sheet = Sheet.LineActions(12)
            "08" -> sheet = Sheet.OrderActions
            "09" -> sheet = Sheet.Split
            "10" -> sheet = Sheet.Discount
            "11" -> { active = "a1"; sheet = Sheet.Void(1) }
            "12" -> { active = "a1"; sheet = Sheet.Override("Void 1× Nasi Goreng Kampung · Rp 22.000 · Alasan: Salah input", "void_line", kotlinx.serialization.json.JsonObject(emptyMap())) {} }
            "13" -> dest = Dest.TENDER
            "14" -> { dest = Dest.TENDER; sheet = Sheet.Edc }
            "15" -> { dest = Dest.TENDER; sheet = Sheet.QrisRecord }
            "16" -> { dest = Dest.TENDER; orders = orders.map { if (it.id == "c24") it.copy(tenders = listOf(Tender(9, TenderMethod.QRIS, 66_000, TenderStatus.PENDING))) else it }; sheet = Sheet.QrisVerify(9) }
            "17" -> { active = "c24p"; dest = Dest.DONE }
            "18" -> { active = "c24p"; dest = Dest.DONE; orders = orders.map { if (it.id == "c24p") it.copy(reprints = 1) else it }; sheet = Sheet.Receipt("c24p") }
            "19" -> dest = Dest.ORDERS
            "20" -> { dest = Dest.ORDERS; tab = 1 }
            "21" -> dest = Dest.SHIFT
            "22" -> { dest = Dest.SHIFT; sheet = Sheet.CashOut(inflow = false) }
            "23" -> dest = Dest.XREPORT
            "24" -> dest = Dest.CLOSE_SHIFT
            "25" -> { close = CloseOutcome(362_900, 359_900, -3_000, 5_000, 85_800); dest = Dest.CLOSE_RESULT }
            "26" -> { conn = Conn.OFFLINE; dest = Dest.SYNC }
            "27" -> dark = true
            "28" -> conn = Conn.OFFLINE_LIMITED
            "03" -> dest = Dest.TABLES
        }
        store.debugInstall(rina, shift, orders, active, dest, sheet, conn, dark, tab, close)
    }
}
