package com.boothpos.customer.core.data.contract

import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.prefs.StoreConfigCache
import com.boothpos.customer.core.data.remote.StoreConfigDto
import com.boothpos.customer.core.data.remote.StorefrontConfigApi
import com.boothpos.customer.core.data.store.StoreConfigRepositoryImpl
import com.boothpos.customer.core.data.testing.RealPayloads
import com.boothpos.customer.core.network.HttpClientFactory
import com.boothpos.customer.core.network.NetworkConfig
import com.boothpos.customer.core.network.NetworkJson
import com.boothpos.customer.core.network.RetrofitFactory
import com.boothpos.customer.core.network.UnauthorizedEvents
import com.boothpos.customer.core.network.dto.DataEnvelope
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import java.io.File
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import org.junit.runner.RunWith

/**
 * Kontrak §4 terhadap jawaban NYATA backend (`real/storefront_config_200`, rekaman #3), lewat
 * Retrofit + converter + `Json` milik :core:network dan repository yang dipakai aplikasi.
 */
@RunWith(AndroidJUnit4::class)
class RealStoreConfigContractTest {

    @get:Rule
    val folder = TemporaryFolder()

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

    @Before
    fun setUp() = server.start()

    @After
    fun tearDown() = server.close()

    private fun TestScope.repository(): StoreConfigRepositoryImpl {
        val config = NetworkConfig(server.url("/api/v1/").toString())
        val client = HttpClientFactory.create(config, { FAKE_TOKEN }, UnauthorizedEvents())
        val api = RetrofitFactory.create(config, json, client).create(StorefrontConfigApi::class.java)
        val dataStore = PreferenceDataStoreFactory.create(
            scope = backgroundScope,
            produceFile = { File(folder.root, "customer_prefs.preferences_pb") },
        )
        return StoreConfigRepositoryImpl(api, StoreConfigCache(dataStore, json), SafeApiCall(ProblemJsonParser(json)))
    }

    @Test
    fun `200 konfigurasi etalase nyata terbaca dan tersimpan untuk offline`() = runTest {
        val recorded = RealPayloads.recorded("storefront_config_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)
        val repository = repository()

        val config = (repository.refresh() as ApiResult.Success).value

        assertTrue(config.enabled)
        assertEquals("Toko Merchandise Dummy", config.storeName)
        assertEquals("IDR", config.currency)
        assertEquals(setOf(FulfilmentMethod.Pickup, FulfilmentMethod.Courier), config.fulfilmentMethods)
        // Server mengirim `null` eksplisit untuk kedua petunjuk (belum diisi pemilik toko).
        assertNull(config.pickupInstructions)
        assertNull(config.courierInstructions)
        assertEquals(20, config.maxQtyPerLine)
        assertEquals(50, config.maxLinesPerOrder)
        // Bukan nilai bawaan dari DTO: angka-angka di atas memang dari server.
        assertEquals(config, repository.config.first())

        val request = server.takeRequest()
        assertEquals("GET", request.method)
        assertEquals("/api/v1/storefront/config", request.url.encodedPath)
        // Endpoint publik: token pelanggan tidak pernah ikut.
        assertNull(request.headers["Authorization"])
    }

    @Test
    fun `setiap field rekaman konfigurasi dimodelkan DTO aplikasi (batas 20 dan 50 sama dengan nilai bawaan)`() {
        // Angka 20/50 dari server kebetulan sama dengan nilai bawaan DTO, jadi tes di atas tidak dapat
        // membedakan "terbaca" dari "jatuh ke bawaan"; mode ketat ini membuktikan namanya dikenali.
        strictDecode<DataEnvelope<StoreConfigDto>>("storefront_config_200")
    }

    private companion object {
        const val FAKE_TOKEN = "tes-token-palsu-123"
    }
}
