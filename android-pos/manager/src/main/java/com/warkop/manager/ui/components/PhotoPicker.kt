package com.warkop.manager.ui.components

import android.content.Context
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.PhotoCamera
import androidx.compose.material.icons.outlined.PhotoLibrary
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import java.io.File

/**
 * 094 / D11 — pilih foto: galeri lewat Photo Picker (tanpa izin penyimpanan) atau kamera lewat aplikasi kamera sistem
 * (`TakePicture`, tanpa izin CAMERA karena kameranya aplikasi lain). Foto kamera ditulis ke `cache/camera/` lewat
 * FileProvider. Foto baru diunggah saat simpan; [current] = sudah ada foto di server.
 */
@Composable
fun PhotoPicker(selected: Uri?, hasCurrent: Boolean, onPicked: (Uri?) -> Unit, error: String? = null) {
    val context = LocalContext.current
    var pending by rememberSaveableUri()
    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri -> if (uri != null) onPicked(uri) }
    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok -> if (ok) onPicked(pending) }
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text("Foto", style = MaterialTheme.typography.labelLarge)
        Text(
            when {
                selected != null -> "Foto baru dipilih — diunggah saat disimpan."
                hasCurrent -> "Sudah ada foto. Pilih foto lain untuk mengganti."
                else -> "Belum ada foto. JPG/PNG, maksimal 5 MB."
            },
            style = MaterialTheme.typography.bodySmall,
            color = if (error != null) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant,
        )
        error?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.error) }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(onClick = { gallery.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }) {
                Icon(Icons.Outlined.PhotoLibrary, null); Text(" Galeri")
            }
            OutlinedButton(onClick = { val uri = newCameraUri(context); pending = uri; camera.launch(uri) }) {
                Icon(Icons.Outlined.PhotoCamera, null); Text(" Kamera")
            }
            if (selected != null) TextButton(onClick = { onPicked(null) }) { Text("Batalkan") }
        }
    }
}

@Composable
private fun rememberSaveableUri() = rememberSaveable(stateSaver = androidx.compose.runtime.saveable.Saver<Uri?, String>(
    save = { it?.toString() ?: "" },
    restore = { s -> s.takeIf { it.isNotEmpty() }?.let(Uri::parse) },
)) { androidx.compose.runtime.mutableStateOf<Uri?>(null) }

private fun newCameraUri(context: Context): Uri {
    val dir = File(context.cacheDir, "camera").apply { mkdirs() }
    val file = File(dir, "photo-${System.currentTimeMillis()}.jpg")
    return FileProvider.getUriForFile(context, "${context.packageName}.files", file)
}
