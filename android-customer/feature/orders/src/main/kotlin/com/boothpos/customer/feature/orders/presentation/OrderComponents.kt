package com.boothpos.customer.feature.orders.presentation

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.isOffline
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.InlineMessageTone
import com.boothpos.customer.core.ui.component.OfflineBanner
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.statusToneColors
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.orders.R
import com.boothpos.customer.feature.orders.domain.OrderStatus
import com.boothpos.customer.core.ui.R as CoreUiR

// Bagian kecil yang dipakai daftar pesanan DAN detail pesanan.

/**
 * Status pesanan sebagai ikon + teks berlatar warna nada. Sengaja TANPA
 * `semantics(mergeDescendants = true)` (berbeda dari `StatusBadge` di :core:ui): lencana ini hidup
 * di dalam baris daftar yang dapat ditekan, dan node yang menggabung sendiri tidak ikut dibacakan
 * sebagai bagian dari baris itu.
 */
@Composable
internal fun OrderStatusChip(status: OrderStatus, modifier: Modifier = Modifier) {
    val visual = status.visual()
    val (container, content) = statusToneColors(visual.tone)
    Surface(color = container, contentColor = content, shape = MaterialTheme.shapes.small, modifier = modifier) {
        Row(
            modifier = Modifier.padding(horizontal = Spacing.sm, vertical = Spacing.xs),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            // Dekoratif bagi pembaca layar: teks di sebelahnya menyebut statusnya.
            Icon(visual.icon.asImageVector(), contentDescription = null, modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(Spacing.xs))
            Text(stringResource(visual.label), style = MaterialTheme.typography.labelLarge)
        }
    }
}

/** Belum masuk (atau sesi berakhir): satu aksi utama, Masuk. */
@Composable
internal fun OrdersSignInPrompt(
    title: String,
    message: String,
    sessionExpired: Boolean,
    onSignIn: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(Spacing.md),
        verticalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        Spacer(Modifier.height(Spacing.lg))
        if (sessionExpired) {
            InlineMessage(text = stringResource(R.string.orders_session_expired))
        }
        Text(
            text = title,
            style = MaterialTheme.typography.headlineSmall,
            modifier = Modifier.semantics { heading() },
        )
        Text(
            text = message,
            style = MaterialTheme.typography.bodyLarge,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(Modifier.height(Spacing.md))
        PrimaryButton(
            text = stringResource(R.string.orders_action_sign_in),
            onClick = onSignIn,
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

/**
 * Yang tampil mungkin bukan data terbaru: perangkat offline, atau penyegaran terakhir gagal.
 * Pesanan yang sudah dimuat tetap tampil di bawahnya.
 */
@Composable
internal fun OrdersStaleNotice(reason: ApiError, onRetry: () -> Unit, modifier: Modifier = Modifier) {
    if (reason.isOffline) {
        OfflineBanner(
            message = stringResource(R.string.orders_offline_stale),
            actionLabel = stringResource(CoreUiR.string.core_ui_retry),
            onAction = onRetry,
            modifier = modifier,
        )
    } else {
        InlineMessage(
            text = stringResource(R.string.orders_refresh_failed),
            tone = InlineMessageTone.Error,
            actionLabel = stringResource(CoreUiR.string.core_ui_retry),
            onAction = onRetry,
            modifier = modifier.padding(horizontal = Spacing.md),
        )
    }
}
