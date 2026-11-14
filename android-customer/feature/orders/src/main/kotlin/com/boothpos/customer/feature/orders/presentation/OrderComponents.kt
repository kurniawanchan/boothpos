package com.boothpos.customer.feature.orders.presentation

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
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.isOffline
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.InlineMessageTone
import com.boothpos.customer.core.ui.component.OfflineBanner
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.statusToneColors
import com.boothpos.customer.core.ui.icon.SkyIcons
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.PillShape
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.orders.R
import com.boothpos.customer.feature.orders.domain.OrderStatus
import com.boothpos.customer.core.ui.R as CoreUiR

// Bagian kecil yang dipakai daftar pesanan DAN detail pesanan.

/**
 * Status pesanan sebagai lencana pil: ikon + teks berlatar warna nada, rupa yang sama dengan
 * `StatusBadge` di :core:ui. Sengaja BUKAN `StatusBadge` itu sendiri, karena ia memakai
 * `semantics(mergeDescendants = true)`: lencana ini hidup di dalam kartu pesanan yang dapat
 * ditekan, dan node yang menggabung sendiri tidak ikut dibacakan sebagai bagian dari kartu itu.
 */
@Composable
internal fun OrderStatusChip(status: OrderStatus, modifier: Modifier = Modifier) {
    val visual = status.visual()
    val (container, content) = statusToneColors(visual.tone)
    Surface(color = container, contentColor = content, shape = PillShape, modifier = modifier) {
        Row(
            modifier = Modifier
                .heightIn(min = ChipMinHeight)
                .padding(horizontal = Spacing.ms, vertical = Spacing.xs),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            // Dekoratif bagi pembaca layar: teks di sebelahnya menyebut statusnya.
            Icon(visual.icon.asImageVector(), contentDescription = null, modifier = Modifier.size(ChipIconSize))
            Spacer(Modifier.width(Spacing.xs))
            Text(stringResource(visual.label), style = MaterialTheme.typography.labelMedium)
        }
    }
}

/**
 * Dua bagian dalam satu baris: [start] di tepi awal, [end] di tepi akhir. Bila keduanya tidak muat
 * berdampingan (huruf sistem 200 %, layar sempit, teks panjang), [end] turun ke bawah [start].
 *
 * Bukan `Row` dengan `weight`: di sana bagian yang tidak diberi bobot mengambil lebarnya lebih
 * dulu, sehingga pada huruf besar bagian yang lain tersisa beberapa dp saja dan teksnya patah per
 * huruf. Di sini kedua bagian diukur dengan lebar penuh, baru diputuskan berdampingan atau ditumpuk.
 * Bagian yang kosong tidak memakan tempat maupun jarak.
 */
@Composable
internal fun EndsOrStack(
    start: @Composable () -> Unit,
    end: @Composable () -> Unit,
    modifier: Modifier = Modifier,
    gap: Dp = Spacing.sm,
    stackedGap: Dp = Spacing.xs,
) {
    Layout(
        content = {
            // Tepat dua anak, apa pun isi kedua bagian (kosong atau lebih dari satu node).
            Box { start() }
            Box { end() }
        },
        modifier = modifier,
    ) { measurables, constraints ->
        val loose = constraints.copy(minWidth = 0, minHeight = 0)
        val first = measurables[0].measure(loose)
        val second = measurables[1].measure(loose)
        val bothPresent = first.width > 0 && second.width > 0
        val sideGap = if (bothPresent) gap.roundToPx() else 0
        val sideBySide = !bothPresent || first.width + sideGap + second.width <= constraints.maxWidth
        val stackGap = if (bothPresent) stackedGap.roundToPx() else 0

        val contentWidth = if (sideBySide) first.width + sideGap + second.width else maxOf(first.width, second.width)
        // Selebar induk bila ada batasnya, supaya [end] benar-benar sampai di tepi akhir.
        val width = if (constraints.hasBoundedWidth) {
            constraints.maxWidth
        } else {
            contentWidth.coerceAtLeast(constraints.minWidth)
        }
        val contentHeight = if (sideBySide) {
            maxOf(first.height, second.height)
        } else {
            first.height + stackGap + second.height
        }
        val height = contentHeight.coerceIn(constraints.minHeight, constraints.maxHeight)

        layout(width, height) {
            if (sideBySide) {
                first.placeRelative(0, (height - first.height) / 2)
                second.placeRelative(width - second.width, (height - second.height) / 2)
            } else {
                first.placeRelative(0, 0)
                second.placeRelative(0, first.height + stackGap)
            }
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
    // Di tengah bila muat; DIGULIR bila tidak (huruf sistem 200 %, layar pendek), supaya tombol
    // Masuk tidak pernah terdorong keluar layar. Pola yang sama dengan keadaan kosong/galat di
    // :core:ui, sehingga ajakan masuk tampil serupa dengan keadaan layar lainnya.
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
                modifier = Modifier.widthIn(max = Dimens.ContentMaxWidth),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                if (sessionExpired) {
                    InlineMessage(text = stringResource(R.string.orders_session_expired))
                    Spacer(Modifier.height(Spacing.lg))
                }
                // Dekoratif: judul di bawahnya sudah menyampaikan maknanya.
                Box(
                    modifier = Modifier
                        .size(PromptDiscSize)
                        .clip(CircleShape)
                        .background(BoothTheme.sky.hero),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        SkyIcons.Receipt,
                        contentDescription = null,
                        tint = BoothTheme.sky.onHero,
                        modifier = Modifier.size(PromptIconSize),
                    )
                }
                Spacer(Modifier.height(Spacing.md))
                Text(
                    text = title,
                    style = MaterialTheme.typography.titleLarge,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.semantics { heading() },
                )
                Spacer(Modifier.height(Spacing.sm))
                Text(
                    text = message,
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    textAlign = TextAlign.Center,
                )
                Spacer(Modifier.height(Spacing.lg))
                PrimaryButton(
                    text = stringResource(R.string.orders_action_sign_in),
                    onClick = onSignIn,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        }
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
            // Pita ini sudah membawa jarak tepi 16 dp sendiri; sisanya ditambahkan di sini supaya
            // ia sejajar dengan kartu pesanan (24 dp).
            modifier = modifier.padding(horizontal = Spacing.lg - Spacing.md),
        )
    } else {
        InlineMessage(
            text = stringResource(R.string.orders_refresh_failed),
            tone = InlineMessageTone.Error,
            actionLabel = stringResource(CoreUiR.string.core_ui_retry),
            onAction = onRetry,
            modifier = modifier.padding(horizontal = Spacing.lg),
        )
    }
}

private val ChipMinHeight = 24.dp
private val ChipIconSize = 14.dp
private val PromptDiscSize = 72.dp
private val PromptIconSize = 32.dp
