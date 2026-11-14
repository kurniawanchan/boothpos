package com.boothpos.customer.feature.orders.presentation

import android.content.ActivityNotFoundException
import android.content.ClipData
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.annotation.StringRes
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.platform.ClipEntry
import androidx.compose.ui.platform.LocalClipboard
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalResources
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.core.net.toUri
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.ui.component.ApiErrorState
import com.boothpos.customer.core.ui.component.AppCard
import com.boothpos.customer.core.ui.component.EmptyState
import com.boothpos.customer.core.ui.component.LoadingState
import com.boothpos.customer.core.ui.component.Mascot
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.ProductImage
import com.boothpos.customer.core.ui.component.SecondaryButton
import com.boothpos.customer.core.ui.component.SectionLabel
import com.boothpos.customer.core.ui.component.SegmentedProgress
import com.boothpos.customer.core.ui.component.StatusBadge
import com.boothpos.customer.core.ui.component.StatusTone
import com.boothpos.customer.core.ui.component.paletteSeed
import com.boothpos.customer.core.ui.format.RupiahFormatter
import com.boothpos.customer.core.ui.icon.SkyIcons
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.PillShape
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
 * Detail pesanan, desain "Sky Capsule". Urutan isi: kartu status sekarang (berwarna hero, dengan
 * penjelasan dan batang progres), lalu satu kartu putih per bagian: nomor pesanan, barang,
 * petunjuk toko, alamat (kurir), kontak, catatan, biaya, pelacakan, dan riwayat (terbaru dulu).
 * Alasan pembatalan ditaruh tepat di bawah status, karena itulah pertanyaan pertama pelanggan
 * saat melihat "Dibatalkan".
 *
 * Tata letak: satu kolom untuk semua lebar; di layar lebar bingkai aplikasi memusatkan konten
 * dengan lebar maksimum. Judul layar dan tombol kembali digambar bingkai aplikasi, bukan di sini.
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
    // Jarak tepi dipasang per kartu, bukan pada kolom gulirnya: bayangan kartu melebar ke samping
    // dan tidak boleh terpotong tepi kolom.
    val card = Modifier.padding(horizontal = Spacing.lg)
    PullToRefreshBox(isRefreshing = state.refreshing, onRefresh = onRefresh, modifier = Modifier.fillMaxSize()) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(top = Spacing.sm, bottom = Spacing.lg),
            verticalArrangement = Arrangement.spacedBy(Spacing.ms),
        ) {
            state.refreshError?.let { reason -> OrdersStaleNotice(reason = reason, onRetry = onRefresh) }
            StatusHeader(order = order, dateFormatter = dateFormatter, modifier = card)
            SummarySection(order = order, dateFormatter = dateFormatter, modifier = card)
            ItemsSection(items = order.items, modifier = card)
            order.instructions?.let { instructions ->
                TextSection(
                    title = stringResource(instructionsTitle(order.summary.fulfilmentMethod)),
                    body = instructions,
                    modifier = card,
                )
            }
            order.shippingAddress?.let { address -> AddressSection(address = address, modifier = card) }
            ContactSection(contact = order.contact, modifier = card)
            order.notes?.let { notes ->
                TextSection(title = stringResource(R.string.orders_detail_notes_title), body = notes, modifier = card)
            }
            TotalsSection(order = order, modifier = card)
            TrackingSection(
                state = state,
                dateFormatter = dateFormatter,
                onRefresh = onRefresh,
                onCopyTrackingNumber = onCopyTrackingNumber,
                onTrackParcel = onTrackParcel,
                modifier = card,
            )
            TimelineSection(events = order.timeline, dateFormatter = dateFormatter, modifier = card)
        }
    }
}

/** Huruf sistem besar: bagian hiasan dan tata letak berdampingan mengalah supaya teks tidak terjepit. */
@Composable
private fun isLargeFont(): Boolean = LocalDensity.current.fontScale > LARGE_FONT_SCALE

