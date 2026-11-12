package com.boothpos.customer.feature.orders.presentation

import androidx.compose.material3.SnackbarHostState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.getUnclippedBoundsInRoot
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithTag
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.unit.dp
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.feature.orders.domain.OrderDetail
import com.boothpos.customer.feature.orders.domain.OrderEventType
import com.boothpos.customer.feature.orders.domain.OrderStatus
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.SAFE_TRACKING_URL
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.TRACKING_NUMBER
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.detail
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.event
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.shipment
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.shippedDetail
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/** Detail pesanan stateless di ponsel kecil (360 x 640 dp), tanpa Hilt, jaringan, atau Intent. */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class OrderDetailScreenTest {

    @get:Rule
    val rule = createComposeRule()

    private var state by mutableStateOf<OrderDetailUiState>(OrderDetailUiState.Loading)
    private val snackbarHostState = SnackbarHostState()
    private var refreshes = 0
    private var signIns = 0
    private var backs = 0
    private val copied = mutableListOf<String>()
    private var trackClicks = 0
    private var handoffConfirms = 0
    private var handoffDismissals = 0

    private fun show(initial: OrderDetailUiState) {
        state = initial
        rule.setContent {
            BoothTheme {
                OrderDetailScreen(
                    state = state,
                    snackbarHostState = snackbarHostState,
                    onRefresh = { refreshes++ },
                    onSignIn = { signIns++ },
                    onBackToOrders = { backs++ },
                    onCopyTrackingNumber = { copied += it },
                    onTrackParcel = { trackClicks++ },
                    onConfirmHandoff = { handoffConfirms++ },
                    onDismissHandoff = { handoffDismissals++ },
                    dateFormatter = OrdersTestData.dates,
                )
            }
        }
        rule.waitForIdle()
    }

    private fun content(order: OrderDetail, updatedByStore: Boolean = true) =
        OrderDetailUiState.Content(order = order, updatedByStore = updatedByStore)

    private fun SemanticsNodeInteraction.top() = getUnclippedBoundsInRoot().top

    // --- status lebih dulu ---

    @Test
    fun `status sekarang tampil paling atas dengan penjelasan lalu nomor pesanan dan barang`() {
        show(content(shippedDetail()))

        val status = rule.onNodeWithTag(OrdersTestTags.DETAIL_STATUS)
        status.assertIsDisplayed()
        rule.onNodeWithText("Dikirim").assertIsDisplayed()
        rule.onNodeWithContentDescription("Status pesanan: Dikirim").assertIsDisplayed()
        rule.onNodeWithText("Pesanan Anda sudah diserahkan ke kurir. Rincian pengiriman ada di bawah.").assertIsDisplayed()
        // Waktu pembaruan status, bukan warna saja, menyertai status.
        rule.onNodeWithText("Diperbarui 12 Nov 2026, 10.00").assertIsDisplayed()

        val orderNumber = rule.onNodeWithTag(OrdersTestTags.DETAIL_ORDER_NUMBER)
        orderNumber.assert(hasText("Nomor pesanan")).assert(hasText(OrdersTestData.ORDER_NUMBER))
        val items = rule.onNodeWithText("Barang pesanan")
        val tracking = rule.onNodeWithTag(OrdersTestTags.TRACKING_SECTION)
        val timeline = rule.onNodeWithText("Riwayat pesanan")

        assertTrue("Status di atas nomor pesanan", status.top() < orderNumber.top())
        assertTrue("Nomor pesanan di atas barang", orderNumber.top() < items.top())
        assertTrue("Barang di atas pelacakan", items.top() < tracking.top())
        assertTrue("Pelacakan di atas riwayat", tracking.top() < timeline.top())
    }

    @Test
    fun `setiap status punya nama dan penjelasan sehari-hari`() {
        val explanations = mapOf(
            OrderStatus.AwaitingConfirmation to ("Menunggu konfirmasi" to "Pesanan Anda sudah masuk dan sedang menunggu konfirmasi toko."),
            OrderStatus.Confirmed to ("Dikonfirmasi" to "Toko sudah mengonfirmasi pesanan Anda dan akan segera menyiapkannya."),
            OrderStatus.Packing to ("Sedang dikemas" to "Toko sedang menyiapkan dan mengemas barang pesanan Anda."),
            OrderStatus.ReadyForPickup to ("Siap diambil" to "Pesanan Anda siap diambil. Ikuti petunjuk pengambilan di bawah."),
            OrderStatus.Shipped to ("Dikirim" to "Pesanan Anda sudah diserahkan ke kurir. Rincian pengiriman ada di bawah."),
            OrderStatus.Delivered to ("Sudah sampai" to "Toko mencatat pesanan Anda sudah sampai di alamat tujuan."),
            OrderStatus.PickedUp to ("Sudah diambil" to "Pesanan Anda sudah diambil. Terima kasih sudah berbelanja."),
            OrderStatus.Cancelled to ("Dibatalkan" to "Pesanan ini dibatalkan dan tidak akan diproses."),
        )
        show(OrderDetailUiState.Loading)

        explanations.forEach { (status, texts) ->
            state = content(detail(status = status, timeline = emptyList()))
            rule.waitForIdle()

            rule.onNodeWithContentDescription("Status pesanan: ${texts.first}").assertIsDisplayed()
            rule.onNodeWithText(texts.second).assertIsDisplayed()
        }
    }

    @Test
    fun `status yang belum dikenal tampil netral tanpa merusak layar`() {
        show(
            content(
                detail(
                    status = OrderStatus.Unknown,
                    method = null,
                    instructions = "Petunjuk umum dari toko.",
                    timeline = listOf(
                        event(OrderStatus.Unknown, 1),
                        event(OrderStatus.Confirmed, 0, OrderEventType.Unknown),
                    ),
                ),
            ),
        )

        rule.onNodeWithContentDescription("Status pesanan: Status diperbarui").assertIsDisplayed()
        rule.onNodeWithText(
            "Status pesanan ini belum dikenali oleh versi aplikasi ini. Perbarui aplikasi untuk melihat rinciannya.",
        ).assertIsDisplayed()
        // Metode yang belum dikenal: barisnya tidak ditampilkan, judul petunjuk menjadi umum.
        rule.onAllNodesWithText("Metode").assertCountEquals(0)
        rule.onNodeWithText("Petunjuk dari toko").performScrollTo().assertIsDisplayed()
        val events = rule.onAllNodesWithTag(OrdersTestTags.TIMELINE_EVENT)
        events.assertCountEquals(2)
        events[0].assert(hasText("Status pesanan diperbarui"))
        events[1].assert(hasText("Pesanan diperbarui"))
    }

    @Test
    fun `pesanan dibatalkan menampilkan alasan tepat di bawah status`() {
        show(content(detail(status = OrderStatus.Cancelled, cancelReason = "Stok habis di toko.")))

        rule.onNodeWithContentDescription("Status pesanan: Dibatalkan").assertIsDisplayed()
        rule.onNodeWithText("Alasan pembatalan").assertIsDisplayed()
        rule.onNodeWithText("Stok habis di toko.").assertIsDisplayed()
    }

    // --- isi pesanan ---

    @Test
    fun `barang petunjuk kontak dan biaya pesanan ambil di toko`() {
        show(content(detail(notes = "Tolong dibungkus rapi.")))

        rule.onNodeWithText("Ambil di toko").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Gantungan Kunci Uji — Kecil").performScrollTo()
            .assert(hasText("2 × Rp15.000"))
            .assert(hasText("Rp30.000"))
        rule.onNodeWithText("Stiker Uji — A5").performScrollTo().assert(hasText("1 × Rp25.000"))
        rule.onNodeWithText("Petunjuk pengambilan").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Ambil di meja depan, tunjukkan nomor pesanan.").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Kontak pesanan").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Pelanggan Uji").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("pelanggan.uji@example.test").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Catatan Anda").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Tolong dibungkus rapi.").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Subtotal").performScrollTo().assert(hasText("Rp55.000"))
        rule.onNodeWithTag(OrdersTestTags.DETAIL_TOTAL).performScrollTo().assert(hasText("Total")).assert(hasText("Rp55.000"))
        // Ambil di toko: tidak ada alamat kirim dan tidak ada baris ongkos kirim.
        rule.onAllNodesWithText("Alamat pengiriman").assertCountEquals(0)
        rule.onAllNodesWithTag(OrdersTestTags.DETAIL_SHIPPING_COST).assertCountEquals(0)
    }

    @Test
    fun `pesanan kurir menampilkan alamat petunjuk pengiriman dan ongkos kirim`() {
        show(content(shippedDetail()))

        rule.onNodeWithText("Dikirim kurir").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Petunjuk pengiriman").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Pesanan dikirim pada hari kerja.").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Alamat pengiriman").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Penerima Uji").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Jl. Contoh No. 1").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Kota Uji, Provinsi Uji, 00000").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Catatan alamat: Pagar hijau").performScrollTo().assertIsDisplayed()
        rule.onNodeWithTag(OrdersTestTags.DETAIL_SHIPPING_COST).performScrollTo()
            .assert(hasText("Ongkos kirim"))
            .assert(hasText("Rp12.000"))
        rule.onNodeWithTag(OrdersTestTags.DETAIL_TOTAL).performScrollTo().assert(hasText("Rp67.000"))
    }

    @Test
    fun `ongkos kirim yang belum ditetapkan tampil sebagai dikonfirmasi toko bukan nol rupiah`() {
        show(
            content(
                detail(
                    method = FulfilmentMethod.Courier,
                    shippingCost = "0.00",
                    shippingCostPending = true,
                    address = OrdersTestData.address,
                ),
            ),
        )

        rule.onNodeWithTag(OrdersTestTags.DETAIL_SHIPPING_COST).performScrollTo()
            .assert(hasText("Ongkos kirim"))
            .assert(hasText("Dikonfirmasi toko"))
        rule.onNodeWithText(
            "Ongkos kirim ditetapkan toko saat pesanan dikonfirmasi. Total di bawah belum termasuk ongkos kirim.",
            substring = true,
        ).performScrollTo().assertIsDisplayed()
        rule.onAllNodesWithText("Rp0").assertCountEquals(0)
    }

    // --- pelacakan ---

    @Test
    fun `pelacakan menyatakan diperbarui oleh toko dan menampilkan kurir layanan serta nomor resi`() {
        show(content(shippedDetail()))

        rule.onNodeWithText("Pelacakan").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Diperbarui oleh toko").performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Status dan informasi pengiriman dicatat oleh staf toko, bukan langsung dari kurir.")
            .performScrollTo().assertIsDisplayed()
        rule.onNodeWithText("Kurir").performScrollTo().assert(hasText("JNE"))
        rule.onNodeWithText("Layanan").performScrollTo().assert(hasText("REG"))
        rule.onNodeWithText(TRACKING_NUMBER).performScrollTo().assertIsDisplayed().assert(hasText("Nomor resi"))
        rule.onNodeWithText("Diserahkan ke kurir").performScrollTo().assert(hasText("12 Nov 2026, 13.00"))
        // Belum sampai: baris "Sampai di tujuan" tidak ada.
        rule.onAllNodesWithText("Sampai di tujuan").assertCountEquals(0)
    }

    @Test
    fun `tanpa tanda dari server label diperbarui oleh toko tidak ditampilkan`() {
        show(content(shippedDetail(), updatedByStore = false))

        rule.onNodeWithText("Pelacakan").performScrollTo().assertIsDisplayed()
        rule.onAllNodesWithText("Diperbarui oleh toko").assertCountEquals(0)
    }

    @Test
    fun `salin nomor resi menyerahkan nomor itu dan tombolnya cukup besar`() {
        show(content(shippedDetail()))

        rule.onNodeWithContentDescription("Salin nomor resi")
            .performScrollTo()
            .assertHasClickAction()
            .assertHeightIsAtLeast(48.dp)
            .performClick()

        assertEquals(listOf(TRACKING_NUMBER), copied)
    }

    @Test
    fun `nomor resi yang belum ada tidak ditampilkan dan tidak dapat disalin`() {
        show(content(shippedDetail(shipment(trackingNumber = null, serviceName = null))))

        rule.onNodeWithText("Nomor resi").performScrollTo().assert(hasText("Belum ada nomor resi"))
        rule.onAllNodesWithText(TRACKING_NUMBER).assertCountEquals(0)
        rule.onAllNodesWithText("Salin").assertCountEquals(0)
        rule.onAllNodesWithText("Layanan").assertCountEquals(0)
        // Kurir tetap tampil.
        rule.onNodeWithText("Kurir").performScrollTo().assert(hasText("JNE"))
    }

    @Test
    fun `pesanan kurir yang belum dikirim menjelaskan kapan informasi pengiriman muncul`() {
        show(content(detail(status = OrderStatus.Packing, method = FulfilmentMethod.Courier, address = OrdersTestData.address)))

        rule.onNodeWithText("Informasi pengiriman tampil di sini setelah toko menyerahkan pesanan ke kurir.")
            .performScrollTo().assertIsDisplayed()
        rule.onAllNodesWithText("Nomor resi").assertCountEquals(0)
        rule.onAllNodesWithText("Lacak paket").assertCountEquals(0)
    }

    @Test
    fun `pesanan ambil di toko tidak menampilkan blok pengiriman`() {
        show(content(detail(status = OrderStatus.ReadyForPickup)))

        rule.onNodeWithText("Diperbarui oleh toko").performScrollTo().assertIsDisplayed()
        rule.onAllNodesWithText("Kurir").assertCountEquals(0)
        rule.onAllNodesWithText("Nomor resi").assertCountEquals(0)
        rule.onAllNodesWithText("Informasi pengiriman tampil di sini setelah toko menyerahkan pesanan ke kurir.").assertCountEquals(0)
        rule.onAllNodesWithText("Lacak paket").assertCountEquals(0)
    }

    @Test
    fun `sampai di tujuan menampilkan waktunya`() {
        show(
            content(
                shippedDetail(shipment(deliveredAt = OrdersTestData.createdAt.plusSeconds(26 * 3600)))
                    .let { it.copy(summary = it.summary.copy(status = OrderStatus.Delivered)) },
            ),
        )

        rule.onNodeWithText("Sampai di tujuan").performScrollTo().assert(hasText("13 Nov 2026, 12.00"))
    }

    // --- Lacak paket ---

    @Test
    fun `Lacak paket tersembunyi tanpa tautan https yang aman`() {
        val unsafe = listOf(null, "http://kurir.example.test/lacak?resi=$TRACKING_NUMBER", "javascript:alert(1)", "https://", "bukan tautan")
        show(OrderDetailUiState.Loading)

        unsafe.forEach { url ->
            state = content(shippedDetail(shipment(trackingUrl = url)))
            rule.waitForIdle()

            rule.onAllNodesWithText("Lacak paket").assertCountEquals(0)
            // Nomor resi tetap tampil dan dapat disalin.
            rule.onNodeWithText(TRACKING_NUMBER).performScrollTo().assertIsDisplayed()
        }
    }

    @Test
    fun `Lacak paket tampil dengan tautan https yang aman dan hanya meminta pemberitahuan`() {
        show(content(shippedDetail(shipment(trackingUrl = SAFE_TRACKING_URL))))

        rule.onNodeWithText("Lacak paket")
            .performScrollTo()
            .assertIsDisplayed()
            .assertHasClickAction()
            .assertHeightIsAtLeast(48.dp)
            .performClick()

        assertEquals(1, trackClicks)
        // Layar stateless tidak membuka apa pun sendiri, dan tautannya tidak pernah ditampilkan sebagai teks.
        assertEquals(0, handoffConfirms)
        rule.onAllNodesWithText(SAFE_TRACKING_URL, substring = true).assertCountEquals(0)
    }

    @Test
    fun `pemberitahuan serah terima menjelaskan bahwa situs kurir dibuka di luar aplikasi`() {
        show(content(shippedDetail(shipment(trackingUrl = SAFE_TRACKING_URL))).copy(confirmingHandoff = true))

        rule.onNodeWithText("Buka situs kurir?").assertIsDisplayed()
        rule.onNodeWithText("Anda akan membuka situs kurir di luar aplikasi.").assertIsDisplayed()

        rule.onNodeWithText("Batal").performClick()
        assertEquals(1, handoffDismissals)
        assertEquals(0, handoffConfirms)

        rule.onNodeWithText("Buka situs kurir").performClick()
        assertEquals(1, handoffConfirms)
    }

    @Test
    fun `tanpa pemberitahuan terbuka dialog serah terima tidak ada`() {
        show(content(shippedDetail(shipment(trackingUrl = SAFE_TRACKING_URL))))

        rule.onAllNodesWithText("Anda akan membuka situs kurir di luar aplikasi.").assertCountEquals(0)
    }

    // --- riwayat ---

    @Test
    fun `riwayat tampil terbaru lebih dulu dengan kalimat polos catatan dan waktu`() {
        show(content(shippedDetail()))

        val events = rule.onAllNodesWithTag(OrdersTestTags.TIMELINE_EVENT)
        events.assertCountEquals(5)
        events[0].performScrollTo()
            .assert(hasText("Pesanan diserahkan ke kurir"))
            .assert(hasText("Paket diserahkan ke kurir sore ini."))
            .assert(hasText("12 Nov 2026, 13.00"))
        events[1].assert(hasText("Pesanan mulai dikemas")).assert(hasText("12 Nov 2026, 12.00"))
        // Peristiwa ongkos kirim memakai kalimatnya sendiri, bukan nama status.
        events[2].assert(hasText("Toko menetapkan ongkos kirim")).assert(hasText("12 Nov 2026, 11.00"))
        events[3].assert(hasText("Pesanan dikonfirmasi toko"))
        events[4].assert(hasText("Pesanan dibuat, menunggu konfirmasi toko")).assert(hasText("12 Nov 2026, 10.00"))

        // Urutan di layar sama dengan urutan data: yang terbaru paling atas.
        val tops = (0 until 5).map { events[it].getUnclippedBoundsInRoot().top }
        assertEquals(tops.sorted(), tops)
        assertEquals(5, tops.distinct().size)
    }

    @Test
    fun `pembaruan nomor resi dan peristiwa tanpa waktu diberi kalimat yang jelas`() {
        show(
            content(
                shippedDetail().copy(
                    timeline = listOf(
                        event(OrderStatus.Shipped, 4, OrderEventType.TrackingUpdated),
                        event(OrderStatus.Shipped, 3).copy(occurredAt = null),
                    ),
                ),
            ),
        )

        val events = rule.onAllNodesWithTag(OrdersTestTags.TIMELINE_EVENT)
        events[0].performScrollTo().assert(hasText("Toko memperbarui informasi pengiriman")).assert(hasText("12 Nov 2026, 14.00"))
        events[1].performScrollTo().assert(hasText("Pesanan diserahkan ke kurir")).assert(hasText("Waktu tidak tersedia"))
    }

    @Test
    fun `riwayat kosong dijelaskan dengan teks`() {
        show(content(detail(timeline = emptyList())))

        rule.onNodeWithText("Belum ada riwayat untuk pesanan ini.").performScrollTo().assertIsDisplayed()
        rule.onAllNodesWithTag(OrdersTestTags.TIMELINE_EVENT).assertCountEquals(0)
    }

    // --- penyegaran ---

    @Test
    fun `perbarui status memanggil penyegaran dan mati selama penyegaran berjalan`() {
        show(content(shippedDetail()))

        rule.onNodeWithText("Perbarui status")
            .performScrollTo()
            .assertIsEnabled()
            .assertHeightIsAtLeast(48.dp)
            .performClick()
        assertEquals(1, refreshes)

        state = content(shippedDetail()).copy(refreshing = true)
        rule.waitForIdle()
        rule.onNodeWithText("Perbarui status").performScrollTo().assertIsNotEnabled()
    }

    @Test
    fun `penyegaran gagal memberi tahu di atas rincian yang tetap tampil`() {
        show(content(shippedDetail()).copy(refreshError = ApiError.Network))
        rule.onNodeWithText("Anda sedang offline. Menampilkan data yang terakhir dimuat.").assertIsDisplayed()
        rule.onNodeWithContentDescription("Status pesanan: Dikirim").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").performClick()
        assertEquals(1, refreshes)

        state = content(shippedDetail()).copy(refreshError = ApiError.Http(500, ApiErrorCodes.SERVER_ERROR))
        rule.waitForIdle()
        rule.onNodeWithText("Data terbaru belum dapat dimuat. Yang tampil mungkin belum terbaru.").assertIsDisplayed()
    }

    // --- memuat, galat, tidak ditemukan, belum masuk ---

    @Test
    fun `keadaan memuat diumumkan dengan teks`() {
        show(OrderDetailUiState.Loading)

        rule.onNodeWithText("Memuat…").assertIsDisplayed()
    }

    @Test
    fun `pesanan tidak ditemukan menawarkan kembali ke daftar pesanan`() {
        show(OrderDetailUiState.NotFound)

        rule.onNodeWithText("Pesanan tidak ditemukan").assertIsDisplayed()
        rule.onNodeWithText("Pesanan ini tidak ada, atau tidak terdaftar di akun yang sedang masuk.").assertIsDisplayed()
        rule.onNodeWithText("Kembali ke daftar pesanan").assertHeightIsAtLeast(48.dp).performClick()
        assertEquals(1, backs)
        assertEquals(0, refreshes)
    }

    @Test
    fun `tanpa koneksi menampilkan keadaan offline dengan coba lagi`() {
        show(OrderDetailUiState.Error(ApiError.Timeout))

        rule.onNodeWithText("Tidak ada koneksi").assertIsDisplayed()
        rule.onNodeWithText("Koneksi terlalu lambat. Silakan coba lagi.").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").performClick()
        assertEquals(1, refreshes)
    }

    @Test
    fun `galat server menampilkan pesan umum dan coba lagi yang mati selama mengulang`() {
        show(OrderDetailUiState.Error(ApiError.Http(500, ApiErrorCodes.SERVER_ERROR, detail = "detail internal"), retrying = true))

        rule.onNodeWithText("Terjadi kesalahan").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").assertIsNotEnabled()
        rule.onAllNodesWithText("detail internal").assertCountEquals(0)
    }

    @Test
    fun `belum masuk atau sesi berakhir menampilkan ajakan masuk tanpa rincian pesanan`() {
        show(OrderDetailUiState.SignedOut(sessionExpired = true))

        rule.onNodeWithText("Sesi berakhir, silakan masuk lagi.").assertIsDisplayed()
        rule.onNodeWithText("Masuk untuk melihat pesanan ini").assertIsDisplayed()
        rule.onNodeWithText("Masuk").performClick()
        assertEquals(1, signIns)
        rule.onAllNodesWithTag(OrdersTestTags.DETAIL_STATUS).assertCountEquals(0)
        rule.onAllNodesWithText("Pelanggan Uji").assertCountEquals(0)
    }
}
