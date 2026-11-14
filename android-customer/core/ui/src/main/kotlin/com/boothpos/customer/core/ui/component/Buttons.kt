package com.boothpos.customer.core.ui.component

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.IconButtonDefaults
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.painter.Painter
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.graphics.vector.rememberVectorPainter
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.boothpos.customer.core.ui.R
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.PillShape
import com.boothpos.customer.core.ui.theme.SoftShadow
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.core.ui.theme.softShadow

/**
 * Aksi utama layar (satu per layar): kuning, membulat penuh, tinggi 56 dp. [loading] menonaktifkan
 * tombol selama permintaan berjalan, sehingga ketukan ganda tidak mengirim dua kali; pembaca layar
 * mendengar "Sedang diproses". [icon] dekoratif (teksnya sudah menyebut aksinya).
 */
@Composable
fun PrimaryButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    loading: Boolean = false,
    icon: ImageVector? = null,
) {
    val busy = stringResource(R.string.core_ui_in_progress)
    val active = enabled && !loading
    val scheme = MaterialTheme.colorScheme
    Button(
        onClick = onClick,
        enabled = active,
        shape = PillShape,
        colors = ButtonDefaults.buttonColors(
            containerColor = scheme.primaryContainer,
            contentColor = scheme.onPrimaryContainer,
        ),
        modifier = modifier
            .heightIn(min = Dimens.ButtonHeight)
            // Pendar hanya untuk tombol yang dapat ditekan; tombol nonaktif tampil datar.
            .then(if (active) Modifier.softShadow(PillShape, BoothTheme.sky.actionGlow, SoftShadow.Glow) else Modifier)
            .semantics { if (loading) stateDescription = busy },
    ) {
        if (loading) {
            CircularProgressIndicator(
                modifier = Modifier.size(18.dp),
                strokeWidth = 2.dp,
                color = LocalContentColor.current,
            )
            Spacer(Modifier.width(Spacing.sm))
        } else if (icon != null) {
            Icon(icon, contentDescription = null, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(Spacing.sm))
        }
        Text(text, textAlign = TextAlign.Center)
    }
}

/** Aksi pendamping (mis. "Coba lagi", "Batal", "Daftar"): isi tinta tipis, membulat penuh. */
@Composable
fun SecondaryButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    icon: ImageVector? = null,
) {
    val scheme = MaterialTheme.colorScheme
    Button(
        onClick = onClick,
        enabled = enabled,
        shape = PillShape,
        colors = ButtonDefaults.buttonColors(
            containerColor = BoothTheme.sky.subtle,
            contentColor = scheme.onSurface,
        ),
        modifier = modifier.heightIn(min = Dimens.MinTouchTarget),
    ) {
        if (icon != null) {
            Icon(icon, contentDescription = null, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(Spacing.sm))
        }
        Text(text, textAlign = TextAlign.Center)
    }
}

/**
 * Tombol ikon bulat putih berbayangan (kembali, urutkan, muat ulang). Bawaan digambar 44 dp; area
 * sentuhnya tetap 48 dp (jaminan `IconButton` Material 3). [size] 48 dp untuk tempat yang
 * mensyaratkan NODE-nya sendiri 48 dp.
 */
@Composable
fun RoundIconButton(
    icon: ImageVector,
    contentDescription: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    size: Dp = Dimens.RoundButton,
) {
    RoundIconButton(
        painter = rememberVectorPainter(icon),
        contentDescription = contentDescription,
        onClick = onClick,
        modifier = modifier,
        enabled = enabled,
        size = size,
    )
}

/** Sama, untuk ikon dari vector drawable modul fitur. */
@Composable
fun RoundIconButton(
    painter: Painter,
    contentDescription: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    size: Dp = Dimens.RoundButton,
) {
    val scheme = MaterialTheme.colorScheme
    IconButton(
        onClick = onClick,
        enabled = enabled,
        colors = IconButtonDefaults.iconButtonColors(
            containerColor = scheme.surfaceContainerLow,
            contentColor = scheme.onSurface,
            disabledContainerColor = scheme.surfaceContainerLow,
        ),
        modifier = modifier
            .size(size)
            .softShadow(PillShape, BoothTheme.sky.shadow),
    ) {
        Icon(painter, contentDescription = contentDescription)
    }
}

/**
 * Tombol pengatur jumlah (kurang/tambah). Node-nya 48 dp (target sentuh), lingkarannya digambar
 * 44 dp: putih bergaris tepi untuk "kurang", kuning lembut bergaris kuning untuk [emphasized]
 * ("tambah"). Di batas jumlah tombol dinonaktifkan dan memudar, tidak disembunyikan.
 */
@Composable
fun StepperButton(
    painter: Painter,
    contentDescription: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    emphasized: Boolean = false,
) {
    val scheme = MaterialTheme.colorScheme
    IconButton(onClick = onClick, enabled = enabled, modifier = modifier.size(Dimens.MinTouchTarget)) {
        Box(
            modifier = Modifier
                .size(Dimens.RoundButton)
                .alpha(if (enabled) 1f else DISABLED_ALPHA)
                .clip(PillShape)
                .background(if (emphasized) scheme.secondaryContainer else scheme.surfaceContainerLow)
                .border(2.dp, if (emphasized) scheme.primaryContainer else scheme.outlineVariant, PillShape),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                painter = painter,
                contentDescription = contentDescription,
                tint = if (emphasized) scheme.onSecondaryContainer else scheme.onSurface,
                modifier = Modifier.size(20.dp),
            )
        }
    }
}

private const val DISABLED_ALPHA = 0.38f
