package com.boothpos.customer.feature.catalog.presentation

import android.content.res.Resources
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.SnackbarDuration
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.SnackbarResult
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.rememberVectorPainter
import androidx.compose.ui.platform.LocalResources
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import com.boothpos.customer.core.ui.component.ApiErrorState
import com.boothpos.customer.core.ui.component.AppCard
import com.boothpos.customer.core.ui.component.EmptyState
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.LoadingState
import com.boothpos.customer.core.ui.component.OfflineBanner
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.ProductCapsule
import com.boothpos.customer.core.ui.component.SectionLabel
import com.boothpos.customer.core.ui.component.SelectionMark
import com.boothpos.customer.core.ui.component.StepperButton
import com.boothpos.customer.core.ui.component.paletteSeed
import com.boothpos.customer.core.ui.format.RupiahFormatter
import com.boothpos.customer.core.ui.icon.SkyIcons
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.core.ui.theme.HeroBackdrop
import com.boothpos.customer.core.ui.theme.SheetShape
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.catalog.R
import com.boothpos.customer.feature.catalog.domain.ProductVariant
import kotlinx.coroutines.launch
import com.boothpos.customer.core.ui.R as CoreUiR

/** Bagian stateful detail produk: state + kejadian keranjang (snackbar dengan "Lihat keranjang"). */
@Composable
internal fun ProductDetailScreenRoute(
    onViewCart: () -> Unit,
    onBackToShop: () -> Unit,
    viewModel: ProductDetailViewModel = hiltViewModel(),
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val snackbarHostState = remember { SnackbarHostState() }
    val resources = LocalResources.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val currentOnViewCart by rememberUpdatedState(onViewCart)

    LaunchedEffect(viewModel, lifecycleOwner, resources) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
            viewModel.events.collect { event ->
                // Konfirmasi terbaru menggantikan yang masih tampil, bukan mengantre di belakangnya.
                snackbarHostState.currentSnackbarData?.dismiss()
                launch {
                    val result = snackbarHostState.showSnackbar(
                        message = event.message(resources),
                        actionLabel = if (event.offersCart) resources.getString(R.string.catalog_detail_view_cart) else null,
                        // Eksplisit: bawaan untuk snackbar beraksi adalah "tanpa batas waktu".
                        duration = SnackbarDuration.Long,
                    )
                    if (result == SnackbarResult.ActionPerformed) currentOnViewCart()
                }
            }
        }
    }

    ProductDetailScreen(
        state = state,
        snackbarHostState = snackbarHostState,
        onRetry = viewModel::retry,
        onVariantSelected = viewModel::onVariantSelected,
        onDecreaseQuantity = viewModel::decreaseQuantity,
        onIncreaseQuantity = viewModel::increaseQuantity,
        onAddToCart = viewModel::addToCart,
        onBackToShop = onBackToShop,
    )
}

/** Teks konfirmasi keranjang. Fungsi biasa (bukan composable) supaya dapat dipakai dari coroutine. */
internal fun ProductDetailEvent.message(resources: Resources): String = when (this) {
    is ProductDetailEvent.AddedToCart ->
        resources.getQuantityString(R.plurals.catalog_detail_added, quantity, quantity)
    is ProductDetailEvent.QuantityCapped ->
        resources.getQuantityString(R.plurals.catalog_detail_added_capped, maxQuantity, maxQuantity)
    is ProductDetailEvent.CartFull ->
        resources.getQuantityString(R.plurals.catalog_detail_cart_full, maxLines, maxLines)
    ProductDetailEvent.VariantUnavailable -> resources.getString(R.string.catalog_detail_variant_unavailable)
    ProductDetailEvent.AddFailed -> resources.getString(R.string.catalog_detail_add_failed)
}

