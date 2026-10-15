package com.warkop.pos.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.unit.dp

/**
 * Token warna diambil APA ADANYA dari design/screens/pos-app-logic.js (M3L / M3D, seed merah WJM #EC3013),
 * bukan diperkirakan dari tangkapan layar. Peran yang tidak ada di Material 3 (ok/warning/QRIS dll.)
 * dipisah ke [PosColors] supaya tidak menyalahgunakan peran `tertiary`.
 */
@Immutable
data class PosColors(
    val ok: Color, val okContainer: Color, val onOkContainer: Color,
    val warn: Color, val warnContainer: Color, val onWarnContainer: Color,
    val tertiaryTile: Color, val onTertiaryTile: Color,
    val occupied: Color, val onOccupied: Color,
    val frame: Color,
)

val LocalPosColors = staticCompositionLocalOf<PosColors> { error("PosTheme belum dipasang") }

private val Light: ColorScheme = lightColorScheme(
    primary = Color(0xFFB02A17), onPrimary = Color(0xFFFFFFFF),
    primaryContainer = Color(0xFFFFDAD4), onPrimaryContainer = Color(0xFF3F0400),
    secondary = Color(0xFF77524D), onSecondary = Color(0xFFFFFFFF),
    secondaryContainer = Color(0xFFFFDAD4), onSecondaryContainer = Color(0xFF2C1511),
    tertiary = Color(0xFF6D5D2E), tertiaryContainer = Color(0xFFFBDFA6), onTertiaryContainer = Color(0xFF251A00),
    background = Color(0xFFFFF8F6), onBackground = Color(0xFF231918),
    surface = Color(0xFFFFF8F6), onSurface = Color(0xFF231918), surfaceVariant = Color(0xFFF5DDD9), onSurfaceVariant = Color(0xFF534341),
    surfaceContainerLowest = Color(0xFFFFFFFF), surfaceContainerLow = Color(0xFFFFF0EE),
    surfaceContainer = Color(0xFFFCEAE7), surfaceContainerHigh = Color(0xFFF6E4E1), surfaceContainerHighest = Color(0xFFF1DEDB),
    outline = Color(0xFF857370), outlineVariant = Color(0xFFD8C2BE),
    error = Color(0xFFBA1A1A), onError = Color(0xFFFFFFFF), errorContainer = Color(0xFFFFDAD6), onErrorContainer = Color(0xFF410002),
    inverseSurface = Color(0xFF392E2C), inverseOnSurface = Color(0xFFFFEDEA), inversePrimary = Color(0xFFFFB4A8),
    scrim = Color(0x52000000),
)

private val Dark: ColorScheme = darkColorScheme(
    primary = Color(0xFFFFB4A8), onPrimary = Color(0xFF690100),
    primaryContainer = Color(0xFF8F1406), onPrimaryContainer = Color(0xFFFFDAD4),
    secondary = Color(0xFFE7BDB6), onSecondary = Color(0xFF442925),
    secondaryContainer = Color(0xFF5D3F3A), onSecondaryContainer = Color(0xFFFFDAD4),
    tertiary = Color(0xFFDEC48C), tertiaryContainer = Color(0xFF574419), onTertiaryContainer = Color(0xFFFBDFA6),
    background = Color(0xFF1A1110), onBackground = Color(0xFFF1DEDB),
    surface = Color(0xFF1A1110), onSurface = Color(0xFFF1DEDB), surfaceVariant = Color(0xFF534341), onSurfaceVariant = Color(0xFFD8C2BE),
    surfaceContainerLowest = Color(0xFF140C0B), surfaceContainerLow = Color(0xFF231918),
    surfaceContainer = Color(0xFF271D1C), surfaceContainerHigh = Color(0xFF322826), surfaceContainerHighest = Color(0xFF3D3231),
    outline = Color(0xFFA08C89), outlineVariant = Color(0xFF534341),
    error = Color(0xFFFFB4AB), onError = Color(0xFF690005), errorContainer = Color(0xFF93000A), onErrorContainer = Color(0xFFFFDAD6),
    inverseSurface = Color(0xFFF1DEDB), inverseOnSurface = Color(0xFF392E2C), inversePrimary = Color(0xFFB02A17),
    scrim = Color(0x80000000),
)

private val PosLight = PosColors(
    ok = Color(0xFF2B6A46), okContainer = Color(0xFFB1F1C3), onOkContainer = Color(0xFF002111),
    warn = Color(0xFF7A5900), warnContainer = Color(0xFFFFDEA6), onWarnContainer = Color(0xFF261900),
    tertiaryTile = Color(0xFFFBDFA6), onTertiaryTile = Color(0xFF251A00),
    occupied = Color(0xFFB02A17), onOccupied = Color(0xFFFFFFFF), frame = Color(0xFF1B1B1F),
)
private val PosDark = PosColors(
    ok = Color(0xFF96D5A9), okContainer = Color(0xFF0F5131), onOkContainer = Color(0xFFB1F1C3),
    warn = Color(0xFFF5BD48), warnContainer = Color(0xFF5C4200), onWarnContainer = Color(0xFFFFDEA6),
    tertiaryTile = Color(0xFF574419), onTertiaryTile = Color(0xFFFBDFA6),
    occupied = Color(0xFFFFB4A8), onOccupied = Color(0xFF690100), frame = Color(0xFF000000),
)

private val PosShapes = Shapes(
    extraSmall = RoundedCornerShape(8.dp), small = RoundedCornerShape(12.dp), medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(20.dp), extraLarge = RoundedCornerShape(28.dp),
)

object PosColorsAccessor {
    val current: PosColors @Composable get() = LocalPosColors.current
}

/** `dark = null` mengikuti sistem; desain juga menyalakan mode gelap otomatis pada jam malam (>= 18 atau < 6). */
@Composable
fun PosTheme(dark: Boolean? = null, content: @Composable () -> Unit) {
    val isDark = dark ?: isSystemInDarkTheme()
    CompositionLocalProvider(LocalPosColors provides if (isDark) PosDark else PosLight) {
        MaterialTheme(colorScheme = if (isDark) Dark else Light, shapes = PosShapes, content = content)
    }
}

val MaterialTheme.pos: PosColors @Composable get() = LocalPosColors.current
