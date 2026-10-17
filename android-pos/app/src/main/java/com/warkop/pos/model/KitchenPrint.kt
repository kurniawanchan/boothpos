package com.warkop.pos.model

/**
 * 050 / T004 — data KOT yang sudah siap dicetak. Sengaja tanpa field harga: KOT tidak memuat uang (FR-014).
 * Dibentuk dari respons server (tiket dapur), tidak dihitung ulang di klien.
 */
data class PrintableKot(
    val ticketId: Long,
    val stationName: String?,
    val tableName: String?,
    val tabLabel: String?,
    val orderNumber: String?,
    val roundNo: Int,
    val sentAtText: String,
    val items: List<PrintableItem>,
)

data class PrintableItem(
    val qty: Int,
    val name: String,
    val modifiers: List<String>,
    val note: String?,
)
