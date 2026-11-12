package com.boothpos.customer.feature.orders.presentation

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithTag
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollToIndex
import androidx.compose.ui.unit.dp
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.feature.orders.domain.OrderStatus
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.number
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.summaries
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.summary
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/** Daftar pesanan stateless di ponsel kecil (360 x 640 dp), tanpa Hilt maupun jaringan. */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class OrdersScreenTest {

    @get:Rule
    val rule = createComposeRule()

    private var state by mutableStateOf<OrdersUiState>(OrdersUiState.Loading)
    private var signIns = 0
    private var refreshes = 0
    private var loadMores = 0
    private var loadMoreRetries = 0
    private val opened = mutableListOf<String>()

    private fun show(initial: OrdersUiState) {
        state = initial
        rule.setContent {
            BoothTheme {
                OrdersScreen(
                    state = state,
                    onSignIn = { signIns++ },
                    onRefresh = { refreshes++ },
                    onLoadMore = { loadMores++ },
                    onRetryLoadMore = { loadMoreRetries++ },
                    onOrderClick = { opened += it },
                    dateFormatter = OrdersTestData.dates,
                )
            }
        }
        rule.waitForIdle()
    }

    private fun row(index: Int) = hasClickAction() and hasText("Pesanan ${number(index)}")

    // --- baris ---

    @Test
    fun `baris pesanan menampilkan nomor status tanggal ringkasan barang dan total`() {
        show(OrdersUiState.Orders(items = listOf(summary(1, itemCount = 3), summary(2, status = OrderStatus.Shipped, method = FulfilmentMethod.Courier, total = "120500.50", itemCount = 1, firstItemName = "Stiker Uji — A5"))))

        // Seluruh baris satu tombol: pembaca layar membacakan nomor, status, tanggal, barang, total sekaligus.
        rule.onNode(row(1))
            .assertIsDisplayed()
            .assert(hasText("Menunggu konfirmasi"))
            .assert(hasText("Dibuat 12 Nov 2026, 10.00"))
            .assert(hasText("Gantungan Kunci Uji — Kecil dan 2 barang lainnya"))
            .assert(hasText("Total Rp55.000"))
        rule.onNode(row(2))
            .assert(hasText("Dikirim"))
            .assert(hasText("Stiker Uji — A5"))
            .assert(hasText("Total Rp120.500,50"))
        rule.onAllNodesWithTag(OrdersTestTags.ORDER_ROW).assertCountEquals(2)
    }

    @Test
    fun `menekan baris membuka pesanan itu`() {
        show(OrdersUiState.Orders(items = summaries(1..3)))

        rule.onNode(row(2)).assertHasClickAction().performClick()

        assertEquals(listOf(number(2)), opened)
    }

    @Test
    fun `setiap status punya teksnya sendiri dan status tidak dikenal tampil netral`() {
        val labels = mapOf(
            OrderStatus.AwaitingConfirmation to "Menunggu konfirmasi",
            OrderStatus.Confirmed to "Dikonfirmasi",
            OrderStatus.Packing to "Sedang dikemas",
            OrderStatus.ReadyForPickup to "Siap diambil",
            OrderStatus.Shipped to "Dikirim",
            OrderStatus.Delivered to "Sudah sampai",
            OrderStatus.PickedUp to "Sudah diambil",
            OrderStatus.Cancelled to "Dibatalkan",
            OrderStatus.Unknown to "Status diperbarui",
        )
        assertEquals("Semua status tercakup", OrderStatus.entries.toSet(), labels.keys)
        show(OrdersUiState.Orders(items = labels.keys.mapIndexed { index, status -> summary(index + 1, status = status, method = null) }))

        labels.values.forEachIndexed { index, label ->
            rule.onNodeWithTag(OrdersTestTags.ORDER_LIST).performScrollToIndex(index + 1)
            rule.onNode(row(index + 1)).assert(hasText(label))
        }
    }

    @Test
    fun `baris tanpa nama barang dan tanpa tanggal tetap dapat dibaca`() {
        show(OrdersUiState.Orders(items = listOf(summary(1, itemCount = 4, firstItemName = null, createdAt = null))))

        rule.onNode(row(1)).assert(hasText("4 barang"))
        rule.onAllNodesWithText("Dibuat", substring = true).assertCountEquals(0)
    }

    // --- muat ulang dan paging ---

    @Test
    fun `tombol muat ulang terlihat cukup besar dan memanggil penyegaran`() {
        show(OrdersUiState.Orders(items = summaries(1..2)))

        rule.onNodeWithContentDescription("Muat ulang daftar pesanan")
            .assertIsDisplayed()
            .assertIsEnabled()
            .assertHeightIsAtLeast(48.dp)
            .performClick()

        assertEquals(1, refreshes)
    }

    @Test
    fun `tombol muat ulang mati selama penyegaran berjalan`() {
        show(OrdersUiState.Orders(items = summaries(1..2), refreshing = true))

        rule.onNodeWithContentDescription("Muat ulang daftar pesanan").assertIsNotEnabled()
    }

    @Test
    fun `mendekati akhir daftar meminta halaman berikutnya`() {
        show(OrdersUiState.Orders(items = summaries(1..3), canLoadMore = true))

        assertTrue("Halaman berikutnya diminta", loadMores >= 1)
    }

    @Test
    fun `daftar tanpa halaman berikutnya tidak meminta apa pun`() {
        show(OrdersUiState.Orders(items = summaries(1..3), canLoadMore = false))

        assertEquals(0, loadMores)
    }

    @Test
    fun `halaman berikutnya yang sedang dimuat dan yang gagal tampil di akhir daftar`() {
        show(OrdersUiState.Orders(items = summaries(1..2), canLoadMore = true, loadingMore = true))
        rule.onNodeWithText("Memuat pesanan berikutnya…").assertIsDisplayed()

        state = OrdersUiState.Orders(
            items = summaries(1..2),
            canLoadMore = true,
            loadMoreError = ApiError.Http(500, ApiErrorCodes.SERVER_ERROR),
        )
        rule.waitForIdle()

        rule.onNodeWithText("Pesanan berikutnya belum dapat dimuat.").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").performClick()
        assertEquals(1, loadMoreRetries)
        // Pesanan yang sudah dimuat tetap tampil.
        rule.onNode(row(1)).assertIsDisplayed()
    }

    @Test
    fun `daftar yang mungkin belum terbaru memberi tahu dan menawarkan coba lagi`() {
        show(OrdersUiState.Orders(items = summaries(1..2), staleReason = ApiError.Network))
        rule.onNodeWithText("Anda sedang offline. Menampilkan data yang terakhir dimuat.").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").performClick()
        assertEquals(1, refreshes)

        state = OrdersUiState.Orders(items = summaries(1..2), staleReason = ApiError.Http(500, ApiErrorCodes.SERVER_ERROR))
        rule.waitForIdle()
        rule.onNodeWithText("Data terbaru belum dapat dimuat. Yang tampil mungkin belum terbaru.").assertIsDisplayed()
        rule.onNode(row(1)).assertIsDisplayed()
    }

    // --- kosong, memuat, galat, belum masuk ---

    @Test
    fun `keadaan kosong menjelaskan dan menawarkan muat ulang`() {
        show(OrdersUiState.Empty)

        rule.onNodeWithText("Belum ada pesanan").assertIsDisplayed()
        rule.onNodeWithText("Pesanan yang Anda buat akan tampil di sini.").assertIsDisplayed()
        rule.onNodeWithText("Muat ulang").performClick()
        assertEquals(1, refreshes)
        rule.onAllNodesWithTag(OrdersTestTags.ORDER_ROW).assertCountEquals(0)
    }

    @Test
    fun `keadaan memuat diumumkan dengan teks`() {
        show(OrdersUiState.Loading)

        rule.onNodeWithText("Memuat…").assertIsDisplayed()
    }

    @Test
    fun `tanpa koneksi menampilkan keadaan offline dengan coba lagi`() {
        show(OrdersUiState.Error(ApiError.Network))

        rule.onNodeWithText("Tidak ada koneksi").assertIsDisplayed()
        rule.onNodeWithText("Periksa koneksi internet Anda, lalu coba lagi.").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").assertHeightIsAtLeast(48.dp).performClick()
        assertEquals(1, refreshes)
    }

    @Test
    fun `galat server menampilkan pesan umum dan coba lagi yang mati selama mengulang`() {
        show(OrdersUiState.Error(ApiError.Http(500, ApiErrorCodes.SERVER_ERROR, detail = "detail internal"), retrying = true))

        rule.onNodeWithText("Terjadi kesalahan").assertIsDisplayed()
        rule.onNodeWithText("Terjadi kesalahan. Silakan coba lagi.").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").assertIsNotEnabled()
        rule.onAllNodesWithText("detail internal").assertCountEquals(0)
    }

    @Test
    fun `instalasi terkunci menampilkan toko tidak tersedia dengan tenang`() {
        show(OrdersUiState.Error(ApiError.Http(423, ApiErrorCodes.STORE_LOCKED)))

        rule.onNodeWithText("Toko sedang tidak tersedia").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").performClick()
        assertEquals(1, refreshes)
    }

    @Test
    fun `belum masuk menampilkan ajakan masuk dengan satu aksi utama`() {
        show(OrdersUiState.SignedOut(sessionExpired = false))

        rule.onNodeWithText("Masuk untuk melihat pesanan").assertIsDisplayed()
        rule.onAllNodesWithText("Sesi berakhir, silakan masuk lagi.").assertCountEquals(0)
        rule.onNodeWithText("Masuk").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()
        assertEquals(1, signIns)
        rule.onAllNodesWithTag(OrdersTestTags.ORDER_ROW).assertCountEquals(0)
    }

    @Test
    fun `sesi berakhir memberi tahu dengan jelas sebelum ajakan masuk`() {
        show(OrdersUiState.SignedOut(sessionExpired = true))

        rule.onNodeWithText("Sesi berakhir, silakan masuk lagi.").assertIsDisplayed()
        rule.onNodeWithText("Masuk").assertIsDisplayed()
    }

    @Test
    fun `nomor pesanan yang sama dengan kunci tetap daftar tidak merusak daftar`() {
        // Nomor dari server dipakai sebagai kunci baris; awalan kunci mencegah tabrakan dengan kunci tetap.
        val odd = listOf("header:refresh", "footer", "header:stale").mapIndexed { index, value ->
            summary(index + 1).copy(orderNumber = value)
        }
        show(OrdersUiState.Orders(items = odd, staleReason = ApiError.Network, loadingMore = true, canLoadMore = true))

        rule.onAllNodesWithTag(OrdersTestTags.ORDER_ROW).onFirst().assertIsDisplayed()
    }
}
