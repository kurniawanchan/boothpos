package com.boothpos.customer.core.data.address

import com.boothpos.customer.core.data.domain.model.AddressInput
import com.boothpos.customer.core.data.remote.AddressApi
import com.boothpos.customer.core.network.HttpClientFactory
import com.boothpos.customer.core.network.NetworkConfig
import com.boothpos.customer.core.network.NetworkJson
import com.boothpos.customer.core.network.RetrofitFactory
import com.boothpos.customer.core.network.UnauthorizedEvents
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.core.network.error.errorOrNull
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.jsonObject
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

class AddressRepositoryImplTest {

    private val server = MockWebServer()
    private val json = NetworkJson.create()
    private lateinit var repository: AddressRepositoryImpl

    // Alamat contoh yang jelas-jelas palsu.
    private val addressJson = """{"id":5,"label":"Rumah","recipient_name":"Pelanggan Uji","phone":"0800-0000-0000",
        "address_line":"Jl. Contoh No. 1","city":"Kota Uji","province":"Provinsi Uji","postal_code":null,
        "notes":null,"is_default":true}"""

    private val input = AddressInput(
        label = " Rumah ",
        recipientName = " Pelanggan Uji ",
        phone = "0800-0000-0000",
        addressLine = "Jl. Contoh No. 1",
        city = "Kota Uji",
        province = "Provinsi Uji",
        postalCode = "  ",
        notes = "",
        isDefault = true,
    )

    @Before
    fun setUp() {
        server.start()
        val config = NetworkConfig(server.url("/api/v1/").toString())
        val client = HttpClientFactory.create(config, { "tes-token-palsu-123" }, UnauthorizedEvents())
        val api = RetrofitFactory.create(config, json, client).create(AddressApi::class.java)
        repository = AddressRepositoryImpl(api, SafeApiCall(ProblemJsonParser(json)))
    }

    @After
    fun tearDown() = server.close()

    @Test
    fun `daftar alamat dipetakan ke model domain dan membawa token`() = runTest {
        server.enqueue(MockResponse.Builder().body("""{"data":[$addressJson]}""").build())

        val address = (repository.list() as ApiResult.Success).value.single()

        assertEquals(5L, address.id)
        assertEquals("Pelanggan Uji", address.recipientName)
        assertNull(address.postalCode)
        assertTrue(address.isDefault)
        val request = server.takeRequest()
        assertEquals("GET", request.method)
        assertEquals("/api/v1/customer/addresses", request.url.encodedPath)
        assertEquals("Bearer tes-token-palsu-123", request.headers["Authorization"])
    }

    @Test
    fun `buat alamat mengirim field kontrak dan kolom opsional kosong dikirim sebagai null`() = runTest {
        server.enqueue(MockResponse.Builder().code(201).body("""{"data":$addressJson}""").build())

        val created = (repository.create(input) as ApiResult.Success).value

        assertEquals(5L, created.id)
        val request = server.takeRequest()
        assertEquals("POST", request.method)
        val body = json.parseToJsonElement(request.body!!.utf8()).jsonObject
        assertEquals(
            setOf(
                "label", "recipient_name", "phone", "address_line", "city", "province",
                "postal_code", "notes", "is_default",
            ),
            body.keys,
        )
        assertEquals("\"Rumah\"", body["label"].toString())
        assertEquals("\"Pelanggan Uji\"", body["recipient_name"].toString())
        // Null eksplisit: tanpa kunci ini, PUT tidak dapat mengosongkan kolom yang pernah terisi.
        assertEquals(JsonNull, body["postal_code"])
        assertEquals(JsonNull, body["notes"])
    }

    @Test
    fun `ubah dan hapus memakai id pada path`() = runTest {
        server.enqueue(MockResponse.Builder().body("""{"data":$addressJson}""").build())
        server.enqueue(MockResponse.Builder().code(204).build())

        repository.update(5, input.copy(isDefault = false))
        val deleted = repository.delete(5)

        val update = server.takeRequest()
        assertEquals("PUT", update.method)
        assertEquals("/api/v1/customer/addresses/5", update.url.encodedPath)
        val delete = server.takeRequest()
        assertEquals("DELETE", delete.method)
        assertEquals("/api/v1/customer/addresses/5", delete.url.encodedPath)
        assertEquals(ApiResult.Success(Unit), deleted)
    }

    @Test
    fun `batas jumlah alamat dan alamat milik akun lain terpetakan ke kode galat`() = runTest {
        server.enqueue(
            MockResponse.Builder().code(409)
                .body("""{"status":409,"code":"address_limit_reached","detail":"Maksimal 10 alamat."}""").build(),
        )
        server.enqueue(MockResponse.Builder().code(404).body("""{"status":404,"code":"not_found"}""").build())

        val limit = repository.create(input).errorOrNull() as ApiError.Http
        val foreign = repository.delete(999).errorOrNull() as ApiError.Http

        assertEquals(ApiErrorCodes.ADDRESS_LIMIT_REACHED, limit.code)
        assertEquals("Maksimal 10 alamat.", limit.detail)
        assertEquals(ApiErrorCodes.NOT_FOUND, foreign.code)
        assertFalse(foreign.fieldErrors.isNotEmpty())
    }
}
