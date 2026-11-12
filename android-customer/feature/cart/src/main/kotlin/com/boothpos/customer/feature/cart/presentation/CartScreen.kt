package com.boothpos.customer.feature.cart.presentation

import android.content.res.Resources
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.FilledTonalIconButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.SnackbarDuration
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.SnackbarResult
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalResources
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import com.boothpos.customer.core.ui.component.EmptyState
import com.boothpos.customer.core.ui.component.LoadingState
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.ProductImage
import com.boothpos.customer.core.ui.format.RupiahFormatter
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.cart.R
import com.boothpos.customer.feature.cart.domain.CartLineIssue
import kotlinx.coroutines.launch

internal object CartTestTags {
    const val TOTAL = "cart_total"

    fun quantity(variantId: Long) = "cart_quantity_$variantId"
}

/** Bagian stateful Keranjang: state + kejadian (snackbar "Urungkan", lanjut ke checkout). */
@Composable
internal fun CartScreenRoute(
    onStartShopping: () -> Unit,
    onCheckout: () -> Unit,
    onOpenProduct: (String) -> Unit,
    viewModel: CartViewModel = hiltViewModel(),
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val snackbarHostState = remember { SnackbarHostState() }
    val resources = LocalResources.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val currentOnCheckout by rememberUpdatedState(onCheckout)

    LaunchedEffect(viewModel, lifecycleOwner, resources) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
            viewModel.events.collect { event ->
                if (event == CartEvent.ProceedToCheckout) {
                    currentOnCheckout()
                    return@collect
                }
                // Pesan terbaru menggantikan yang masih tampil, bukan mengantre di belakangnya.
                snackbarHostState.currentSnackbarData?.dismiss()
                launch {
                    val canUndo = event is CartEvent.LineRemoved && event.canUndo
                    val result = snackbarHostState.showSnackbar(
                        message = event.message(resources),
                        actionLabel = if (canUndo) resources.getString(R.string.cart_action_undo) else null,
                        // Eksplisit: bawaan untuk snackbar beraksi adalah "tanpa batas waktu".
                        duration = SnackbarDuration.Long,
                    )
                    if (canUndo && result == SnackbarResult.ActionPerformed) viewModel.undoRemove()
                }
            }
        }
    }

    CartScreen(
        state = state,
        snackbarHostState = snackbarHostState,
        onStartShopping = onStartShopping,
        onIncrease = viewModel::increase,
        onDecrease = viewModel::decrease,
        onAdjustToLimit = viewModel::adjustToLimit,
        onRemove = viewModel::remove,
        onOpenProduct = onOpenProduct,
        onCheckout = viewModel::checkout,
    )
}

/** Teks snackbar. Fungsi biasa (bukan composable) supaya dapat dipakai dari coroutine. */
internal fun CartEvent.message(resources: Resources): String = when (this) {
    is CartEvent.LineRemoved -> resources.getString(R.string.cart_line_removed, productName)
    CartEvent.UndoFailed -> resources.getString(R.string.cart_undo_failed)
    CartEvent.UpdateFailed -> resources.getString(R.string.cart_update_failed)
    CartEvent.ProceedToCheckout -> ""
}

/**
 * Keranjang. Daftar baris dapat digulir; total sementara dan satu aksi utama ("Lanjut ke
 * checkout") selalu terlihat di bawah. Di layar lebar bingkai aplikasi memusatkannya.
 */
@Composable
internal fun CartScreen(
    state: CartUiState,
    snackbarHostState: SnackbarHostState,
    onStartShopping: () -> Unit,
    onIncrease: (Long) -> Unit,
    onDecrease: (Long) -> Unit,
    onAdjustToLimit: (Long) -> Unit,
    onRemove: (Long) -> Unit,
    onOpenProduct: (String) -> Unit,
    onCheckout: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(modifier = modifier.fillMaxSize()) {
        Box(modifier = Modifier.weight(1f)) {
            when (state) {
                CartUiState.Loading -> LoadingState()
                CartUiState.Empty -> EmptyState(
                    title = stringResource(R.string.cart_empty_title),
                    message = stringResource(R.string.cart_empty_message),
                    actionLabel = stringResource(R.string.cart_empty_action),
                    onAction = onStartShopping,
                )
                is CartUiState.Content -> LazyColumn(modifier = Modifier.fillMaxSize()) {
                    // Awalan kunci: id dari data tidak boleh bertabrakan dengan kunci lain di daftar.
                    items(state.lines, key = { "line:${it.line.variantId}" }) { item ->
                        CartLineRow(
                            item = item,
                            onIncrease = { onIncrease(item.line.variantId) },
                            onDecrease = { onDecrease(item.line.variantId) },
                            onAdjustToLimit = { onAdjustToLimit(item.line.variantId) },
                            onRemove = { onRemove(item.line.variantId) },
                            onOpenProduct = { onOpenProduct(item.line.productSlug) },
                        )
                        HorizontalDivider()
                    }
                }
            }
            // Di atas bilah total, dan tetap tampil saat baris terakhir dihapus (keranjang kosong),
            // supaya "Urungkan" masih bisa ditekan.
            SnackbarHost(hostState = snackbarHostState, modifier = Modifier.align(Alignment.BottomCenter))
        }
        if (state is CartUiState.Content) {
            HorizontalDivider()
            CartSummaryBar(state = state, onCheckout = onCheckout)
        }
    }
}