/**
 * Status sekarang, paling atas: kartu berwarna hero berisi nomor pesanan dan cara menerimanya,
 * ikon + nama status, waktu, penjelasan sehari-hari, dan batang progres lima tahap. Maskot di
 * kanan hanya hiasan.
 */
@Composable
private fun StatusHeader(order: OrderDetail, dateFormatter: OrderDateFormatter, modifier: Modifier = Modifier) {
    val summary = order.summary
    val visual = summary.status.visual()
    val sky = BoothTheme.sky
    val typography = MaterialTheme.typography
    val label = stringResource(visual.label)
    val description = stringResource(R.string.orders_detail_status_description, label)
    val method = summary.fulfilmentMethod.labelRes()?.let { stringResource(it) }
    val stages = summary.status.reachedStages()
    // Pada huruf besar maskot disembunyikan: ia hanya hiasan, dan lebarnya lebih berguna bagi teks.
    val showMascot = !isLargeFont()
    Surface(
        color = sky.hero,
        contentColor = sky.onHero,
        shape = MaterialTheme.shapes.large,
        modifier = modifier
            .fillMaxWidth()
            .testTag(OrdersTestTags.DETAIL_STATUS),
    ) {
        Row(
            modifier = Modifier.padding(Spacing.md),
            horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
                // Satu teks, bukan dua: nama metode yang berdiri sendiri sudah ada di kartu "Pesanan".
                Text(
                    text = listOfNotNull(summary.orderNumber, method).joinToString(HEADER_SEPARATOR),
                    style = typography.titleSmall,
                )
                Row(verticalAlignment = Alignment.CenterVertically) {
                    // Dekoratif bagi pembaca layar: nama status di sebelahnya sudah dibacakan.
                    Icon(visual.icon.asImageVector(), contentDescription = null, modifier = Modifier.size(StatusIconSize))
                    Spacer(Modifier.width(Spacing.sm))
                    Text(
                        text = label,
                        style = typography.titleLarge,
                        modifier = Modifier.semantics {
                            heading()
                            contentDescription = description
                        },
                    )
                }
                summary.updatedAt?.let { updatedAt ->
                    Text(
                        text = stringResource(R.string.orders_detail_status_updated, dateFormatter.format(updatedAt)),
                        style = typography.bodySmall,
                    )
                }
                Text(text = stringResource(visual.explanation), style = typography.bodySmall)
                order.cancelReason?.let { reason ->
                    OrderNote {
                        Text(
                            text = stringResource(R.string.orders_detail_cancel_reason_title),
                            style = typography.labelMedium,
                        )
                        Text(text = reason, style = typography.bodyMedium)
                    }
                }
                // Pesanan batal dan status yang belum dikenal tidak punya posisi di batang progres.
                if (stages != null) {
                    SegmentedProgress(
                        total = ORDER_STAGE_COUNT,
                        filled = stages,
                        modifier = Modifier.padding(top = Spacing.sm),
                    )
                }
            }
            if (showMascot) Mascot(modifier = Modifier.width(MascotWidth))
        }
    }
}

/** Catatan koral desain (alasan pembatalan): latar lembut bersudut 14 dp, teks koral tua. */
@Composable
private fun OrderNote(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    val scheme = MaterialTheme.colorScheme
    CompositionLocalProvider(LocalContentColor provides scheme.onTertiaryContainer) {
        Column(
            modifier = modifier
                .fillMaxWidth()
                .clip(MaterialTheme.shapes.small)
                .background(scheme.tertiaryContainer)
                .padding(horizontal = Spacing.ms, vertical = Spacing.sm),
            content = content,
        )
    }
}

