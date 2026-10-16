package com.warkop.pos.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CloudOff
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.core.domain.ApiError

/** Pesan ramah untuk satu kegagalan memuat (T039). Skema server usang → "Server perlu diperbarui". */
fun friendlyLoadError(e: ApiError): String = when (e) {
    is ApiError.SchemaOutdated -> "Server perlu diperbarui"
    is ApiError.Network -> "Tidak dapat menjangkau server. Periksa koneksi lalu coba lagi."
    else -> e.message.ifBlank { "Gagal memuat data. Coba lagi." }
}

/** Kegagalan jaringan = offline; galat server lain bukan offline. */
fun isOfflineError(e: ApiError): Boolean = e is ApiError.Network

/**
 * Keadaan GAGAL memuat (T039): pesan ramah, indikator offline bila relevan, tombol Coba lagi, dan — bila aksi tulis
 * dinonaktifkan — alasannya. Tidak pernah ditampilkan sebagai daftar kosong, dan tidak memakai toast.
 */
@Composable
fun ErrorState(
    message: String,
    onRetry: () -> Unit,
    modifier: Modifier = Modifier,
    offline: Boolean = false,
    writeDisabledReason: String? = null,
) {
    Surface(modifier, shape = RoundedCornerShape(20.dp), color = MaterialTheme.colorScheme.errorContainer, contentColor = MaterialTheme.colorScheme.onErrorContainer) {
        Column(Modifier.padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Icon(Icons.Filled.CloudOff, null, Modifier.size(32.dp))
            Text(message, Modifier.padding(vertical = 10.dp), fontSize = 16.sp, fontWeight = FontWeight.Medium)
            if (offline) Text("Offline — data di layar ini mungkin belum terbaru.", fontSize = 14.sp)
            if (writeDisabledReason != null) Text(writeDisabledReason, Modifier.padding(top = 6.dp), fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
            Button(onClick = onRetry, Modifier.padding(top = 8.dp)) { Text("Coba lagi") }
        }
    }
}