/** "Lihat keranjang" ditawarkan bila keranjang memang berisi sesuatu yang relevan untuk dilihat. */
internal val ProductDetailEvent.offersCart: Boolean
    get() = this is ProductDetailEvent.AddedToCart ||
        this is ProductDetailEvent.QuantityCapped ||
        this is ProductDetailEvent.CartFull

/**
 * Detail produk, desain "Sky Capsule": hero berwarna dengan gambar di dalam kapsul, lalu lembar
 * konten bersudut atas membulat yang menumpang di atasnya. Satu kolom yang dapat digulir dengan
 * satu aksi utama ("Tambah ke keranjang") yang selalu terlihat di bawah; di layar lebar bingkai
 * aplikasi memusatkannya dengan lebar maksimum.
 */
@Composable
internal fun ProductDetailScreen(
    state: ProductDetailUiState,
    snackbarHostState: SnackbarHostState,
    onRetry: () -> Unit,
    onVariantSelected: (Long) -> Unit,
    onDecreaseQuantity: () -> Unit,
    onIncreaseQuantity: () -> Unit,
    onAddToCart: () -> Unit,
    onBackToShop: () -> Unit,
    modifier: Modifier = Modifier,
) {
    when (state) {
        ProductDetailUiState.Loading -> LoadingState(modifier = modifier)
        is ProductDetailUiState.Error -> ApiErrorState(
            error = state.error,
            onRetry = onRetry,
            retrying = state.retrying,
            modifier = modifier,
        )
        ProductDetailUiState.NotFound -> EmptyState(
            title = stringResource(R.string.catalog_detail_not_found_title),
            message = stringResource(R.string.catalog_detail_not_found_message),
            icon = rememberVectorPainter(SkyIcons.Info),
            actionLabel = stringResource(R.string.catalog_detail_not_found_action),
            onAction = onBackToShop,
            modifier = modifier,
        )
        is ProductDetailUiState.Content -> ProductContent(
            state = state,
            snackbarHostState = snackbarHostState,
            onRetry = onRetry,
            onVariantSelected = onVariantSelected,
            onDecreaseQuantity = onDecreaseQuantity,
            onIncreaseQuantity = onIncreaseQuantity,
            onAddToCart = onAddToCart,
            modifier = modifier,
        )
    }
}

