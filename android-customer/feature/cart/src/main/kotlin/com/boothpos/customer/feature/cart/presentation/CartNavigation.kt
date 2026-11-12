package com.boothpos.customer.feature.cart.presentation

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import kotlinx.serialization.Serializable

/** Rute fitur ini. :app yang merangkai navigasi antarfitur; modul fitur tidak saling mengenal. */
@Serializable
data object CartRoute

/**
 * @param onStartShopping membuka tab Belanja, [onCheckout] membuka checkout, dan [onOpenProduct]
 * membuka detail produk (slug): semuanya milik modul lain, jadi dirangkai oleh :app.
 */
fun NavGraphBuilder.cartGraph(
    onStartShopping: () -> Unit,
    onCheckout: () -> Unit,
    onOpenProduct: (String) -> Unit,
) {
    composable<CartRoute> {
        CartScreenRoute(onStartShopping = onStartShopping, onCheckout = onCheckout, onOpenProduct = onOpenProduct)
    }
}
