package com.boothpos.customer.feature.catalog.presentation

import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.layout
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.isOffline
import com.boothpos.customer.core.ui.component.ApiErrorState
import com.boothpos.customer.core.ui.component.AppTextField
import com.boothpos.customer.core.ui.component.CircleImage
import com.boothpos.customer.core.ui.component.EmptyState
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.InlineMessageTone
import com.boothpos.customer.core.ui.component.LoadingState
import com.boothpos.customer.core.ui.component.OfflineBanner
import com.boothpos.customer.core.ui.component.RoundIconButton
import com.boothpos.customer.core.ui.icon.SkyIcons
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.PillShape
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.catalog.R
import com.boothpos.customer.feature.catalog.domain.Category
import com.boothpos.customer.feature.catalog.domain.ProductSort
import com.boothpos.customer.core.ui.R as CoreUiR

/** Bagian stateful daftar produk: mengumpulkan state, lalu menyerahkannya ke layar stateless. */
@Composable
internal fun ShopScreenRoute(
    onProductClick: (slug: String) -> Unit,
    viewModel: ShopViewModel = hiltViewModel(),
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    ShopScreen(
        state = state,
        onSearchChange = viewModel::onSearchChange,
        onSearchSubmit = viewModel::onSearchSubmit,
        onCategorySelected = viewModel::onCategorySelected,
        onSortSelected = viewModel::onSortSelected,
        onRefresh = viewModel::refresh,
        onLoadMore = viewModel::loadMore,
        onRetryLoadMore = viewModel::retryLoadMore,
        onClearFilters = viewModel::clearFilters,
        onProductClick = onProductClick,
    )
}

/**
 * Daftar produk, desain "Sky Capsule": kolom cari membulat, lingkaran kategori, lalu grid kartu.
 * Jumlah kolom grid mengikuti lebar DAN skala huruf: dua kolom di ponsel biasa, satu kolom di
 * ponsel sempit atau huruf sangat besar (nama dan harga tetap terbaca), lebih banyak di layar
 * lebar (bingkai aplikasi membatasi lebar konten, jadi bukan dasbor).
 *
 * Kolom cari sengaja berada di luar bagian yang berganti-ganti (memuat/daftar/galat), supaya
 * fokus dan papan ketik tidak hilang ketika hasil pencarian tiba.
 */
@Composable
internal fun ShopScreen(
    state: ShopUiState,
    onSearchChange: (String) -> Unit,
    onSearchSubmit: () -> Unit,
    onCategorySelected: (Long?) -> Unit,
    onSortSelected: (ProductSort) -> Unit,
    onRefresh: () -> Unit,
    onLoadMore: () -> Unit,
    onRetryLoadMore: () -> Unit,
    onClearFilters: () -> Unit,
    onProductClick: (slug: String) -> Unit,
    modifier: Modifier = Modifier,
) {
    // Dipegang di sini supaya posisi gulir baris kategori bertahan saat konten di bawahnya berganti.
    val chipsState = rememberLazyListState()
    val chips: @Composable () -> Unit = {
        CategoryChips(
            categories = state.categories,
            selectedCategoryId = state.selectedCategoryId,
            onCategorySelected = onCategorySelected,
            listState = chipsState,
        )
    }

    Column(modifier = modifier.fillMaxSize()) {
        SearchBar(
            search = state.search,
            sort = state.sort,
            onSearchChange = onSearchChange,
            onSearchSubmit = onSearchSubmit,
            onSortSelected = onSortSelected,
            onRefresh = onRefresh,
        )
        when (val content = state.content) {
            ShopContent.Loading -> {
                chips()
                LoadingState(modifier = Modifier.weight(1f))
            }

            is ShopContent.Error -> {
                chips()
                ApiErrorState(
                    error = content.error,
                    onRetry = onRefresh,
                    retrying = content.retrying,
                    modifier = Modifier.weight(1f),
                )
            }

            is ShopContent.Empty -> {
                chips()
                if (content.filtered) {
                    EmptyState(
                        title = stringResource(R.string.catalog_no_results_title),
                        message = stringResource(R.string.catalog_no_results_message),
                        actionLabel = stringResource(R.string.catalog_no_results_action),
                        onAction = onClearFilters,
                        modifier = Modifier.weight(1f),
                    )
                } else {
                    EmptyState(
                        title = stringResource(R.string.catalog_empty_title),
                        message = stringResource(R.string.catalog_empty_message),
                        actionLabel = stringResource(R.string.catalog_refresh),
                        onAction = onRefresh,
                        modifier = Modifier.weight(1f),
                    )
                }
            }

            is ShopContent.Products -> ProductList(
                content = content,
                chips = chips.takeIf { state.categories.isNotEmpty() },
                onRefresh = onRefresh,
                onLoadMore = onLoadMore,
                onRetryLoadMore = onRetryLoadMore,
                onProductClick = onProductClick,
                modifier = Modifier.weight(1f),
            )
        }
    }
}

