package com.warkop.pos.data

import com.warkop.core.network.dto.TicketDto
import com.warkop.pos.model.PrintableItem
import com.warkop.pos.model.PrintableKot
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter

/**
 * 050 / T005 — DTO tiket dapur → data KOT siap cetak. Murni: tidak ada aritmetika dan tidak ada field harga.
 * Modifier ditampilkan sebagai "Grup: Pilihan"; `price_delta` sengaja dibuang (FR-014).
 */
object TicketMapper {
    private val HHMM = DateTimeFormatter.ofPattern("HH:mm")

    fun kot(dto: TicketDto): PrintableKot = PrintableKot(
        ticketId = dto.id,
        stationName = dto.station?.name?.takeIf { it.isNotBlank() },
        tableName = dto.table?.name?.takeIf { it.isNotBlank() },
        tabLabel = dto.tabLabel?.takeIf { it.isNotBlank() },
        orderNumber = dto.order?.orderNumber?.takeIf { it.isNotBlank() },
        roundNo = dto.roundNo,
        sentAtText = sentAt(dto.sentAt),
        orderNote = dto.orderNote?.trim()?.takeIf { it.isNotBlank() },
        items = dto.items.map { item ->
            PrintableItem(
                qty = item.qty,
                name = item.name,
                modifiers = item.modifiers.map { m -> if (m.group.isBlank()) m.option else "${m.group}: ${m.option}" },
                note = item.note?.takeIf { it.isNotBlank() },
            )
        },
    )

    private fun sentAt(iso: String?): String {
        if (iso == null) return "--:--"
        return runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(OrderMapper.STORE_ZONE).format(HHMM) }.getOrDefault("--:--")
    }
}
