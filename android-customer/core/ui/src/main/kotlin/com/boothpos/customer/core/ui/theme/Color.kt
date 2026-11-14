package com.boothpos.customer.core.ui.theme

import androidx.compose.material3.ColorScheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Immutable
import androidx.compose.ui.graphics.Color

// Desain "Sky Capsule" (skema terang): biru langit, kuning cerah, koral. Tanpa warna dinamis:
// tampilan sama di semua perangkat. Setiap pasangan teks/latar diuji kontras WCAG AA di
// ThemeContrastTest.
//
// Pemetaan token desain ke peran Material 3 (sengaja, jangan ditukar):
// - Kuning aksi utama (#FFD84D / #3B2A00) ada di primaryContainer/onPrimaryContainer, BUKAN di
//   primary: `primary` juga dipakai Material untuk tombol teks, tautan, dan fokus kolom isian, dan
//   kuning di atas putih hanya ±1,4 : 1. `primary` = biru tautan, sedikit lebih gelap daripada
//   token desain (#1F6FD0 -> #1A66C2): tautan juga tampil di atas warna hero (#DCEEFF, mis. layar
//   sambutan), dan di sana #1F6FD0 hanya ±4,2 : 1.
// - Isi "terpilih" yang lembut (#FFF3C4) = secondaryContainer.
// - Catatan koral (#FFE3DD / #7A2A1A) = tertiaryContainer/onTertiaryContainer.
// - Kartu putih = surfaceContainerLowest..High (komponen memakai surfaceContainerLow: di skema
//   gelap itulah permukaan yang sedikit lebih terang dari latar); latar halaman (#F4F9FF) = background/surface.
// - Teks: tinta rgb(20,40,74) 100 % = onSurface. Teks sekunder desain adalah tinta 60 %, tetapi itu
//   hanya ±4,0 : 1 di atas latar; onSurfaceVariant memakai padanan tinta ±70 % supaya lolos AA.
internal val Ink = Color(0xFF14284A)

/** Tinta 80 % di atas putih: teks isi. */
internal val InkBody = Color(0xFF435370)

/** Tinta ±70 % di atas putih: teks sekunder (lihat catatan kontras di atas). */
internal val InkMuted = Color(0xFF5B6980)

internal val LightColors: ColorScheme = lightColorScheme(
    primary = Color(0xFF1A66C2),
    onPrimary = Color(0xFFFFFFFF),
    primaryContainer = Color(0xFFFFD84D),
    onPrimaryContainer = Color(0xFF3B2A00),
    inversePrimary = Color(0xFF8FC8FF),
    secondary = Color(0xFF3A5A8C),
    onSecondary = Color(0xFFFFFFFF),
    secondaryContainer = Color(0xFFFFF3C4),
    onSecondaryContainer = Color(0xFF3B2A00),
    tertiary = Color(0xFFA8402B),
    onTertiary = Color(0xFFFFFFFF),
    tertiaryContainer = Color(0xFFFFE3DD),
    onTertiaryContainer = Color(0xFF7A2A1A),
    background = Color(0xFFF4F9FF),
    onBackground = Ink,
    surface = Color(0xFFF4F9FF),
    onSurface = Ink,
    surfaceVariant = Color(0xFFDCEEFF),
    onSurfaceVariant = InkMuted,
    surfaceTint = Color(0xFF1A66C2),
    inverseSurface = Ink,
    inverseOnSurface = Color(0xFFF4F9FF),
    error = Color(0xFFB3261E),
    onError = Color(0xFFFFFFFF),
    errorContainer = Color(0xFFF9DEDC),
    onErrorContainer = Color(0xFF410E0B),
    // Tinta 25 % dan 10 % di atas putih: garis tepi kolom isian/radio dan garis pemisah.
    outline = Color(0xFFC4C9D2),
    outlineVariant = Color(0xFFE8EAED),
    scrim = Ink,
    surfaceBright = Color(0xFFFFFFFF),
    surfaceDim = Color(0xFFD9E6F5),
    surfaceContainerLowest = Color(0xFFFFFFFF),
    surfaceContainerLow = Color(0xFFFFFFFF),
    surfaceContainer = Color(0xFFFFFFFF),
    surfaceContainerHigh = Color(0xFFFFFFFF),
    surfaceContainerHighest = Color(0xFFE6F0FC),
)

