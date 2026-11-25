package com.warkop.manager.ui.products

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.ArrowDownward
import androidx.compose.material.icons.outlined.ArrowUpward
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedCard
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import com.warkop.manager.domain.Format
import com.warkop.manager.domain.RecipeForm
import com.warkop.manager.ui.components.Choice
import com.warkop.manager.ui.components.ConfirmDialog
import com.warkop.manager.ui.components.DecimalInput
import com.warkop.manager.ui.components.FormMessage
import com.warkop.manager.ui.components.IntInput
import com.warkop.manager.ui.components.LoadContent
import com.warkop.manager.ui.components.LoadingBox
import com.warkop.manager.ui.components.SelectInput
import com.warkop.manager.ui.components.TextInput
import com.warkop.manager.ui.theme.ManagerColors

@Composable
private fun Err(text: String?) { text?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) } }

/** 094 / T068 — tautan modifier, cermin `ModifierLinksDrawer.vue` + `InheritedModifierList.vue`. */
@Composable
fun ModifierLinksPane(vm: ModifierLinksViewModel, onClose: () -> Unit, onSaved: (String) -> Unit) {
    val f by vm.form.value.collectAsState()
    val loading by vm.loading.collectAsState()
    val groups by vm.groups.collectAsState()
    val s by vm.status.collectAsState()
    Column(Modifier.fillMaxSize()) {
        PaneHeader("Grup modifier", onClose)
        if (loading) { LoadingBox(); return@Column }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Muted("Isi grup (opsi dan harganya) hanya bisa diubah di layar Setup restoran. Di sini Anda menautkan grup ke item ini dan menentukan aturannya.")
            if (f.rows.isEmpty()) Muted("Belum ada grup yang tertaut ke item ini.")
            f.rows.forEachIndexed { i, r ->
                OutlinedCard(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(r.name + if (r.single) " · Pilihan tunggal" else "", fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                            IconButton(onClick = { vm.edit { it.move(i, -1) } }, enabled = i > 0) { Icon(Icons.Outlined.ArrowUpward, "Naikkan ${r.name}") }
                            IconButton(onClick = { vm.edit { it.move(i, 1) } }, enabled = i < f.rows.lastIndex) { Icon(Icons.Outlined.ArrowDownward, "Turunkan ${r.name}") }
                            IconButton(onClick = { vm.edit { it.copy(rows = it.rows - r) } }) { Icon(Icons.Outlined.Close, "Lepas tautan ${r.name}") }
                        }
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Checkbox(r.isRequired, { on -> vm.edit { it.setRequired(r.groupId, on) } }); Text("Wajib")
                            Box(Modifier.width(90.dp)) { IntInput("Min", r.min, { v -> vm.edit { it.copy(rows = it.rows.map { x -> if (x.groupId == r.groupId) x.copy(min = v) else x }) } }) }
                            Box(Modifier.width(110.dp)) { IntInput("Maks", r.max, { v -> vm.edit { it.copy(rows = it.rows.map { x -> if (x.groupId == r.groupId) x.copy(max = v) else x }) } }, helper = if (r.max.isBlank()) "Tanpa batas" else null) }
                        }
                        Err(s.error("links.$i.min_select")); Err(s.error("links.$i.max_select") ?: s.error("links.$i.group_id"))
                    }
                }
            }
            when (val g = groups) {
                null -> Text("Daftar grup gagal dimuat, jadi grup baru belum bisa ditautkan. Aturan grup yang sudah tertaut tetap bisa diubah.", color = ManagerColors.warning.fg, style = MaterialTheme.typography.bodySmall)
                else -> {
                    val avail = f.available(g)
                    if (avail.isEmpty()) Muted("Semua grup sudah tertaut ke item ini.")
                    else SelectInput("Tautkan grup", avail.map { Choice(it.id, it.name) }, null, { id -> avail.firstOrNull { it.id == id }?.let { grp -> vm.edit { it.add(grp) } } })
                }
            }
            Err(s.error("links"))
            if (f.inherited.isNotEmpty()) Section("Dari kategori") {
                Muted("Isi grup diubah di Setup restoran. Di sini Anda hanya bisa melepasnya untuk item ini; kategori dan item lain tidak berubah.")
                f.inherited.forEach { r ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) { Text(r.name); Muted(r.rule) }
                        TextButton(onClick = { vm.edit { it.release(r.groupId) } }) { Text("Lepas") }
                    }
                }
            }
            if (f.excluded.isNotEmpty()) Section("Dilepas untuk item ini") {
                Muted("Grup ini tidak ditanyakan di kasir untuk item ini. Pulihkan agar item kembali mengikuti kategori.")
                f.excluded.forEach { r ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(r.name, textDecoration = TextDecoration.LineThrough, modifier = Modifier.weight(1f))
                        TextButton(onClick = { vm.edit { it.restore(r.groupId) } }) { Text("Pulihkan") }
                    }
                }
            }
            FormMessage(s.message)
        }
        HorizontalDivider()
        Row(Modifier.fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.End) {
            Button(onClick = { vm.save(onSaved) }, enabled = !s.saving) { Text(if (s.saving) "Menyimpan…" else "Simpan modifier") }
        }
    }
}

