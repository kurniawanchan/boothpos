package com.boothpos.customer.core.data.domain.time

import java.time.Instant
import java.time.OffsetDateTime
import java.time.format.DateTimeParseException

/** Cap waktu API selalu ISO-8601 dengan offset (`2026-11-11T10:00:00+07:00`). */
object IsoTime {
    fun parse(raw: String?): Instant? {
        if (raw.isNullOrBlank()) return null
        return try {
            OffsetDateTime.parse(raw.trim()).toInstant()
        } catch (_: DateTimeParseException) {
            null
        }
    }
}
