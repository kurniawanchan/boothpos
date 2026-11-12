package com.boothpos.customer.core.ui.component

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.size
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
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.Dp
import coil3.compose.AsyncImage
import coil3.request.ImageRequest
import com.boothpos.customer.core.ui.R

/**
 * Gambar produk berukuran tetap. [size] wajib: permintaan gambar dikecilkan ke ukuran tampil
 * (hemat memori dan data di grid katalog) dan tata letak tidak melompat saat gambar tiba.
 *
 * Tanpa URL, ikon pengganti tampil tanpa menyentuh jaringan. Bila pemuatan gagal, ikon "gambar
 * rusak" beserta deskripsinya menggantikan gambar.
 */
@Composable
fun ProductImage(
    imageUrl: String?,
    contentDescription: String?,
    size: Dp,
    modifier: Modifier = Modifier,
) {
    var failed by remember(imageUrl) { mutableStateOf(false) }
    Box(
        modifier = modifier
            .size(size)
            .clip(MaterialTheme.shapes.small)
            .background(MaterialTheme.colorScheme.surfaceVariant),
        contentAlignment = Alignment.Center,
    ) {
        when {
            imageUrl.isNullOrBlank() -> Icon(
                painter = painterResource(R.drawable.core_ui_ic_image),
                contentDescription = contentDescription,
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.size(size / 3),
            )

            failed -> Icon(
                painter = painterResource(R.drawable.core_ui_ic_broken_image),
                contentDescription = stringResource(R.string.core_ui_image_failed),
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.size(size / 3),
            )

            else -> {
                val sizePx = with(LocalDensity.current) { size.roundToPx() }
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