/** 094 / T069 — daftar resep produk (cermin `RecipeSection.vue`). Tulis hanya dengan izin `menu.recipe.edit`. */
@Composable
fun RecipesPane(vm: RecipesViewModel, canEdit: Boolean, onClose: () -> Unit, open: (String) -> Unit) {
    val state by vm.list.collectAsState()
    val s by vm.status.collectAsState()
    var confirm by remember { mutableStateOf<Long?>(null) }
    androidx.compose.runtime.LaunchedEffect(Unit) { vm.load() }
    Column(Modifier.fillMaxSize()) {
        PaneHeader("Resep", onClose) { if (canEdit) TextButton(onClick = { open("new") }) { Text("+ Resep") } }
        LoadContent(state, onRetry = vm::load) { rows ->
            Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                if (rows.count { !it.archived } > 1) Muted("Semua resep aktif sebuah varian DIJUMLAHKAN untuk harga modal dan pemotongan stok.")
                if (rows.isEmpty()) Muted("Belum ada resep.")
                rows.forEach { r ->
                    OutlinedCard(Modifier.fillMaxWidth().then(if (canEdit && !r.archived) Modifier.clickable { open(r.id.toString()) } else Modifier)) {
                        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(r.name, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                                Text(if (r.archived) "Arsip" else if (r.isActiveNow) "Berlaku sekarang" else "", style = MaterialTheme.typography.labelMedium, color = if (r.archived) MaterialTheme.colorScheme.onSurfaceVariant else ManagerColors.success.fg)
                            }
                            Muted("Porsi ${Format.qty(r.yield)} · berlaku ${r.effectiveFrom ?: "—"} · ${r.blockCount} tabel bahan")
                            Muted(r.variants.joinToString { it.name })
                            r.portionCost?.let { Muted("Biaya porsi ${Format.rupiah(it)}${if (r.incomplete == true) " (sebagian)" else ""}") }
                            if (canEdit && !r.archived) Row { TextButton(onClick = { confirm = r.id }, enabled = !s.saving) { Text("Hapus resep", color = MaterialTheme.colorScheme.error) } }
                        }
                    }
                }
                FormMessage(s.message)
            }
        }
    }
    confirm?.let { id -> ConfirmDialog("Hapus resep?", "Resep ini dihapus; resep lain item ini tidak berubah.", "Hapus", onConfirm = { confirm = null; vm.delete(id) }, onDismiss = { confirm = null }, destructive = true) }
}

