package com.boothpos.customer.core.ui.component

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.dp
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.ui.theme.BoothTheme
import java.math.BigDecimal
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/**
 * Bukti bahwa harness Compose + Robolectric berjalan di JVM (tanpa emulator), sekaligus tes
 * perilaku komponen bersama pada layar ponsel kecil.
 */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class SharedComponentsTest {

    @get:Rule
    val rule = createComposeRule()

    @Test
    fun `ErrorState menampilkan pesan dan tombol coba lagi memanggil callback`() {
        var retries = 0
        rule.setContent {
            BoothTheme { ErrorState(message = "Daftar produk gagal dimuat.", onRetry = { retries++ }) }
        }

        rule.onNodeWithText("Terjadi kesalahan").assertIsDisplayed()
        rule.onNodeWithText("Daftar produk gagal dimuat.").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(1, retries)
    }

    @Test
    fun `ApiErrorState memilih tampilan toko tidak tersedia untuk 503 fitur dimatikan`() {
        var retries = 0
        rule.setContent {
            BoothTheme {
                ApiErrorState(
                    error = ApiError.Http(503, ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED),
                    onRetry = { retries++ },
                )
            }
        }

        rule.onNodeWithText("Toko sedang tidak tersedia").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").performClick()
        assertEquals(1, retries)
    }

    @Test
    fun `ApiErrorState menampilkan keadaan tanpa koneksi untuk galat jaringan`() {
        rule.setContent { BoothTheme { ApiErrorState(error = ApiError.Network, onRetry = {}) } }

        rule.onNodeWithText("Tidak ada koneksi").assertIsDisplayed()
        rule.onNodeWithText("Periksa koneksi internet Anda, lalu coba lagi.").assertIsDisplayed()
    }

    @Test
    fun `EmptyState menampilkan aksi tunggalnya`() {
        var actions = 0
        rule.setContent {
            BoothTheme {
                EmptyState(
                    title = "Keranjang kosong",
                    message = "Belum ada barang.",
                    actionLabel = "Mulai belanja",
                    onAction = { actions++ },
                )
            }
        }

        rule.onNodeWithText("Keranjang kosong").assertIsDisplayed()
        rule.onNodeWithText("Mulai belanja").performClick()
        assertEquals(1, actions)
    }

    @Test
    fun `AppTextField menampilkan galat tepat di bawah kolom`() {
        var value by mutableStateOf("")
        rule.setContent {
            BoothTheme {
                AppTextField(
                    value = value,
                    onValueChange = { value = it },
                    label = "Email",
                    error = "Format email tidak valid.",
                )
            }
        }

        rule.onNodeWithText("Format email tidak valid.").assertIsDisplayed()
        rule.onNodeWithText("Email").performTextInput("a@b")
        assertEquals("a@b", value)
    }

    @Test
    fun `PasswordTextField dapat menampilkan dan menyembunyikan kata sandi`() {
        var value by mutableStateOf("rahasia-palsu")
        rule.setContent {
            BoothTheme { PasswordTextField(value = value, onValueChange = { value = it }, label = "Kata sandi") }
        }

        // EditableText adalah teks yang TAMPIL (setelah transformasi visual): titik-titik saat
        // disembunyikan, isi aslinya saat ditampilkan.
        val field = hasSetTextAction()
        val showsSecret = SemanticsMatcher.expectValue(SemanticsProperties.EditableText, AnnotatedString("rahasia-palsu"))
        rule.onNode(field).assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.Password))
        rule.onNode(field).assert(showsSecret.not())
        rule.onNodeWithContentDescription("Tampilkan kata sandi").assertHeightIsAtLeast(48.dp).performClick()
        rule.onNode(field).assert(showsSecret)
        rule.onNodeWithContentDescription("Sembunyikan kata sandi").performClick()
        rule.onNode(field).assert(showsSecret.not())
    }

    @Test
    fun `PrimaryButton yang sedang memuat tidak dapat ditekan lagi`() {
        var clicks = 0
        rule.setContent {
            BoothTheme { PrimaryButton(text = "Buat pesanan", onClick = { clicks++ }, loading = true) }
        }

        rule.onNodeWithText("Buat pesanan").assertIsNotEnabled().performClick()

        assertEquals(0, clicks)
    }

    @Test
    fun `StatusBadge dan PriceText menampilkan teksnya`() {
        rule.setContent {
            BoothTheme {
                androidx.compose.foundation.layout.Column {
                    StatusBadge(text = "Siap diambil", icon = Icons.Filled.CheckCircle, tone = StatusTone.Success)
                    PriceText(amount = BigDecimal("150000.00"))
                }
            }
        }

        rule.onNodeWithText("Siap diambil").assertIsDisplayed()
        rule.onNodeWithText("Rp150.000").assertIsDisplayed()
    }

    @Test
    fun `OfflineBanner menampilkan pesan dan aksi opsionalnya dengan target sentuh penuh`() {
        var retries = 0
        var withAction by mutableStateOf(false)
        rule.setContent {
            BoothTheme {
                if (withAction) {
                    OfflineBanner(message = "Menampilkan data tersimpan.", actionLabel = "Coba lagi", onAction = { retries++ })
                } else {
                    OfflineBanner()
                }
            }
        }

        rule.onNodeWithText("Anda sedang offline. Data yang tampil mungkin belum terbaru.").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").assertDoesNotExist()

        withAction = true
        rule.onNodeWithText("Menampilkan data tersimpan.").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(1, retries)
    }

    @Test
    fun `ProductImage tanpa URL menampilkan pengganti berdeskripsi tanpa jaringan`() {
        rule.setContent {
            BoothTheme { ProductImage(imageUrl = null, contentDescription = "Gantungan kunci", size = 96.dp) }
        }

        rule.onNodeWithContentDescription("Gantungan kunci").assertIsDisplayed()
    }

    @Test
    @Config(qualifiers = "w320dp-h320dp")
    fun `keadaan pesan yang tidak muat di layar pendek dengan huruf 200 persen dapat digulir ke aksinya`() {
        var retries = 0
        rule.setContent {
            val density = LocalDensity.current
            CompositionLocalProvider(LocalDensity provides Density(density.density, fontScale = 2f)) {
                BoothTheme(darkTheme = true) {
                    ErrorState(
                        message = "Pesan galat uji yang cukup panjang untuk memenuhi layar yang pendek ini.",
                        onRetry = { retries++ },
                    )
                }
            }
        }

        rule.onNodeWithText("Coba lagi").performScrollTo().assertIsDisplayed().performClick()
        assertEquals(1, retries)
    }

    @Test
    fun `keadaan kosong di dalam induk yang sudah menggulir tidak membuat gulir bersarang`() {
        rule.setContent {
            BoothTheme {
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    EmptyState(title = "Kosong uji", actionLabel = "Aksi uji", onAction = {})
                }
            }
        }

        rule.onNodeWithText("Aksi uji").assertIsDisplayed()
    }
}
