package com.boothpos.customer.feature.auth.presentation

import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.unit.dp
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.ui.theme.BoothTheme
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config

@RunWith(AndroidJUnit4::class)
@Config(qualifiers = "w360dp-h640dp")
class AuthChoiceScreenTest {

    @get:Rule
    val rule = createComposeRule()

    @Test
    fun `tiga pilihan tampil dan masing-masing memanggil callback-nya`() {
        val chosen = mutableListOf<String>()
        rule.setContent {
            BoothTheme {
                AuthChoiceScreen(
                    onSignIn = { chosen += "masuk" },
                    onRegister = { chosen += "daftar" },
                    onContinueAsGuest = { chosen += "tamu" },
                )
            }
        }

        rule.onNodeWithText("Selamat datang")
            .assertIsDisplayed()
            .assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading))
        listOf("Masuk", "Daftar", "Lihat toko tanpa akun").forEach { label ->
            rule.onNodeWithText(label).assertIsDisplayed().assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()
        }

        assertEquals(listOf("masuk", "daftar", "tamu"), chosen)
    }
}
