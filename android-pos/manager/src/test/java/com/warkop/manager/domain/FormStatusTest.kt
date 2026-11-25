package com.warkop.manager.domain

import com.warkop.core.domain.ApiError
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class FormStatusTest {
    @Test fun `422 dipetakan per kolom dengan kunci server`() {
        val s = FormStatus.from(ApiError.Validation("x", mapOf("name" to listOf("Nama wajib diisi."), "links.0.max_select" to listOf("Maks 1."))))
        assertEquals("Nama wajib diisi.", s.error("name"))
        assertEquals("Maks 1.", s.errorUnder("links.0"))
        assertNull(s.message)
    }

    @Test fun `kunci terjemahan mentah tidak pernah ditampilkan`() {
        val s = FormStatus.from(ApiError.Validation("validation.required (and 1 more error)", mapOf("username" to listOf("validation.required"))))
        assertEquals("Wajib diisi.", s.error("username"))
    }

    @Test fun `409 dan 403 menjadi pesan umum dari server`() {
        assertEquals("Meja masih punya order terbuka.", FormStatus.from(ApiError.Conflict("Meja masih punya order terbuka.", "table_has_open_order")).message)
        assertEquals("Tidak berhak.", FormStatus.from(ApiError.Forbidden("Tidak berhak.")).message)
    }
}
