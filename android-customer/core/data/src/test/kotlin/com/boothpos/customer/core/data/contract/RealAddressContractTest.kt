package com.boothpos.customer.core.data.contract

import com.boothpos.customer.core.data.address.AddressRepositoryImpl
import com.boothpos.customer.core.data.domain.model.AddressInput
import com.boothpos.customer.core.data.remote.AddressApi
import com.boothpos.customer.core.data.remote.AddressDto
import com.boothpos.customer.core.data.remote.SessionApi
import com.boothpos.customer.core.data.session.ApiTokenRevoker
import com.boothpos.customer.core.data.testing.RealPayloads
import com.boothpos.customer.core.network.HttpClientFactory
import com.boothpos.customer.core.network.NetworkConfig
import com.boothpos.customer.core.network.NetworkJson
import com.boothpos.customer.core.network.RetrofitFactory
import com.boothpos.customer.core.network.UnauthorizedEvents
import com.boothpos.customer.core.network.dto.DataEnvelope
import com.boothpos.customer.core.network.dto.ProblemDto
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.core.network.error.errorOrNull
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Kontrak §7 (alamat) dan §5 (logout) terhadap jawaban NYATA backend, lewat Retrofit + converter +
 * `Json` milik :core:network dan repository yang dipakai aplikasi. Berkas ada di
 * `src/test/resources/real` (lihat README di sana). Nomor telepon dan e-mail sudah disamarkan.
 *
 * Tidak pernah direkam: PUT alamat milik sendiri (200) dan DELETE alamat milik sendiri (204).
 * Bentuknya diturunkan dari kode PHP (`AddressController::update` mengembalikan
 * `CustomerAddressResource` yang sama dengan `store`, `destroy` mengembalikan `noContent()`),
 * jadi tesnya memakai ulang badan rekaman #27 dan header 204 rekaman logout #70, bukan badan karangan.
 */
class RealAddressContractTest {

    private val server = MockWebServer()
    private val json = NetworkJson.create()

    // Diagnostik, bukan jalur aplikasi: `Json` aplikasi yang sama, hanya `ignoreUnknownKeys` dimatikan,
    // supaya field rekaman yang namanya tidak cocok dengan DTO (lalu diam-diam jatuh ke nilai bawaan)
    // ketahuan. Dipakai hanya oleh tes "tidak ada field rekaman yang diabaikan".
    private val strict = Json(from = json) { ignoreUnknownKeys = false }

    private inline fun <reified T> strictDecode(name: String, derived: Boolean = false): T {
        val body = (if (derived) RealPayloads.derived(name) else RealPayloads.recorded(name)).body
        return strict.decodeFromString(body)
    }
    private val safeApiCall = SafeApiCall(ProblemJsonParser(json))
    private lateinit var addressApi: AddressApi
    private lateinit var sessionApi: SessionApi
    private lateinit var repository: AddressRepositoryImpl

    // Isi formulir yang menghasilkan PERSIS permintaan rekaman #27.
    private val homeInput = AddressInput(
        label = "Rumah",
        recipientName = "Pelanggan Bruno A",
        phone = "0800****0011",
        addressLine = "Jl. Contoh Uji No. 1",
        city = "Bandung",
        province = "Jawa Barat",
        postalCode = "40111",
        notes = "Pagar hijau",
        isDefault = true,
    )

    @Before
    fun setUp() {
        server.start()
        val config = NetworkConfig(server.url("/api/v1/").toString())
        val client = HttpClientFactory.create(config, { FAKE_TOKEN }, UnauthorizedEvents())
        val retrofit = RetrofitFactory.create(config, json, client)
        addressApi = retrofit.create(AddressApi::class.java)
        sessionApi = retrofit.create(SessionApi::class.java)
        repository = AddressRepositoryImpl(addressApi, safeApiCall)
    }

    @After
    fun tearDown() = server.close()

    private fun notFound(requestId: String) = ApiError.Http(
        status = 404,
        code = "not_found",
        detail = "Data yang Anda cari tidak ditemukan.",
        requestId = requestId,
    )

    // --- 200 daftar ---

