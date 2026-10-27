package com.warkop.pos.data

import com.warkop.core.domain.Money
import com.warkop.core.network.dto.ReceiptDto
import com.warkop.pos.model.ReceiptLine
import com.warkop.pos.model.ReceiptView

/**
 * DTO struk server → model layar (T038). Tidak ada perhitungan uang di sini: angka server disimpan apa adanya
 * (dibulatkan ke rupiah utuh untuk tampilan). Tanggal bayar diambil dari waktu pembayaran server, bukan tanggal tetap.
 */
object ReceiptMapper {
    /** [serverBase] = alamat server yang dipakai tablet; dipakai untuk memasang ulang URL logo (059). */
    fun map(dto: ReceiptDto, serverBase: String? = null): ReceiptView = ReceiptView(
        storeName = dto.storeName?.takeIf { it.isNotBlank() } ?: "Toko",
        storeAddress = dto.storeAddress?.takeIf { it.isNotBlank() },
        storeContactPerson = dto.storeContactPerson?.trim()?.takeIf { it.isNotBlank() },
        storeContactPhone = dto.storeContactPhone?.trim()?.takeIf { it.isNotBlank() },
        storeContactEmail = dto.storeContactEmail?.trim()?.takeIf { it.isNotBlank() },
        storeLogoUrl = ServerUrl.resolve(serverBase, dto.storeLogoUrl),
        orderNumber = dto.orderNumber,
        orderNote = dto.notes?.trim()?.takeIf { it.isNotBlank() },
        receiptNo = dto.receiptNo?.takeIf { it.isNotBlank() },
        queueNo = dto.queueNo?.takeIf { it.isNotBlank() },
        terminalName = dto.terminalName?.trim()?.takeIf { it.isNotBlank() },
        copyNo = dto.copyNo ?: 1,
        cashier = dto.cashierName?.takeIf { it.isNotBlank() },
        paidAtText = OrderMapper.receiptDateTime(dto.paymentSummary.map { it.paidAt }),
        taxLabel = dto.taxLabel?.takeIf { it.isNotBlank() } ?: "PPN",
        taxRatePercent = percentText(dto.taxRate),
        subtotal = rp(dto.subtotal),
        discount = rp(dto.discountAmount),
        tax = rp(dto.taxAmount),
        total = rp(dto.totalAmount),
        paid = rp(dto.paidAmount),
        change = rp(dto.changeAmount),
        items = dto.items.map { ReceiptLine(it.name, it.qty, rp(it.lineTotal), it.note?.takeIf { n -> n.isNotBlank() }) },
        payments = dto.paymentSummary.map { methodLabel(it.method) to rp(it.amount) },
    )

    private fun rp(value: String): Int = Money.parse(value).toInt()

    /** "10.00" → "10"; "0.00" → "0". Hanya untuk label persen. */
    private fun percentText(value: String): String =
        value.toBigDecimalOrNull()?.stripTrailingZeros()?.toPlainString() ?: "0"

    fun methodLabel(method: String): String = when (method) {
        "cash" -> "Tunai"
        "edc" -> "EDC"
        "qr_ewallet" -> "QRIS"
        "bank_transfer" -> "Transfer bank"
        else -> method
    }
}
