package com.warkop.manager.domain

import com.warkop.manager.data.dto.TicketDto
import java.time.Duration
import java.time.Instant
import java.time.OffsetDateTime

/**
 * 094 / T088 — aturan papan Dapur & Bar, cermin `TicketCard.vue`/`stores/kitchen.js` + `KitchenService::advance`.
 * Server penegak; di sini hanya menentukan tombol yang ditampilkan dan pengelompokan.
 */
object KitchenRules {
    val STATUS = mapOf("new" to "Baru", "preparing" to "Dimasak", "ready" to "Siap saji", "served" to "Diantar", "cancelled" to "Dibatalkan")
    val ACTION = mapOf("preparing" to "Mulai", "ready" to "Siap", "served" to "Sudah diantar")
    private val NEXT = mapOf("new" to "preparing", "preparing" to "ready", "ready" to "served")
    const val LATE_MINUTES = 15L

    /**
     * Langkah berikut untuk item ini yang BOLEH dilakukan pengguna, atau null (tombol disembunyikan).
     * new→preparing→ready butuh menu `kitchen`; ready→served butuh `kitchen` atau `floor`.
     */
    fun nextAllowed(status: String, menuKeys: Set<String>): String? {
        val next = NEXT[status] ?: return null
        val ok = if (next == "served") "kitchen" in menuKeys || "floor" in menuKeys else "kitchen" in menuKeys
        return next.takeIf { ok }
    }

    /** Aktif = masih ada item new/preparing/ready; sisanya "Baru selesai". */
    fun isActive(t: TicketDto) = t.items.any { it.status in setOf("new", "preparing", "ready") }

    fun waitingMinutes(t: TicketDto, now: Instant): Long? =
        t.sentAt?.let { runCatching { Duration.between(OffsetDateTime.parse(it).toInstant(), now).toMinutes().coerceAtLeast(0) }.getOrNull() }

    /** Terlambat: menunggu ≥ 15 menit DAN masih ada item new/preparing. */
    fun isLate(t: TicketDto, now: Instant) = (waitingMinutes(t, now) ?: 0) >= LATE_MINUTES && t.items.any { it.status == "new" || it.status == "preparing" }

    /** Tempat: "Meja X", label tab, atau "Takeaway". */
    fun place(t: TicketDto) = t.table?.let { "Meja ${it.name}" } ?: t.tabLabel?.takeIf { it.isNotBlank() } ?: "Takeaway"

    /** Jeda polling: 5 dtk; setelah gagal berlipat dua sampai 60 dtk (pola `SelfServicePoller`). */
    fun pollDelayMs(failures: Int): Long = minOf(5_000L shl minOf(failures, 5), 60_000L)
}