@Composable
private fun SearchBar(
    search: String,
    sort: ProductSort,
    onSearchChange: (String) -> Unit,
    onSearchSubmit: () -> Unit,
    onSortSelected: (ProductSort) -> Unit,
    onRefresh: () -> Unit,
) {
    val keyboard = LocalSoftwareKeyboardController.current
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(start = Spacing.lg, end = Spacing.lg, top = Spacing.xs, bottom = Spacing.md),
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        AppTextField(
            value = search,
            onValueChange = onSearchChange,
            label = stringResource(R.string.catalog_search_label),
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
            keyboardActions = KeyboardActions(
                onSearch = {
                    keyboard?.hide()
                    onSearchSubmit()
                },
            ),
            // Dekoratif: label kolomnya sudah berbunyi "Cari produk".
            leadingIcon = {
                Icon(SkyIcons.Search, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
            },
            trailingIcon = if (search.isNotEmpty()) {
                {
                    IconButton(onClick = { onSearchChange("") }, modifier = Modifier.size(Dimens.MinTouchTarget)) {
                        Icon(SkyIcons.Close, contentDescription = stringResource(R.string.catalog_search_clear))
                    }
                }
            } else {
                null
            },
            shape = PillShape,
            modifier = Modifier.weight(1f),
        )
        SortMenu(sort = sort, onSortSelected = onSortSelected)
        // Padanan tarik-untuk-segarkan yang dapat dijangkau tanpa gestur (pembaca layar, tombol).
        RoundIconButton(
            icon = SkyIcons.Refresh,
            contentDescription = stringResource(R.string.catalog_refresh),
            onClick = onRefresh,
            size = Dimens.MinTouchTarget,
        )
    }
}

@Composable
private fun SortMenu(sort: ProductSort, onSortSelected: (ProductSort) -> Unit) {
    // Murni keadaan tampilan (menu terbuka/tertutup); pilihannya sendiri ada di ViewModel.
    var expanded by remember { mutableStateOf(false) }
    val description = stringResource(R.string.catalog_sort_description, stringResource(sort.labelRes()))
    Box {
        RoundIconButton(
            painter = painterResource(R.drawable.catalog_ic_sort),
            contentDescription = description,
            onClick = { expanded = true },
            size = Dimens.MinTouchTarget,
        )
        DropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            ProductSort.entries.forEach { option ->
                val isSelected = option == sort
                DropdownMenuItem(
                    text = {
                        // Tebal untuk yang terpilih; bawaan menu Material memakai gaya tombol (tebal semua).
                        Text(
                            stringResource(option.labelRes()),
                            style = MaterialTheme.typography.bodyLarge,
                            fontWeight = if (isSelected) FontWeight.SemiBold else FontWeight.Normal,
                        )
                    },
                    onClick = {
                        expanded = false
                        onSortSelected(option)
                    },
                    // Centang (bukan warna) menandai urutan yang sedang dipakai.
                    leadingIcon = {
                        if (isSelected) {
                            Icon(SkyIcons.Check, contentDescription = null)
                        } else {
                            Spacer(Modifier.size(24.dp))
                        }
                    },
                    modifier = Modifier.semantics { selected = isSelected },
                )
            }
        }
    }
}

