package com.boothpos.customer.feature.orders.presentation

import androidx.navigation.NavController
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import kotlinx.serialization.Serializable

// Rute fitur ini. :app yang merangkai navigasi antarfitur; modul fitur tidak saling mengenal.

@Serializable
data object OrdersRoute

/**
 * Detail satu pesanan. [orderNumber] adalah pengenal publik pesanan (`CO-…`), bukan id numerik;
 * server yang memutuskan apakah pesanan itu milik akun yang sedang masuk.
 */
@Serializable
data class OrderDetailRoute(val orderNumber: String) {
    companion object {
        /**
         * Nama argumen navigasi = nama properti rute. ViewModel membacanya langsung dari
         * SavedStateHandle dengan kunci ini, supaya dapat diuji di JVM polos dengan
         * `SavedStateHandle(mapOf(...))`; `OrdersNavigationTest` menjaga keduanya tetap sama.
         */
        const val ORDER_NUMBER_ARG = "orderNumber"
    }
}

/**
 * @param onSignIn membuka alur auth (modul lain), jadi dirangkai oleh :app. Setelah berhasil masuk
 * :app menutup alur itu dan layar pesanan memuat datanya sendiri dari perubahan sesi.
 */
fun NavGraphBuilder.ordersGraph(navController: NavController, onSignIn: () -> Unit) {
    composable<OrdersRoute> {
        OrdersScreenRoute(
            onSignIn = onSignIn,
            onOrderClick = { orderNumber ->
                navController.navigate(OrderDetailRoute(orderNumber)) { launchSingleTop = true }
            },
        )
    }
    composable<OrderDetailRoute> {
        OrderDetailScreenRoute(
            onSignIn = onSignIn,
            onBackToOrders = { navController.popBackStack<OrderDetailRoute>(inclusive = true) },
        )
    }
}
