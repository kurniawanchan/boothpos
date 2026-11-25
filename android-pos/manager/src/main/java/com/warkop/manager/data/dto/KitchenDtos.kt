package com.warkop.manager.data.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** 094 / T087 — DTO Dapur & Bar (`TicketResource`, `KitchenController::tickets`). */

@Serializable data class TicketModifierDto(val label: String? = null, val option: String? = null)

@Serializable data class TicketItemDto(
    val id: Long,
    val name: String = "",
    @Serializable(with = FlexIntSerializer::class) val qty: Int? = 1,
    val modifiers: List<TicketModifierDto> = emptyList(),
    val note: String? = null,
    val status: String = "new",
    @SerialName("cancel_reason") val cancelReason: String? = null,
)

@Serializable data class TicketOrderRef(val id: Long? = null, @SerialName("order_number") val orderNumber: String? = null)

@Serializable data class TicketDto(
    val id: Long,
    @SerialName("round_no") val roundNo: Int = 1,
    @SerialName("sent_at") val sentAt: String? = null,
    val order: TicketOrderRef? = null,
    val table: IdName? = null,
    @SerialName("tab_label") val tabLabel: String? = null,
    @SerialName("service_type") val serviceType: String? = null,
    @SerialName("self_service") val selfService: Boolean = false,
    @SerialName("order_note") val orderNote: String? = null,
    val items: List<TicketItemDto> = emptyList(),
)

/** `unchanged: true` = versi sama, `tickets` tidak dikirim (selalu HTTP 200, tidak pernah 304). */
@Serializable data class TicketFeedDto(val tickets: List<TicketDto>? = null, val version: String? = null, val unchanged: Boolean = false)

@Serializable data class TicketStatusBody(val status: String)