@Composable
private fun CategoryChips(
    categories: List<Category>,
    selectedCategoryId: Long?,
    onCategorySelected: (Long?) -> Unit,
    listState: LazyListState,
    modifier: Modifier = Modifier,
) {
    if (categories.isEmpty()) return
    val label = stringResource(R.string.catalog_categories_label)
    val sky = BoothTheme.sky
    LazyRow(
        state = listState,
        contentPadding = PaddingValues(horizontal = Spacing.lg),
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
        modifier = modifier
            .fillMaxWidth()
            .padding(bottom = Spacing.md)
            .semantics { contentDescription = label },
    ) {
        item(key = "all") {
            CategoryCircle(
                name = stringResource(R.string.catalog_category_all),
                imageUrl = null,
                tint = sky.tint(ALL_CATEGORIES_TINT),
                selected = selectedCategoryId == null,
                onClick = { onCategorySelected(null) },
                allCategories = true,
            )
        }
        items(categories.size, key = { categories[it].id }) { index ->
            val category = categories[index]
            CategoryCircle(
                name = category.name,
                imageUrl = category.imageUrl,
                tint = sky.tint(index),
                selected = category.id == selectedCategoryId,
                onClick = { onCategorySelected(category.id) },
            )
        }
    }
}

/**
 * Satu kategori: lingkaran berwarna di atas namanya. Yang terpilih ditandai cincin kuning DAN nama
 * yang tebal (bukan warna saja); semantik pilihannya sama dengan chip filter sebelumnya.
 */