/** 094 / T069 — formulir resep, cermin `RecipeDrawer.vue`. HPP dihitung server, tidak di sini. */
@Composable
fun RecipeFormPane(vm: RecipeFormViewModel, onClose: () -> Unit, onSaved: () -> Unit) {
    val f by vm.form.value.collectAsState()
    val loading by vm.loading.collectAsState()
    val variants by vm.variants.collectAsState()
    val options by vm.options.collectAsState()
    val s by vm.status.collectAsState()
    val searching by vm.searchBlock.collectAsState()
    val kind by vm.searchKind.collectAsState()
    val q by vm.searchQuery.collectAsState()
    val results by vm.results.collectAsState()
    val optionChoices = options.flatMap { g -> g.options.map { Choice(it.id, "${g.name}: ${it.name}") } }
    Column(Modifier.fillMaxSize()) {
        PaneHeader(if (f.id == null) "Resep baru" else "Ubah resep", onClose)
        if (loading) { LoadingBox(); return@Column }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            TextInput("Nama resep", f.name, { v -> vm.edit { it.copy(name = v) } }, s.error("name"), maxLength = RecipeForm.NAME_MAX, helper = "Pembeda antar resep satu item, mis. Dasar, Topping standar.")
            DecimalInput("Porsi", f.yield, { v -> vm.edit { it.copy(yield = v) } }, s.error("yield"))
            TextInput("Berlaku mulai (YYYY-MM-DD)", f.effectiveFrom, { v -> vm.edit { it.copy(effectiveFrom = v.take(10)) } }, s.error("effective_from"), helper = "Paling lama ${RecipeForm.MAX_BACKDATE_DAYS} hari ke belakang.")
            TextInput("Catatan perubahan", f.note, { v -> vm.edit { it.copy(note = v) } }, s.error("note"), maxLength = RecipeForm.NOTE_MAX, singleLine = false)
            Text("Bahan per porsi", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
            Err(s.error("blocks"))
            f.blocks.forEachIndexed { i, b ->
                OutlinedCard(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text("Tabel bahan ${i + 1}", fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                            IconButton(onClick = { vm.removeBlock(b.key) }) { Icon(Icons.Outlined.Close, "Hapus tabel bahan") }
                        }
                        SelectInput("Varian", variants.map { Choice(it.id, it.variantName) }, b.variantId, { v -> vm.editBlock(b.key) { it.copy(variantId = v) } }, s.error("blocks.$i.variant_id"))
                        SelectInput("Modifier", optionChoices, b.optionId, { v -> vm.editBlock(b.key) { it.copy(optionId = v) } }, s.error("blocks.$i.modifier_option_id"), allowNone = true, noneLabel = "Tanpa modifier (resep dasar)")
                        b.lines.forEachIndexed { j, l ->
                            val p = "blocks.$i.lines.$j"
                            HorizontalDivider()
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                Column(Modifier.weight(1f)) { Text(l.name); Muted("${if (l.kind == "product") "Produk" else "Bahan"} · ${l.unit ?: "—"}") }
                                Box(Modifier.width(96.dp)) { DecimalInput("Qty", l.quantity, { v -> vm.editLine(b.key, l.key) { it.copy(quantity = v) } }) }
                                if (l.kind == "material") Box(Modifier.width(86.dp)) { DecimalInput("Susut %", l.shrinkage, { v -> vm.editLine(b.key, l.key) { it.copy(shrinkage = v) } }) }
                                IconButton(onClick = { vm.removeLine(b.key, l.key) }) { Icon(Icons.Outlined.Close, "Hapus bahan") }
                            }
                            Err(s.error("$p.quantity") ?: s.error("$p.shrinkage_pct") ?: s.error("$p.material_id") ?: s.error("$p.variant_id"))
                        }
                        Err(s.error("blocks.$i.lines"))
                        if (searching == b.key) {
                            SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
                                listOf("material" to "Bahan", "product" to "Produk").forEachIndexed { k, (key, label) ->
                                    SegmentedButton(selected = kind == key, onClick = { vm.setSearchKind(key) }, shape = SegmentedButtonDefaults.itemShape(k, 2)) { Text(label) }
                                }
                            }
                            OutlinedTextField(q, vm::setQuery, singleLine = true, leadingIcon = { Icon(Icons.Outlined.Search, null) }, placeholder = { Text("Pilih bahan") }, modifier = Modifier.fillMaxWidth())
                            results.forEach { r -> Text("${r.name} · ${r.unit ?: "—"}", modifier = Modifier.fillMaxWidth().clickable { vm.pick(r) }.padding(vertical = 8.dp)) }
                        } else OutlinedButton(onClick = { vm.startSearch(b.key) }, enabled = b.variantId != null) { Text("+ Tambah bahan") }
                    }
                }
            }
            OutlinedButton(onClick = vm::addBlock) { Text("+ Tambah tabel bahan") }
            FormMessage(s.message)
        }
        HorizontalDivider()
        Row(Modifier.fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.End) {
            Button(onClick = { vm.save(onSaved) }, enabled = !s.saving) { Text(if (s.saving) "Menyimpan…" else "Simpan resep") }
        }
    }
}
