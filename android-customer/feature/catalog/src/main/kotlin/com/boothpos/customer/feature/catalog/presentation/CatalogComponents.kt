package com.boothpos.customer.feature.catalog.presentation

import androidx.annotation.StringRes
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Info
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedCard
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.boothpos.customer.core.ui.component.ProductImage
import com.boothpos.customer.core.ui.component.StatusBadge
import com.boothpos.customer.core.ui.component.StatusTone
import com.boothpos.customer.core.ui.format.RupiahFormatter
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.catalog.R
import com.boothpos.customer.feature.catalog.domain.ProductSort
import com.boothpos.customer.feature.catalog.domain.ProductSummary

/** Tag tes untuk node yang teksnya tidak unik di layar (mis. harga juga muncul di baris varian). */
internal object CatalogTestTags {
    const val PRODUCT_LIST = "catalog_product_list"
    const val DETAIL_PRICE = "catalog_detail_price"
    const val DETAIL_QUANTITY = "catalog_detail_quantity"
}

@StringRes
internal fun ProductSort.labelRes(): Int = when (this) {
    ProductSort.Newest -> R.string.catalog_sort_newest
    ProductSort.Name -> R.string.catalog_sort_name
    ProductSort.PriceAsc -> R.string.catalog_sort_price_asc
    ProductSort.PriceDesc -> R.string.catalog_sort_price_desc
}

/** "Rp15.000", atau "Mulai dari Rp15.000" bila harga variannya berbeda-beda. */
@Composable
internal fun priceLabel(product: ProductSummary): String {
    val lowest = RupiahFormatter.format(product.priceMin)
    return if (product.hasPriceRange) stringResource(R.string.catalog_price_from, lowest) else lowest
}

/** Ketersediaan sebagai ikon + teks (bukan warna saja), untuk layar detail. */
@Composable
internal fun AvailabilityBadge(inStock: Boolean, modifier: Modifier = Modifier) {
    StatusBadge(
        text = stringResource(if (inStock) R.string.catalog_in_stock else R.string.catalog_out_of_stock),
        icon = if (inStock) Icons.Filled.CheckCircle else Icons.Filled.Info,
        tone = if (inStock) StatusTone.Success else StatusTone.Neutral,
        modifier = modifier,
    )
}

/**
 * Ketersediaan di dalam kartu. Sengaja bukan [StatusBadge]: lencana itu menggabungkan semantiknya
 * sendiri, sehingga pembaca layar akan membacanya terpisah dari kartu; di sini teksnya ikut
 * terbaca sebagai bagian dari kartu (nama, penjual, harga, ketersediaan).
 */
@Composable
private fun AvailabilityLabel(inStock: Boolean) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(
            imageVector = if (inStock) Icons.Filled.CheckCircle else Icons.Filled.Info,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.size(16.dp),
        )
        Spacer(Modifier.width(Spacing.xs))
        Text(
            text = stringResource(if (inStock) R.string.catalog_in_stock else R.string.catalog_out_of_stock),
            style = MaterialTheme.typography.labelLarge,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

/**
 * Kartu produk di daftar: gambar, nama, penjual, harga, ketersediaan. Seluruh kartu adalah satu
 * aksi (buka detail), jadi pembaca layar membacanya sebagai satu tombol.
 */
@Composable
internal fun ProductCard(product: ProductSummary, onClick: () -> Unit, modifier: Modifier = Modifier) {
    OutlinedCard(
        modifier = modifier
            .fillMaxWidth()
            .clip(CardDefaults.outlinedShape)
            .clickable(
                onClickLabel = stringResource(R.string.catalog_card_click_label),
                role = Role.Button,
                onClick = onClick,
            ),
    ) {
        Row(
            modifier = Modifier.padding(Spacing.md),
            horizontalArrangement = Arrangement.spacedBy(Spacing.md),
        ) {
            // Dekoratif di daftar: nama produk di sebelahnya sudah menjelaskannya.
            ProductImage(imageUrl = product.imageUrl, contentDescription = null, size = CardImageSize)
            Column(verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
                Text(
                    text = product.name,
                    style = MaterialTheme.typography.titleMedium,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
                Text(
                    text = product.sellerName,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Text(
                    text = priceLabel(product),
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.SemiBold,
                )
                AvailabilityLabel(inStock = product.inStock)
            }
        }
    }
}

private val CardImageSize = 88.dp
