package com.boothpos.customer.core.ui.theme

import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.dropShadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.graphics.shadow.Shadow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.DpOffset
import androidx.compose.ui.unit.dp

/** Bayangan lembut desain: selalu berwarna (biru, atau kuning untuk tombol utama), tidak pernah abu/hitam. */
enum class SoftShadow(internal val radius: Dp, internal val offsetY: Dp, internal val alpha: Float) {
    /** Kartu dan tombol bulat: `0 8 24 / 12 %`. */
    Card(radius = 24.dp, offsetY = 8.dp, alpha = 0.12f),

    /** Navigasi bawah yang menempel: bayangan ke ATAS. */
    Dock(radius = 24.dp, offsetY = (-8).dp, alpha = 0.12f),

    /** Bingkai kapsul gambar produk. */
    Capsule(radius = 16.dp, offsetY = 6.dp, alpha = 0.20f),

    /** Pendar tombol aksi utama. */
    Glow(radius = 20.dp, offsetY = 8.dp, alpha = 0.45f),
}

/**
 * Bayangan digambar sendiri (bukan `Modifier.shadow`): bayangan elevasi berwarna baru ada di
 * API 28, sedangkan minSdk 26, dan di sana ia jatuh kembali ke abu-abu/hitam.
 *
 * Harus dipasang SEBELUM latar/clip pada rantai modifier. [color] = `BoothTheme.sky.shadow`
 * (atau warna pendar tombol); sengaja parameter biasa supaya fungsi ini bukan composable.
 */
fun Modifier.softShadow(shape: Shape, color: Color, style: SoftShadow = SoftShadow.Card): Modifier =
    dropShadow(
        shape = shape,
        shadow = Shadow(
            radius = style.radius,
            color = color,
            offset = DpOffset(0.dp, style.offsetY),
            alpha = style.alpha,
        ),
    )

/**
 * Permintaan "latar hero" dari layar kepada bingkai aplikasi. Layar yang seluruh latarnya berwarna
 * hero (sambutan, detail produk, "Pesanan dibuat") tetap mewarnai dirinya sendiri; permintaan ini
 * hanya membuat bilah atas ikut berwarna sama, supaya tidak ada sambungan warna di bawahnya.
 *
 * Dihitung (bukan boolean): saat berpindah layar, layar baru dapat tersusun sebelum layar lama
 * dibuang, dan urutan itu tidak boleh menentukan hasil akhirnya.
 */
@Stable
class HeroBackdropRequests {
    private var count by mutableIntStateOf(0)

    val active: Boolean
        get() = count > 0

    internal fun acquire() {
        count++
    }

    internal fun release() {
        count--
    }
}

/** Disediakan bingkai aplikasi; null di luar bingkai (tes layar, pratinjau), dan itu bukan galat. */
val LocalHeroBackdropRequests = staticCompositionLocalOf<HeroBackdropRequests?> { null }

/** Dipanggil layar selama ia menampilkan latar hero. Tidak menggambar apa pun. */
@Composable
fun HeroBackdrop() {
    val requests = LocalHeroBackdropRequests.current ?: return
    DisposableEffect(requests) {
        requests.acquire()
        onDispose { requests.release() }
    }
}
