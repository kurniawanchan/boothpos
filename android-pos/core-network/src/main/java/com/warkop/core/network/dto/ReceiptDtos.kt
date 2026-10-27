package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * 048 / T038 — struk dari server (`GET /orders/{id}/receipt`). Semua uang berupa string "0.00"; tidak ada angka
 * yang dihitung ulang di klien. Field yang belum ada di server bernilai null/default.
 */
@Serializable data class ReceiptDto(
    @SerialName("store_name") val storeName: String? = null,
    @SerialName("store_address") val storeAddress: String? = null,
    /** 059 — kontak toko dari pengaturan admin (bukan pelanggan). Null/kosong = tidak dicetak. */
    @SerialName("store_contact_person") val storeContactPerson: String? = null,
    @SerialName("store_contact_phone") val storeContactPhone: String? = null,
    @SerialName("store_contact_email") val storeContactEmail: String? = null,
    /** 059 — URL logo; null bila admin mematikan logo di struk. Dapat berupa localhost (lihat ServerUrl). */
    @SerialName("store_logo_url") val storeLogoUrl: String? = null,
    @SerialName("order_number") val orderNumber: String = "",
    /** 078 — catatan kasir untuk SELURUH order (dicetak di struk di bawah item). Beda dari `items[].note`. */
    val notes: String? = null,
    @SerialName("receipt_no") val receiptNo: String? = null,
    @SerialName("queue_no") val queueNo: String? = null,
    /** 064 — nama terminal yang mengambil order; null bila order tanpa terminal. */
    @SerialName("terminal_name") val terminalName: String? = null,
    @SerialName("copy_no") val copyNo: Int? = null,
    @SerialName("cashier_name") val cashierName: String? = null,
    @SerialName("tax_amount") val taxAmount: String = "0.00",
    @SerialName("tax_rate") val taxRate: String = "0.00",
    @SerialName("tax_label") val taxLabel: String? = null,
    val subtotal: String = "0.00",
    @SerialName("discount_amount") val discountAmount: String = "0.00",
    @SerialName("total_amount") val totalAmount: String = "0.00",
    @SerialName("paid_amount") val paidAmount: String = "0.00",
    @SerialName("change_amount") val changeAmount: String = "0.00",
    @SerialName("payment_status") val paymentStatus: String? = null,
    val items: List<ReceiptItemDto> = emptyList(),
    @SerialName("payment_summary") val paymentSummary: List<ReceiptPaymentDto> = emptyList(),
)

@Serializable data class ReceiptItemDto(
    val name: String = "",
    val qty: Int = 0,
    val price: String = "0.00",
    @SerialName("line_total") val lineTotal: String = "0.00",
    val note: String? = null,
)

@Serializable data class ReceiptPaymentDto(
    val method: String = "",
    val amount: String = "0.00",
    @SerialName("paid_at") val paidAt: String? = null,
)
