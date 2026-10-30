package com.warkop.pos.ui

import com.warkop.pos.data.TaxDisplay
import com.warkop.pos.model.Order
import com.warkop.pos.model.TenderStatus
import com.warkop.pos.model.rp

/** Satu baris uang pada detail pesanan; [big] = baris Total. */
data class MoneyLine(val label: String, val value: String, val big: Boolean = false)

/**
 * 084 — rincian uang LENGKAP sebuah pesanan (Selesai maupun Terbuka) untuk ditinjau ulang: subtotal, diskon, pajak, total,
 * pembayaran per metode, dan kembalian. Angka dari server ([Order.gross]/[Order.tax]/[Order.total], tidak dihitung ulang).
 * Baris pajak mengikuti aturan yang sama dengan layar Kasir: tampil bila `tax_show` tidak dimatikan DAN pesanan memang berpajak.
 */
fun orderMoneyLines(o: Order, tax: TaxDisplay?): List<MoneyLine> = buildList {
    add(MoneyLine("Subtotal", rp(o.gross)))
    if (o.discount > 0) add(MoneyLine("Diskon", "− ${rp(o.discount)}"))
    if (tax?.show != false && o.tax > 0) add(MoneyLine(taxLabelText(tax), rp(o.tax)))
    add(MoneyLine("Total", rp(o.total), big = true))
    o.tenders.filter { it.status == TenderStatus.OK }.forEach { add(MoneyLine(it.method.label, rp(it.amount))) }
    val change = o.serverChange ?: 0
    if (change > 0) add(MoneyLine("Kembalian", rp(change)))
}

/** Nama baris pajak dari server ("PPN 11%"); tarif ditampilkan hanya bila server memberinya. */
fun taxLabelText(t: TaxDisplay?): String {
    if (t == null) return "Pajak"
    val rate = t.ratePercent ?: return t.label
    return "${t.label} ${if (rate % 1.0 == 0.0) rate.toLong().toString() else rate.toString()}%"
}
