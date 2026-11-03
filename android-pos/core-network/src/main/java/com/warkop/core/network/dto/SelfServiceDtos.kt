package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** 090 / US8 — GET /self-service/alerts. Kunci tak dikenal diabaikan (ignoreUnknownKeys), jadi server boleh menambah. */
@Serializable
data class SelfServiceAlertDto(
    val id: Long,
    @SerialName("table_name") val tableName: String = "",
    @SerialName("reference_no") val referenceNo: String = "",
    @SerialName("total_amount") val totalAmount: String = "0.00",
)

@Serializable
data class SelfServiceAlertsDto(
    val items: List<SelfServiceAlertDto> = emptyList(),
    @SerialName("waiting_count") val waitingCount: Int = 0,
    @SerialName("last_id") val lastId: Long = 0,
)

/** 090 — tab Mandiri di Pesanan: satu pesanan mandiri untuk staf (daftar ramping; `lines` hanya ada di detail). */
@Serializable
data class SelfServiceOrderDto(
    val id: Long,
    @SerialName("reference_no") val referenceNo: String = "",
    val status: String = "",
    @SerialName("created_at") val createdAt: String? = null,
    val table: SelfServiceTableDto = SelfServiceTableDto(),
    @SerialName("service_type") val serviceType: String = "dine_in",
    @SerialName("item_count") val itemCount: Int = 0,
    @SerialName("total_amount") val totalAmount: String = "0.00",
    val customer: SelfServiceCustomerDto? = null,
    val payment: SelfServicePaymentDto = SelfServicePaymentDto(),
    val rejection: SelfServiceRejectionDto? = null,
    val order: SelfServiceLinkedOrderDto? = null,
    val lines: List<SelfServiceLineDto> = emptyList(),
)

@Serializable data class SelfServiceTableDto(val id: Long? = null, val name: String = "")
@Serializable data class SelfServiceCustomerDto(val name: String? = null)
@Serializable data class SelfServicePaymentDto(val status: String = "", val reference: String? = null, @SerialName("proof_id") val proofId: Long? = null)
@Serializable data class SelfServiceRejectionDto(val reason: String? = null)
@Serializable data class SelfServiceLinkedOrderDto(@SerialName("order_number") val orderNumber: String? = null)
@Serializable
data class SelfServiceLineDto(
    val name: String = "", val qty: Int = 1,
    @SerialName("line_total") val lineTotal: String = "0.00",
    val note: String? = null,
    val modifiers: List<SelfServiceModifierDto> = emptyList(),
)
@Serializable data class SelfServiceModifierDto(val option: String? = null, val qty: Int = 1)

@Serializable data class SelfServiceCountsDto(val pending: Int = 0)
@Serializable data class SelfServiceMetaDto(val counts: SelfServiceCountsDto = SelfServiceCountsDto())
@Serializable data class SelfServiceOrdersPageDto(val data: List<SelfServiceOrderDto> = emptyList(), val meta: SelfServiceMetaDto = SelfServiceMetaDto())
@Serializable data class SelfServiceOrderEnvelopeDto(val data: SelfServiceOrderDto)

@Serializable data class VerifySelfServiceRequestDto(val reference: String)
@Serializable data class RejectSelfServiceRequestDto(val reason: String)
