package com.boothpos.customer.feature.orders.presentation

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedCard
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.boothpos.customer.core.ui.component.ApiErrorState
import com.boothpos.customer.core.ui.component.EmptyState
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.InlineMessageTone
import com.boothpos.customer.core.ui.component.LoadingState
import com.boothpos.customer.core.ui.format.RupiahFormatter
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.orders.R
import com.boothpos.customer.feature.orders.domain.OrderSummary
import com.boothpos.customer.core.ui.R as CoreUiR

/** Bagian stateful daftar pesanan: mengumpulkan state, lalu menyerahkannya ke layar stateless. */
@Composable
internal fun OrdersScreenRoute(
    onSignIn: () -> Unit,
    onOrderClick: (orderNumber: String) -> Unit,
    viewModel: OrdersViewModel = hiltViewModel(),
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    // Berjalan setiap kali tujuan ini masuk komposisi lagi (kembali dari detail, pindah tab,
    // selesai checkout): status pesanan diubah toko kapan saja, jadi daftar disegarkan.
    LaunchedEffect(viewModel) { viewModel.onScreenShown() }
    OrdersScreen(
        state = state,
        onSignIn = onSignIn,
        onRefresh = viewModel::refresh,
        onLoadMore = viewModel::loadMore,
        onRetryLoadMore = viewModel::retryLoadMore,
        onOrderClick = onOrderClick,
    )
}

/**
 * Daftar "Pesanan Saya". Tata letak: ponsel kecil lebih dulu (satu kolom); di layar lebar bingkai
 * aplikasi memusatkan konten dengan lebar maksimum, jadi tetap satu kolom, bukan dasbor.
 * Tidak ada mockup desain untuk aplikasi ini: Material 3 polos dengan tema :core:ui.
 */
