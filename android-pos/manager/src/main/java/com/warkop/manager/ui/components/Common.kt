package com.warkop.manager.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.ExperimentalMaterialApi
import androidx.compose.material.pullrefresh.PullRefreshIndicator
import androidx.compose.material.pullrefresh.pullRefresh
import androidx.compose.material.pullrefresh.rememberPullRefreshState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CloudOff
import androidx.compose.material.icons.outlined.ErrorOutline
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.warkop.manager.ui.theme.ManagerColors

/** Keadaan muat standar sebuah layar (FR-030): memuat, galat, atau berisi. */
sealed interface Load<out T> {
    data object Loading : Load<Nothing>
    data class Failed(val message: String) : Load<Nothing>
    data class Ready<T>(val data: T, val refreshing: Boolean = false) : Load<T>
}

@Composable
fun LoadingBox(modifier: Modifier = Modifier) {
    Box(modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
}

@Composable
fun MessageBox(title: String, body: String? = null, error: Boolean = false, actionLabel: String? = null, onAction: (() -> Unit)? = null) {
    Box(Modifier.fillMaxSize().padding(24.dp), contentAlignment = Alignment.Center) {
        Column(Modifier.widthIn(max = 420.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Icon(if (error) Icons.Outlined.ErrorOutline else Icons.Outlined.Inbox, null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(title, style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
            body?.let { Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, textAlign = TextAlign.Center) }
            if (actionLabel != null && onAction != null) OutlinedButton(onClick = onAction) { Text(actionLabel) }
        }
    }
}

/** Isi layar berdasarkan [Load], dengan tarik-untuk-segarkan (material3 1.3.0 belum punya PullToRefreshBox). */
@OptIn(ExperimentalMaterialApi::class)
@Composable
fun <T> LoadContent(state: Load<T>, onRetry: () -> Unit, modifier: Modifier = Modifier, content: @Composable (T) -> Unit) {
    when (state) {
        Load.Loading -> LoadingBox(modifier)
        is Load.Failed -> MessageBox("Gagal memuat", state.message, error = true, actionLabel = "Coba lagi", onAction = onRetry)
        is Load.Ready -> {
            val pull = rememberPullRefreshState(state.refreshing, onRetry)
            Box(modifier.fillMaxSize().pullRefresh(pull)) {
                content(state.data)
                PullRefreshIndicator(state.refreshing, pull, Modifier.align(Alignment.TopCenter))
            }
        }
    }
}

/** Pita mode data (FR-033): DEMO selalu mencolok supaya tidak tertukar dengan data sungguhan. */
@Composable
fun ModeBadge(demo: Boolean) {
    val c = if (demo) ManagerColors.warning else ManagerColors.success
    Text(
        if (demo) "DEMO" else "LIVE",
        modifier = Modifier.background(c.bg, RoundedCornerShape(6.dp)).padding(horizontal = 8.dp, vertical = 2.dp),
        color = c.fg,
        style = MaterialTheme.typography.labelMedium,
        fontWeight = FontWeight.Bold,
    )
}

/** Pita "Luring" (FR-031): data terakhir tetap tampil sebagai baca-saja, simpan diblokir. */
@Composable
fun OfflineBanner() {
    val c = ManagerColors.danger
    Row(
        Modifier.fillMaxWidth().background(c.bg).padding(horizontal = 16.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Icon(Icons.Outlined.CloudOff, null, tint = c.fg)
        Text("Luring — data terakhir ditampilkan, perubahan tidak bisa disimpan.", color = c.fg, style = MaterialTheme.typography.bodySmall)
    }
}

/** Galat umum (bukan per kolom) di atas tombol simpan. */
@Composable
fun FormMessage(message: String?) {
    if (message == null) return
    Text(message, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(vertical = 4.dp))
}
