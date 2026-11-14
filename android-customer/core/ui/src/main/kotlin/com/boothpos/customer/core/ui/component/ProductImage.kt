package com.boothpos.customer.core.ui.component

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import coil3.compose.AsyncImage
import coil3.request.ImageRequest
import com.boothpos.customer.core.ui.R
import com.boothpos.customer.core.ui.theme.BoothTheme

/**
 * Angka stabil untuk memilih warna ubin dan kapsul sebuah produk. Dihitung dari nama produk,
 * karena hanya nama yang tersedia di SEMUA layar (katalog, keranjang, checkout, pesanan): produk
 * yang sama memakai warna yang sama di mana pun ia tampil.
 */
fun paletteSeed(productName: String): Int = productName.hashCode()

/**
 * Gambar produk di dalam bingkai kapsul dua warna (tanpa ubin). Dipakai langsung oleh hero detail
 * produk dan kartu grid, yang menggambar ubinnya sendiri; [seamColor] = warna ubin itu.
 *
 * Foto tampil sebagai jendela bulat di tengah kapsul. Tanpa URL, ikon pengganti tampil tanpa
 * menyentuh jaringan. Bila pemuatan gagal, ikon "gambar rusak" beserta deskripsinya menggantikannya.
 */
@Composable
fun ProductCapsule(
    imageUrl: String?,
    contentDescription: String?,
    diameter: Dp,
    seamColor: Color,
    modifier: Modifier = Modifier,
    paletteSeed: Int = 0,
) {
    val sky = BoothTheme.sky
    var failed by remember(imageUrl) { mutableStateOf(false) }
    CapsuleFrame(
        diameter = diameter,
        lowerColor = sky.capsuleLower(paletteSeed),
        seamColor = seamColor,
        modifier = modifier,
    ) {
        val window = diameter * WINDOW_FRACTION
        Box(
            modifier = Modifier
                .size(window)
                .clip(CircleShape)
                .background(sky.capsuleUpper)
                // Cincin sewarna ubin: jendela foto tetap terbaca di atas belahan atas yang putih.
                .border(WindowBorder, seamColor, CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            when {
                imageUrl.isNullOrBlank() -> Icon(
                    painter = painterResource(R.drawable.core_ui_ic_image),
                    contentDescription = contentDescription,
                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.size(window / 2),
                )

                failed -> Icon(
                    painter = painterResource(R.drawable.core_ui_ic_broken_image),
                    contentDescription = stringResource(R.string.core_ui_image_failed),
                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.size(window / 2),
                )

                else -> {
                    // Diminta sebesar jendela tampilnya: hemat memori dan data di grid katalog.
                    val sizePx = with(LocalDensity.current) { window.roundToPx() }
                    val context = LocalContext.current
                    val request = remember(imageUrl, sizePx) {
                        ImageRequest.Builder(context).data(imageUrl).size(sizePx).build()
                    }
                    AsyncImage(
                        model = request,
                        contentDescription = contentDescription,
                        contentScale = ContentScale.Crop,
                        onError = { failed = true },
                        modifier = Modifier.fillMaxSize(),
                    )
                }
            }
        }
    }
}

/**
 * Gambar produk berukuran tetap: ubin berwarna dengan kapsul di dalamnya. [size] wajib, supaya
 * tata letak tidak melompat saat gambar tiba. [paletteSeed] (lihat [paletteSeed]) memilih warna
 * ubin dan kapsulnya.
 */
@Composable
fun ProductImage(
    imageUrl: String?,
    contentDescription: String?,
    size: Dp,
    modifier: Modifier = Modifier,
    paletteSeed: Int = 0,
) {
    val tint = BoothTheme.sky.tint(paletteSeed)
    Box(
        modifier = modifier
            .size(size)
            .clip(RoundedCornerShape(if (size > LargeTileFrom) LargeTileCorner else SmallTileCorner))
            .background(tint),
        contentAlignment = Alignment.Center,
    ) {
        ProductCapsule(
            imageUrl = imageUrl,
            contentDescription = contentDescription,
            diameter = size - TileInset,
            seamColor = tint,
            paletteSeed = paletteSeed,
        )
    }
}

/**
 * Gambar bulat kecil di atas warna latar (lingkaran kategori). [fallback] tampil bila tidak ada
 * URL atau gambarnya gagal dimuat. Dekoratif: label di bawahnya yang menyebut namanya.
 */
@Composable
fun CircleImage(
    imageUrl: String?,
    size: Dp,
    background: Color,
    modifier: Modifier = Modifier,
    fallback: @Composable BoxScope.() -> Unit,
) {
    var failed by remember(imageUrl) { mutableStateOf(false) }
    Box(
        modifier = modifier
            .size(size)
            .clip(CircleShape)
            .background(background),
        contentAlignment = Alignment.Center,
    ) {
        if (imageUrl.isNullOrBlank() || failed) {
            fallback()
        } else {
            val sizePx = with(LocalDensity.current) { size.roundToPx() }
            val context = LocalContext.current
            val request = remember(imageUrl, sizePx) {
                ImageRequest.Builder(context).data(imageUrl).size(sizePx).build()
            }
            AsyncImage(
                model = request,
                contentDescription = null,
                contentScale = ContentScale.Crop,
                onError = { failed = true },
                modifier = Modifier.fillMaxSize(),
            )
        }
    }
}

private const val WINDOW_FRACTION = 0.62f
private val WindowBorder = 2.dp
private val TileInset = 8.dp
private val LargeTileFrom = 56.dp
private val LargeTileCorner = 20.dp
private val SmallTileCorner = 16.dp