@Composable
private fun ProductContent(
    state: ProductDetailUiState.Content,
    snackbarHostState: SnackbarHostState,
    onRetry: () -> Unit,
    onVariantSelected: (Long) -> Unit,
    onDecreaseQuantity: () -> Unit,
    onIncreaseQuantity: () -> Unit,
    onAddToCart: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val summary = state.product.summary
    val variant = state.selectedVariant
    val scheme = MaterialTheme.colorScheme
    // Bilah atas ikut berwarna hero selama isi produk tampil (tidak saat memuat/galat).
    HeroBackdrop()
    Column(modifier = modifier.fillMaxSize()) {
        Box(modifier = Modifier.weight(1f)) {
            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .verticalScroll(rememberScrollState()),
                // Negatif: lembar konten menumpang di atas tepi bawah hero, seperti di desain.
                verticalArrangement = Arrangement.spacedBy(-SheetOverlap),
            ) {
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .background(BoothTheme.sky.hero),
                ) {
                    if (state.refreshing) {
                        LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
                    }
                    if (state.offline) {
                        OfflineBanner(
                            message = stringResource(R.string.catalog_detail_offline),
                            actionLabel = stringResource(CoreUiR.string.core_ui_retry),
                            onAction = onRetry,
                            modifier = Modifier.padding(top = Spacing.sm),
                        )
                    }
                    DetailImage(
                        imageUrl = variant?.imageUrl?.takeIf { it.isNotBlank() } ?: summary.imageUrl,
                        productName = summary.name,
                    )
                }
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .clip(SheetShape)
                        .background(scheme.background)
                        .padding(Spacing.lg),
                    verticalArrangement = Arrangement.spacedBy(Spacing.md),
                ) {
                    Column(verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
                        AvailabilityBadge(inStock = state.maxQuantity >= 1)
                        Text(
                            text = summary.name,
                            style = MaterialTheme.typography.titleLarge,
                            modifier = Modifier
                                .padding(top = Spacing.xs)
                                .semantics { heading() },
                        )
                        Text(
                            text = stringResource(R.string.catalog_detail_seller, summary.sellerName),
                            style = MaterialTheme.typography.bodySmall,
                            color = scheme.onSurfaceVariant,
                        )
                        Text(
                            text = stringResource(R.string.catalog_detail_category, summary.categoryName),
                            style = MaterialTheme.typography.bodySmall,
                            color = scheme.onSurfaceVariant,
                        )
                        // Harga varian terpilih; tanpa pilihan (tidak ada yang dapat dibeli) tampil rentang harganya.
                        Text(
                            text = variant?.let { RupiahFormatter.format(it.price) } ?: priceLabel(summary),
                            style = MaterialTheme.typography.headlineMedium,
                            modifier = Modifier
                                .padding(top = Spacing.xs)
                                .testTag(CatalogTestTags.DETAIL_PRICE),
                        )
                    }
                    if (state.maxQuantity < 1) {
                        InlineMessage(text = stringResource(R.string.catalog_detail_unavailable))
                    }
                    if (state.product.variants.isNotEmpty()) {
                        VariantSelector(
                            variants = state.product.variants,
                            selectedVariantId = state.selectedVariantId,
                            onVariantSelected = onVariantSelected,
                        )
                    }
                    if (state.maxQuantity >= 1) {
                        QuantityStepper(
                            quantity = state.quantity,
                            maxQuantity = state.maxQuantity,
                            canDecrease = state.canDecrease,
                            canIncrease = state.canIncrease,
                            onDecrease = onDecreaseQuantity,
                            onIncrease = onIncreaseQuantity,
                        )
                    }
                    state.product.description?.let { description ->
                        Column(verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
                            SectionLabel(stringResource(R.string.catalog_detail_description_title))
                            Text(text = description, style = MaterialTheme.typography.bodyLarge)
                        }
                    }
                }
            }
            // Di atas bilah tombol, supaya konfirmasi tidak menutupi aksi utama.
            SnackbarHost(hostState = snackbarHostState, modifier = Modifier.align(Alignment.BottomCenter))
        }
        PrimaryButton(
            text = stringResource(R.string.catalog_detail_add_to_cart),
            onClick = onAddToCart,
            enabled = state.maxQuantity >= 1,
            loading = state.adding,
            icon = SkyIcons.Bag,
            modifier = Modifier
                .fillMaxWidth()
                .background(scheme.background)
                .padding(horizontal = Spacing.lg, vertical = Spacing.md),
        )
    }
}

/** Gambar produk di dalam kapsul, di tengah hero. Ruang di bawahnya tertutup lembar konten. */
@Composable
private fun DetailImage(imageUrl: String?, productName: String) {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .padding(top = Spacing.sm, bottom = SheetOverlap + Spacing.lg),
        contentAlignment = Alignment.Center,
    ) {
        ProductCapsule(
            imageUrl = imageUrl,
            // Ikon pengganti (tanpa gambar) tidak diumumkan sebagai foto.
            contentDescription = if (imageUrl.isNullOrBlank()) {
                null
            } else {
                stringResource(R.string.catalog_detail_image_description, productName)
            },
            diameter = HeroCapsuleSize,
            seamColor = BoothTheme.sky.hero,
            paletteSeed = paletteSeed(productName),
        )
    }
}

/**
 * Pilihan varian: satu kartu pilihan per varian dengan nama, ketersediaan (teks), dan harganya.
 * Bukan chip seperti di mockup: chip tidak memuat harga dan ketersediaan tiap varian, dan
 * keduanya tidak boleh hilang. Semantiknya tetap grup radio.
 */
