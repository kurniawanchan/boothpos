package com.boothpos.customer.core.ui.component

import androidx.compose.ui.semantics.LiveRegionMode
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
class InlineMessageTest {

    @get:Rule
    val rule = createComposeRule()

    @Test
    fun `pesan diumumkan pembaca layar dan aksinya dapat ditekan`() {
        var actions = 0
        rule.setContent {
            BoothTheme {
                InlineMessage(
                    text = "Profil gagal diperbarui.",
                    tone = InlineMessageTone.Error,
                    actionLabel = "Coba lagi",
                    onAction = { actions++ },
                )
            }
        }

        rule.onNodeWithText("Profil gagal diperbarui.")
            .assertIsDisplayed()
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.LiveRegion, LiveRegionMode.Polite))
        rule.onNodeWithText("Coba lagi").assertHasClickAction().assertHeightIsAtLeast(48.dp).performClick()

        assertEquals(1, actions)
    }

    @Test
    fun `pesan tanpa aksi tidak menampilkan tombol`() {
        rule.setContent { BoothTheme { InlineMessage(text = "Sesi berakhir, silakan masuk lagi.") } }

        rule.onNodeWithText("Sesi berakhir, silakan masuk lagi.").assertIsDisplayed()
        rule.onNodeWithText("Coba lagi").assertDoesNotExist()
    }
}
