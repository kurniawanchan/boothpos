package com.boothpos.customer.feature.orders.presentation

import android.content.ActivityNotFoundException
import android.content.ClipData
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.annotation.StringRes
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Info
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.ClipEntry
import androidx.compose.ui.platform.LocalClipboard
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalResources
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.core.net.toUri
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.ui.component.ApiErrorState
import com.boothpos.customer.core.ui.component.EmptyState
import com.boothpos.customer.core.ui.component.LoadingState
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.SecondaryButton
import com.boothpos.customer.core.ui.component.statusToneColors
import com.boothpos.customer.core.ui.format.RupiahFormatter
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.orders.R
import com.boothpos.customer.feature.orders.domain.OrderAddress
import com.boothpos.customer.feature.orders.domain.OrderContact
import com.boothpos.customer.feature.orders.domain.OrderDetail
import com.boothpos.customer.feature.orders.domain.OrderEvent
import com.boothpos.customer.feature.orders.domain.OrderItem
import com.boothpos.customer.feature.orders.domain.Shipment
import java.math.BigDecimal
import kotlinx.coroutines.launch

/**
 * Bagian stateful detail pesanan: state, kejadian sekali-jalan (membuka situs kurir di peramban
 * luar), dan papan klip. Semua yang menyentuh Android (Intent, papan klip) ada di sini, bukan di
 * layar stateless maupun ViewModel.
 */
@Composable
internal fun OrderDetailScreenRoute(
    onSignIn: () -> Unit,
    onBackToOrders: () -> Unit,
    viewModel: OrderDetailViewModel = hiltViewModel(),
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val snackbarHostState = remember { SnackbarHostState() }
    val context = LocalContext.current
    val resources = LocalResources.current
    val clipboard = LocalClipboard.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val scope = rememberCoroutineScope()

    LaunchedEffect(viewModel, lifecycleOwner, context, resources) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
            viewModel.events.collect { event ->
                when (event) {
                    is OrderDetailEvent.OpenExternalUrl -> if (!openInBrowser(context, event.url)) {
                        launch { snackbarHostState.showSnackbar(resources.getString(R.string.orders_tracking_open_failed)) }
                    }
                }
            }
        }
    }

    OrderDetailScreen(
        state = state,
        snackbarHostState = snackbarHostState,
        onRefresh = viewModel::refresh,
        onSignIn = onSignIn,
        onBackToOrders = onBackToOrders,
        onCopyTrackingNumber = { trackingNumber ->
            scope.launch {
                val label = resources.getString(R.string.orders_tracking_number_label)
                clipboard.setClipEntry(ClipEntry(ClipData.newPlainText(label, trackingNumber)))
                // Android 13+ menampilkan konfirmasi salin milik sistem; konfirmasi kedua hanya mengganggu.
                if (Build.VERSION.SDK_INT <= Build.VERSION_CODES.S_V2) {
                    snackbarHostState.showSnackbar(resources.getString(R.string.orders_tracking_copied))
                }
            }
        },
        onTrackParcel = viewModel::onTrackParcelClick,
        onConfirmHandoff = viewModel::confirmHandoff,
        onDismissHandoff = viewModel::dismissHandoff,
    )
}

/**
 * Membuka [url] di peramban LUAR (ACTION_VIEW), tidak pernah di WebView. [url] selalu tautan yang
 * sudah lolos `TrackingLink` (https, host wajar). Mengembalikan false bila tidak ada aplikasi yang
 * dapat membukanya.
 */
internal fun openInBrowser(context: Context, url: String): Boolean {
    val intent = Intent(Intent.ACTION_VIEW, url.toUri())
        .addCategory(Intent.CATEGORY_BROWSABLE)
        // Konteks dari komposisi bisa saja bukan Activity; tugas baru aman di kedua keadaan.
        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    return try {
        context.startActivity(intent)
        true
    } catch (_: ActivityNotFoundException) {
        false
    }
}

/**
 * Detail pesanan. Urutan isi: status sekarang (besar, dengan penjelasan), lalu nomor pesanan,
 * barang, petunjuk toko, alamat (kurir), kontak, biaya, pelacakan, dan riwayat (terbaru dulu).
 * Alasan pembatalan ditaruh tepat di bawah status, karena itulah pertanyaan pertama pelanggan
 * saat melihat "Dibatalkan".
 *
 * Tata letak: satu kolom untuk semua lebar; di layar lebar bingkai aplikasi memusatkan konten
 * dengan lebar maksimum. Tidak ada mockup desain untuk aplikasi ini (ASUMSI: Material 3 polos).
 */
