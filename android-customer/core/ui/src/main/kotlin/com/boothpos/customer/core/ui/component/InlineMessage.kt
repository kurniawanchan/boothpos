package com.boothpos.customer.core.ui.component

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.boothpos.customer.core.ui.icon.SkyIcons
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.Spacing

enum class InlineMessageTone { Info, Error }

/**
 * Pesan tingkat formulir/layar di dalam konten (bukan snackbar yang hilang sendiri): mis. "e-mail
 * atau kata sandi salah", "sesi berakhir", atau gagal menyegarkan data. Maknanya dibawa ikon +
 * teks, bukan warna saja, dan diumumkan pembaca layar saat muncul.
 */
@Composable
fun InlineMessage(
    text: String,
    modifier: Modifier = Modifier,
    tone: InlineMessageTone = InlineMessageTone.Info,
    actionLabel: String? = null,
    onAction: (() -> Unit)? = null,
) {
    val error = tone == InlineMessageTone.Error
    Surface(
        color = if (error) MaterialTheme.colorScheme.errorContainer else MaterialTheme.colorScheme.surfaceVariant,
        contentColor = if (error) MaterialTheme.colorScheme.onErrorContainer else MaterialTheme.colorScheme.onSurfaceVariant,
        shape = MaterialTheme.shapes.small,
        modifier = modifier.fillMaxWidth(),
    ) {
        Column(modifier = Modifier.padding(horizontal = Spacing.ms, vertical = Spacing.sm)) {
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier.semantics(mergeDescendants = true) { liveRegion = LiveRegionMode.Polite },
            ) {
                // Dekoratif: teks di sebelahnya sudah menyampaikan maknanya.
                Icon(
                    imageVector = if (error) SkyIcons.Alert else SkyIcons.Info,
                    contentDescription = null,
                    modifier = Modifier.size(20.dp),
                )
                Spacer(Modifier.width(Spacing.sm))
                Text(text, style = MaterialTheme.typography.bodyMedium)
            }
            if (actionLabel != null && onAction != null) {
                TextButton(
                    onClick = onAction,
                    colors = ButtonDefaults.textButtonColors(contentColor = LocalContentColor.current),
                    modifier = Modifier
                        .align(Alignment.End)
                        .heightIn(min = Dimens.MinTouchTarget),
                ) {
                    Text(actionLabel)
                }
            }
        }
    }
}
