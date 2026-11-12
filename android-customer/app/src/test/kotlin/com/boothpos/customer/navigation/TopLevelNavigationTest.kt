package com.boothpos.customer.navigation

import androidx.compose.material3.Text
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.toRoute
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.feature.account.presentation.AccountRoute
import com.boothpos.customer.feature.account.presentation.EditProfileRoute
import com.boothpos.customer.feature.auth.presentation.AuthChoiceRoute
import com.boothpos.customer.feature.auth.presentation.RegisterRoute
import com.boothpos.customer.feature.auth.presentation.SignInRoute
import com.boothpos.customer.feature.cart.presentation.CartRoute
import com.boothpos.customer.feature.catalog.presentation.ProductDetailRoute
import com.boothpos.customer.feature.catalog.presentation.ShopRoute
import com.boothpos.customer.feature.checkout.presentation.AddressFormRoute
import com.boothpos.customer.feature.checkout.presentation.AddressListRoute
import com.boothpos.customer.feature.checkout.presentation.CheckoutRoute
import com.boothpos.customer.feature.orders.presentation.OrderDetailRoute
import com.boothpos.customer.feature.orders.presentation.OrdersRoute
import com.boothpos.customer.ui.leaveAuth
import com.boothpos.customer.ui.leaveCheckout
import com.boothpos.customer.ui.navigateToTopLevel
import com.boothpos.customer.ui.openOrderAfterCheckout
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/**
 * Navigasi bertipe (rute `@Serializable`) dan pemetaan bingkai, dijalankan sungguhan di JVM.
 * Layar di sini sengaja teks polos: yang diuji adalah rute milik fitur + aturan navigasi milik
 * :app, bukan isi layar fitur (yang memakai Hilt dan diuji di modulnya masing-masing).
 */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class TopLevelNavigationTest {

    @get:Rule
    val rule = createComposeRule()

    private lateinit var navController: NavHostController

    private fun show() {
        rule.setContent {
            navController = rememberNavController()
            NavHost(navController = navController, startDestination = ShopRoute) {
                composable<ShopRoute> { Text("layar-belanja") }
                composable<ProductDetailRoute> { Text("layar-detail-produk") }
                composable<CartRoute> { Text("layar-keranjang") }
                composable<OrdersRoute> { Text("layar-pesanan") }
                composable<OrderDetailRoute> { Text("layar-detail-pesanan") }
                composable<AccountRoute> { Text("layar-akun") }
                composable<EditProfileRoute> { Text("layar-ubah-profil") }
                composable<AuthChoiceRoute> { Text("layar-sambutan") }
                composable<SignInRoute> { Text("layar-masuk") }
                composable<RegisterRoute> { Text("layar-daftar") }
                composable<CheckoutRoute> { Text("layar-checkout") }
                composable<AddressListRoute> { Text("layar-daftar-alamat") }
                composable<AddressFormRoute> { Text("layar-formulir-alamat") }
            }
        }
        rule.waitForIdle()
    }

    private fun navigate(block: NavHostController.() -> Unit) {
        rule.runOnUiThread { navController.block() }
        rule.waitForIdle()
    }

    private fun currentChrome() = chromeFor(navController.currentDestination)

    @Test
    fun `aplikasi mulai di Belanja dengan bingkai tab yang digerbangi toko`() {
        show()

        rule.onNodeWithText("layar-belanja").assertIsDisplayed()
        assertEquals(ShopChrome, currentChrome())
        assertTrue(currentChrome().storeGated)
    }

    @Test
    fun `berpindah tab tidak menumpuk back stack dan kembali selalu menuju Belanja`() {
        show()

        navigate { navigateToTopLevel(TopLevelDestination.Cart) }
        rule.onNodeWithText("layar-keranjang").assertIsDisplayed()
        assertEquals(CartChrome, currentChrome())

        navigate { navigateToTopLevel(TopLevelDestination.Orders) }
        navigate { navigateToTopLevel(TopLevelDestination.Cart) }
        navigate { navigateToTopLevel(TopLevelDestination.Orders) }
        rule.onNodeWithText("layar-pesanan").assertIsDisplayed()
        assertEquals(OrdersChrome, currentChrome())
        assertFalse("Pesanan tetap terbuka saat toko tutup", currentChrome().storeGated)

        // Kembali dari tab mana pun: satu langkah ke Belanja, bukan menelusuri riwayat tab.
        navigate { popBackStack() }
        rule.onNodeWithText("layar-belanja").assertIsDisplayed()
        assertNull(navController.previousBackStackEntry)
    }

    @Test
    fun `memilih tab yang sedang aktif tidak membuat salinan layar`() {
        show()

        navigate { navigateToTopLevel(TopLevelDestination.Shop) }
        navigate { navigateToTopLevel(TopLevelDestination.Shop) }

        rule.onNodeWithText("layar-belanja").assertIsDisplayed()
        assertNull(navController.previousBackStackEntry)
    }

    @Test
    fun `tujuan non-tab ditumpuk di atas tab dan memakai bingkai tanpa navigasi bawah`() {
        show()

        navigate { navigateToTopLevel(TopLevelDestination.Orders) }
        navigate { navigate(AccountRoute) { launchSingleTop = true } }
        assertEquals(AccountChrome, currentChrome())
        navigate { navigate(SignInRoute) { launchSingleTop = true } }
        rule.onNodeWithText("layar-masuk").assertIsDisplayed()
        assertEquals(SignInChrome, currentChrome())
        assertNull(currentChrome().topLevel)

        navigate { navigate(CheckoutRoute) }
        assertEquals(CheckoutChrome, currentChrome())
        assertTrue(currentChrome().storeGated)

        navigate { popBackStack() }
        navigate { popBackStack() }
        navigate { popBackStack() }
        rule.onNodeWithText("layar-pesanan").assertIsDisplayed()
    }

    @Test
    fun `detail produk ditumpuk di atas Belanja dan Lihat keranjang berpindah ke tab Keranjang`() {
        show()

        navigate { navigate(ProductDetailRoute("gantungan-kunci-uji")) { launchSingleTop = true } }
        rule.onNodeWithText("layar-detail-produk").assertIsDisplayed()
        assertEquals(ProductDetailChrome, currentChrome())
        assertNull("Detail produk tidak menampilkan navigasi bawah", currentChrome().topLevel)
        assertTrue("Detail produk ditutup saat toko tidak tersedia", currentChrome().storeGated)

        // "Lihat keranjang" di snackbar: pindah tab, detail tidak tertinggal di bawah Keranjang.
        navigate { navigateToTopLevel(TopLevelDestination.Cart) }
        rule.onNodeWithText("layar-keranjang").assertIsDisplayed()
        assertEquals(CartChrome, currentChrome())

        navigate { popBackStack() }
        rule.onNodeWithText("layar-belanja").assertIsDisplayed()
        assertNull(navController.previousBackStackEntry)
    }

    @Test
    fun `selesai masuk dari sambutan menutup seluruh alur auth dan kembali ke Belanja`() {
        show()

        navigate { navigate(AuthChoiceRoute) { launchSingleTop = true } }
        assertEquals(WelcomeChrome, currentChrome())
        navigate { navigate(RegisterRoute) { launchSingleTop = true } }
        assertEquals(RegisterChrome, currentChrome())
        assertNull("Alur auth tidak menampilkan navigasi bawah", currentChrome().topLevel)

        navigate { leaveAuth() }

        rule.onNodeWithText("layar-belanja").assertIsDisplayed()
        assertNull(navController.previousBackStackEntry)
    }

    @Test
    fun `selesai masuk dari Akun kembali ke Akun bukan ke layar auth`() {
        show()

        navigate { navigate(AccountRoute) { launchSingleTop = true } }
        navigate { navigate(SignInRoute) { launchSingleTop = true } }
        navigate { leaveAuth() }

        rule.onNodeWithText("layar-akun").assertIsDisplayed()
        assertEquals(AccountChrome, currentChrome())
    }

    @Test
    fun `sesi berakhir di layar ubah profil membuka masuk di atasnya dan kembali ke sana`() {
        show()

        navigate { navigate(AccountRoute) { launchSingleTop = true } }
        navigate { navigate(EditProfileRoute) { launchSingleTop = true } }
        assertEquals(EditProfileChrome, currentChrome())
        navigate { navigate(SignInRoute) { launchSingleTop = true } }
        navigate { leaveAuth() }

        rule.onNodeWithText("layar-ubah-profil").assertIsDisplayed()
    }

    @Test
    fun `checkout ditumpuk di atas Keranjang dan masuk di tengah checkout kembali ke checkout`() {
        show()

        navigate { navigateToTopLevel(TopLevelDestination.Cart) }
        navigate { navigate(CheckoutRoute) { launchSingleTop = true } }
        rule.onNodeWithText("layar-checkout").assertIsDisplayed()
        assertNull("Checkout tidak menampilkan navigasi bawah", currentChrome().topLevel)

        // Checkout meminta masuk; setelah berhasil, alur auth ditutup dan checkout tampil lagi.
        navigate { navigate(SignInRoute) { launchSingleTop = true } }
        navigate { leaveAuth() }
        rule.onNodeWithText("layar-checkout").assertIsDisplayed()

        navigate { leaveCheckout() }
        rule.onNodeWithText("layar-keranjang").assertIsDisplayed()
        assertEquals(CartChrome, currentChrome())
    }

    @Test
    fun `formulir alamat dari checkout kembali ke checkout dan alamat tidak digerbangi toko`() {
        show()

        navigate { navigateToTopLevel(TopLevelDestination.Cart) }
        navigate { navigate(CheckoutRoute) { launchSingleTop = true } }
        navigate { navigate(AddressFormRoute()) { launchSingleTop = true } }
        rule.onNodeWithText("layar-formulir-alamat").assertIsDisplayed()
        assertEquals(AddressFormChrome, currentChrome())
        assertFalse(currentChrome().storeGated)

        navigate { popBackStack<AddressFormRoute>(inclusive = true) }
        rule.onNodeWithText("layar-checkout").assertIsDisplayed()

        navigate { leaveCheckout() }
        navigate { navigate(AccountRoute) { launchSingleTop = true } }
        navigate { navigate(AddressListRoute) { launchSingleTop = true } }
        rule.onNodeWithText("layar-daftar-alamat").assertIsDisplayed()
        assertEquals(AddressListChrome, currentChrome())
        navigate { navigate(AddressFormRoute(addressId = 5)) { launchSingleTop = true } }
        navigate { popBackStack() }
        rule.onNodeWithText("layar-daftar-alamat").assertIsDisplayed()
    }

    @Test
    fun `pesanan dibuat membuang checkout lalu membuka detail pesanan di atas daftar Pesanan`() {
        show()

        navigate { navigateToTopLevel(TopLevelDestination.Cart) }
        navigate { navigate(CheckoutRoute) { launchSingleTop = true } }

        navigate { openOrderAfterCheckout("CO-UJI0000001") }
        rule.onNodeWithText("layar-detail-pesanan").assertIsDisplayed()
        assertEquals(OrderDetailChrome, currentChrome())

        // Kembali dari detail: daftar pesanan, bukan checkout yang sudah selesai.
        navigate { popBackStack() }
        rule.onNodeWithText("layar-pesanan").assertIsDisplayed()
        assertEquals(OrdersChrome, currentChrome())

        // Tab Keranjang tidak menghidupkan lagi checkout yang sudah selesai.
        navigate { navigateToTopLevel(TopLevelDestination.Cart) }
        rule.onNodeWithText("layar-keranjang").assertIsDisplayed()

        navigate { popBackStack() }
        rule.onNodeWithText("layar-belanja").assertIsDisplayed()
        assertNull(navController.previousBackStackEntry)
    }

    @Test
    fun `detail pesanan ditumpuk di atas Pesanan tanpa navigasi bawah dan tetap terbuka saat toko tutup`() {
        show()

        navigate { navigateToTopLevel(TopLevelDestination.Orders) }
        navigate { navigate(OrderDetailRoute("CO-UJI0000001")) { launchSingleTop = true } }
        rule.onNodeWithText("layar-detail-pesanan").assertIsDisplayed()
        assertEquals(OrderDetailChrome, currentChrome())
        assertNull("Detail pesanan tidak menampilkan navigasi bawah", currentChrome().topLevel)
        assertFalse("Pesanan yang sudah dibuat tetap dapat diikuti saat toko tutup", currentChrome().storeGated)

        // Sesi berakhir di detail: masuk dibuka di atasnya, dan selesai masuk kembali ke detail.
        navigate { navigate(SignInRoute) { launchSingleTop = true } }
        navigate { leaveAuth() }
        rule.onNodeWithText("layar-detail-pesanan").assertIsDisplayed()

        navigate { popBackStack<OrderDetailRoute>(inclusive = true) }
        rule.onNodeWithText("layar-pesanan").assertIsDisplayed()
    }

    @Test
    fun `membuka pesanan lain setelah checkout mengganti detail yang tersimpan di tab Pesanan`() {
        show()

        // Tab Pesanan ditinggalkan dengan sebuah detail terbuka (tersimpan oleh saveState).
        navigate { navigateToTopLevel(TopLevelDestination.Orders) }
        navigate { navigate(OrderDetailRoute("CO-LAMA000001")) { launchSingleTop = true } }
        val oldEntryId = navController.currentBackStackEntry?.id
        navigate { navigateToTopLevel(TopLevelDestination.Cart) }
        navigate { navigate(CheckoutRoute) { launchSingleTop = true } }

        navigate { openOrderAfterCheckout("CO-BARU000001") }

        rule.onNodeWithText("layar-detail-pesanan").assertIsDisplayed()
        assertEquals("CO-BARU000001", navController.currentBackStackEntry?.toRoute<OrderDetailRoute>()?.orderNumber)
        // Entri BARU (bukan entri lama dengan argumen diganti): ViewModel detail lama tidak dipakai ulang.
        assertTrue(
            "Detail pesanan baru harus berupa entri back stack baru",
            navController.currentBackStackEntry?.id != oldEntryId,
        )
        // Hanya satu detail di atas daftar: Kembali langsung menuju daftar pesanan.
        navigate { popBackStack() }
        rule.onNodeWithText("layar-pesanan").assertIsDisplayed()
    }

    @Test
    fun `leaveAuth di luar alur auth tidak mengubah apa pun`() {
        show()

        navigate { navigateToTopLevel(TopLevelDestination.Orders) }
        navigate { leaveAuth() }

        rule.onNodeWithText("layar-pesanan").assertIsDisplayed()
    }
}
