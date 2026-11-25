package com.warkop.manager.ui.categories

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.Button
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.unit.dp
import androidx.lifecycle.createSavedStateHandle
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.ImageUpload
import com.warkop.manager.data.InventoryRepository
import com.warkop.manager.data.dto.CategoryDto
import com.warkop.manager.domain.CategoryForm
import com.warkop.manager.ui.components.Choice
import com.warkop.manager.ui.components.ConfirmDialog
import com.warkop.manager.ui.components.FormColumn
import com.warkop.manager.ui.components.FormMessage
import com.warkop.manager.ui.components.IntInput
import com.warkop.manager.ui.components.ListDetail
import com.warkop.manager.ui.components.LoadingBox
import com.warkop.manager.ui.components.PagedListContent
import com.warkop.manager.ui.components.PhotoPicker
import com.warkop.manager.ui.components.SelectInput
import com.warkop.manager.ui.components.SwitchRow
import com.warkop.manager.ui.components.TextInput
import com.warkop.manager.ui.managerViewModel

/** 094 / T057 — Kategori: daftar + formulir (dua panel di layar lebar). Saring "Hanya aktif" tidak ada di web, jadi tidak ada. */
@Composable
fun CategoriesScreen(onSessionError: (ApiError) -> Boolean) {
    val list = managerViewModel { c -> CategoriesViewModel(InventoryRepository(c::api), createSavedStateHandle(), onSessionError) }
    ListDetail(
        list = { open -> CategoryList(list, open) },
        detail = { key, close, open ->
            val id = key.toLongOrNull()
            val vm = managerViewModel(key = "category-$key") { c -> CategoryFormViewModel(InventoryRepository(c::api), createSavedStateHandle(), id, onSessionError) }
            CategoryFormPane(vm, onClose = close, onSaved = { saved -> list.reload(); if (id == null) open(saved.toString()) }, onDeactivated = { list.reload(); close() })
        },
    )
}

@Composable
private fun CategoryList(vm: CategoriesViewModel, open: (String) -> Unit) {
    val q by vm.search.collectAsState()
    val state by vm.list.collectAsState()
    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            OutlinedTextField(
                value = q, onValueChange = vm::setSearch, singleLine = true,
                leadingIcon = { Icon(Icons.Outlined.Search, null) }, placeholder = { Text("Cari nama kategori") },
                modifier = Modifier.fillMaxWidth().padding(16.dp, 8.dp),
            )
            PagedListContent(state, onRefresh = vm::reload, onEnd = vm::loadMore, empty = "Belum ada kategori.") { rows ->
                items(rows, key = { it.id }) { c -> CategoryRow(c) { open(c.id.toString()) }; HorizontalDivider() }
            }
        }
        ExtendedFloatingActionButton(onClick = { open("new") }, icon = { Icon(Icons.Outlined.Add, null) }, text = { Text("Kategori") }, modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp))
    }
}

@Composable
private fun CategoryRow(c: CategoryDto, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(c.code, fontWeight = FontWeight.Bold, modifier = Modifier.padding(end = 12.dp))
        Column(Modifier.weight(1f)) {
            Text(c.name, style = MaterialTheme.typography.bodyLarge)
            Text(listOfNotNull(c.productCount?.let { "$it produk" }, if (c.isActive) null else "Nonaktif").joinToString(" · ").ifEmpty { " " }, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun CategoryFormPane(vm: CategoryFormViewModel, onClose: () -> Unit, onSaved: (Long) -> Unit, onDeactivated: () -> Unit) {
    val f by vm.form.value.collectAsState()
    val status by vm.status.collectAsState()
    val loading by vm.loading.collectAsState()
    val parents by vm.parents.collectAsState()
    val stations by vm.stations.collectAsState()
    val photo by vm.photo.collectAsState()
    val resolver = LocalContext.current.contentResolver
    var confirm by remember { mutableStateOf(false) }

    Column(Modifier.fillMaxSize()) {
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(4.dp)) {
            IconButton(onClick = onClose) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, "Kembali") }
            Text(if (f.editing) "Ubah kategori" else "Kategori baru", style = MaterialTheme.typography.titleLarge)
        }
        if (loading) { LoadingBox(); return@Column }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp)) {
            FormColumn {
                TextInput(
                    "Kode", f.code, { v -> vm.edit { it.copy(code = CategoryForm.cleanCode(v)) } }, status.error("code"),
                    enabled = !f.editing, caps = KeyboardCapitalization.Characters,
                    helper = if (f.editing) "Kode permanen, tidak bisa diubah." else "2 huruf, dipakai di kode produk. Tidak bisa diubah setelah disimpan.",
                )
                TextInput("Nama", f.name, { v -> vm.edit { it.copy(name = v) } }, status.error("name"), maxLength = CategoryForm.NAME_MAX)
                SelectInput("Induk", parents.map { Choice(it.id, it.name) }, f.parentId, { v -> vm.edit { it.copy(parentId = v) } }, status.error("parent_id"), allowNone = true)
                SelectInput("Stasiun", stations.map { Choice(it.id, it.name) }, f.stationId, { v -> vm.edit { it.copy(stationId = v) } }, status.error("station_id"), allowNone = true)
                IntInput("Urutan tampil", f.displayOrder, { v -> vm.edit { it.copy(displayOrder = v) } }, status.error("display_order"), signed = true)
                SwitchRow("Aktif", f.isActive, { v -> vm.edit { it.copy(isActive = v) } })
                PhotoPicker(photo, hasCurrent = f.imageUrl != null, onPicked = vm::setPhoto, error = status.error("image"))
                FormMessage(status.message)
            }
        }
        HorizontalDivider()
        Row(Modifier.fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            if (f.editing && f.isActive) TextButton(onClick = { confirm = true }, enabled = !status.saving) { Text("Nonaktifkan", color = MaterialTheme.colorScheme.error) }
            Box(Modifier.weight(1f))
            Button(onClick = { vm.save({ uri -> ImageUpload.part(resolver, uri) }) { _ -> vm.form.current.id?.let(onSaved) } }, enabled = !status.saving) { Text(if (status.saving) "Menyimpan…" else "Simpan") }
        }
    }
    if (confirm) ConfirmDialog(
        title = "Nonaktifkan kategori?",
        body = "\"${f.name}\" tidak akan tampil lagi. Kategori yang masih punya sub-kategori atau produk aktif tidak bisa dinonaktifkan.",
        confirmLabel = "Nonaktifkan", destructive = true,
        onConfirm = { confirm = false; vm.deactivate(onDeactivated) }, onDismiss = { confirm = false },
    )
}