/** Satu bagian detail: kartu putih dengan label bagian kecil di atas isinya. */
@Composable
private fun SectionCard(
    title: String,
    modifier: Modifier = Modifier,
    spacing: Dp = Spacing.sm,
    content: @Composable ColumnScope.() -> Unit,
) {
    AppCard(modifier = modifier.fillMaxWidth()) {
        SectionLabel(title)
        Spacer(Modifier.height(Spacing.sm))
        Column(
            modifier = Modifier.fillMaxWidth(),
            verticalArrangement = Arrangement.spacedBy(spacing),
            content = content,
        )
    }
}

/**
 * Baris "label: nilai"; nilainya ([content]) bebas, teks atau pil. Digabung menjadi satu node
 * supaya pembaca layar membacanya sebagai satu kalimat. Nilai turun ke bawah label bila tidak muat.
 */
@Composable
private fun DetailRow(
    label: String,
    modifier: Modifier = Modifier,
    emphasised: Boolean = false,
    content: @Composable () -> Unit,
) {
    EndsOrStack(
        start = {
            Text(
                text = label,
                style = if (emphasised) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyLarge,
                color = if (emphasised) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.onSurfaceVariant,
            )
        },
        end = content,
        modifier = modifier
            .fillMaxWidth()
            .semantics(mergeDescendants = true) {},
        gap = Spacing.md,
    )
}

/** Baris "label: nilai" teks. Nilai setebal angka uang di desain; total memakai ukuran judul. */
@Composable
private fun LabeledRow(label: String, value: String, modifier: Modifier = Modifier, emphasised: Boolean = false) {
    DetailRow(label = label, modifier = modifier, emphasised = emphasised) {
        Text(
            text = value,
            style = if (emphasised) MaterialTheme.typography.titleLarge else MaterialTheme.typography.titleMedium,
        )
    }
}

