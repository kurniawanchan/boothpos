package com.boothpos.customer.core.ui.theme

import androidx.compose.material3.ColorScheme
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.luminance
import org.junit.Assert.assertTrue
import org.junit.Test

/** WCAG 2.x AA: teks normal butuh rasio kontras minimal 4,5 : 1. */
class ThemeContrastTest {

    private fun ratio(a: Color, b: Color): Float {
        val (light, dark) = listOf(a.luminance(), b.luminance()).sortedDescending()
        return (light + 0.05f) / (dark + 0.05f)
    }

    private fun assertAa(name: String, foreground: Color, background: Color) {
        val value = ratio(foreground, background)
        assertTrue("$name kontras $value < 4.5", value >= 4.5f)
    }

    private fun assertScheme(label: String, scheme: ColorScheme, status: StatusColors) {
        assertAa("$label onPrimary/primary", scheme.onPrimary, scheme.primary)
        assertAa("$label onPrimaryContainer", scheme.onPrimaryContainer, scheme.primaryContainer)
        assertAa("$label onSecondary/secondary", scheme.onSecondary, scheme.secondary)
        assertAa("$label onSecondaryContainer", scheme.onSecondaryContainer, scheme.secondaryContainer)
        assertAa("$label onTertiaryContainer", scheme.onTertiaryContainer, scheme.tertiaryContainer)
        assertAa("$label onBackground", scheme.onBackground, scheme.background)
        assertAa("$label onSurface", scheme.onSurface, scheme.surface)
        assertAa("$label onSurface/surfaceContainerHighest", scheme.onSurface, scheme.surfaceContainerHighest)
        assertAa("$label onSurfaceVariant/surface", scheme.onSurfaceVariant, scheme.surface)
        assertAa("$label onSurfaceVariant/surfaceVariant", scheme.onSurfaceVariant, scheme.surfaceVariant)
        assertAa("$label onSurfaceVariant/surfaceContainer", scheme.onSurfaceVariant, scheme.surfaceContainer)
        assertAa("$label primary/surface (tombol teks, tautan)", scheme.primary, scheme.surface)
        assertAa("$label error/surface (galat kolom)", scheme.error, scheme.surface)
        assertAa("$label onError/error", scheme.onError, scheme.error)
        assertAa("$label onErrorContainer", scheme.onErrorContainer, scheme.errorContainer)
        assertAa("$label inverseOnSurface (snackbar)", scheme.inverseOnSurface, scheme.inverseSurface)
        assertAa("$label onSuccessContainer", status.onSuccessContainer, status.successContainer)
        assertAa("$label onWarningContainer", status.onWarningContainer, status.warningContainer)
    }

    @Test
    fun `tema terang memenuhi kontras AA`() = assertScheme("terang", LightColors, LightStatusColors)

    @Test
    fun `tema gelap memenuhi kontras AA`() = assertScheme("gelap", DarkColors, DarkStatusColors)
}
