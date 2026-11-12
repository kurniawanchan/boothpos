package com.boothpos.customer.feature.auth.presentation

import androidx.compose.foundation.layout.Arrangement
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
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.repeatOnLifecycle
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.Spacing
import kotlinx.coroutines.flow.Flow

/**
 * Kerangka formulir satu kolom: dapat digulir (layar kecil, teks besar) dan terdorong naik oleh
 * papan ketik, sehingga tombol utama tetap terjangkau.
 */
@Composable
internal fun AuthFormColumn(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .imePadding()
            .verticalScroll(rememberScrollState())
            .padding(Spacing.md),
        verticalArrangement = Arrangement.spacedBy(Spacing.md),
        content = content,
    )
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
        Text(text)
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
