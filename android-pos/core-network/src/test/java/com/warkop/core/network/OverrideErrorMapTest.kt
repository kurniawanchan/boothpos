package com.warkop.core.network

import com.warkop.core.domain.ApiError
import org.junit.Assert.*
import org.junit.Test

/** 048 / US4 — galat persetujuan dipetakan ke teks Indonesia di satu tempat (ApiErrorMapper.localMessage). */
class OverrideErrorMapTest {
    @Test fun `PIN salah membawa sisa percobaan dan teks Indonesia`() {
        val e = ApiErrorMapper.fromHttp(401, """{"message":"Invalid PIN.","code":"invalid_pin","attempts_remaining":2}""")
        assertTrue(e is ApiError.Unauthorized)
        assertEquals("PIN salah.", e.message)
        assertEquals("invalid_pin", e.code)
        assertEquals(2, e.attemptsRemaining)
    }

    @Test fun `PIN terkunci menjadi TooMany dengan waktu buka`() {
        val e = ApiErrorMapper.fromHttp(429, """{"message":"Locked.","code":"pin_locked","locked_until":"2026-10-16T10:15:00+07:00"}""")
        assertTrue(e is ApiError.TooMany)
        assertTrue(e.message, e.message.contains("terkunci"))
        assertEquals("2026-10-16T10:15:00+07:00", (e as ApiError.TooMany).lockedUntil)
    }

    @Test fun `penyetuju tidak berwenang menjadi Forbidden Indonesia`() {
        val e = ApiErrorMapper.fromHttp(403, """{"message":"Forbidden.","code":"approver_not_permitted"}""")
        assertTrue(e is ApiError.Forbidden)
        assertEquals("Penyetuju ini tidak berwenang menyetujui aksi ini.", e.message)
    }

    @Test fun `penyetuju sama dengan peminta menjadi Conflict`() {
        val e = ApiErrorMapper.fromHttp(409, """{"message":"Same.","code":"same_person"}""")
        assertTrue(e is ApiError.Conflict)
        assertEquals("same_person", e.code)
        assertEquals("Penyetuju tidak boleh orang yang sama dengan peminta.", e.message)
    }

    @Test fun `persetujuan kedaluwarsa menjadi Conflict`() {
        val e = ApiErrorMapper.fromHttp(409, """{"message":"Expired.","code":"override_expired"}""")
        assertEquals("Persetujuan sudah kedaluwarsa. Minta persetujuan ulang.", e.message)
    }

    @Test fun `data berubah sejak disetujui menjadi Validation`() {
        val e = ApiErrorMapper.fromHttp(422, """{"message":"Mismatch.","code":"override_mismatch"}""")
        assertTrue(e is ApiError.Validation)
        assertEquals("Data aksi berubah sejak disetujui. Minta persetujuan ulang.", e.message)
    }

    @Test fun `422 tanpa kode tetap memakai pesan server`() {
        val e = ApiErrorMapper.fromHttp(422, """{"message":"Field tidak valid."}""")
        assertEquals("Field tidak valid.", e.message)
    }

    @Test fun `kode yang tidak dikenal tidak diterjemahkan`() {
        assertNull(localMessage("tidak_ada_kode"))
        assertNull(localMessage(null))
    }
}
