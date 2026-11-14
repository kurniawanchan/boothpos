package com.boothpos.customer.feature.auth.presentation

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.repeatOnLifecycle
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.Spacing
import kotlinx.coroutines.flow.Flow

/**
 * Kerangka formulir satu kolom: isian ([content]) di atas, aksi ([actions]) di dasar layar seperti
 * di desain selama semuanya muat. Bila tidak muat (layar kecil, teks besar, papan ketik terbuka)
 * seluruhnya digulir, sehingga tombol utama tetap terjangkau. Aksi sengaja ikut digulir, bukan
 * ditempel di luar gulungan: dengan huruf 200 % tombol dan tautannya saja sudah memakan sebagian
 * besar layar, dan kolom isian harus tetap terlihat.
 */
@Composable
internal fun AuthFormColumn(
    modifier: Modifier = Modifier,
    actions: @Composable ColumnScope.() -> Unit,
    content: @Composable ColumnScope.() -> Unit,
) {
    BoxWithConstraints(
        modifier = modifier
            .fillMaxSize()
            .imePadding(),
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                // Setinggi layar bila isinya lebih pendek: sisa ruangnya jatuh di antara isian dan aksi.
                .heightIn(min = maxHeight),
            verticalArrangement = Arrangement.SpaceBetween,
        ) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(start = Spacing.lg, top = Spacing.sm, end = Spacing.lg),
                verticalArrangement = Arrangement.spacedBy(Spacing.sm),
                content = content,
            )
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    // Jarak atas = jarak minimum dari isian saat layar penuh dan semuanya digulir.
                    .padding(start = Spacing.lg, top = Spacing.lg, end = Spacing.lg, bottom = Spacing.md),
                verticalArrangement = Arrangement.spacedBy(Spacing.sm),
                content = actions,
            )
        }
    }
}

/** Tautan teks pendamping (pindah formulir, lanjut tanpa akun) dengan target sentuh 48dp. */
@Composable
internal fun AuthTextLink(text: String, onClick: () -> Unit, modifier: Modifier = Modifier, enabled: Boolean = true) {
    TextButton(
        onClick = onClick,
        enabled = enabled,
        modifier = modifier
            .fillMaxWidth()
            .heightIn(min = Dimens.MinTouchTarget),
    ) {
        // Rata tengah juga saat teksnya turun baris (huruf besar di ponsel sempit).
        Text(text, textAlign = TextAlign.Center)
    }
}

/** Mengumpulkan kejadian sekali-jalan hanya saat layar tampil (STARTED), supaya navigasi tidak terjadi di latar. */
@Composable
internal fun CollectAuthEvents(events: Flow<AuthEvent>, onAuthenticated: () -> Unit) {
    val currentOnAuthenticated by rememberUpdatedState(onAuthenticated)
    val lifecycleOwner = LocalLifecycleOwner.current
    LaunchedEffect(events, lifecycleOwner) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
            events.collect { event ->
                when (event) {
                    AuthEvent.Authenticated -> currentOnAuthenticated()
                }
            }
        }
    }
}
