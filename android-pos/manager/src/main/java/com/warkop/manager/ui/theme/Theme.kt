package com.warkop.manager.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

/**
 * 094 / T039 — warna merek dipetakan SEKALI dari token web `resources/css/app.css` (`--color-brand`, `--color-ink`,
 * `--color-surface`, `--color-danger-text`, `--color-success-text`). Komponen memakai `MaterialTheme.colorScheme` dan
 * [ManagerColors], tidak pernah hex lepas (Konstitusi III). Warna dinamis Material You SENGAJA tidak dipakai: produk ini
 * dikirim ke pelanggan dan harus tampil dengan warna merek yang sama di semua perangkat (catatan di research D2).
 */
private object Brand {
    val Indigo = Color(0xFF4F46E5)        // --color-brand
    val IndigoLight = Color(0xFFA5A1F5)   // turunan untuk mode gelap (kontras di latar gelap)
    val Ink = Color(0xFF0F172A)           // --color-ink
    val Surface = Color(0xFFF8FAFC)       // --color-surface
    val SurfaceSoft = Color(0xFFFFFFFF)   // --color-surface-soft
    val Muted = Color(0xFF64748B)         // --color-muted
    val Danger = Color(0xFFB91C1C)        // --color-danger-text
    val DangerBg = Color(0xFFFEF2F2)      // --color-danger-bg
    val Success = Color(0xFF186D54)       // --color-success-text
    val SuccessBg = Color(0xFFD1FAE5)     // --color-success-bg
    val Warning = Color(0xFFB45309)
    val WarningBg = Color(0xFFFEF3C7)
}

private val LightScheme = lightColorScheme(
    primary = Brand.Indigo,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFE0E7FF),
    onPrimaryContainer = Color(0xFF1E1B4B),
    secondary = Brand.Muted,
    background = Brand.Surface,
    onBackground = Brand.Ink,
    surface = Brand.SurfaceSoft,
    onSurface = Brand.Ink,
    surfaceVariant = Color(0xFFF1F5F9),
    onSurfaceVariant = Color(0xFF475569),
    outline = Color(0xFFCBD5E1),
    error = Brand.Danger,
    errorContainer = Brand.DangerBg,
)

private val DarkScheme = darkColorScheme(
    primary = Brand.IndigoLight,
    onPrimary = Color(0xFF1E1B4B),
    primaryContainer = Color(0xFF3730A3),
    onPrimaryContainer = Color(0xFFE0E7FF),
    secondary = Color(0xFF94A3B8),
    background = Brand.Ink,
    onBackground = Color(0xFFE2E8F0),
    surface = Color(0xFF111827),
    onSurface = Color(0xFFE2E8F0),
    surfaceVariant = Color(0xFF1E293B),
    onSurfaceVariant = Color(0xFFCBD5E1),
    outline = Color(0xFF475569),
    error = Color(0xFFF87171),
    errorContainer = Color(0xFF450A0A),
)

/** Warna status di luar skema Material (pita mode, label status). */
data class StatusColors(val fg: Color, val bg: Color)

object ManagerColors {
    val success = StatusColors(Brand.Success, Brand.SuccessBg)
    val warning = StatusColors(Brand.Warning, Brand.WarningBg)
    val danger = StatusColors(Brand.Danger, Brand.DangerBg)
}

@Composable
fun ManagerTheme(dark: Boolean = isSystemInDarkTheme(), content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = if (dark) DarkScheme else LightScheme, content = content)
}
