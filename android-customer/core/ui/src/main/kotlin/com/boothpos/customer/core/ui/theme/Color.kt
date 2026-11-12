package com.boothpos.customer.core.ui.theme

import androidx.compose.material3.ColorScheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Immutable
import androidx.compose.ui.graphics.Color

// Satu palet tenang (biru-abu netral), tanpa gradien dan tanpa warna dinamis: tampilan sama di
// semua perangkat. Setiap pasangan teks/latar diuji kontras WCAG AA di ThemeContrastTest.
internal val LightColors: ColorScheme = lightColorScheme(
    primary = Color(0xFF1B4F7A),
    onPrimary = Color(0xFFFFFFFF),
    primaryContainer = Color(0xFFD3E4F5),
    onPrimaryContainer = Color(0xFF0A2A45),
    inversePrimary = Color(0xFF9CCAF2),
    secondary = Color(0xFF4E6070),
    onSecondary = Color(0xFFFFFFFF),
    secondaryContainer = Color(0xFFDCE4EC),
    onSecondaryContainer = Color(0xFF16222C),
    tertiary = Color(0xFF5F5A7D),
    onTertiary = Color(0xFFFFFFFF),
    tertiaryContainer = Color(0xFFE5DFFA),
    onTertiaryContainer = Color(0xFF1C1736),
    background = Color(0xFFFAFBFC),
    onBackground = Color(0xFF171C21),
    surface = Color(0xFFFAFBFC),
    onSurface = Color(0xFF171C21),
    surfaceVariant = Color(0xFFDEE3EA),
    onSurfaceVariant = Color(0xFF414850),
    surfaceTint = Color(0xFF1B4F7A),
    inverseSurface = Color(0xFF2C3136),
    inverseOnSurface = Color(0xFFEEF1F4),
    error = Color(0xFFB3261E),
    onError = Color(0xFFFFFFFF),
    errorContainer = Color(0xFFF9DEDC),
    onErrorContainer = Color(0xFF410E0B),
    outline = Color(0xFF6B727A),
    outlineVariant = Color(0xFFC1C7CE),
    scrim = Color(0xFF000000),
    surfaceBright = Color(0xFFFAFBFC),
    surfaceDim = Color(0xFFD8DBDF),
    surfaceContainerLowest = Color(0xFFFFFFFF),
    surfaceContainerLow = Color(0xFFF3F5F7),
    surfaceContainer = Color(0xFFEDEFF2),
    surfaceContainerHigh = Color(0xFFE7EAED),
    surfaceContainerHighest = Color(0xFFE1E4E8),
)

internal val DarkColors: ColorScheme = darkColorScheme(
    primary = Color(0xFF9CCAF2),
    onPrimary = Color(0xFF003353),
    primaryContainer = Color(0xFF1B4F7A),
    onPrimaryContainer = Color(0xFFD3E4F5),
    inversePrimary = Color(0xFF1B4F7A),
    secondary = Color(0xFFB6C8D9),
    onSecondary = Color(0xFF21323F),
    secondaryContainer = Color(0xFF374957),
    onSecondaryContainer = Color(0xFFD2E4F5),
    tertiary = Color(0xFFC9C2EA),
    onTertiary = Color(0xFF312C4C),
    tertiaryContainer = Color(0xFF474264),
    onTertiaryContainer = Color(0xFFE5DFFA),
    background = Color(0xFF101418),
    onBackground = Color(0xFFE0E3E7),
    surface = Color(0xFF101418),
    onSurface = Color(0xFFE0E3E7),
    surfaceVariant = Color(0xFF414850),
    onSurfaceVariant = Color(0xFFC1C7CE),
    surfaceTint = Color(0xFF9CCAF2),
    inverseSurface = Color(0xFFE0E3E7),
    inverseOnSurface = Color(0xFF2C3136),
    error = Color(0xFFF2B8B5),
    onError = Color(0xFF601410),
    errorContainer = Color(0xFF8C1D18),
    onErrorContainer = Color(0xFFF9DEDC),
    outline = Color(0xFF8B9198),
    outlineVariant = Color(0xFF414850),
    scrim = Color(0xFF000000),
    surfaceBright = Color(0xFF363A3E),
    surfaceDim = Color(0xFF101418),
    surfaceContainerLowest = Color(0xFF0B0F12),
    surfaceContainerLow = Color(0xFF181C20),
    surfaceContainer = Color(0xFF1C2024),
    surfaceContainerHigh = Color(0xFF272B2F),
    surfaceContainerHighest = Color(0xFF31363A),
)

/** Warna status yang tidak ada di skema Material 3 (berhasil, peringatan). */
@Immutable
data class StatusColors(
    val successContainer: Color,
    val onSuccessContainer: Color,
    val warningContainer: Color,
    val onWarningContainer: Color,
)

internal val LightStatusColors = StatusColors(
    successContainer = Color(0xFFD7EFDD),
    onSuccessContainer = Color(0xFF0D3A1E),
    warningContainer = Color(0xFFFFE9C2),
    onWarningContainer = Color(0xFF4A2E00),
)

internal val DarkStatusColors = StatusColors(
    successContainer = Color(0xFF1E5631),
    onSuccessContainer = Color(0xFFC9EFD3),
    warningContainer = Color(0xFF5F4100),
    onWarningContainer = Color(0xFFFFDEA6),
)
