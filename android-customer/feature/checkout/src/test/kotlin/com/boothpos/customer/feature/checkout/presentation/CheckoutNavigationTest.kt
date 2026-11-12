package com.boothpos.customer.feature.checkout.presentation

import androidx.compose.material3.Text
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.createSavedStateHandle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

/**
 * `AddressFormViewModel` membaca id alamat langsung dari SavedStateHandle dengan kunci
 * [AddressFormRoute.ADDRESS_ID_ARG]. Tes ini menjalankan navigasi bertipe sungguhan dan membuat
 * SavedStateHandle dengan cara yang sama seperti Hilt, supaya kunci dan tipenya (Long) tidak
 * diam-diam berbeda dari yang dibuat pustaka navigasi.
 */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class CheckoutNavigationTest {

    private class HandleProbe(val handle: SavedStateHandle) : ViewModel()

    @get:Rule
    val rule = createComposeRule()

    private lateinit var navController: NavHostController
    private val seenIds = mutableListOf<Long?>()

    private fun show() {
        rule.setContent {
            navController = rememberNavController()
            NavHost(navController = navController, startDestination = CheckoutRoute) {
                composable<CheckoutRoute> { Text("layar-checkout") }
                composable<AddressListRoute> { Text("layar-daftar-alamat") }
                composable<AddressFormRoute> {
                    val probe = viewModel<HandleProbe> { HandleProbe(createSavedStateHandle()) }
                    seenIds += probe.handle.get<Long>(AddressFormRoute.ADDRESS_ID_ARG)
                    Text("layar-formulir-alamat")
                }
            }
        }
        rule.waitForIdle()
    }

    @Test
    fun `id alamat rute formulir tersedia di SavedStateHandle ViewModel sebagai Long`() {
        show()

        rule.runOnUiThread { navController.navigate(AddressFormRoute(addressId = 6)) { launchSingleTop = true } }
        rule.waitForIdle()

        rule.onNodeWithText("layar-formulir-alamat").assertIsDisplayed()
        assertEquals(6L, seenIds.last())
    }

    @Test
    fun `formulir alamat baru memakai id nol`() {
        show()

        rule.runOnUiThread { navController.navigate(AddressFormRoute()) { launchSingleTop = true } }
        rule.waitForIdle()

        assertEquals(AddressFormRoute.NEW_ADDRESS_ID, seenIds.last())
        rule.runOnUiThread { navController.popBackStack<AddressFormRoute>(inclusive = true) }
        rule.waitForIdle()
        rule.onNodeWithText("layar-checkout").assertIsDisplayed()
    }
}
