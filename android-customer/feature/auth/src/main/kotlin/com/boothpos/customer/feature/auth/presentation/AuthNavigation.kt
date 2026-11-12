package com.boothpos.customer.feature.auth.presentation

import androidx.navigation.NavController
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import kotlinx.serialization.Serializable

// Rute fitur ini. :app yang merangkai navigasi antarfitur; modul fitur tidak saling mengenal.

/** Layar selamat datang / pilihan: Masuk, Daftar, atau lihat toko tanpa akun. */
@Serializable
data object AuthChoiceRoute

@Serializable
data object SignInRoute

@Serializable
data object RegisterRoute

/**
 * @param onAuthenticated sesi sudah dimulai; :app menutup seluruh alur auth dan kembali ke layar asal.
 * @param onContinueAsGuest pelanggan memilih melihat toko tanpa akun.
 */
fun NavGraphBuilder.authGraph(
    navController: NavController,
    onAuthenticated: () -> Unit,
    onContinueAsGuest: () -> Unit,
) {
    composable<AuthChoiceRoute> {
        AuthChoiceScreen(
            onSignIn = { navController.navigate(SignInRoute) { launchSingleTop = true } },
            onRegister = { navController.navigate(RegisterRoute) { launchSingleTop = true } },
            onContinueAsGuest = onContinueAsGuest,
        )
    }
    composable<SignInRoute> {
        SignInScreenRoute(
            onAuthenticated = onAuthenticated,
            onRegister = { navController.switchAuthForm<SignInRoute>(to = RegisterRoute) },
        )
    }
    composable<RegisterRoute> {
        RegisterScreenRoute(
            onAuthenticated = onAuthenticated,
            onSignIn = { navController.switchAuthForm<RegisterRoute>(to = SignInRoute) },
        )
    }
}

/**
 * Pindah antara formulir Masuk dan Daftar dengan MENGGANTI layar, bukan menumpuknya: tombol
 * kembali selalu menuju layar asal, tidak bolak-balik di antara dua formulir.
 */
private inline fun <reified From : Any> NavController.switchAuthForm(to: Any) {
    navigate(to) {
        popUpTo<From> { inclusive = true }
        launchSingleTop = true
    }
}