@Composable
private fun SummarySection(order: OrderDetail, dateFormatter: OrderDateFormatter, modifier: Modifier = Modifier) {
    SectionCard(title = stringResource(R.string.orders_detail_summary_title), modifier = modifier) {
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
private fun ItemsSection(items: List<OrderItem>, modifier: Modifier = Modifier) {
    // Pada huruf besar jumlah uang pindah ke bawah nama: di samping, ia menyisakan beberapa dp
    // saja untuk nama barang.
    val amountBelow = isLargeFont()
    SectionCard(
        title = stringResource(R.string.orders_detail_items_title),
        modifier = modifier,
        spacing = Spacing.ms,
    ) {
        items.forEach { item ->
            val amount = RupiahFormatter.format(item.lineTotal)
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .semantics(mergeDescendants = true) {},
                horizontalArrangement = Arrangement.spacedBy(Spacing.ms),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                // Pesanan tidak membawa URL foto: ubin dan kapsulnya diwarnai dari nama barang,
                // sama dengan warna barang itu di katalog dan keranjang. Dekoratif.
                ProductImage(
                    imageUrl = null,
                    contentDescription = null,
                    size = ItemThumbSize,
                    paletteSeed = paletteSeed(item.name),
                )
                Column(modifier = Modifier.weight(1f)) {
                    Text(text = item.name, style = MaterialTheme.typography.titleMedium)
                    Text(
                        text = stringResource(
                            R.string.orders_detail_item_quantity,
                            item.quantity.toString(),
                            RupiahFormatter.format(item.unitPrice),
                        ),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    if (amountBelow) Text(text = amount, style = MaterialTheme.typography.titleMedium)
                }
                if (!amountBelow) Text(text = amount, style = MaterialTheme.typography.titleMedium)
            }
        }
    }
}

/** Judul + teks polos (petunjuk dari toko, catatan pelanggan). Teks server tidak pernah ditafsirkan sebagai markup. */
@Composable
private fun TextSection(title: String, body: String, modifier: Modifier = Modifier) {
    SectionCard(title = title, modifier = modifier) {
        Text(text = body, style = MaterialTheme.typography.bodyMedium)
    }
}

@StringRes
private fun instructionsTitle(method: FulfilmentMethod?): Int = when (method) {
    FulfilmentMethod.Pickup -> R.string.orders_detail_instructions_pickup_title
    FulfilmentMethod.Courier -> R.string.orders_detail_instructions_courier_title
    null -> R.string.orders_detail_instructions_title
}

@Composable
private fun AddressSection(address: OrderAddress, modifier: Modifier = Modifier) {
    SectionCard(
        title = stringResource(R.string.orders_detail_address_title),
        modifier = modifier,
        spacing = Spacing.xs,
    ) {
        address.recipientName?.let { Text(text = it, style = MaterialTheme.typography.titleMedium) }
        address.phone?.let { Text(text = it, style = MaterialTheme.typography.bodyMedium) }
        address.addressLine?.let { Text(text = it, style = MaterialTheme.typography.bodyMedium) }
        val area = listOfNotNull(address.city, address.province, address.postalCode).joinToString(", ")
        if (area.isNotEmpty()) Text(text = area, style = MaterialTheme.typography.bodyMedium)
        address.notes?.let { notes ->
            Text(
                text = stringResource(R.string.orders_detail_address_notes, notes),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun ContactSection(contact: OrderContact, modifier: Modifier = Modifier) {
    SectionCard(
        title = stringResource(R.string.orders_detail_contact_title),
        modifier = modifier,
        spacing = Spacing.xs,
    ) {
        if (contact.name.isNotEmpty()) Text(text = contact.name, style = MaterialTheme.typography.titleMedium)
        contact.phone?.let { Text(text = it, style = MaterialTheme.typography.bodyMedium) }
        contact.email?.let { Text(text = it, style = MaterialTheme.typography.bodyMedium) }
    }
}

/** Angka-angka di sini semuanya dari server; aplikasi tidak menghitung ulang apa pun. */
@Composable
private fun TotalsSection(order: OrderDetail, modifier: Modifier = Modifier) {
    val scheme = MaterialTheme.colorScheme
    val typography = MaterialTheme.typography
    val courier = order.summary.fulfilmentMethod == FulfilmentMethod.Courier
    // Pesanan ambil-di-toko tidak punya ongkos kirim: barisnya tidak ditampilkan.
    val showShipping = courier || order.shippingCostPending || order.shippingCost.compareTo(BigDecimal.ZERO) != 0
    SectionCard(title = stringResource(R.string.orders_detail_totals_title), modifier = modifier) {
        LabeledRow(label = stringResource(R.string.orders_detail_subtotal), value = RupiahFormatter.format(order.subtotal))
        if (showShipping) {
            DetailRow(
                label = stringResource(R.string.orders_detail_shipping_cost),
                modifier = Modifier.testTag(OrdersTestTags.DETAIL_SHIPPING_COST),
            ) {
                if (order.shippingCostPending) {
                    // Belum ada angkanya: pil koral, bukan "Rp0" yang terbaca seperti gratis.
                    Text(
                        text = stringResource(R.string.orders_detail_shipping_pending),
                        style = typography.labelMedium,
                        color = scheme.onTertiaryContainer,
                        modifier = Modifier
                            .background(scheme.tertiaryContainer, PillShape)
                            .padding(horizontal = Spacing.ms, vertical = Spacing.xs),
                    )
                } else {
                    Text(text = RupiahFormatter.format(order.shippingCost), style = typography.titleMedium)
                }
            }
        }
        // Di ATAS total: kalimatnya sendiri menyebut "total di bawah".
        if (order.shippingCostPending) {
            Text(
                text = stringResource(R.string.orders_detail_shipping_pending_note),
                style = typography.bodySmall,
                color = scheme.onSurfaceVariant,
            )
        }
        HorizontalDivider(modifier = Modifier.padding(vertical = Spacing.xs))
        LabeledRow(
            label = stringResource(R.string.orders_detail_total),
            value = RupiahFormatter.format(order.summary.total),
            emphasised = true,
            modifier = Modifier.testTag(OrdersTestTags.DETAIL_TOTAL),
        )
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
    modifier: Modifier = Modifier,
) {
    val shipment = state.order.shipment
    SectionCard(
        title = stringResource(R.string.orders_tracking_title),
        modifier = modifier.testTag(OrdersTestTags.TRACKING_SECTION),
        spacing = Spacing.ms,
    ) {
        if (state.updatedByStore) UpdatedByStoreNotice()
        when {
            shipment != null -> ShipmentBlock(
                shipment = shipment,
                dateFormatter = dateFormatter,
                onCopyTrackingNumber = onCopyTrackingNumber,
            )
            state.order.summary.fulfilmentMethod == FulfilmentMethod.Courier -> Text(
                text = stringResource(R.string.orders_tracking_no_shipment_courier),
                style = MaterialTheme.typography.bodyMedium,
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
            icon = SkyIcons.Refresh,
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

/** Pelacakan v1 dicatat staf toko, bukan telemetri kurir: dikatakan terus terang, ikon + teks. */
@Composable
private fun UpdatedByStoreNotice() {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
        StatusBadge(
            text = stringResource(R.string.orders_tracking_updated_by_store),
            icon = SkyIcons.Info,
            tone = StatusTone.Info,
        )
        Text(
            text = stringResource(R.string.orders_tracking_updated_by_store_note),
            style = MaterialTheme.typography.bodySmall,
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
                        style = MaterialTheme.typography.bodySmall,
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

/**
 * Riwayat sebagai daftar langkah tegak: titik di kiri, garis tipis antartitik, kalimat peristiwa
 * dan waktunya. Urutannya tetap dari server (terbaru dulu), dan hanya peristiwa yang SUDAH
 * terjadi yang digambar; langkah yang akan datang tidak dikarang.
 */
@Composable
private fun TimelineSection(events: List<OrderEvent>, dateFormatter: OrderDateFormatter, modifier: Modifier = Modifier) {
    AppCard(modifier = modifier.fillMaxWidth()) {
        SectionLabel(stringResource(R.string.orders_timeline_title))
        Spacer(Modifier.height(Spacing.ms))
        if (events.isEmpty()) {
            Text(
                text = stringResource(R.string.orders_timeline_empty),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        } else {
            val largeFont = isLargeFont()
            BoxWithConstraints(modifier = Modifier.fillMaxWidth()) {
                // Waktu di kanan kalimat (seperti desain) hanya di kartu yang lebar. Waktu di sini
                // berupa tanggal lengkap, jauh lebih panjang daripada jam di mockup; di ponsel ia
                // akan menjepit kalimatnya menjadi tiga baris, jadi di sana ia turun ke bawah kalimat.
                // Diputuskan sekali untuk seluruh kartu supaya semua barisnya seragam.
                val timeBeside = !largeFont && maxWidth >= TimelineTimeBesideFrom
                Column(modifier = Modifier.fillMaxWidth()) {
                    events.forEachIndexed { index, event ->
                        TimelineRow(
                            event = event,
                            current = index == 0,
                            last = index == events.lastIndex,
                            timeBeside = timeBeside,
                            dateFormatter = dateFormatter,
                        )
                    }
                }
            }
        }
    }
}

/**
 * Satu peristiwa: titik + kalimat polos + catatan toko + waktu. [current] = peristiwa terbaru
 * (keadaan sekarang): titiknya bercincin dan memuat ikon peristiwanya, kalimatnya tebal.
 * Peristiwa yang lebih lama memakai titik kuning bercentang. Maknanya selalu dibawa kalimatnya.
 */
@Composable
private fun TimelineRow(
    event: OrderEvent,
    current: Boolean,
    last: Boolean,
    timeBeside: Boolean,
    dateFormatter: OrderDateFormatter,
) {
    val typography = MaterialTheme.typography
    val connectorColor = BoothTheme.sky.progress
    val time = event.occurredAt?.let(dateFormatter::format) ?: stringResource(R.string.orders_time_unknown)
    // Garis penghubung digambar di belakang baris, dari bawah titik sampai tepi bawah baris (tempat
    // titik berikutnya mulai): tingginya mengikuti tinggi teks tanpa pengukuran intrinsik.
    val connector = if (last) {
        Modifier
    } else {
        Modifier.drawBehind {
            val dot = TimelineDotSize.toPx()
            val x = if (layoutDirection == LayoutDirection.Ltr) dot / 2 else size.width - dot / 2
            drawLine(
                color = connectorColor,
                start = Offset(x, dot),
                end = Offset(x, size.height),
                strokeWidth = TimelineConnectorWidth.toPx(),
            )
        }
    }
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .testTag(OrdersTestTags.TIMELINE_EVENT)
            .semantics(mergeDescendants = true) {}
            .then(connector)
            .padding(bottom = if (last) 0.dp else Spacing.ms),
        horizontalArrangement = Arrangement.spacedBy(Spacing.ms),
    ) {
        TimelineDot(event = event, current = current)
        Column(
            modifier = Modifier
                .weight(1f)
                // Baris pertama kalimat (24 sp) sejajar tengah dengan titik 32 dp.
                .padding(top = Spacing.xs),
        ) {
            Text(
                text = stringResource(event.labelRes()),
                style = if (current) typography.titleMedium else typography.bodyLarge,
            )
            event.note?.let { note ->
                Text(text = note, style = typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            if (!timeBeside) TimelineTime(text = time, current = current)
        }
        if (timeBeside) TimelineTime(text = time, current = current, modifier = Modifier.padding(top = Spacing.sm))
    }
}

@Composable
private fun TimelineTime(text: String, current: Boolean, modifier: Modifier = Modifier) {
    Text(
        text = text,
        style = if (current) MaterialTheme.typography.labelMedium else MaterialTheme.typography.bodySmall,
        color = if (current) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = modifier,
    )
}

/** Titik riwayat. Ikonnya dekoratif: kalimat di sebelahnya yang dibacakan. */
@Composable
private fun TimelineDot(event: OrderEvent, current: Boolean) {
    val scheme = MaterialTheme.colorScheme
    Box(
        modifier = Modifier
            .size(TimelineDotSize)
            .clip(CircleShape)
            .background(if (current) scheme.secondaryContainer else scheme.primaryContainer)
            .then(
                if (current) {
                    Modifier.border(TimelineRingWidth, BoothTheme.sky.progress, CircleShape)
                } else {
                    Modifier
                },
            ),
        contentAlignment = Alignment.Center,
    ) {
        Icon(
            imageVector = if (current) event.icon().asImageVector() else SkyIcons.Check,
            contentDescription = null,
            tint = if (current) scheme.onSecondaryContainer else scheme.onPrimaryContainer,
            modifier = Modifier.size(TimelineIconSize),
        )
    }
}

/** Di atas skala huruf ini tata letak beralih ke bentuk yang lebih lapang (lihat [isLargeFont]). */
private const val LARGE_FONT_SCALE = 1.3f

/** Pemisah nomor pesanan dan cara menerimanya di kartu status; tanda baca saja, bukan kata. */
private const val HEADER_SEPARATOR = " · "

private val MascotWidth = 72.dp
private val StatusIconSize = 24.dp
private val ItemThumbSize = 48.dp
private val TimelineDotSize = 32.dp
private val TimelineIconSize = 20.dp
private val TimelineRingWidth = 2.dp
private val TimelineConnectorWidth = 2.dp

/** Lebar isi kartu riwayat mulai dari mana waktu muat di kanan kalimat tanpa menjepitnya. */
private val TimelineTimeBesideFrom = 420.dp
