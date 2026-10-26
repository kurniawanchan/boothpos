package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * 050 / T002 — tiket dapur dari server (`TicketResource`): satu tiket per stasiun per ronde. Dipakai untuk mencetak
 * KOT. Semua field diberi nilai bawaan agar respons lama (tanpa `tickets`) tetap bisa dibaca. Tidak ada field harga:
 * dapur tidak menangani uang (FR-014).
 */
@Serializable data class TicketDto(
    val id: Long = 0,
    @SerialName("round_no") val roundNo: Int = 1,
    @SerialName("sent_at") val sentAt: String? = null,
    val station: TicketRefDto? = null,
    val order: TicketOrderDto? = null,
    val table: TicketRefDto? = null,
    @SerialName("tab_label") val tabLabel: String? = null,
    /** 078 — catatan kasir untuk seluruh order, dibaca langsung dari order (bukan salinan di tiket). */
    @SerialName("order_note") val orderNote: String? = null,
    val items: List<TicketItemDto> = emptyList(),
)

@Serializable data class TicketRefDto(val id: Long = 0, val name: String = "")

@Serializable data class TicketOrderDto(
    val id: Long = 0,
    @SerialName("order_number") val orderNumber: String = "",
)

@Serializable data class TicketItemDto(
    val id: Long = 0,
    val name: String = "",
    val qty: Int = 0,
    val modifiers: List<TicketModifierDto> = emptyList(),
    val note: String? = null,
    val status: String? = null,
    @SerialName("cancel_reason") val cancelReason: String? = null,
)

/** Pilihan modifier dari `ModifierSnapshot::forDisplay`. `price_delta` ada di respons, tetapi KOT tidak mencetaknya. */
@Serializable data class TicketModifierDto(
    val group: String = "",
    val option: String = "",
    @SerialName("price_delta") val priceDelta: String = "0.00",
)
