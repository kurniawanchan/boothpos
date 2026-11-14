package com.boothpos.customer.feature.checkout.presentation.address

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.unit.dp
import com.boothpos.customer.core.data.domain.model.Address
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.checkout.R

/**
 * Isi satu alamat sebagai teks: label (atau nama penerima), penerima + telepon, alamat lengkap,
 * dan catatan. Dipakai daftar alamat, pilihan alamat di checkout, dan ringkasan pesanan.
 *
 * Setiap bagian tetap satu baris teks sendiri (tidak digabung menjadi satu kalimat), supaya alamat
 * panjang membungkus per bagian dan pembaca layar membacanya berurutan.
 *
 * [titleStyle]: di ringkasan pesanan alamat hanyalah keterangan di bawah cara menerima pesanan,
 * jadi judulnya dikecilkan pemanggil.
 */
@Composable
internal fun AddressText(
    address: Address,
    modifier: Modifier = Modifier,
    showDefaultMark: Boolean = true,
    titleStyle: TextStyle = MaterialTheme.typography.titleMedium,
) {
    val muted = MaterialTheme.colorScheme.onSurfaceVariant
    Column(modifier = modifier) {
        val title = address.label?.takeIf { it.isNotBlank() } ?: address.recipientName
        Text(
            text = if (address.isDefault && showDefaultMark) {
                stringResource(R.string.checkout_address_title_default, title)
            } else {
                title
            },
            style = titleStyle,
        )
        Text(
            text = stringResource(R.string.checkout_address_recipient, address.recipientName, address.phone),
            style = MaterialTheme.typography.bodySmall,
            modifier = Modifier.padding(top = Spacing.xs),
        )
        Text(text = address.addressLine, style = MaterialTheme.typography.bodySmall, color = muted)
        Text(
            text = listOfNotNull(address.city, address.province, address.postalCode?.takeIf { it.isNotBlank() })
                .joinToString(", "),
            style = MaterialTheme.typography.bodySmall,
            color = muted,
        )
        address.notes?.takeIf { it.isNotBlank() }?.let { notes ->
            Text(
                text = stringResource(R.string.checkout_address_notes, notes),
                style = MaterialTheme.typography.bodySmall,
                color = muted,
            )
        }
    }
}

/**
 * Tautan teks di dalam kartu atau di kepala bagian ("Ubah", "Hapus", "Tambah alamat"). Bantalan
 * mendatarnya lebih rapat daripada tombol teks bawaan supaya isinya hampir sejajar dengan teks
 * kartu; tinggi sentuhnya tetap 48 dp. [icon] dekoratif: teksnya sudah menyebut aksinya.
 *
 * [contentColor] diisi pemanggil bila tautan berada di atas isi kartu terpilih (biru tautan di atas
 * kuning lembut tidak lolos kontras AA) atau untuk aksi yang menghapus.
 */
@Composable
internal fun CardLink(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    icon: ImageVector? = null,
    contentColor: Color = MaterialTheme.colorScheme.primary,
    textStyle: TextStyle = MaterialTheme.typography.labelLarge,
) {
    TextButton(
        onClick = onClick,
        enabled = enabled,
        colors = ButtonDefaults.textButtonColors(contentColor = contentColor),
        contentPadding = LinkContentPadding,
        modifier = modifier.heightIn(min = Dimens.MinTouchTarget),
    ) {
        if (icon != null) {
            Icon(icon, contentDescription = null, modifier = Modifier.size(LinkIconSize))
            Spacer(Modifier.width(Spacing.xs))
        }
        Text(text, style = textStyle)
    }
}

/** Teks galat untuk pemeriksaan bentuk di perangkat; [fieldLabel] adalah nama kolomnya. */
@Composable
internal fun fieldIssueText(issue: FieldIssue, fieldLabel: String): String = when (issue) {
    FieldIssue.Required -> stringResource(R.string.checkout_error_required, fieldLabel)
    is FieldIssue.TooLong -> pluralStringResource(R.plurals.checkout_error_too_long, issue.max, fieldLabel, issue.max)
    // Kolom checkout dan alamat tidak punya aturan format atau panjang minimum (kontrak §7, §8).
    FieldIssue.InvalidFormat, is FieldIssue.TooShort -> stringResource(R.string.checkout_error_invalid, fieldLabel)
}

private val LinkContentPadding = PaddingValues(horizontal = Spacing.sm, vertical = Spacing.sm)
private val LinkIconSize = 20.dp
