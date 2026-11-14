package com.boothpos.customer.core.ui.component

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.painter.Painter
import androidx.compose.ui.graphics.vector.rememberVectorPainter
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.boothpos.customer.core.ui.R
import com.boothpos.customer.core.ui.icon.SkyIcons
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.Spacing

// Empat keadaan baku layar data: memuat, kosong, galat (dengan coba lagi), dan toko tidak tersedia.
// Semuanya stateless; ViewModel yang menentukan keadaan mana yang tampil.

@Composable
fun LoadingState(
    modifier: Modifier = Modifier,
    label: String = stringResource(R.string.core_ui_loading),
) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .padding(Spacing.lg)
            .semantics(mergeDescendants = true) {},
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        CircularProgressIndicator()
        Spacer(Modifier.height(Spacing.md))
        Text(label, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
fun EmptyState(
    title: String,
    modifier: Modifier = Modifier,
    message: String? = null,
    icon: Painter? = null,
    actionLabel: String? = null,
    onAction: (() -> Unit)? = null,
) {
    MessageState(
        icon = icon,
        title = title,
        message = message,
        modifier = modifier,
        action = if (actionLabel != null && onAction != null) {
            { PrimaryButton(text = actionLabel, onClick = onAction) }
        } else {
            null
        },
    )
}

@Composable
fun ErrorState(
    message: String,
    onRetry: (() -> Unit)?,
    modifier: Modifier = Modifier,
    title: String = stringResource(R.string.core_ui_error_title),
    icon: Painter = rememberVectorPainter(SkyIcons.Alert),
    retrying: Boolean = false,
) {
    MessageState(
        icon = icon,
        title = title,
        message = message,
        modifier = modifier,
        announce = true,
        action = onRetry?.let { retry ->
            { PrimaryButton(text = stringResource(R.string.core_ui_retry), onClick = retry, loading = retrying) }
        },
    )
}

/**
 * Fitur storefront dimatikan (503 `customer_commerce_disabled`) atau instalasi terkunci (423).
 * Nadanya tenang: ini bukan kesalahan pengguna, dan pesanan lama tetap dapat dilihat.
 */
@Composable
fun StoreUnavailableState(
    onRetry: () -> Unit,
    modifier: Modifier = Modifier,
    retrying: Boolean = false,
) {
    MessageState(
        icon = rememberVectorPainter(SkyIcons.Info),
        title = stringResource(R.string.core_ui_store_unavailable_title),
        message = stringResource(R.string.core_ui_store_unavailable_message),
        modifier = modifier,
        announce = true,
        action = { PrimaryButton(text = stringResource(R.string.core_ui_retry), onClick = onRetry, loading = retrying) },
    )
}

/**
 * Pita tipis di atas konten tersimpan ketika perangkat offline. Ikon + teks, bukan warna saja.
 * [actionLabel] + [onAction] menambahkan satu aksi (mis. "Coba lagi") di ujung pita.
 */
@Composable
fun OfflineBanner(
    modifier: Modifier = Modifier,
    message: String = stringResource(R.string.core_ui_offline_banner),
    actionLabel: String? = null,
    onAction: (() -> Unit)? = null,
) {
    val hasAction = actionLabel != null && onAction != null
    Surface(
        color = MaterialTheme.colorScheme.surfaceVariant,
        contentColor = MaterialTheme.colorScheme.onSurfaceVariant,
        shape = MaterialTheme.shapes.small,
        modifier = modifier
            .fillMaxWidth()
            .padding(horizontal = Spacing.md),
    ) {
        Row(
            modifier = Modifier.padding(
                start = Spacing.ms,
                end = if (hasAction) Spacing.xs else Spacing.ms,
                top = Spacing.sm,
                bottom = Spacing.sm,
            ),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Row(
                modifier = Modifier
                    .weight(1f)
                    .semantics(mergeDescendants = true) { liveRegion = LiveRegionMode.Polite },
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(painterResource(R.drawable.core_ui_ic_cloud_off), contentDescription = null, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(Spacing.sm))
                Text(message, style = MaterialTheme.typography.bodySmall)
            }
            if (actionLabel != null && onAction != null) {
                TextButton(
                    onClick = onAction,
                    colors = ButtonDefaults.textButtonColors(contentColor = LocalContentColor.current),
                    modifier = Modifier.heightIn(min = Dimens.MinTouchTarget),
                ) {
                    Text(actionLabel)
                }
            }
        }
    }
}

@Composable
private fun MessageState(
    icon: Painter?,
    title: String,
    message: String?,
    modifier: Modifier = Modifier,
    announce: Boolean = false,
    action: (@Composable () -> Unit)? = null,
) {
    // Di tengah bila muat; DIGULIR bila tidak (huruf sistem 200 %, layar pendek/landscape), supaya
    // tombol aksinya tidak pernah terdorong keluar layar tanpa jalan untuk mencapainya.
    // Di dalam induk yang sudah menggulir (tinggi tak terbatas) tidak boleh ada gulir kedua.
    BoxWithConstraints(modifier = modifier.fillMaxSize()) {
        val scrollState = rememberScrollState()
        val fitOrScroll = if (constraints.hasBoundedHeight) {
            Modifier.verticalScroll(scrollState).heightIn(min = maxHeight)
        } else {
            Modifier
        }
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .then(fitOrScroll)
                .padding(Spacing.lg),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Column(
                modifier = Modifier
                    .widthIn(max = Dimens.ContentMaxWidth)
                    .semantics(mergeDescendants = true) { if (announce) liveRegion = LiveRegionMode.Polite },
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                if (icon != null) {
                    // Dekoratif: judul di bawahnya sudah menyampaikan maknanya.
                    Box(
                        modifier = Modifier
                            .size(72.dp)
                            .clip(CircleShape)
                            .background(BoothTheme.sky.hero),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(
                            painter = icon,
                            contentDescription = null,
                            tint = BoothTheme.sky.onHero,
                            modifier = Modifier.size(32.dp),
                        )
                    }
                    Spacer(Modifier.height(Spacing.md))
                }
                Text(title, style = MaterialTheme.typography.titleLarge, textAlign = TextAlign.Center)
                if (message != null) {
                    Spacer(Modifier.height(Spacing.sm))
                    Text(
                        message,
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.Center,
                    )
                }
            }
            if (action != null) {
                Spacer(Modifier.height(Spacing.lg))
                action()
            }
        }
    }
}
