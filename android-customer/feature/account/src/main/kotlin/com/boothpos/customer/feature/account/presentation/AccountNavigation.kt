package com.boothpos.customer.feature.account.presentation

import androidx.navigation.NavController
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import kotlinx.serialization.Serializable

// Rute fitur ini. :app yang merangkai navigasi antarfitur; modul fitur tidak saling mengenal.

@Serializable
data object AccountRoute

@Serializable
data object EditProfileRoute

/**
 * @param onSignIn / [onRegister] membuka alur auth (modul lain), jadi dirangkai oleh :app.
 * @param onManageAddresses membuka kelola alamat kirim (modul checkout), dirangkai oleh :app.
 * @param onProfileSaved dipanggil setelah layar ubah profil ditutup, untuk konfirmasi di bingkai aplikasi.
 */
fun NavGraphBuilder.accountGraph(
    navController: NavController,
    onSignIn: () -> Unit,
    onRegister: () -> Unit,
    onManageAddresses: () -> Unit,
    onProfileSaved: () -> Unit,
) {
    composable<AccountRoute> {
        AccountScreenRoute(
            onSignIn = onSignIn,
            onRegister = onRegister,
            onEditProfile = { navController.navigate(EditProfileRoute) { launchSingleTop = true } },
            onManageAddresses = onManageAddresses,
        )
    }
    composable<EditProfileRoute> {
        EditProfileScreenRoute(
            onSaved = {
                navController.popBackStack<EditProfileRoute>(inclusive = true)
                onProfileSaved()
            },
            onClose = { navController.popBackStack<EditProfileRoute>(inclusive = true) },
        )
    }
}
