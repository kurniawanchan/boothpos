package com.boothpos.customer.feature.orders.presentation

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
 * `OrderDetailViewModel` membaca nomor pesanan langsung dari SavedStateHandle dengan kunci
 * [OrderDetailRoute.ORDER_NUMBER_ARG]. Tes ini menjalankan navigasi bertipe sungguhan dan membuat
 * SavedStateHandle dengan cara yang sama seperti Hilt (`createSavedStateHandle()`), supaya kunci
 * itu tidak diam-diam berbeda dari nama argumen yang dibuat pustaka navigasi.
 */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class OrdersNavigationTest {

    private class HandleProbe(val handle: SavedStateHandle) : ViewModel()

    @get:Rule
    val rule = createComposeRule()

    @Test
    fun `nomor pesanan rute detail tersedia di SavedStateHandle ViewModel dengan kunci yang dipakai`() {
        lateinit var navController: NavHostController
        var numberSeenByViewModel: String? = null
        rule.setContent {
            navController = rememberNavController()
            NavHost(navController = navController, startDestination = OrdersRoute) {
                composable<OrdersRoute> { Text("layar-pesanan") }
                composable<OrderDetailRoute> {
                    val probe = viewModel<HandleProbe> { HandleProbe(createSavedStateHandle()) }
                    numberSeenByViewModel = probe.handle.get<String>(OrderDetailRoute.ORDER_NUMBER_ARG)
                    Text("layar-detail-pesanan")
                }
            }
        }
        rule.waitForIdle()

        rule.runOnUiThread { navController.navigate(OrderDetailRoute("CO-UJI0000001")) { launchSingleTop = true } }
        rule.waitForIdle()

        rule.onNodeWithText("layar-detail-pesanan").assertIsDisplayed()
        assertEquals("CO-UJI0000001", numberSeenByViewModel)

        // Kembali dari detail menuju daftar pesanan.
        rule.runOnUiThread { navController.popBackStack<OrderDetailRoute>(inclusive = true) }
        rule.waitForIdle()
        rule.onNodeWithText("layar-pesanan").assertIsDisplayed()
    }
}