@Composable
private fun CategoryCircle(
    name: String,
    imageUrl: String?,
    tint: Color,
    selected: Boolean,
    onClick: () -> Unit,
    allCategories: Boolean = false,
) {
    val scheme = MaterialTheme.colorScheme
    Column(
        modifier = Modifier
            .widthIn(min = CategoryMinWidth, max = CategoryMaxWidth)
            .selectable(selected = selected, role = Role.Checkbox, onClick = onClick)
            .padding(vertical = Spacing.xs),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(
            modifier = Modifier
                .size(CategoryRingSize)
                .border(2.dp, if (selected) scheme.primaryContainer else Color.Transparent, CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            CircleImage(imageUrl = imageUrl, size = CategoryCircleSize, background = tint) {
                if (allCategories) {
                    Icon(SkyIcons.Sparkle, contentDescription = null, tint = BoothTheme.sky.onHero)
                } else {
                    // Huruf awal sebagai gambar pengganti; namanya sudah tertulis lengkap di bawah.
                    Text(
                        text = name.take(1).uppercase(),
                        style = MaterialTheme.typography.titleLarge,
                        color = BoothTheme.sky.onHero,
                        modifier = Modifier.clearAndSetSemantics { },
                    )
                }
            }
        }
        Spacer(Modifier.size(Spacing.xs))
        Text(
            text = name,
            style = MaterialTheme.typography.bodySmall,
            fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
            color = if (selected) scheme.onSurface else scheme.onSurfaceVariant,
            textAlign = TextAlign.Center,
            maxLines = 2,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ProductList(
    content: ShopContent.Products,
    chips: (@Composable () -> Unit)?,
    onRefresh: () -> Unit,
    onLoadMore: () -> Unit,
    onRetryLoadMore: () -> Unit,
    onProductClick: (slug: String) -> Unit,
    modifier: Modifier = Modifier,
) {
    val currentOnLoadMore by rememberUpdatedState(onLoadMore)
    // Lebar minimum kartu ikut skala huruf: dengan huruf besar grid turun ke satu kolom.
    val minCardWidth = MinCardWidth * LocalDensity.current.fontScale.coerceAtLeast(1f)
    PullToRefreshBox(isRefreshing = content.refreshing, onRefresh = onRefresh, modifier = modifier.fillMaxWidth()) {
        LazyVerticalGrid(
            columns = GridCells.Adaptive(minSize = minCardWidth),
            contentPadding = PaddingValues(start = Spacing.lg, end = Spacing.lg, bottom = Spacing.lg),
            verticalArrangement = Arrangement.spacedBy(Spacing.md),
            horizontalArrangement = Arrangement.spacedBy(Spacing.md),
            modifier = Modifier
                .fillMaxSize()
                .testTag(CatalogTestTags.PRODUCT_LIST),
        ) {
            if (chips != null) {
                item(key = KEY_CATEGORIES, span = { GridItemSpan(maxLineSpan) }, contentType = "categories") {
                    // Baris kategori menggulir sampai tepi layar, melewati padding sisi grid.
                    Box(modifier = Modifier.bleed(Spacing.lg)) { chips() }
                }
            }
            content.staleReason?.let { reason ->
                item(key = KEY_STALE, span = { GridItemSpan(maxLineSpan) }, contentType = "notice") {
                    StaleNotice(reason = reason, onRetry = onRefresh)
                }
            }
            itemsIndexed(
                items = content.items,
                key = { _, product -> PRODUCT_KEY_PREFIX + product.slug },
                contentType = { _, _ -> "product" },
            ) { index, product ->
                // Halaman berikutnya diminta ketika kartu di dekat akhir daftar mulai disusun
                // (artinya guliran sudah dekat), bukan di muka: katalog tidak pernah dimuat seluruhnya.
                // Kunci `refreshing`: permintaan yang diabaikan selama penyegaran diulang sesudahnya.
                if (content.canLoadMore && index >= content.items.lastIndex - LOAD_MORE_THRESHOLD) {
                    LaunchedEffect(content.items.size, content.refreshing) { currentOnLoadMore() }
                }
                ProductCard(product = product, onClick = { onProductClick(product.slug) })
            }
            when {
                content.loadingMore -> item(key = KEY_FOOTER, span = { GridItemSpan(maxLineSpan) }, contentType = "footer") {
                    LoadingMoreRow()
                }
                content.loadMoreError != null -> item(
                    key = KEY_FOOTER,
                    span = { GridItemSpan(maxLineSpan) },
                    contentType = "footer",
                ) {
                    InlineMessage(
                        text = stringResource(R.string.catalog_load_more_failed),
                        tone = InlineMessageTone.Error,
                        actionLabel = stringResource(CoreUiR.string.core_ui_retry),
                        onAction = onRetryLoadMore,
                    )
                }
            }
        }
    }
}

/** Melebarkan anak melewati padding sisi induknya (kiri dan kanan sebesar [horizontal]). */
private fun Modifier.bleed(horizontal: Dp): Modifier = layout { measurable, constraints ->
    val extra = horizontal.roundToPx()
    val width = constraints.maxWidth + extra * 2
    val placeable = measurable.measure(constraints.copy(minWidth = width, maxWidth = width))
    layout(constraints.maxWidth, placeable.height) { placeable.place(-extra, 0) }
}

/** Yang tampil mungkin bukan data terbaru: offline (daftar dari cache) atau penyegaran gagal. */
@Composable
private fun StaleNotice(reason: ApiError, onRetry: () -> Unit) {
    if (reason.isOffline) {
        // Pita offline membawa padding sisinya sendiri; grid sudah memberi padding yang sama.
        Box(modifier = Modifier.bleed(Spacing.md)) {
            OfflineBanner(
                message = stringResource(R.string.catalog_offline_cached),
                actionLabel = stringResource(CoreUiR.string.core_ui_retry),
                onAction = onRetry,
            )
        }
    } else {
        InlineMessage(
            text = stringResource(R.string.catalog_refresh_failed),
            tone = InlineMessageTone.Error,
            actionLabel = stringResource(CoreUiR.string.core_ui_retry),
            onAction = onRetry,
        )
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
            text = stringResource(R.string.catalog_loading_more),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

/** Jarak (dalam kartu) dari akhir daftar saat halaman berikutnya mulai diminta. */
private const val LOAD_MORE_THRESHOLD = 4

// Kunci item daftar. Slug berasal dari server (bisa saja "categories"), jadi kunci produk diberi
// awalan supaya tidak pernah bertabrakan dengan kunci item tetap: kunci ganda membuat grid crash.
private const val PRODUCT_KEY_PREFIX = "product:"
private const val KEY_CATEGORIES = "header:categories"
private const val KEY_STALE = "header:stale"
private const val KEY_FOOTER = "footer"

// Dua kolom pada ponsel 360 dp ke atas (padding sisi 24 dp, jarak 16 dp); satu kolom di bawahnya.
private val MinCardWidth = 148.dp
private val CategoryCircleSize = 56.dp
private val CategoryRingSize = 66.dp
private val CategoryMinWidth = 66.dp
private val CategoryMaxWidth = 88.dp

// "Semua" memakai warna ubin keempat (hijau muda), seperti di desain.
private const val ALL_CATEGORIES_TINT = 3