@Composable
internal fun OrderDetailScreen(
    state: OrderDetailUiState,
    snackbarHostState: SnackbarHostState,
    onRefresh: () -> Unit,
    onSignIn: () -> Unit,
    onBackToOrders: () -> Unit,
    onCopyTrackingNumber: (String) -> Unit,
    onTrackParcel: () -> Unit,
    onConfirmHandoff: () -> Unit,
    onDismissHandoff: () -> Unit,
    modifier: Modifier = Modifier,
    dateFormatter: OrderDateFormatter = remember { OrderDateFormatter.system() },
) {
    Box(modifier = modifier.fillMaxSize()) {
        when (state) {
            OrderDetailUiState.Loading -> LoadingState()

            is OrderDetailUiState.Error -> ApiErrorState(error = state.error, onRetry = onRefresh, retrying = state.retrying)

            OrderDetailUiState.NotFound -> EmptyState(
                title = stringResource(R.string.orders_detail_not_found_title),
                message = stringResource(R.string.orders_detail_not_found_message),
                actionLabel = stringResource(R.string.orders_detail_not_found_action),
                onAction = onBackToOrders,
            )

            is OrderDetailUiState.SignedOut -> OrdersSignInPrompt(
                title = stringResource(R.string.orders_detail_signed_out_title),
                message = stringResource(R.string.orders_detail_signed_out_message),
                sessionExpired = state.sessionExpired,
                onSignIn = onSignIn,
            )

            is OrderDetailUiState.Content -> OrderContent(
                state = state,
                dateFormatter = dateFormatter,
                onRefresh = onRefresh,
                onCopyTrackingNumber = onCopyTrackingNumber,
                onTrackParcel = onTrackParcel,
            )
        }
        SnackbarHost(snackbarHostState, modifier = Modifier.align(Alignment.BottomCenter))
    }

    if (state is OrderDetailUiState.Content && state.confirmingHandoff) {
        HandoffDialog(onConfirm = onConfirmHandoff, onDismiss = onDismissHandoff)
    }
}

/** Pemberitahuan singkat sebelum meninggalkan aplikasi; tautan baru dibuka setelah disetujui. */
@Composable
private fun HandoffDialog(onConfirm: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.orders_tracking_handoff_title)) },
        text = { Text(stringResource(R.string.orders_tracking_handoff_message)) },
        confirmButton = {
            TextButton(onClick = onConfirm, modifier = Modifier.heightIn(min = Dimens.MinTouchTarget)) {
                Text(stringResource(R.string.orders_tracking_handoff_confirm))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss, modifier = Modifier.heightIn(min = Dimens.MinTouchTarget)) {
                Text(stringResource(R.string.orders_tracking_handoff_cancel))
            }
        },
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun OrderContent(
    state: OrderDetailUiState.Content,
    dateFormatter: OrderDateFormatter,
    onRefresh: () -> Unit,
    onCopyTrackingNumber: (String) -> Unit,
    onTrackParcel: () -> Unit,
) {
    val order = state.order
    PullToRefreshBox(isRefreshing = state.refreshing, onRefresh = onRefresh, modifier = Modifier.fillMaxSize()) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(bottom = Spacing.lg),
            verticalArrangement = Arrangement.spacedBy(Spacing.md),
        ) {
            state.refreshError?.let { reason -> OrdersStaleNotice(reason = reason, onRetry = onRefresh) }
            StatusHeader(order = order, dateFormatter = dateFormatter)
            Column(
                modifier = Modifier.padding(horizontal = Spacing.md),
                verticalArrangement = Arrangement.spacedBy(Spacing.md),
            ) {
                SummarySection(order = order, dateFormatter = dateFormatter)
                HorizontalDivider()
                ItemsSection(items = order.items)
                order.instructions?.let { instructions ->
                    HorizontalDivider()
                    TextSection(title = stringResource(instructionsTitle(order.summary.fulfilmentMethod)), body = instructions)
                }
                order.shippingAddress?.let { address ->
                    HorizontalDivider()
                    AddressSection(address = address)
                }
                HorizontalDivider()
                ContactSection(contact = order.contact)
                order.notes?.let { notes ->
                    HorizontalDivider()
                    TextSection(title = stringResource(R.string.orders_detail_notes_title), body = notes)
                }
                HorizontalDivider()
                TotalsSection(order = order)
                HorizontalDivider()
                TrackingSection(
                    state = state,
                    dateFormatter = dateFormatter,
                    onRefresh = onRefresh,
                    onCopyTrackingNumber = onCopyTrackingNumber,
                    onTrackParcel = onTrackParcel,
                )
                HorizontalDivider()
                TimelineSection(events = order.timeline, dateFormatter = dateFormatter)
            }
        }
    }
}

