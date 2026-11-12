package com.boothpos.customer.navigation

import androidx.annotation.StringRes
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.List
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.ShoppingCart
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.navigation.NavDestination
import androidx.navigation.NavDestination.Companion.hasRoute
import com.boothpos.customer.R
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

/** Tiga tujuan navigasi bawah. Akun sengaja bukan tab: ia aksi di bilah atas. */
enum class TopLevelDestination(
    val route: Any,
    @StringRes val labelRes: Int,
    val icon: ImageVector,
) {
    Shop(ShopRoute, R.string.app_nav_shop, Icons.Filled.Home),
    Cart(CartRoute, R.string.app_nav_cart, Icons.Filled.ShoppingCart),
    Orders(OrdersRoute, R.string.app_nav_orders, Icons.AutoMirrored.Filled.List),
}

/**
 * Bingkai (bilah atas/bawah) untuk satu tujuan. Slice fitur yang menambah tujuan baru
 * mendaftarkannya di [chromeFor]; modul fitur sendiri tidak tahu apa pun tentang bingkai.
 *
 * [storeGated]: tujuan ini membuat pesanan BARU, jadi ditutup saat toko tidak tersedia. Pesanan
 * dan Akun tidak digerbangi: pelanggan tetap boleh mengikuti pesanan yang sudah dibuat (kontrak §2).
 */
data class ScreenChrome(
    @StringRes val titleRes: Int,
    val topLevel: TopLevelDestination? = null,
    val showAccountAction: Boolean = false,
    val storeGated: Boolean = false,
)

internal val ShopChrome = ScreenChrome(R.string.app_title_shop, TopLevelDestination.Shop, showAccountAction = true, storeGated = true)
internal val ProductDetailChrome = ScreenChrome(R.string.app_title_product_detail, storeGated = true)
internal val CartChrome = ScreenChrome(R.string.app_title_cart, TopLevelDestination.Cart, showAccountAction = true, storeGated = true)
internal val OrdersChrome = ScreenChrome(R.string.app_title_orders, TopLevelDestination.Orders, showAccountAction = true)
// Detail pesanan tidak digerbangi: pesanan yang sudah dibuat tetap dapat diikuti saat toko tutup.
internal val OrderDetailChrome = ScreenChrome(R.string.app_title_order_detail)
internal val AccountChrome = ScreenChrome(R.string.app_title_account)
internal val EditProfileChrome = ScreenChrome(R.string.app_title_edit_profile)
internal val WelcomeChrome = ScreenChrome(R.string.app_title_welcome)
internal val SignInChrome = ScreenChrome(R.string.app_title_sign_in)
internal val RegisterChrome = ScreenChrome(R.string.app_title_register)
internal val CheckoutChrome = ScreenChrome(R.string.app_title_checkout, storeGated = true)
// Alamat tidak digerbangi: daftar tetap dapat dilihat saat toko tutup; server yang menolak perubahan (503).
internal val AddressListChrome = ScreenChrome(R.string.app_title_addresses)
internal val AddressFormChrome = ScreenChrome(R.string.app_title_address_form)

internal fun chromeFor(destination: NavDestination?): ScreenChrome = when {
    destination == null -> ShopChrome
    destination.hasRoute<ShopRoute>() -> ShopChrome
    destination.hasRoute<ProductDetailRoute>() -> ProductDetailChrome
    destination.hasRoute<CartRoute>() -> CartChrome
    destination.hasRoute<OrdersRoute>() -> OrdersChrome
    destination.hasRoute<OrderDetailRoute>() -> OrderDetailChrome
    destination.hasRoute<AccountRoute>() -> AccountChrome
    destination.hasRoute<EditProfileRoute>() -> EditProfileChrome
    destination.hasRoute<AuthChoiceRoute>() -> WelcomeChrome
    destination.hasRoute<SignInRoute>() -> SignInChrome
    destination.hasRoute<RegisterRoute>() -> RegisterChrome
    destination.hasRoute<CheckoutRoute>() -> CheckoutChrome
    destination.hasRoute<AddressListRoute>() -> AddressListChrome
    destination.hasRoute<AddressFormRoute>() -> AddressFormChrome
    else -> ShopChrome
}

/** Tujuan milik alur auth (selamat datang, masuk, daftar). */
internal fun NavDestination?.isAuthDestination(): Boolean =
    this != null && (hasRoute<AuthChoiceRoute>() || hasRoute<SignInRoute>() || hasRoute<RegisterRoute>())
