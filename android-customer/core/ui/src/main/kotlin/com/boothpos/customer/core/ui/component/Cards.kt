package com.boothpos.customer.core.ui.component

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.boothpos.customer.core.ui.icon.SkyIcons
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.core.ui.theme.softShadow

/**
 * Kartu desain: permukaan putih, sudut 28 dp, bayangan biru lembut.
 *
 * [selected] = kartu pilihan yang sedang terpilih: garis tepi kuning dan isi kuning lembut. Garis
 * tepi (transparan) selalu ada, supaya ukuran kartu tidak berubah saat pilihan berpindah.
 *
 * [interaction] adalah modifier klik/pilih (`clickable`, `selectable`, …) milik pemanggil. Ia
 * dipasang DI DALAM clip kartu, sehingga riak sentuhnya mengikuti sudut kartu dan bayangan tidak
 * ikut terpotong; karena itu jangan menaruhnya di [modifier].
 */
@Composable
fun AppCard(
    modifier: Modifier = Modifier,
    selected: Boolean = false,
    interaction: Modifier = Modifier,
    containerColor: Color = MaterialTheme.colorScheme.surfaceContainerLow,
    contentPadding: PaddingValues = PaddingValues(Spacing.md),
    content: @Composable ColumnScope.() -> Unit,
) {
    val scheme = MaterialTheme.colorScheme
    val shape = MaterialTheme.shapes.large
    CompositionLocalProvider(
        LocalContentColor provides if (selected) scheme.onSecondaryContainer else scheme.onSurface,
    ) {
        Column(
            modifier = modifier
                .softShadow(shape, BoothTheme.sky.shadow)
                .clip(shape)
                .background(if (selected) scheme.secondaryContainer else containerColor)
                .border(SelectedBorder, if (selected) scheme.primaryContainer else Color.Transparent, shape)
                .then(interaction)
                .padding(contentPadding),
            content = content,
        )
    }
}

/**
 * Tanda pilihan di kartu pilihan: lingkaran kosong, atau lingkaran kuning bercentang bila
 * terpilih. Dekoratif: status terpilih disampaikan semantik `selectable` milik kartunya.
 */
@Composable
fun SelectionMark(selected: Boolean, modifier: Modifier = Modifier) {
    val scheme = MaterialTheme.colorScheme
    Box(
        modifier = modifier
            .size(24.dp)
            .clip(CircleShape)
            .then(
                if (selected) {
                    Modifier.background(scheme.primaryContainer)
                } else {
                    Modifier.border(2.dp, scheme.outline, CircleShape)
                },
            ),
        contentAlignment = Alignment.Center,
    ) {
        if (selected) {
            Icon(
                SkyIcons.Check,
                contentDescription = null,
                tint = scheme.onPrimaryContainer,
                modifier = Modifier.size(16.dp),
            )
        }
    }
}

/** Ubin ikon 48 dp berwarna hero di kiri kartu pilihan dan baris menu. Ikonnya dekoratif. */
@Composable
fun IconTile(icon: ImageVector, modifier: Modifier = Modifier) {
    val sky = BoothTheme.sky
    Box(
        modifier = modifier
            .size(Dimens.IconTile)
            .clip(MaterialTheme.shapes.medium)
            .background(sky.hero),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, contentDescription = null, tint = sky.onHero)
    }
}

/**
 * Batang progres bersegmen (langkah checkout, tahap pesanan): [filled] dari [total] segmen terisi.
 * Dekoratif: pemanggil selalu menuliskan posisinya sebagai teks ("Langkah 1 dari 3", nama status).
 */
@Composable
fun SegmentedProgress(total: Int, filled: Int, modifier: Modifier = Modifier) {
    val sky = BoothTheme.sky
    Row(modifier = modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Spacing.xs)) {
        repeat(total) { index ->
            Box(
                modifier = Modifier
                    .weight(1f)
                    .height(SegmentHeight)
                    .clip(RoundedCornerShape(SegmentHeight / 2))
                    .background(if (index < filled) sky.progress else sky.progressTrack),
            )
        }
    }
}

/** Label bagian kecil di dalam kartu atau di atas kelompok isian ("Barang", "Pilih varian"). */
@Composable
fun SectionLabel(text: String, modifier: Modifier = Modifier) {
    Text(
        text = text,
        style = MaterialTheme.typography.titleSmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = modifier.semantics { heading() },
    )
}

private val SelectedBorder = 2.dp
private val SegmentHeight = 8.dp
