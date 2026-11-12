package com.boothpos.customer.core.ui.component

import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.ui.R
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class ApiErrorMessageTest {

    @Test
    fun `galat jaringan dan batas waktu punya pesan sendiri`() {
        assertEquals(R.string.core_ui_error_network, apiErrorMessageRes(ApiError.Network))
        assertEquals(R.string.core_ui_error_timeout, apiErrorMessageRes(ApiError.Timeout))
        assertEquals(R.string.core_ui_error_generic, apiErrorMessageRes(ApiError.Unknown))
    }

    @Test
    fun `galat server tidak pernah menampilkan detail mentah`() {
        val error = ApiError.Http(500, ApiErrorCodes.SERVER_ERROR, detail = "apa pun dari server")

        assertEquals(R.string.core_ui_error_generic, apiErrorMessageRes(error))
    }

    @Test
    fun `kode bisnis memakai detail server yang sudah dilokalkan`() {
        val error = ApiError.Http(409, ApiErrorCodes.ADDRESS_LIMIT_REACHED, detail = "Maksimal 10 alamat.")

        assertNull(apiErrorMessageRes(error))
    }

    @Test
    fun `kode bisnis tanpa detail jatuh ke pesan umum`() {
        assertEquals(
            R.string.core_ui_error_generic,
            apiErrorMessageRes(ApiError.Http(409, ApiErrorCodes.PRICE_CHANGED)),
        )
    }

    @Test
    fun `sesi yang gagal disimpan di perangkat punya pesan tenang sendiri`() {
        assertEquals(R.string.core_ui_error_session_storage, apiErrorMessageRes(ApiError.SessionStorage))
    }

    @Test
    fun `kode baru dari server dipetakan menurut status dan detailnya, bukan menurut kode`() {
        // 413: kode `payload_too_large` belum dikenal aplikasi; detail server (sudah dilokalkan) dipakai.
        assertNull(apiErrorMessageRes(ApiError.Http(413, "payload_too_large", detail = "Isi permintaan terlalu besar.")))
        // Tanpa detail (atau tanpa problem+json): pesan umum, tidak pernah kosong.
        assertEquals(R.string.core_ui_error_generic, apiErrorMessageRes(ApiError.Http(413, "payload_too_large")))
        assertEquals(R.string.core_ui_error_generic, apiErrorMessageRes(ApiError.Http(413, ApiErrorCodes.HTTP_ERROR)))
        // 423: parser tidak membawa detail-nya (urusan pemilik toko), jadi pesan umum.
        assertEquals(R.string.core_ui_error_generic, apiErrorMessageRes(ApiError.Http(423, "installation_locked")))
        assertEquals(R.string.core_ui_error_generic, apiErrorMessageRes(ApiError.Http(423, ApiErrorCodes.STORE_LOCKED)))
    }

    @Test
    fun `sesi berakhir dan terlalu banyak percobaan punya pesan baku`() {
        assertEquals(
            R.string.core_ui_error_unauthenticated,
            apiErrorMessageRes(ApiError.Http(401, ApiErrorCodes.UNAUTHENTICATED)),
        )
        assertEquals(
            R.string.core_ui_error_too_many_requests,
            apiErrorMessageRes(ApiError.Http(429, ApiErrorCodes.TOO_MANY_REQUESTS, detail = "x")),
        )
    }
}
