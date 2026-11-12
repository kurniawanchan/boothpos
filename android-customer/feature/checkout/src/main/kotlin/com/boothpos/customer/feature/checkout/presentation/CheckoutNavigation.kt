package com.boothpos.customer.feature.checkout.presentation

import androidx.navigation.NavController
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import com.boothpos.customer.feature.checkout.presentation.address.AddressFormScreenRoute
import com.boothpos.customer.feature.checkout.presentation.address.AddressListScreenRoute
import kotlinx.serialization.Serializable

// Rute fitur ini. :app yang merangkai navigasi antarfitur; modul fitur tidak saling mengenal.

@Serializable
data object CheckoutRoute

/**
 * Kelola alamat kirim (daftar, tambah, ubah, hapus, jadikan utama). Tinggal di modul ini karena
 * checkout yang memakainya; layar Akun membukanya lewat :app, bukan lewat ketergantungan antarfitur.
 */
@Serializable
data object AddressListRoute

/** Formulir alamat. [addressId] = [NEW_ADDRESS_ID] berarti alamat baru. */
@Serializable
data class AddressFormRoute(val addressId: Long = NEW_ADDRESS_ID) {
    companion object {
        const val NEW_ADDRESS_ID = 0L

        /**
         * Nama argumen navigasi = nama properti rute. ViewModel membacanya langsung dari
         * SavedStateHandle dengan kunci ini; `CheckoutNavigationTest` menjaga keduanya tetap sama.
         */
        const val ADDRESS_ID_ARG = "addressId"
    }
}

/**
 * @param onSignIn membuka alur masuk; setelah berhasil, :app menutup alur itu dan checkout berlanjut.
 * @param onBackToCart menutup checkout dan kembali ke Keranjang.
 * @param onOrderPlaced dipanggil dengan nomor pesanan setelah server mencatat pesanan.
 */
fun NavGraphBuilder.checkoutGraph(
    navController: NavController,
    onSignIn: () -> Unit,
    onBackToCart: () -> Unit,
    onOrderPlaced: (String) -> Unit,
) {
    val openAddressForm: (Long) -> Unit = { addressId ->
        navController.navigate(AddressFormRoute(addressId)) { launchSingleTop = true }
    }
    composable<CheckoutRoute> {
        CheckoutScreenRoute(
            onSignIn = onSignIn,
            onBackToCart = onBackToCart,
            onOrderPlaced = onOrderPlaced,
            onExit = { navController.popBackStack<CheckoutRoute>(inclusive = true) },
            onAddAddress = { openAddressForm(AddressFormRoute.NEW_ADDRESS_ID) },
            onEditAddress = openAddressForm,
        )
    }
    composable<AddressListRoute> {
        AddressListScreenRoute(
            onAddAddress = { openAddressForm(AddressFormRoute.NEW_ADDRESS_ID) },
            onEditAddress = openAddressForm,
        )
    }
    composable<AddressFormRoute> {
        AddressFormScreenRoute(onClose = { navController.popBackStack<AddressFormRoute>(inclusive = true) })
    }
}