@Composable
private fun CartLineRow(
    item: CartLineUi,
    onIncrease: () -> Unit,
    onDecrease: () -> Unit,
    onAdjustToLimit: () -> Unit,
    onRemove: () -> Unit,
    onOpenProduct: () -> Unit,
) {
    val line = item.line
    val openLabel = stringResource(R.string.cart_open_product, line.productName)
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(start = Spacing.md, top = Spacing.md, bottom = Spacing.sm),
    ) {
        // Gambar adalah jalan kembali ke detail produk; 64dp memenuhi target sentuh minimum.
        Box(
            modifier = Modifier
                .clickable(role = Role.Button, onClick = onOpenProduct)
                .semantics { contentDescription = openLabel },
        ) {
            ProductImage(imageUrl = line.imageUrl, contentDescription = null, size = ImageSize)
        }
        Spacer(Modifier.width(Spacing.md))
        Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
            Text(
                text = line.productName,
                style = MaterialTheme.typography.titleSmall,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                text = line.variantName,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Text(
                text = stringResource(R.string.cart_unit_price, RupiahFormatter.format(line.unitPrice)),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            item.issue?.let { issue -> LineIssue(issue = issue, onAdjustToLimit = onAdjustToLimit) }
            Row(verticalAlignment = Alignment.CenterVertically) {
                QuantityStepper(item = item, onIncrease = onIncrease, onDecrease = onDecrease)
                Spacer(Modifier.weight(1f))
                Text(
                    text = RupiahFormatter.format(line.lineTotal),
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                )
            }
        }
        IconButton(onClick = onRemove, modifier = Modifier.size(Dimens.MinTouchTarget)) {
            Icon(
                Icons.Filled.Delete,
                contentDescription = stringResource(R.string.cart_remove_line, line.productName),
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

/** Masalah baris: ikon + teks (bukan warna saja), dengan satu perbaikan langsung bila ada. */
@Composable
private fun LineIssue(issue: CartLineIssue, onAdjustToLimit: () -> Unit) {
    Column {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(
                Icons.Filled.Warning,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.error,
                modifier = Modifier.size(16.dp),
            )
            Spacer(Modifier.width(Spacing.xs))
            Text(
                text = when (issue) {
                    CartLineIssue.Unavailable -> stringResource(R.string.cart_issue_unavailable)
                    is CartLineIssue.ExceedsLimit ->
                        pluralStringResource(R.plurals.cart_issue_exceeds, issue.maxQuantity, issue.maxQuantity)
                },
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.error,
            )
        }
        if (issue is CartLineIssue.ExceedsLimit) {
            TextButton(onClick = onAdjustToLimit, modifier = Modifier.heightIn(min = Dimens.MinTouchTarget)) {
                Text(pluralStringResource(R.plurals.cart_issue_adjust, issue.maxQuantity, issue.maxQuantity))
            }
        }
    }
}

/** Tombol di batas dinonaktifkan, bukan disembunyikan; perubahan jumlah diumumkan pembaca layar. */
@Composable
private fun QuantityStepper(item: CartLineUi, onIncrease: () -> Unit, onDecrease: () -> Unit) {
    val line = item.line
    val quantityDescription = pluralStringResource(R.plurals.cart_quantity_value, line.quantity, line.quantity)
    Row(verticalAlignment = Alignment.CenterVertically) {
        FilledTonalIconButton(
            onClick = onDecrease,
            enabled = item.canDecrease,
            modifier = Modifier.size(Dimens.MinTouchTarget),
        ) {
            Icon(
                painterResource(R.drawable.cart_ic_remove),
                contentDescription = stringResource(R.string.cart_quantity_decrease, line.productName),
            )
        }
        Text(
            text = line.quantity.toString(),
            style = MaterialTheme.typography.titleMedium,
            textAlign = TextAlign.Center,
            modifier = Modifier
                .widthIn(min = QuantityMinWidth)
                .padding(horizontal = Spacing.xs)
                .testTag(CartTestTags.quantity(line.variantId))
                .semantics {
                    contentDescription = quantityDescription
                    liveRegion = LiveRegionMode.Polite
                },
        )
        FilledTonalIconButton(
            onClick = onIncrease,
            enabled = item.canIncrease,
            modifier = Modifier.size(Dimens.MinTouchTarget),
        ) {
            Icon(Icons.Filled.Add, contentDescription = stringResource(R.string.cart_quantity_increase, line.productName))
        }
    }
}

/** Total sementara yang selalu terlihat, keterangan bahwa harga dikonfirmasi saat checkout, dan aksi utama. */
@Composable
private fun CartSummaryBar(state: CartUiState.Content, onCheckout: () -> Unit) {
    Surface {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = Spacing.md, vertical = Spacing.sm),
            verticalArrangement = Arrangement.spacedBy(Spacing.xs),
        ) {
            // Label + jumlah dibaca sebagai satu kalimat ("Total sementara, 3 barang, Rp55.000").
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .testTag(CartTestTags.TOTAL)
                    .semantics(mergeDescendants = true) {},
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column(modifier = Modifier.weight(1f)) {
                    Text(stringResource(R.string.cart_total_label), style = MaterialTheme.typography.labelLarge)
                    Text(
                        text = pluralStringResource(R.plurals.cart_item_count, state.itemCount, state.itemCount),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                Text(
                    text = RupiahFormatter.format(state.subtotal),
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.SemiBold,
                )
            }
            Text(
                text = stringResource(
                    if (state.hasIssues) R.string.cart_fix_issues_hint else R.string.cart_price_confirmed_at_checkout,
                ),
                style = MaterialTheme.typography.bodySmall,
                color = if (state.hasIssues) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant,
            )
            PrimaryButton(
                text = stringResource(R.string.cart_action_checkout),
                onClick = onCheckout,
                enabled = state.canCheckout,
                modifier = Modifier.fillMaxWidth(),
            )
        }
    }
}

private val ImageSize = 64.dp
private val QuantityMinWidth = 40.dp