@Composable
internal fun OrdersScreen(
    state: OrdersUiState,
    onSignIn: () -> Unit,
    onRefresh: () -> Unit,
    onLoadMore: () -> Unit,
    onRetryLoadMore: () -> Unit,
    onOrderClick: (orderNumber: String) -> Unit,
    modifier: Modifier = Modifier,
    dateFormatter: OrderDateFormatter = remember { OrderDateFormatter.system() },
) {
    when (state) {
        OrdersUiState.Loading -> LoadingState(modifier = modifier)

        is OrdersUiState.SignedOut -> OrdersSignInPrompt(
            title = stringResource(R.string.orders_signed_out_title),
            message = stringResource(R.string.orders_signed_out_message),
            sessionExpired = state.sessionExpired,
            onSignIn = onSignIn,
            modifier = modifier,
        )

        is OrdersUiState.Error -> ApiErrorState(
            error = state.error,
            onRetry = onRefresh,
            retrying = state.retrying,
            modifier = modifier,
        )

        OrdersUiState.Empty -> EmptyState(
            title = stringResource(R.string.orders_empty_title),
            message = stringResource(R.string.orders_empty_message),
            actionLabel = stringResource(R.string.orders_refresh),
            onAction = onRefresh,
            modifier = modifier,
        )

        is OrdersUiState.Orders -> OrderList(
            content = state,
            dateFormatter = dateFormatter,
            onRefresh = onRefresh,
            onLoadMore = onLoadMore,
            onRetryLoadMore = onRetryLoadMore,
            onOrderClick = onOrderClick,
            modifier = modifier,
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun OrderList(
    content: OrdersUiState.Orders,
    dateFormatter: OrderDateFormatter,
    onRefresh: () -> Unit,
    onLoadMore: () -> Unit,
    onRetryLoadMore: () -> Unit,
    onOrderClick: (orderNumber: String) -> Unit,
    modifier: Modifier = Modifier,
) {
    val currentOnLoadMore by rememberUpdatedState(onLoadMore)
    PullToRefreshBox(isRefreshing = content.refreshing, onRefresh = onRefresh, modifier = modifier.fillMaxSize()) {
        LazyColumn(
            contentPadding = PaddingValues(bottom = Spacing.md),
            verticalArrangement = Arrangement.spacedBy(Spacing.sm),
            modifier = Modifier
                .fillMaxSize()
                .testTag(OrdersTestTags.ORDER_LIST),
        ) {
            // Tombol muat ulang yang terlihat: gerakan tarik-untuk-segarkan saja tidak dapat
            // dipakai semua orang (pembaca layar, sakelar, papan ketik).
            item(key = KEY_REFRESH, contentType = "refresh") {
                RefreshRow(refreshing = content.refreshing, onRefresh = onRefresh)
            }
            content.staleReason?.let { reason ->
                item(key = KEY_STALE, contentType = "notice") { OrdersStaleNotice(reason = reason, onRetry = onRefresh) }
            }
            itemsIndexed(
                items = content.items,
                key = { _, order -> ORDER_KEY_PREFIX + order.orderNumber },
                contentType = { _, _ -> "order" },
            ) { index, order ->
                // Halaman berikutnya diminta ketika baris di dekat akhir daftar mulai disusun
                // (artinya guliran sudah dekat), bukan di muka: riwayat tidak dimuat seluruhnya.
                // Kunci `refreshing`: permintaan yang diabaikan selama penyegaran diulang sesudahnya.
                if (content.canLoadMore && index >= content.items.lastIndex - LOAD_MORE_THRESHOLD) {
                    LaunchedEffect(content.items.size, content.refreshing) { currentOnLoadMore() }
                }
                OrderRow(
                    order = order,
                    dateFormatter = dateFormatter,
                    onClick = { onOrderClick(order.orderNumber) },
                    modifier = Modifier.padding(horizontal = Spacing.md),
                )
            }
            when {
                content.loadingMore -> item(key = KEY_FOOTER, contentType = "footer") { LoadingMoreRow() }
                content.loadMoreError != null -> item(key = KEY_FOOTER, contentType = "footer") {
                    InlineMessage(
                        text = stringResource(R.string.orders_load_more_failed),
                        tone = InlineMessageTone.Error,
                        actionLabel = stringResource(CoreUiR.string.core_ui_retry),
                        onAction = onRetryLoadMore,
                        modifier = Modifier.padding(horizontal = Spacing.md),
                    )
                }
            }
        }
    }
}

@Composable
private fun RefreshRow(refreshing: Boolean, onRefresh: () -> Unit) {
    val description = stringResource(R.string.orders_refresh_description)
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = Spacing.xs),
        horizontalArrangement = Arrangement.End,
    ) {
        TextButton(
            onClick = onRefresh,
            // Selama penyegaran berjalan tombol mati: ketukan ganda tidak mengirim dua kali.
            enabled = !refreshing,
            modifier = Modifier
                .heightIn(min = Dimens.MinTouchTarget)
                .semantics { contentDescription = description },
        ) {
            Icon(Icons.Filled.Refresh, contentDescription = null, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(Spacing.xs))
            Text(stringResource(R.string.orders_refresh))
        }
    }
}

/**
 * Satu pesanan sebagai satu tombol: nomor, status (ikon + teks), tanggal, ringkasan barang, total.
 * Isinya teks dan ikon polos, sehingga pembaca layar membacakan seluruh baris sebagai satu item.
 */
@Composable
private fun OrderRow(
    order: OrderSummary,
    dateFormatter: OrderDateFormatter,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    OutlinedCard(
        shape = MaterialTheme.shapes.medium,
        modifier = modifier.fillMaxWidth(),
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .testTag(OrdersTestTags.ORDER_ROW)
                .clickable(
                    onClickLabel = stringResource(R.string.orders_row_click_label),
                    role = Role.Button,
                    onClick = onClick,
                )
                .padding(Spacing.md),
            verticalArrangement = Arrangement.spacedBy(Spacing.xs),
        ) {
            Text(
                text = stringResource(R.string.orders_row_number, order.orderNumber),
                style = MaterialTheme.typography.titleMedium,
            )
            OrderStatusChip(status = order.status)
            order.createdAt?.let { createdAt ->
                Text(
                    text = stringResource(R.string.orders_row_created, dateFormatter.format(createdAt)),
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Text(
                text = itemSummary(order),
                style = MaterialTheme.typography.bodyMedium,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                text = stringResource(R.string.orders_row_total, RupiahFormatter.format(order.total)),
                style = MaterialTheme.typography.bodyLarge,
                fontWeight = FontWeight.SemiBold,
            )
        }
    }
}

/** "Stiker Uji — A5 dan 2 barang lainnya", "Stiker Uji — A5", atau "3 barang" bila namanya tidak ada. */
@Composable
private fun itemSummary(order: OrderSummary): String {
    val first = order.firstItemName
    val others = order.itemCount - 1
    return when {
        first != null && others > 0 -> pluralStringResource(R.plurals.orders_row_items_more, others, first, others)
        first != null -> first
        else -> pluralStringResource(R.plurals.orders_row_items_count, order.itemCount, order.itemCount)
    }
}

@Composable
private fun LoadingMoreRow() {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(Spacing.md)
            .semantics(mergeDescendants = true) {},
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
        Spacer(Modifier.width(Spacing.sm))
        Text(
            text = stringResource(R.string.orders_loading_more),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

/** Jarak (dalam baris) dari akhir daftar saat halaman berikutnya mulai diminta. */
private const val LOAD_MORE_THRESHOLD = 4

// Kunci item daftar. Nomor pesanan berasal dari server, jadi kuncinya diberi awalan supaya tidak
// pernah bertabrakan dengan kunci item tetap: kunci ganda membuat LazyColumn crash.
private const val ORDER_KEY_PREFIX = "order:"
private const val KEY_REFRESH = "header:refresh"
private const val KEY_STALE = "header:stale"
private const val KEY_FOOTER = "footer"