@Composable
private fun VariantSelector(
    variants: List<ProductVariant>,
    selectedVariantId: Long?,
    onVariantSelected: (Long) -> Unit,
) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        SectionLabel(stringResource(R.string.catalog_detail_variants_title))
        Column(
            modifier = Modifier.selectableGroup(),
            verticalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            variants.forEach { variant ->
                val enabled = variant.isPurchasable
                val selected = variant.id == selectedVariantId
                AppCard(
                    selected = selected,
                    interaction = Modifier.selectable(
                        selected = selected,
                        enabled = enabled,
                        role = Role.RadioButton,
                        onClick = { onVariantSelected(variant.id) },
                    ),
                    contentPadding = PaddingValues(horizontal = Spacing.md, vertical = Spacing.ms),
                    modifier = Modifier
                        .fillMaxWidth()
                        // Varian yang habis tetap tampil dan terbaca, hanya memudar.
                        .alpha(if (enabled) 1f else DISABLED_ALPHA),
                ) {
                    Row(
                        modifier = Modifier.heightIn(min = VariantRowMinHeight),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Column(modifier = Modifier.weight(1f)) {
                            Text(text = variant.name, style = MaterialTheme.typography.titleMedium)
                            Text(
                                text = stringResource(if (enabled) R.string.catalog_in_stock else R.string.catalog_out_of_stock),
                                style = MaterialTheme.typography.bodySmall,
                                // Di kartu terpilih (kuning lembut) teks mengikuti warna isi kartunya.
                                color = if (selected) LocalContentColor.current else MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        Spacer(Modifier.width(Spacing.sm))
                        Text(text = RupiahFormatter.format(variant.price), style = MaterialTheme.typography.titleMedium)
                        Spacer(Modifier.width(Spacing.ms))
                        SelectionMark(selected = selected)
                    }
                }
            }
        }
    }
}

/** Jumlah dibatasi `1..max_qty` oleh ViewModel; tombol di batas dinonaktifkan, bukan disembunyikan. */
@Composable
private fun QuantityStepper(
    quantity: Int,
    maxQuantity: Int,
    canDecrease: Boolean,
    canIncrease: Boolean,
    onDecrease: () -> Unit,
    onIncrease: () -> Unit,
) {
    val quantityDescription = pluralStringResource(R.plurals.catalog_detail_quantity_value, quantity, quantity)
    Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = stringResource(R.string.catalog_detail_quantity_title),
                style = MaterialTheme.typography.titleMedium,
                modifier = Modifier.semantics { heading() },
            )
            Text(
                text = pluralStringResource(R.plurals.catalog_detail_quantity_max, maxQuantity, maxQuantity),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        StepperButton(
            painter = painterResource(R.drawable.catalog_ic_remove),
            contentDescription = stringResource(R.string.catalog_detail_quantity_decrease),
            onClick = onDecrease,
            enabled = canDecrease,
        )
        Text(
            text = quantity.toString(),
            style = MaterialTheme.typography.titleLarge,
            textAlign = TextAlign.Center,
            modifier = Modifier
                .widthIn(min = QuantityMinWidth)
                .padding(horizontal = Spacing.sm)
                .testTag(CatalogTestTags.DETAIL_QUANTITY)
                // Perubahan jumlah diumumkan pembaca layar tanpa harus memindahkan fokus.
                .semantics {
                    contentDescription = quantityDescription
                    liveRegion = LiveRegionMode.Polite
                },
        )
        StepperButton(
            painter = rememberVectorPainter(SkyIcons.Plus),
            contentDescription = stringResource(R.string.catalog_detail_quantity_increase),
            onClick = onIncrease,
            enabled = canIncrease,
            emphasized = true,
        )
    }
}

private val HeroCapsuleSize = 192.dp
private val SheetOverlap = 28.dp
private val VariantRowMinHeight = 40.dp
private val QuantityMinWidth = 40.dp
private const val DISABLED_ALPHA = 0.38f
