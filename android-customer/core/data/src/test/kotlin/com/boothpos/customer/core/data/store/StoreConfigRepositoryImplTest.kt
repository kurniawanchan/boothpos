package com.boothpos.customer.core.data.store

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.emptyPreferences
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.prefs.StoreConfigCache
import com.boothpos.customer.core.data.remote.StorefrontConfigApi
import com.boothpos.customer.core.network.HttpClientFactory
import com.boothpos.customer.core.network.NetworkConfig
import com.boothpos.customer.core.network.NetworkJson
import com.boothpos.customer.core.network.RetrofitFactory
import com.boothpos.customer.core.network.UnauthorizedEvents
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.core.network.error.errorOrNull
import com.boothpos.customer.core.network.error.isStoreUnavailable
import java.io.File
import java.io.IOException
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.runTest
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import org.junit.runner.RunWith

// Robolectric: DataStore versi Android dijalankan pada runtime Android tiruan, bukan JVM polos.
@RunWith(AndroidJUnit4::class)
class StoreConfigRepositoryImplTest {

    @get:Rule
    val folder = TemporaryFolder()

    private val server = MockWebServer()
    private val json = NetworkJson.create()

    @Before
    fun setUp() = server.start()

    @After
    fun tearDown() = server.close()

    private fun TestScope.repository(): StoreConfigRepositoryImpl {
        val config = NetworkConfig(server.url("/api/v1/").toString())
        val client = HttpClientFactory.create(config, { "tes-token-palsu-123" }, UnauthorizedEvents())
        val api = RetrofitFactory.create(config, json, client).create(StorefrontConfigApi::class.java)
        val dataStore = PreferenceDataStoreFactory.create(
            scope = backgroundScope,
            produceFile = { File(folder.root, "customer_prefs.preferences_pb") },
        )
        return StoreConfigRepositoryImpl(api, StoreConfigCache(dataStore, json), SafeApiCall(ProblemJsonParser(json)))
    }

    /** DataStore yang menolak menulis (disk penuh, berkas rusak); membaca selalu kosong. */
    private class FailingWriteDataStore(private val failure: Throwable) : DataStore<Preferences> {
        override val data: Flow<Preferences> = flowOf(emptyPreferences())

        override suspend fun updateData(transform: suspend (t: Preferences) -> Preferences): Preferences = throw failure
    }

    private fun repositoryWith(dataStore: DataStore<Preferences>): StoreConfigRepositoryImpl {
        val config = NetworkConfig(server.url("/api/v1/").toString())
        val client = HttpClientFactory.create(config, { "tes-token-palsu-123" }, UnauthorizedEvents())
        val api = RetrofitFactory.create(config, json, client).create(StorefrontConfigApi::class.java)
        return StoreConfigRepositoryImpl(api, StoreConfigCache(dataStore, json), SafeApiCall(ProblemJsonParser(json)))
    }

    @Test
    fun `refresh memetakan konfigurasi dan menyimpannya untuk dipakai offline`() = runTest {
        server.enqueue(
            MockResponse.Builder().body(
                """{"data":{"enabled":true,"store_name":"Toko Uji","currency":"IDR",
                   "fulfilment_methods":["pickup","courier","drone"],"pickup_instructions":"Ambil di meja depan.",
                   "courier_instructions":null,"max_qty_per_line":20,"max_lines_per_order":50,"field_baru":1}}""",
            ).build(),
        )
        val repository = repository()
        assertNull(repository.config.first())

        val config = (repository.refresh() as ApiResult.Success).value

        assertTrue(config.enabled)
        assertEquals("Toko Uji", config.storeName)
        assertEquals(setOf(FulfilmentMethod.Pickup, FulfilmentMethod.Courier), config.fulfilmentMethods)
        assertEquals("Ambil di meja depan.", config.pickupInstructions)
        assertNull(config.courierInstructions)
        assertEquals(20, config.maxQtyPerLine)
        assertEquals(50, config.maxLinesPerOrder)
        assertEquals(config, repository.config.first())

        val request = server.takeRequest()
        assertEquals("/api/v1/storefront/config", request.url.encodedPath)
        assertNull("Endpoint publik tidak membawa token", request.headers["Authorization"])
    }

    @Test
    fun `toko dimatikan terbaca dari flag enabled`() = runTest {
        server.enqueue(MockResponse.Builder().body("""{"data":{"enabled":false,"store_name":"Toko Uji"}}""").build())

        val config = (repository().refresh() as ApiResult.Success).value

        assertFalse(config.enabled)
        assertEquals(20, config.maxQtyPerLine)
    }

    @Test
    fun `423 gerbang lisensi adalah toko tidak tersedia dan konfigurasi tersimpan tidak hilang`() = runTest {
        server.enqueue(MockResponse.Builder().body("""{"data":{"enabled":true,"store_name":"Toko Uji"}}""").build())
        server.enqueue(MockResponse.Builder().code(423).body("""{"message":"Belum diaktivasi.","activated":false}""").build())
        val repository = repository()
        repository.refresh()

        val error = repository.refresh().errorOrNull()!!

        assertTrue(error.isStoreUnavailable)
        assertEquals("Toko Uji", repository.config.first()?.storeName)
    }

    @Test
    fun `cache yang gagal ditulis tidak menggagalkan refresh dan konfigurasi tetap dikembalikan`() = runTest {
        server.enqueue(MockResponse.Builder().body("""{"data":{"enabled":true,"store_name":"Toko Uji"}}""").build())
        val repository = repositoryWith(FailingWriteDataStore(IOException("disk penuh")))

        val result = repository.refresh()

        val config = (result as ApiResult.Success).value
        assertTrue(config.enabled)
        assertEquals("Toko Uji", config.storeName)
        assertNull("Salinan offline memang tidak tersimpan", repository.config.first())
    }

    @Test
    fun `pengecualian non-IO dari cache juga tidak menjatuhkan refresh`() = runTest {
        server.enqueue(MockResponse.Builder().body("""{"data":{"enabled":false,"store_name":"Toko Uji"}}""").build())
        val repository = repositoryWith(FailingWriteDataStore(IllegalStateException("berkas rusak")))

        assertFalse(((repository.refresh()) as ApiResult.Success).value.enabled)
    }

    @Test
    fun `pembatalan saat menulis cache tetap diteruskan`() = runTest {
        server.enqueue(MockResponse.Builder().body("""{"data":{"enabled":true,"store_name":"Toko Uji"}}""").build())
        val repository = repositoryWith(FailingWriteDataStore(CancellationException("dibatalkan")))

        try {
            repository.refresh()
            fail("CancellationException harus diteruskan, bukan ditelan")
        } catch (_: CancellationException) {
            // diharapkan
        }
    }

    @Test
    fun `galat jaringan tetap menjadi galat dan tidak menyentuh cache`() = runTest {
        server.enqueue(MockResponse.Builder().code(500).body("{}").build())
        val repository = repositoryWith(FailingWriteDataStore(IOException("tidak boleh dipanggil")))

        assertTrue(repository.refresh() is ApiResult.Failure)
    }
}
