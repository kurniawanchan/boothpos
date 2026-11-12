package com.boothpos.customer.core.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.ReadOnlyComposable
import androidx.compose.runtime.staticCompositionLocalOf

private val LocalStatusColors = staticCompositionLocalOf { LightStatusColors }

/**
 * Tema tunggal aplikasi (terang + gelap). Tipografi memakai bawaan Material 3 dengan font sistem,
 * sehingga skala teks pengguna dihormati tanpa aset font tambahan.
 */
@Composable
fun BoothTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    CompositionLocalProvider(LocalStatusColors provides if (darkTheme) DarkStatusColors else LightStatusColors) {
        MaterialTheme(
            colorScheme = if (darkTheme) DarkColors else LightColors,
            shapes = BoothShapes,
            content = content,
        )
    }
}

object BoothTheme {
    val statusColors: StatusColors
        @Composable
        @ReadOnlyComposable
        get() = LocalStatusColors.current
}
