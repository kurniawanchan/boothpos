package com.boothpos.customer.feature.catalog.presentation

import androidx.navigation.NavController
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import kotlinx.serialization.Serializable

// Rute fitur ini. :app yang merangkai navigasi antarfitur; modul fitur tidak saling mengenal.

@Serializable
data object ShopRoute

/** Detail satu produk. [slug] adalah pengenal publik produk (bukan id numerik). */
@Serializable
data class ProductDetailRoute(val slug: String) {
    companion object {
        /**
         * Nama argumen navigasi = nama properti rute. ViewModel membacanya langsung dari
         * SavedStateHandle dengan kunci ini, supaya dapat diuji di JVM polos dengan
         * `SavedStateHandle(mapOf(...))`; `CatalogNavigationTest` menjaga keduanya tetap sama.
         */
        const val SLUG_ARG = "slug"
    }
}

/**
 * @param onViewCart membuka Keranjang (modul lain), jadi dirangkai oleh :app.
 */
fun NavGraphBuilder.catalogGraph(navController: NavController, onViewCart: () -> Unit) {
    composable<ShopRoute> {
        ShopScreenRoute(
            onProductClick = { slug -> navController.navigate(ProductDetailRoute(slug)) { launchSingleTop = true } },
        )
    }
    composable<ProductDetailRoute> {
        ProductDetailScreenRoute(
            onViewCart = onViewCart,
            onBackToShop = { navController.popBackStack<ProductDetailRoute>(inclusive = true) },
        )
    }
}