/** Status sekarang, paling atas dan paling besar: ikon + nama + waktu + penjelasan sehari-hari. */
@Composable
private fun StatusHeader(order: OrderDetail, dateFormatter: OrderDateFormatter) {
    val visual = order.summary.status.visual()
    val (container, content) = statusToneColors(visual.tone)
    val label = stringResource(visual.label)
    val description = stringResource(R.string.orders_detail_status_description, label)
    Surface(
        color = container,
        contentColor = content,
        modifier = Modifier
            .fillMaxWidth()
            .testTag(OrdersTestTags.DETAIL_STATUS),
    ) {
        Column(modifier = Modifier.padding(Spacing.md), verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                // Dekoratif bagi pembaca layar: nama status di sebelahnya sudah dibacakan.
                Icon(visual.icon.asImageVector(), contentDescription = null, modifier = Modifier.size(32.dp))
                Spacer(Modifier.width(Spacing.md))
                Column {
                    Text(
                        text = label,
                        style = MaterialTheme.typography.headlineSmall,
                        modifier = Modifier.semantics {
                            heading()
                            contentDescription = description
                        },
                    )
                    order.summary.updatedAt?.let { updatedAt ->
                        Text(
                            text = stringResource(R.string.orders_detail_status_updated, dateFormatter.format(updatedAt)),
                            style = MaterialTheme.typography.bodyMedium,
                        )
                    }
                }
            }
            Text(text = stringResource(visual.explanation), style = MaterialTheme.typography.bodyLarge)
            order.cancelReason?.let { reason ->
                Column {
                    Text(
                        text = stringResource(R.string.orders_detail_cancel_reason_title),
                        style = MaterialTheme.typography.labelLarge,
                    )
                    Text(text = reason, style = MaterialTheme.typography.bodyLarge)
                }
            }
        }
    }
}

@Composable
private fun SectionTitle(text: String) {
    Text(
        text = text,
        style = MaterialTheme.typography.titleMedium,
        modifier = Modifier.semantics { heading() },
    )
}

/** Baris "label: nilai". Digabung menjadi satu node supaya pembaca layar membacanya sebagai satu kalimat. */
@Composable
private fun LabeledRow(label: String, value: String, modifier: Modifier = Modifier, emphasised: Boolean = false) {
    Row(
        modifier = modifier
            .fillMaxWidth()
            .semantics(mergeDescendants = true) {},
        horizontalArrangement = Arrangement.spacedBy(Spacing.md),
    ) {
        Text(
            text = label,
            style = if (emphasised) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyLarge,
            color = if (emphasised) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.weight(1f),
        )
        Text(
            text = value,
            style = if (emphasised) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyLarge,
            fontWeight = if (emphasised) FontWeight.SemiBold else null,
            textAlign = TextAlign.End,
            modifier = Modifier.weight(1f),
        )
    }
}

@Composable
private fun SummarySection(order: OrderDetail, dateFormatter: OrderDateFormatter) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        SectionTitle(stringResource(R.string.orders_detail_summary_title))
        LabeledRow(
            label = stringResource(R.string.orders_detail_number_label),
            value = order.summary.orderNumber,
            modifier = Modifier.testTag(OrdersTestTags.DETAIL_ORDER_NUMBER),
        )
        order.summary.createdAt?.let { createdAt ->
            LabeledRow(label = stringResource(R.string.orders_detail_created_label), value = dateFormatter.format(createdAt))
        }
        order.summary.fulfilmentMethod.labelRes()?.let { method ->
            LabeledRow(label = stringResource(R.string.orders_detail_method_label), value = stringResource(method))
        }
    }
}

@Composable
private fun ItemsSection(items: List<OrderItem>) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        SectionTitle(stringResource(R.string.orders_detail_items_title))
        items.forEach { item ->
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .semantics(mergeDescendants = true) {},
                horizontalArrangement = Arrangement.spacedBy(Spacing.md),
            ) {
                Column(modifier = Modifier.weight(1f)) {
                    Text(text = item.name, style = MaterialTheme.typography.bodyLarge)
                    Text(
                        text = stringResource(
                            R.string.orders_detail_item_quantity,
                            item.quantity.toString(),
                            RupiahFormatter.format(item.unitPrice),
                        ),
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                Text(text = RupiahFormatter.format(item.lineTotal), style = MaterialTheme.typography.bodyLarge)
            }
        }
    }
}

