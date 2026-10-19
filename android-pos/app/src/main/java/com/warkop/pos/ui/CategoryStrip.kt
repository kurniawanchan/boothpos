package com.warkop.pos.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.warkop.pos.model.MenuCategory

/**
 * 055 / T018 — kategori yang sedang dipilih tetap dipakai bila masih ada setelah daftar dimuat ulang; bila sudah tidak
 * ada, dipilih kategori pertama. Daftar kosong menghasilkan "" (layar menampilkan keadaan kosong).
 */
fun keepOrPickCategory(categories: List<MenuCategory>, current: String): String = when {
    categories.isEmpty() -> ""
    categories.any { it.id == current } -> current
    else -> categories.first().id
}

/**
 * 055 / T017 — satu baris kategori di atas grid, di semua ukuran layar. Digeser mendatar supaya setiap kategori dari
 * server tetap bisa dicapai (FR-003, FR-004). Nama tampil utuh, satu baris, tanpa elipsis (FR-005).
 */
@Composable
fun CategoryStrip(categories: List<MenuCategory>, selected: String, onSelect: (String) -> Unit) {
    if (categories.isEmpty()) {
        Text("Belum ada kategori", Modifier.fillMaxWidth().padding(vertical = 12.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
        return
    }
    LazyRow(Modifier.fillMaxWidth().padding(bottom = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
        items(categories, key = { it.id }) { c ->
            FilterChip(
                selected = c.id == selected,
                onClick = { onSelect(c.id) },
                label = { Text(c.name, maxLines = 1, softWrap = false) },
                shape = RoundedCornerShape(50),
            )
        }
    }
}