// Skema gelap BELUM didesain ("Sky Capsule" hanya punya mockup terang). Sampai pemilik produk
// memutuskan, warna gelap lama dipertahankan apa adanya; bentuk dan tipografi tetap ikut desain baru.
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

// Terang: tag positif desain (#D6F5E8 / #0F5B45) dan kuning lembut untuk "sedang berjalan".
internal val LightStatusColors = StatusColors(
    successContainer = Color(0xFFD6F5E8),
    onSuccessContainer = Color(0xFF0F5B45),
    warningContainer = Color(0xFFFFF3C4),
    onWarningContainer = Color(0xFF3B2A00),
)

internal val DarkStatusColors = StatusColors(
    successContainer = Color(0xFF1E5631),
    onSuccessContainer = Color(0xFFC9EFD3),
    warningContainer = Color(0xFF5F4100),
    onWarningContainer = Color(0xFFFFDEA6),
)

/**
 * Token "Sky Capsule" yang tidak punya peran di skema Material 3: warna latar hero, isi batang
 * progres, lencana keranjang, daftar warna ubin produk/kategori, dan belahan bawah kapsul.
 *
 * Ubin dan kapsul dipilih berurutan (indeks modulo panjang daftar), jadi daftar tidak boleh kosong.
 */
@Immutable
data class SkyColors(
    /** Latar status/hero (kartu status pesanan, layar sambutan, layar "Pesanan dibuat"). */
    val hero: Color,
    val onHero: Color,
    /** Isi batang progres (langkah checkout, status pesanan). Bukan warna teks. */
    val progress: Color,
    val progressTrack: Color,
    val coral: Color,
    val badge: Color,
    val onBadge: Color,
    /** Isi tombol sekunder dan lencana netral: tinta sangat tipis. */
    val subtle: Color,
    val tints: List<Color>,
    val capsuleUpper: Color,
    val capsuleLowers: List<Color>,
    /** Warna dasar bayangan lembut; alfanya ditentukan pemakai (lihat `softShadow`). */
    val shadow: Color,
    /** Pendar di bawah tombol aksi utama; transparan berarti tanpa pendar. */
    val actionGlow: Color,
) {
    fun tint(index: Int): Color = tints[Math.floorMod(index, tints.size)]

    fun capsuleLower(index: Int): Color = capsuleLowers[Math.floorMod(index, capsuleLowers.size)]
}

internal val LightSkyColors = SkyColors(
    hero = Color(0xFFDCEEFF),
    onHero = Ink,
    progress = Color(0xFF4DA3FF),
    progressTrack = Ink.copy(alpha = 0.12f),
    coral = Color(0xFFFF8E7C),
    badge = Color(0xFFE8472F),
    onBadge = Color(0xFFFFFFFF),
    subtle = Color(0xFFE9EEF5),
    tints = listOf(Color(0xFFDCEEFF), Color(0xFFFFE3DD), Color(0xFFFFF3C4), Color(0xFFD6F5E8), Color(0xFFE7E3FF)),
    capsuleUpper = Color(0xFFFFFFFF),
    capsuleLowers = listOf(Color(0xFFFF8E7C), Color(0xFF7FDDB8), Color(0xFFFFD84D), Color(0xFF8FC8FF)),
    shadow = Color(0xFF2E6EBE),
    actionGlow = Color(0xFFFFC428),
)

// Gelap: HANYA warna yang sudah ada di skema gelap lama (tidak ada warna baru yang dikarang).
internal val DarkSkyColors = SkyColors(
    hero = DarkColors.surfaceContainerHigh,
    onHero = DarkColors.onSurface,
    progress = DarkColors.primary,
    progressTrack = DarkColors.onSurface.copy(alpha = 0.12f),
    coral = DarkColors.tertiary,
    badge = DarkColors.error,
    onBadge = DarkColors.onError,
    subtle = DarkColors.surfaceContainerHighest,
    tints = listOf(
        DarkColors.surfaceContainerHigh,
        DarkColors.tertiaryContainer,
        DarkColors.secondaryContainer,
        DarkColors.surfaceContainerHighest,
        DarkColors.surfaceVariant,
    ),
    capsuleUpper = DarkColors.surfaceBright,
    capsuleLowers = listOf(
        DarkColors.primaryContainer,
        DarkColors.tertiaryContainer,
        DarkColors.secondaryContainer,
        DarkColors.surfaceVariant,
    ),
    shadow = DarkColors.scrim,
    actionGlow = Color.Transparent,
)
