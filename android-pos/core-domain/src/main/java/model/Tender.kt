package com.warkop.core.domain.model

/**
 * Represents a payment tender (cash, card, QRIS, etc.).
 * Placeholder implementation with basic fields.
 */

data class Tender(
    val id: String = "",
    val type: TenderType = TenderType.CASH,
    val amount: Double = 0.0
)

enum class TenderType {
    CASH,
    CARD,
    QRIS,
    EDC
}