/** Judul + teks polos (petunjuk dari toko, catatan pelanggan). Teks server tidak pernah ditafsirkan sebagai markup. */
@Composable
private fun TextSection(title: String, body: String) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        SectionTitle(title)
        Text(text = body, style = MaterialTheme.typography.bodyLarge)
    }
}

@StringRes
private fun instructionsTitle(method: FulfilmentMethod?): Int = when (method) {
    FulfilmentMethod.Pickup -> R.string.orders_detail_instructions_pickup_title
    FulfilmentMethod.Courier -> R.string.orders_detail_instructions_courier_title
    null -> R.string.orders_detail_instructions_title
}

@Composable
private fun AddressSection(address: OrderAddress) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
        SectionTitle(stringResource(R.string.orders_detail_address_title))
        address.recipientName?.let { Text(text = it, style = MaterialTheme.typography.bodyLarge) }
        address.phone?.let { Text(text = it, style = MaterialTheme.typography.bodyLarge) }
        address.addressLine?.let { Text(text = it, style = MaterialTheme.typography.bodyLarge) }
        val area = listOfNotNull(address.city, address.province, address.postalCode).joinToString(", ")
        if (area.isNotEmpty()) Text(text = area, style = MaterialTheme.typography.bodyLarge)
        address.notes?.let { notes ->
            Text(
                text = stringResource(R.string.orders_detail_address_notes, notes),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun ContactSection(contact: OrderContact) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
        SectionTitle(stringResource(R.string.orders_detail_contact_title))
        if (contact.name.isNotEmpty()) Text(text = contact.name, style = MaterialTheme.typography.bodyLarge)
        contact.phone?.let { Text(text = it, style = MaterialTheme.typography.bodyLarge) }
        contact.email?.let { Text(text = it, style = MaterialTheme.typography.bodyLarge) }
    }
}

