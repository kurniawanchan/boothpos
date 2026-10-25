package com.warkop.pos.state

import java.time.Instant
import java.time.format.DateTimeParseException

/**
 * 073 — pesan kunci PIN dari `locked_until` milik server, bukan angka yang ditulis di aplikasi: lamanya kunci (kini 5 menit,
 * sebelumnya 15) ditentukan server. Menit dibulatkan ke atas supaya "kurang dari semenit" tetap tampil 1 menit.
 */
object PinLockMessage {
    fun text(lockedUntilIso: String?, nowMs: Long): String {
        val until = try { lockedUntilIso?.let { Instant.parse(it).toEpochMilli() } } catch (_: DateTimeParseException) { null }
        val remainingMs = until?.minus(nowMs)
        if (remainingMs == null || remainingMs <= 0) return "PIN terkunci karena salah 5 kali. Coba lagi sebentar lagi."
        val minutes = ((remainingMs + 59_999) / 60_000).toInt()
        return "PIN terkunci karena salah 5 kali. Coba lagi dalam $minutes menit."
    }
}
