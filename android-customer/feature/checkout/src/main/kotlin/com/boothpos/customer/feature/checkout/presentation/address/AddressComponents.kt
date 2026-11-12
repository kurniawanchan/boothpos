package com.boothpos.customer.feature.checkout.presentation.address

import androidx.compose.foundation.layout.Column
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import com.boothpos.customer.core.data.domain.model.Address
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.feature.checkout.R

/**
 * Isi satu alamat sebagai teks: label (atau nama penerima), penerima + telepon, alamat lengkap,
 * dan catatan. Dipakai daftar alamat, pilihan alamat di checkout, dan ringkasan pesanan.
 */
@Composable
internal fun AddressText(address: Address, modifier: Modifier = Modifier, showDefaultMark: Boolean = true) {
    Column(modifier = modifier) {
        val title = address.label?.takeIf { it.isNotBlank() } ?: address.recipientName
        Text(
            text = if (address.isDefault && showDefaultMark) {
                stringResource(R.string.checkout_address_title_default, title)
            } else {
                title
            },
            style = MaterialTheme.typography.titleSmall,
        )
        Text(
            text = stringResource(R.string.checkout_address_recipient, address.recipientName, address.phone),
            style = MaterialTheme.typography.bodyMedium,
        )
        Text(text = address.addressLine, style = MaterialTheme.typography.bodyMedium)
        Text(
            text = listOfNotNull(address.city, address.province, address.postalCode?.takeIf { it.isNotBlank() })
                .joinToString(", "),
            style = MaterialTheme.typography.bodyMedium,
        )
        address.notes?.takeIf { it.isNotBlank() }?.let { notes ->
            Text(
                text = stringResource(R.string.checkout_address_notes, notes),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
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
