package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

// Bentuk respons server (TabResource, FloorQuery, OrderResource). Uang berupa string desimal; diubah di OrderMapper.

@Serializable data class TableRefDto(val id: Long, val name: String = "", val area: String? = null)

@Serializable data class ModifierDisplayDto(
    val group: String = "",
    val option: String = "",
    @SerialName("price_delta") val priceDelta: String = "0.00",
)

@Serializable data class TabLineDto(
    val id: Long,
    @SerialName("variant_id") val variantId: Long,
    val name: String = "",
    val qty: Int = 1,
    @SerialName("unit_price") val unitPrice: String = "0.00",
    @SerialName("line_total") val lineTotal: String = "0.00",
    val modifiers: List<ModifierDisplayDto> = emptyList(),
    val note: String? = null,
    val sent: Boolean = false,
    @SerialName("prep_status") val prepStatus: String? = null,
)

@Serializable data class TabPaymentDto(
    val id: Long,
    val method: String = "cash",
    val amount: String = "0.00",
    val verification: String = "verified",
)

@Serializable data class DeviceRefDto(val id: Long = 0, val code: String = "")

@Serializable data class TabDto(
    val id: Long,
    @SerialName("order_number") val orderNumber: String = "",
    val status: String = "open",
    @SerialName("service_type") val serviceType: String = "",
    val table: TableRefDto? = null,
    @SerialName("tab_label") val tabLabel: String? = null,
    @SerialName("opened_by") val openedBy: String? = null,
    @SerialName("session_id") val sessionId: Long? = null,
    val subtotal: String = "0.00",
    @SerialName("discount_amount") val discountAmount: String = "0.00",
    @SerialName("tax_amount") val taxAmount: String = "0.00",
    @SerialName("total_amount") val totalAmount: String = "0.00",
    @SerialName("paid_amount") val paidAmount: String = "0.00",
    @SerialName("change_amount") val changeAmount: String = "0.00",
    @SerialName("created_at") val createdAt: String? = null,
    val lines: List<TabLineDto> = emptyList(),
    val payments: List<TabPaymentDto> = emptyList(),
    @SerialName("queue_no") val queueNo: String? = null,
    @SerialName("receipt_no") val receiptNo: String? = null,
    @SerialName("guest_count") val guestCount: Int? = null,
    @SerialName("prebill_at") val prebillAt: String? = null,
    val device: DeviceRefDto? = null,
)

@Serializable data class SendResponseDto(val tab: TabDto)

@Serializable data class FloorTabDto(
    val id: Long,
    @SerialName("order_number") val orderNumber: String = "",
    @SerialName("total_amount") val totalAmount: String = "0.00",
    @SerialName("paid_amount") val paidAmount: String = "0.00",
    @SerialName("opened_at") val openedAt: String? = null,
    @SerialName("ready_count") val readyCount: Int = 0,
    @SerialName("unsent_count") val unsentCount: Int = 0,
    @SerialName("guest_count") val guestCount: Int? = null,
    @SerialName("bill_printed") val billPrinted: Boolean = false,
    @SerialName("device_code") val deviceCode: String? = null,
    @SerialName("queue_no") val queueNo: String? = null,
    @SerialName("tab_label") val tabLabel: String? = null,
)

@Serializable data class FloorTableDto(
    val id: Long,
    val name: String = "",
    val seats: Int = 2,
    @SerialName("is_active") val isActive: Boolean = true,
    val state: String = "free",
    val tab: FloorTabDto? = null,
)

@Serializable data class FloorAreaDto(val area: String = "", val tables: List<FloorTableDto> = emptyList())

@Serializable data class FloorDto(
    val version: String = "",
    val unchanged: Boolean = false,
    val areas: List<FloorAreaDto> = emptyList(),
    @SerialName("takeaway_tabs") val takeawayTabs: List<FloorTabDto> = emptyList(),
)

@Serializable data class OpenTabRequestDto(
    @SerialName("session_id") val sessionId: Long,
    @SerialName("table_id") val tableId: Long? = null,
    @SerialName("guest_count") val guestCount: Int? = null,
    @SerialName("local_ref") val localRef: String,
)

@Serializable data class AddLineRequestDto(
    @SerialName("variant_id") val variantId: Long,
    val qty: Int,
    val note: String? = null,
    @SerialName("modifier_option_ids") val modifierOptionIds: List<Long> = emptyList(),
)

@Serializable data class UpdateLineRequestDto(val qty: Int? = null, val note: String? = null)

/** Satu baris pembayaran. EDC: `approval_code` (6–12 huruf kapital/angka) + `confirm_duplicate` setelah konfirmasi kasir. */
@Serializable data class PaymentInputDto(
    val method: String,
    val amount: String,
    @SerialName("approval_code") val approvalCode: String? = null,
    @SerialName("confirm_duplicate") val confirmDuplicate: Boolean? = null,
    @SerialName("channel_id") val channelId: Long? = null,
)

@Serializable data class PayRequestDto(
    @SerialName("client_ref") val clientRef: String,
    val payments: List<PaymentInputDto>,
)

@Serializable data class ClosedOrderDto(
    val id: Long,
    @SerialName("order_number") val orderNumber: String = "",
    @SerialName("total_amount") val totalAmount: String = "0.00",
    @SerialName("paid_amount") val paidAmount: String = "0.00",
    @SerialName("change_amount") val changeAmount: String = "0.00",
    val status: String = "completed",
    @SerialName("void_reason") val voidReason: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
)

@Serializable data class ClosedOrdersPage(val data: List<ClosedOrderDto> = emptyList())