    @Test
    fun `200 daftar alamat nyata terbaca penuh dan permintaan membawa token`() = runTest {
        val recorded = RealPayloads.recorded("address_list_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)

        val address = (repository.list() as ApiResult.Success).value.single()

        assertEquals(1L, address.id)
        assertEquals("Rumah", address.label)
        assertEquals("Pelanggan Bruno A", address.recipientName)
        assertEquals("0800****0011", address.phone)
        assertEquals("Jl. Contoh Uji No. 1", address.addressLine)
        assertEquals("Bandung", address.city)
        assertEquals("Jawa Barat", address.province)
        assertEquals("40111", address.postalCode)
        assertEquals("Pagar hijau", address.notes)
        assertTrue(address.isDefault)

        val request = server.takeRequest()
        assertEquals("GET", request.method)
        assertEquals("/api/v1/customer/addresses", request.url.encodedPath)
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])
    }

    // --- 201 buat ---

    @Test
    fun `201 buat alamat - badan nyata terbaca dan permintaan aplikasi sama dengan rekaman`() = runTest {
        val recorded = RealPayloads.recorded("address_create_201")
        assertEquals(201, recorded.status)
        server.enqueueRecorded(recorded)

        val address = (repository.create(homeInput) as ApiResult.Success).value

        assertEquals(1L, address.id)
        assertEquals("Rumah", address.label)
        assertEquals("Pagar hijau", address.notes)
        assertTrue(address.isDefault)

        val request = server.takeRequest()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/customer/addresses", request.url.encodedPath)
        // Kunci dan nilai yang dikirim aplikasi = permintaan yang benar-benar diterima backend (#27).
        assertEquals(
            json.parseToJsonElement(RealPayloads.recordedRequest("address_create_201_request")),
            json.parseToJsonElement(request.body!!.utf8()),
        )
    }

    @Test
    fun `201 buat alamat dengan notes null - kolom opsional kosong terbaca null`() = runTest {
        val recorded = RealPayloads.recorded("address_create_notes_null_201")
        assertEquals(201, recorded.status)
        server.enqueueRecorded(recorded)

        val address = (repository.create(homeInput.copy(label = "Kantor", notes = null)) as ApiResult.Success).value

        assertEquals(2L, address.id)
        assertEquals("Kantor", address.label)
        assertEquals("Surabaya", address.city)
        assertEquals("60111", address.postalCode)
        assertNull(address.notes)
        assertTrue(address.isDefault)
    }

    // --- 200 ubah (tidak pernah direkam; badan #27 dipakai ulang, lihat KDoc kelas) ---

    @Test
    fun `200 ubah alamat memakai bentuk CustomerAddressResource yang sama`() = runTest {
        server.enqueueRecorded(RealPayloads.recorded("address_create_201"), status = 200)

        val address = (repository.update(1, homeInput) as ApiResult.Success).value

        assertEquals(1L, address.id)
        assertEquals("Bandung", address.city)
        val request = server.takeRequest()
        assertEquals("PUT", request.method)
        assertEquals("/api/v1/customer/addresses/1", request.url.encodedPath)
    }

    // --- 404 milik akun lain ---

    @Test
    fun `404 ubah alamat milik akun lain - not_found tanpa bocoran`() = runTest {
        val recorded = RealPayloads.recorded("address_update_other_owner_404")
        assertEquals(404, recorded.status)
        server.enqueueRecorded(recorded)

        val error = repository.update(2, homeInput).errorOrNull()

        assertEquals(notFound("701bcd0a-7093-43f9-a6bd-6267d52b1f64"), error)
    }

    @Test
    fun `404 hapus alamat milik akun lain - not_found`() = runTest {
        val recorded = RealPayloads.recorded("address_delete_other_owner_404")
        assertEquals(404, recorded.status)
        server.enqueueRecorded(recorded)

        val error = repository.delete(2).errorOrNull()

        assertEquals(notFound("99afc944-aedc-4a1e-8025-8652fc6bac0e"), error)
        assertEquals("DELETE", server.takeRequest().method)
    }

    // --- 204 (bentuk rekaman logout #70 dipakai untuk hapus alamat: sama-sama `noContent()`) ---

    @Test
    fun `204 hapus alamat sendiri tanpa body dianggap berhasil`() = runTest {
        val recorded = RealPayloads.recorded("logout_204")
        assertEquals(204, recorded.status)
        assertEquals("", recorded.body)
        server.enqueueRecorded(recorded)

        assertEquals(ApiResult.Success(Unit), repository.delete(1))

        val request = server.takeRequest()
        assertEquals("DELETE", request.method)
        assertEquals("/api/v1/customer/addresses/1", request.url.encodedPath)
    }

    // --- logout 204 (#70) ---

    @Test
    fun `204 logout nyata - token dikirim eksplisit dan jawaban tanpa body berhasil`() = runTest {
        val recorded = RealPayloads.recorded("logout_204")
        assertEquals(204, recorded.status)
        assertEquals("", recorded.body)
        server.enqueueRecorded(recorded)

        val result = safeApiCall.unit { sessionApi.logout("Bearer $FAKE_TOKEN") }
        assertEquals(ApiResult.Success(Unit), result)

        // Jalur yang dipakai aplikasi saat keluar (upaya-terbaik, tidak pernah melempar).
        server.enqueueRecorded(recorded)
        ApiTokenRevoker({ sessionApi }, safeApiCall).revoke(FAKE_TOKEN)

        assertEquals(2, server.requestCount)
        val request = server.takeRequest()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/customer/auth/logout", request.url.encodedPath)
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])
    }

    // --- tidak ada field rekaman yang diabaikan diam-diam ---

    @Test
    fun `setiap field rekaman alamat dan galatnya dimodelkan DTO aplikasi`() {
        strictDecode<DataEnvelope<List<AddressDto>>>("address_list_200")
        strictDecode<DataEnvelope<AddressDto>>("address_create_201")
        strictDecode<DataEnvelope<AddressDto>>("address_create_notes_null_201")
        strictDecode<ProblemDto>("address_update_other_owner_404")
        strictDecode<ProblemDto>("address_delete_other_owner_404")
    }

    private companion object {
        const val FAKE_TOKEN = "tes-token-palsu-123"
    }
}