/** Angka-angka di sini semuanya dari server; aplikasi tidak menghitung ulang apa pun. */
@Composable
private fun TotalsSection(order: OrderDetail) {
    val courier = order.summary.fulfilmentMethod == FulfilmentMethod.Courier
    // Pesanan ambil-di-toko tidak punya ongkos kirim: barisnya tidak ditampilkan.
    val showShipping = courier || order.shippingCostPending || order.shippingCost.compareTo(BigDecimal.ZERO) != 0
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        SectionTitle(stringResource(R.string.orders_detail_totals_title))
        LabeledRow(label = stringResource(R.string.orders_detail_subtotal), value = RupiahFormatter.format(order.subtotal))
        if (showShipping) {
            LabeledRow(
                label = stringResource(R.string.orders_detail_shipping_cost),
                value = if (order.shippingCostPending) {
                    stringResource(R.string.orders_detail_shipping_pending)
                } else {
                    RupiahFormatter.format(order.shippingCost)
                },
                modifier = Modifier.testTag(OrdersTestTags.DETAIL_SHIPPING_COST),
            )
        }
        LabeledRow(
            label = stringResource(R.string.orders_detail_total),
            value = RupiahFormatter.format(order.summary.total),
            emphasised = true,
            modifier = Modifier.testTag(OrdersTestTags.DETAIL_TOTAL),
        )
        if (order.shippingCostPending) {
            Text(
                text = stringResource(R.string.orders_detail_shipping_pending_note),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

/**
 * Pelacakan: tanda "Diperbarui oleh toko", data pengiriman kurir bila sudah ada, "Lacak paket"
 * (hanya dengan tautan https yang aman dari server), dan penyegaran manual.
 */
@Composable
private fun TrackingSection(
    state: OrderDetailUiState.Content,
    dateFormatter: OrderDateFormatter,
    onRefresh: () -> Unit,
    onCopyTrackingNumber: (String) -> Unit,
    onTrackParcel: () -> Unit,
) {
    val shipment = state.order.shipment
    Column(
        modifier = Modifier.testTag(OrdersTestTags.TRACKING_SECTION),
        verticalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        SectionTitle(stringResource(R.string.orders_tracking_title))
        if (state.updatedByStore) UpdatedByStoreNotice()
        when {
            shipment != null -> ShipmentBlock(
                shipment = shipment,
                dateFormatter = dateFormatter,
                onCopyTrackingNumber = onCopyTrackingNumber,
            )
            state.order.summary.fulfilmentMethod == FulfilmentMethod.Courier -> Text(
                text = stringResource(R.string.orders_tracking_no_shipment_courier),
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        if (state.trackingLink != null) {
            PrimaryButton(
                text = stringResource(R.string.orders_tracking_track_parcel),
                onClick = onTrackParcel,
                modifier = Modifier.fillMaxWidth(),
            )
        }
        SecondaryButton(
            text = stringResource(R.string.orders_tracking_refresh),
            onClick = onRefresh,
            // Selama penyegaran berjalan tombol mati: ketukan ganda tidak mengirim dua kali.
            enabled = !state.refreshing,
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

/** Pelacakan v1 dicatat staf toko, bukan telemetri kurir: dikatakan terus terang, ikon + teks. */
@Composable
private fun UpdatedByStoreNotice() {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.semantics(mergeDescendants = true) {},
        ) {
            Icon(
                Icons.Filled.Info,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.size(20.dp),
            )
            Spacer(Modifier.width(Spacing.sm))
            Text(
                text = stringResource(R.string.orders_tracking_updated_by_store),
                style = MaterialTheme.typography.labelLarge,
            )
        }
        Text(
            text = stringResource(R.string.orders_tracking_updated_by_store_note),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun ShipmentBlock(
    shipment: Shipment,
    dateFormatter: OrderDateFormatter,
    onCopyTrackingNumber: (String) -> Unit,
) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        shipment.courierName?.let { LabeledRow(label = stringResource(R.string.orders_tracking_courier_label), value = it) }
        shipment.serviceName?.let { LabeledRow(label = stringResource(R.string.orders_tracking_service_label), value = it) }
        val trackingNumber = shipment.trackingNumber
        if (trackingNumber != null) {
            val copyDescription = stringResource(R.string.orders_tracking_copy_description)
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(
                    modifier = Modifier
                        .weight(1f)
                        .semantics(mergeDescendants = true) {},
                ) {
                    Text(
                        text = stringResource(R.string.orders_tracking_number_label),
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Text(text = trackingNumber, style = MaterialTheme.typography.titleMedium)
                }
                TextButton(
                    onClick = { onCopyTrackingNumber(trackingNumber) },
                    modifier = Modifier
                        .heightIn(min = Dimens.MinTouchTarget)
                        .semantics { contentDescription = copyDescription },
                ) {
                    Text(stringResource(R.string.orders_tracking_copy))
                }
            }
        } else {
            LabeledRow(
                label = stringResource(R.string.orders_tracking_number_label),
                value = stringResource(R.string.orders_tracking_number_missing),
            )
        }
        shipment.dispatchedAt?.let {
            LabeledRow(label = stringResource(R.string.orders_tracking_dispatched_label), value = dateFormatter.format(it))
        }
        shipment.deliveredAt?.let {
            LabeledRow(label = stringResource(R.string.orders_tracking_delivered_label), value = dateFormatter.format(it))
        }
    }
}

@Composable
private fun TimelineSection(events: List<OrderEvent>, dateFormatter: OrderDateFormatter) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.md)) {
        SectionTitle(stringResource(R.string.orders_timeline_title))
        if (events.isEmpty()) {
            Text(
                text = stringResource(R.string.orders_timeline_empty),
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        events.forEach { event -> TimelineRow(event = event, dateFormatter = dateFormatter) }
    }
}

/** Satu peristiwa: ikon (bentuknya berbeda per status) + kalimat polos + catatan toko + waktu. */
@Composable
private fun TimelineRow(event: OrderEvent, dateFormatter: OrderDateFormatter) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .testTag(OrdersTestTags.TIMELINE_EVENT)
            .semantics(mergeDescendants = true) {},
        horizontalArrangement = Arrangement.spacedBy(Spacing.md),
    ) {
        Icon(
            event.icon().asImageVector(),
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.size(24.dp),
        )
        Column(modifier = Modifier.weight(1f)) {
            Text(text = stringResource(event.labelRes()), style = MaterialTheme.typography.bodyLarge)
            event.note?.let { note -> Text(text = note, style = MaterialTheme.typography.bodyMedium) }
            Text(
                text = event.occurredAt?.let(dateFormatter::format) ?: stringResource(R.string.orders_time_unknown),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}
