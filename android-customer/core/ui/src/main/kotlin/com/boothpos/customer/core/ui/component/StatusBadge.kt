package com.boothpos.customer.core.ui.component

import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.boothpos.customer.core.ui.theme.BoothTheme
import com.boothpos.customer.core.ui.theme.Spacing

enum class StatusTone { Neutral, Info, Success, Warning, Danger }

/**
 * Pasangan warna (latar, isi) untuk satu nada status. Satu definisi untuk lencana kecil dan untuk
 * permukaan status yang lebih besar (mis. kepala detail pesanan), supaya keduanya selalu serasi.
 */
@Composable
fun statusToneColors(tone: StatusTone): Pair<Color, Color> {
    val scheme = MaterialTheme.colorScheme
    val status = BoothTheme.statusColors
    return when (tone) {
        StatusTone.Neutral -> scheme.surfaceVariant to scheme.onSurfaceVariant
        StatusTone.Info -> scheme.secondaryContainer to scheme.onSecondaryContainer
        StatusTone.Success -> status.successContainer to status.onSuccessContainer
        StatusTone.Warning -> status.warningContainer to status.onWarningContainer
        StatusTone.Danger -> scheme.errorContainer to scheme.onErrorContainer
    }
}

/**
 * Status selalu tampil sebagai IKON + TEKS; warna hanya penguat, tidak pernah satu-satunya
 * pembawa makna. Waktu kejadian ditampilkan pemanggil di sebelahnya.
 */
@Composable
fun StatusBadge(
    text: String,
    icon: ImageVector,
    modifier: Modifier = Modifier,
    tone: StatusTone = StatusTone.Neutral,
) {
    val (container, content) = statusToneColors(tone)
    Surface(
        color = container,
        contentColor = content,
        shape = MaterialTheme.shapes.small,
        modifier = modifier.semantics(mergeDescendants = true) {},
    ) {
        Row(
            modifier = Modifier.padding(horizontal = Spacing.sm, vertical = Spacing.xs),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            // Dekoratif bagi pembaca layar: teks di sebelahnya menyebut statusnya.
            Icon(icon, contentDescription = null, modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(Spacing.xs))
            Text(text, style = MaterialTheme.typography.labelLarge)
        }
    }
}
