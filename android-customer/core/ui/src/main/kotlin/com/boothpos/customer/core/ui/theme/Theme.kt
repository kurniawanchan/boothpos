package com.boothpos.customer.core.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.ReadOnlyComposable
import androidx.compose.runtime.staticCompositionLocalOf

private val LocalStatusColors = staticCompositionLocalOf { LightStatusColors }
private val LocalSkyColors = staticCompositionLocalOf { LightSkyColors }

/**
 * Tema tunggal aplikasi, desain "Sky Capsule". Warna terang, bentuk, dan tipografi (Fredoka)
 * mengikuti desain; skema gelap masih warna lama (belum didesain). Ukuran huruf dalam sp, jadi
 * skala teks pengguna tetap dihormati.
 */
@Composable
fun BoothTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    CompositionLocalProvider(
        LocalStatusColors provides if (darkTheme) DarkStatusColors else LightStatusColors,
        LocalSkyColors provides if (darkTheme) DarkSkyColors else LightSkyColors,
    ) {
        MaterialTheme(
            colorScheme = if (darkTheme) DarkColors else LightColors,
            shapes = BoothShapes,
            typography = BoothTypography,
            content = content,
        )
    }
}

object BoothTheme {
    val statusColors: StatusColors
        @Composable
        @ReadOnlyComposable
        get() = LocalStatusColors.current

    /** Token desain di luar skema Material 3 (hero, progres, ubin, kapsul, bayangan). */
    val sky: SkyColors
        @Composable
        @ReadOnlyComposable
        get() = LocalSkyColors.current
}
