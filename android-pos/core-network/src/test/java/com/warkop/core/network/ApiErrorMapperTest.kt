package com.warkop.core.network

import com.warkop.core.domain.ApiError
import org.junit.Assert.*
import org.junit.Test
import java.io.IOException

class ApiErrorMapperTest {
    @Test fun `401 dengan invalid_pin membawa sisa percobaan`() {
        val e = ApiErrorMapper.fromHttp(401, """{"message":"PIN salah.","code":"invalid_pin","attempts_remaining":4}""")
        assertTrue(e is ApiError.Unauthorized)
        assertEquals("invalid_pin", e.code)
        assertEquals(4, e.attemptsRemaining)
        assertEquals("PIN salah.", e.message)
    }

    @Test fun `403 menjadi Forbidden`() = assertTrue(ApiErrorMapper.fromHttp(403, """{"message":"x"}""") is ApiError.Forbidden)

    @Test fun `409 membawa kode mesin agar alur dapat dipilih`() {
        val e = ApiErrorMapper.fromHttp(409, """{"message":"Perlu persetujuan","code":"override_required"}""") as ApiError.Conflict
        assertEquals("override_required", e.code)
    }

    @Test fun `422 memuat galat per kolom`() {
        val e = ApiErrorMapper.fromHttp(422, """{"message":"Data tidak valid.","errors":{"pin":["PIN harus 6 digit"]}}""") as ApiError.Validation
        assertEquals(listOf("PIN harus 6 digit"), e.fields["pin"])
    }

    @Test fun `429 terkunci membawa waktu buka`() {
        val e = ApiErrorMapper.fromHttp(429, """{"message":"Terkunci","code":"pin_locked","locked_until":"2026-10-15T13:00:00+07:00"}""") as ApiError.TooMany
        assertEquals("pin_locked", e.code)
        assertEquals("2026-10-15T13:00:00+07:00", e.lockedUntil)
    }

    @Test fun `423 menjadi Locked lisensi`() = assertTrue(ApiErrorMapper.fromHttp(423, """{"message":"belum diaktivasi"}""") is ApiError.Locked)

    @Test fun `503 schema_outdated dibedakan dari 503 lain`() {
        assertTrue(ApiErrorMapper.fromHttp(503, """{"code":"schema_outdated","message":"perlu pembaruan"}""") is ApiError.SchemaOutdated)
        assertTrue(ApiErrorMapper.fromHttp(503, "<html>maintenance</html>") is ApiError.Server)
    }

    @Test fun `badan bukan JSON tidak pernah ditampilkan mentah`() {
        val e = ApiErrorMapper.fromHttp(500, "<html><body>SQLSTATE[42S22] Unknown column</body></html>")
        assertTrue(e is ApiError.Server)
        assertFalse(e.message.contains("SQLSTATE"))
    }

    @Test fun `IOException menjadi Network`() = assertTrue(ApiErrorMapper.fromThrowable(IOException("timeout")) is ApiError.Network)

    @Test fun `ApiError dilewatkan apa adanya`() {
        val original = ApiError.Forbidden("tidak boleh")
        assertSame(original, ApiErrorMapper.fromThrowable(original))
    }

    @Test fun `alamat server dinormalkan ke api v1 dengan skema`() {
        assertEquals("http://10.0.2.2:8010/api/v1/", ApiClient.normalise("10.0.2.2:8010"))
        assertEquals("http://10.0.2.2:8010/api/v1/", ApiClient.normalise("http://10.0.2.2:8010/"))
        assertEquals("https://pos.toko.id/api/v1/", ApiClient.normalise("https://pos.toko.id/api/v1"))
    }
}
