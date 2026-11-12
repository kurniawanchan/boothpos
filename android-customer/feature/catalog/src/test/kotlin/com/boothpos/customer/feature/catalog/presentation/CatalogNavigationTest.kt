package com.boothpos.customer.feature.catalog.presentation

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
 * `ProductDetailViewModel` membaca slug langsung dari SavedStateHandle dengan kunci
 * [ProductDetailRoute.SLUG_ARG]. Tes ini menjalankan navigasi bertipe sungguhan dan membuat
 * SavedStateHandle dengan cara yang sama seperti Hilt (`createSavedStateHandle()`), supaya kunci
 * itu tidak diam-diam berbeda dari nama argumen yang dibuat pustaka navigasi.
 */
@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class CatalogNavigationTest {

    private class HandleProbe(val handle: SavedStateHandle) : ViewModel()

    @get:Rule
    val rule = createComposeRule()

    @Test
    fun `slug rute detail tersedia di SavedStateHandle ViewModel dengan kunci yang dipakai`() {
        lateinit var navController: NavHostController
        var slugSeenByViewModel: String? = null
        rule.setContent {
            navController = rememberNavController()
            NavHost(navController = navController, startDestination = ShopRoute) {
                composable<ShopRoute> { Text("layar-belanja") }
                composable<ProductDetailRoute> {
                    val probe = viewModel<HandleProbe> { HandleProbe(createSavedStateHandle()) }
                    slugSeenByViewModel = probe.handle.get<String>(ProductDetailRoute.SLUG_ARG)
                    Text("layar-detail")
                }
            }
        }
        rule.waitForIdle()

        rule.runOnUiThread { navController.navigate(ProductDetailRoute("gantungan-kunci-uji")) { launchSingleTop = true } }
        rule.waitForIdle()

        rule.onNodeWithText("layar-detail").assertIsDisplayed()
        assertEquals("gantungan-kunci-uji", slugSeenByViewModel)
    }
}
