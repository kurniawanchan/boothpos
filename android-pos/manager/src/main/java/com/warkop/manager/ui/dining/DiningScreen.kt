package com.warkop.manager.ui.dining

import android.content.Intent
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
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material3.Checkbox
import androidx.compose.material3.ExtendedFloatingActionButton
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
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
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
import androidx.compose.ui.unit.dp
import androidx.lifecycle.createSavedStateHandle
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.RestaurantRepository
import com.warkop.manager.data.dto.DiningTableDto
import com.warkop.manager.data.dto.ModifierGroupDto
import com.warkop.manager.data.dto.StationDto
import com.warkop.manager.domain.Format
import com.warkop.manager.domain.ModifierGroupForm
import com.warkop.manager.domain.OptionRow
import com.warkop.manager.domain.attachSummary
import com.warkop.manager.ui.components.Choice
import com.warkop.manager.ui.components.ConfirmDialog
import com.warkop.manager.ui.components.DecimalInput
import com.warkop.manager.ui.components.FormMessage
import com.warkop.manager.ui.components.IntInput
import com.warkop.manager.ui.components.ListDetail
import com.warkop.manager.ui.components.Load
import com.warkop.manager.ui.components.LoadContent
import com.warkop.manager.ui.components.LoadingBox
import com.warkop.manager.ui.components.MessageBox
import com.warkop.manager.ui.components.PagedListContent
import com.warkop.manager.ui.components.SelectInput
import com.warkop.manager.ui.components.SwitchRow
import com.warkop.manager.ui.components.TextInput
import com.warkop.manager.ui.container
import com.warkop.manager.ui.managerViewModel
import com.warkop.manager.ui.products.Muted
import com.warkop.manager.ui.products.PaneHeader
import com.warkop.manager.ui.products.Section
import com.warkop.manager.ui.purchase.FooterSave
import com.warkop.manager.ui.purchase.SearchField
import com.warkop.manager.ui.theme.ManagerColors

/**
 * 094 / T090–T092 — Pengaturan Restoran (Meja, Stasiun, Modifier). Kunci detail: "table/new|<id>", "station/new|<id>",
 * "group/new|<id>", "attach/<id>". QR massal sengaja tidak ada (⏸ research).
 */
@Composable
fun DiningScreen(onSessionError: (ApiError) -> Boolean) {
    val outlet = container().session.selectedOutlet() ?: run { MessageBox("Pilih outlet dulu"); return }
    val repoOf = { c: com.warkop.manager.AppContainer -> RestaurantRepository(c::api) }
    val vm = managerViewModel { c -> DiningViewModel(repoOf(c), createSavedStateHandle(), outlet.id, onSessionError) }
    val tables by vm.tables.collectAsState(); val stations by vm.stations.collectAsState(); val groups by vm.groups.collectAsState()
    fun <T> ready(l: Load<*>): List<T> = @Suppress("UNCHECKED_CAST") when (l) { is Load.Ready<*> -> (l.data as? com.warkop.manager.ui.components.Pager.State<T>)?.items ?: (l.data as? List<T>) ?: emptyList(); else -> emptyList() }
    ListDetail(
        list = { open -> DiningList(vm, open) },
        detail = { key, close, _ ->
            val (kind, id) = key.split("/").let { it[0] to it[1].toLongOrNull() }
            when (kind) {
                "table" -> TablePane(managerViewModel(key = "table-$key") { c -> TableFormViewModel(repoOf(c), createSavedStateHandle(), ready<DiningTableDto>(tables).firstOrNull { it.id == id }, outlet.id, onSessionError) }, close) { vm.reloadTables(); close() }
                "station" -> StationPane(managerViewModel(key = "station-$key") { c -> StationFormViewModel(repoOf(c), createSavedStateHandle(), ready<StationDto>(stations).firstOrNull { it.id == id }, outlet.id, ready<StationDto>(stations).size, onSessionError) }, close) { vm.loadStations(); close() }
                "group" -> GroupPane(managerViewModel(key = "group-$key") { c -> GroupFormViewModel(repoOf(c), createSavedStateHandle(), ready<ModifierGroupDto>(groups).firstOrNull { it.id == id }, onSessionError) }, close) { vm.loadGroups(); close() }
                "attach" -> ready<ModifierGroupDto>(groups).firstOrNull { it.id == id }?.let { g ->
                    AttachPane(managerViewModel(key = "attach-$id") { c -> AttachViewModel(repoOf(c), g, onSessionError) }, g.name, close) { vm.loadGroups(); close() }
                } ?: MessageBox("Grup tidak ditemukan")
                else -> MessageBox("Tidak ditemukan")
            }
        },
    )
}

@Composable
private fun DiningList(vm: DiningViewModel, open: (String) -> Unit) {
    val tab by vm.tab.collectAsState()
    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            TabRow(selectedTabIndex = tab.ordinal) { DiningTab.entries.forEach { t -> Tab(selected = t == tab, onClick = { vm.setTab(t) }, text = { Text(t.label) }) } }
            when (tab) {
                DiningTab.TABLES -> {
                    val q by vm.q.collectAsState(); val state by vm.tables.collectAsState()
                    SearchField(q, vm::setQuery, "Nama atau area meja…")
                    PagedListContent(state, vm::reloadTables, vm::moreTables, "Belum ada meja.") { rows ->
                        items(rows, key = { it.id }) { t ->
                            Row(Modifier.fillMaxWidth().clickable { open("table/${t.id}") }.padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(t.name, fontWeight = FontWeight.SemiBold)
                                    Muted(listOf(t.area.ifBlank { "—" }, "${t.seats ?: "—"} kursi").joinToString(" · "))
                                }
                                if (t.hasOpenTab) Text("Ada tab terbuka", color = ManagerColors.warning.fg, style = MaterialTheme.typography.labelSmall, modifier = Modifier.padding(end = 8.dp))
                                Text(if (t.isActive) "Aktif" else "Nonaktif", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            HorizontalDivider()
                        }
                    }
                }
                DiningTab.STATIONS -> {
                    val state by vm.stations.collectAsState()
                    Muted("  Stasiun (mis. Dapur, Bar) menerima tiket. Tetapkan stasiun pada kategori atau produk.")
                    LoadContent(state, vm::loadStations) { rows ->
                        if (rows.isEmpty()) MessageBox("Belum ada stasiun.") else LazyColumn {
                            items(rows, key = { it.id }) { s ->
                                Row(Modifier.fillMaxWidth().clickable { open("station/${s.id}") }.padding(16.dp, 12.dp)) {
                                    Text(s.name, Modifier.weight(1f), fontWeight = FontWeight.SemiBold); Muted("urutan ${s.displayOrder} · ${if (s.isActive) "Aktif" else "Nonaktif"}")
                                }
                                HorizontalDivider()
                            }
                        }
                    }
                }
                DiningTab.MODIFIERS -> {
                    val state by vm.groups.collectAsState()
                    LoadContent(state, vm::loadGroups) { rows ->
                        if (rows.isEmpty()) MessageBox("Belum ada grup modifier.") else LazyColumn {
                            items(rows, key = { it.id }) { g ->
                                Column(Modifier.fillMaxWidth().clickable { open("group/${g.id}") }.padding(16.dp, 10.dp)) {
                                    Row(verticalAlignment = Alignment.CenterVertically) {
                                        Text(g.name, Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
                                        TextButton(onClick = { open("attach/${g.id}") }) { Text("Lampirkan") }
                                    }
                                    Muted(ModifierGroupForm.rule(g) + if (!g.isActive) " · Nonaktif" else "")
                                    Muted(g.options.filter { it.isActive }.joinToString(", ") { o -> o.name + (o.priceDelta.toBigDecimalOrNull()?.takeIf { it.signum() != 0 }?.let { d -> " (${if (d.signum() > 0) "+" else "−"}${Format.rupiah(d.abs().toPlainString())})" } ?: "") })
                                    Muted("${g.productIds.size} produk · ${g.categoryIds.size} kategori" + if (g.options.any { it.isActive && it.materials.isNotEmpty() }) " · memakai bahan" else "")
                                }
                                HorizontalDivider()
                            }
                        }
                    }
                }
            }
        }
        val (label, key) = when (tab) { DiningTab.TABLES -> "Meja" to "table/new"; DiningTab.STATIONS -> "Stasiun" to "station/new"; DiningTab.MODIFIERS -> "Grup" to "group/new" }
        ExtendedFloatingActionButton(onClick = { open(key) }, icon = { Icon(Icons.Outlined.Add, null) }, text = { Text(label) }, modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp))
    }
}

@Composable
private fun OutletMoveField(outlets: List<com.warkop.manager.data.dto.OutletRef>, selected: Long?, moving: Boolean, onPick: (Long?) -> Unit, error: String?) {
    if (outlets.size < 2) return
    SelectInput("Outlet", outlets.map { Choice(it.id, it.name) }, selected, onPick, error)
    if (moving) Text("Menyimpan akan MEMINDAHKAN ke outlet lain. Riwayat order/tiket tidak ikut pindah.", color = ManagerColors.warning.fg, style = MaterialTheme.typography.bodySmall)
}

@Composable
private fun TablePane(vm: TableFormViewModel, onClose: () -> Unit, onDone: () -> Unit) {
    val f by vm.form.value.collectAsState(); val s by vm.status.collectAsState(); val outlets by vm.outlets.collectAsState(); val qr by vm.qr.collectAsState()
    var confirmDelete by remember { mutableStateOf(false) }; var confirmRotate by remember { mutableStateOf(false) }
    val context = LocalContext.current
    Column(Modifier.fillMaxSize()) {
        PaneHeader(if (f.id == null) "Meja baru" else "Ubah meja", onClose)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            TextInput("Nama / nomor meja", f.name, { v -> vm.edit { it.copy(name = v) } }, s.error("name"), maxLength = 60)
            TextInput("Area", f.area, { v -> vm.edit { it.copy(area = v) } }, s.error("area"), maxLength = 60, helper = "Mis. Indoor, Terrace. Kosongkan bila tidak ada area.")
            IntInput("Jumlah kursi", f.seats, { v -> vm.edit { it.copy(seats = v) } }, s.error("seats"))
            IntInput("Urutan tampil", f.displayOrder, { v -> vm.edit { it.copy(displayOrder = v) } }, s.error("display_order"), signed = true)
            SwitchRow("Aktif", f.isActive, { v -> vm.edit { it.copy(isActive = v) } })
            OutletMoveField(outlets, f.outletId, f.moving, { v -> vm.edit { it.copy(outletId = v) } }, s.error("outlet_id"))
            if (f.id != null && f.isActive) Section("QR pesan mandiri") {
                if (qr == null) OutlinedButton(onClick = vm::loadQr, enabled = !s.saving) { Text("Buat QR") }
                qr?.let { url ->
                    Muted("Pindai untuk memesan. Gambar QR dicetak dari web; di sini alamatnya bisa dibagikan.")
                    Text(url, style = MaterialTheme.typography.bodySmall)
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        OutlinedButton(onClick = { context.startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, url), "Bagikan QR meja")) }) { Text("Bagikan") }
                        TextButton(onClick = { confirmRotate = true }) { Text("Buat ulang QR", color = MaterialTheme.colorScheme.error) }
                    }
                }
            }
            FormMessage(s.message)
        }
        FooterSave(s.saving, onSave = { vm.save(onDone) }) { if (f.id != null) TextButton(onClick = { confirmDelete = true }) { Text("Hapus", color = MaterialTheme.colorScheme.error) } }
    }
    if (confirmDelete) ConfirmDialog("Hapus meja", "Hapus meja ${f.name}? Meja yang pernah dipakai order tidak bisa dihapus — nonaktifkan saja.", "Hapus", { confirmDelete = false; vm.delete(onDone) }, { confirmDelete = false }, destructive = true)
    if (confirmRotate) ConfirmDialog("Buat ulang QR", "QR lama meja ${f.name} akan berhenti berlaku dan stikernya harus diganti. Lanjutkan?", "Buat ulang", { confirmRotate = false; vm.rotateQr() }, { confirmRotate = false }, destructive = true)
}

@Composable
private fun StationPane(vm: StationFormViewModel, onClose: () -> Unit, onDone: () -> Unit) {
    val f by vm.form.value.collectAsState(); val s by vm.status.collectAsState(); val outlets by vm.outlets.collectAsState()
    var confirm by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxSize()) {
        PaneHeader(if (f.id == null) "Stasiun baru" else "Ubah stasiun", onClose)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            TextInput("Nama stasiun", f.name, { v -> vm.edit { it.copy(name = v) } }, s.error("name"), maxLength = 60)
            IntInput("Urutan tampil", f.displayOrder, { v -> vm.edit { it.copy(displayOrder = v) } }, s.error("display_order"), signed = true)
            SwitchRow("Aktif", f.isActive, { v -> vm.edit { it.copy(isActive = v) } })
            OutletMoveField(outlets, f.outletId, f.moving, { v -> vm.edit { it.copy(outletId = v) } }, s.error("outlet_id"))
            FormMessage(s.message)
        }
        FooterSave(s.saving, onSave = { vm.save(onDone) }) { if (f.id != null) TextButton(onClick = { confirm = true }) { Text("Hapus", color = MaterialTheme.colorScheme.error) } }
    }
    if (confirm) ConfirmDialog("Hapus stasiun", "Hapus stasiun ${f.name}? Stasiun yang masih dipakai atau pernah menerima tiket tidak bisa dihapus — nonaktifkan saja.", "Hapus", { confirm = false; vm.delete(onDone) }, { confirm = false }, destructive = true)
}

@Composable
private fun GroupPane(vm: GroupFormViewModel, onClose: () -> Unit, onDone: () -> Unit) {
    val f by vm.form.value.collectAsState(); val s by vm.status.collectAsState()
    val searching by vm.searchOption.collectAsState(); val results by vm.results.collectAsState()
    var q by remember { mutableStateOf("") }
    var confirm by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxSize()) {
        PaneHeader(if (f.id == null) "Grup modifier baru" else "Ubah grup modifier", onClose)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            TextInput("Nama grup", f.name, { v -> vm.edit { it.copy(name = v) } }, s.error("name"), maxLength = ModifierGroupForm.NAME_MAX)
            SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
                listOf("single" to "Pilih satu", "multiple" to "Pilih beberapa").forEachIndexed { i, (k, l) -> SegmentedButton(f.selectionType == k, { vm.edit { it.copy(selectionType = k) } }, SegmentedButtonDefaults.itemShape(i, 2)) { Text(l) } }
            }
            SwitchRow("Wajib dipilih", f.isRequired, { v -> vm.edit { it.copy(isRequired = v) } })
            if (f.multiple) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Box(Modifier.weight(1f)) { IntInput("Minimum dipilih", f.minSelect, { v -> vm.edit { it.copy(minSelect = v) } }, s.error("min_select")) }
                    Box(Modifier.weight(1f)) { IntInput("Maksimum dipilih", f.maxSelect, { v -> vm.edit { it.copy(maxSelect = v) } }, s.error("max_select"), helper = "Kosongkan bila tanpa batas.") }
                }
                SwitchRow("Tiap pilihan = 1 porsi", f.portionPerOption, { v -> vm.edit { it.copy(portionPerOption = v) } }, helper = "Mis. Rasa: memilih SOTO + AYAM BAWANG menagih harga item dua kali dan memakai kedua bungkus.")
            }
            SwitchRow("Aktif", f.isActive, { v -> vm.edit { it.copy(isActive = v) } })
            Text("Opsi", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
            Muted("Selisih harga boleh nol atau negatif. Opsi yang dihapus dari daftar akan dinonaktifkan — pesanan lama tidak berubah.")
            s.error("options")?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
            f.options.forEachIndexed { i, o ->
                OutlinedCard(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Box(Modifier.weight(1f)) { TextInput("Nama opsi", o.name, { v -> vm.editOption(o.key) { it.copy(name = v) } }, s.error("options.$i.name"), maxLength = 100) }
                            IconButton(onClick = { vm.edit { g -> g.copy(options = g.options - o) } }) { Icon(Icons.Outlined.Close, "Hapus opsi") }
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Box(Modifier.weight(1f)) { DecimalInput("Selisih harga (Rp)", o.priceDelta, { v -> vm.editOption(o.key) { it.copy(priceDelta = v) } }, s.error("options.$i.price_delta"), signed = true) }
                            Box(Modifier.width(90.dp)) { IntInput("Min", o.minQty, { v -> vm.editOption(o.key) { it.copy(minQty = v) } }, s.error("options.$i.min_qty")) }
                            Box(Modifier.width(90.dp)) { IntInput("Maks", o.maxQty, { v -> vm.editOption(o.key) { it.copy(maxQty = v) } }, s.error("options.$i.max_qty")) }
                        }
                        Muted("Bahan yang dipakai (dipotong per porsi pilihan ini saat pesanan selesai)")
                        o.materials.forEachIndexed { j, m ->
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                Text("${m.name} (${m.unit ?: "—"})", Modifier.weight(1f))
                                Box(Modifier.width(110.dp)) { DecimalInput("Jumlah", m.quantity, { v -> vm.editOption(o.key) { op -> op.copy(materials = op.materials.mapIndexed { k, x -> if (k == j) x.copy(quantity = v) else x }) } }, s.error("options.$i.materials.$j.quantity") ?: s.error("options.$i.materials.$j.material_id")) }
                                IconButton(onClick = { vm.editOption(o.key) { op -> op.copy(materials = op.materials.filterIndexed { k, _ -> k != j }) } }) { Icon(Icons.Outlined.Close, "Hapus bahan") }
                            }
                        }
                        if (searching == o.key) {
                            OutlinedTextField(q, { q = it; vm.search(it) }, singleLine = true, placeholder = { Text("Pilih bahan") }, modifier = Modifier.fillMaxWidth())
                            results.forEach { r -> Text("${r.name} · ${r.unit ?: "—"}", Modifier.fillMaxWidth().clickable { vm.pickMaterial(r); q = "" }.padding(vertical = 8.dp)) }
                        } else TextButton(onClick = { vm.searchOption.value = o.key; q = "" }) { Text("+ Tambah bahan") }
                    }
                }
            }
            OutlinedButton(onClick = { vm.edit { it.copy(options = it.options + OptionRow()) } }) { Text("Tambah opsi") }
            FormMessage(s.message)
        }
        FooterSave(s.saving, onSave = { vm.save(onDone) }) { if (f.id != null) TextButton(onClick = { confirm = true }) { Text("Hapus", color = MaterialTheme.colorScheme.error) } }
    }
    if (confirm) ConfirmDialog("Hapus grup modifier", "Hapus grup ${f.name}? Grup yang pernah dipakai pada order tidak bisa dihapus — nonaktifkan saja.", "Hapus", { confirm = false; vm.delete(onDone) }, { confirm = false }, destructive = true)
}

/** Dialog Attach (pola 091): kosong = "Tidak ada"; ≥ 2 dipilih → ringkasan nama. */
@Composable
private fun AttachPane(vm: AttachViewModel, groupName: String, onClose: () -> Unit, onDone: () -> Unit) {
    val choices by vm.choices.collectAsState(); val cats by vm.categoryIds.collectAsState(); val prods by vm.productIds.collectAsState(); val s by vm.status.collectAsState()
    Column(Modifier.fillMaxSize()) {
        PaneHeader("Lampirkan “$groupName”", onClose)
        val c = choices ?: run { LoadingBox(); return@Column }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Muted("Grup berlaku untuk produk yang dipilih DAN semua produk dalam kategori yang dipilih.")
            Pick("Kategori", c.first, cats) { vm.categoryIds.value = it }
            Pick("Produk", c.second, prods) { vm.productIds.value = it }
            FormMessage(s.message ?: s.error("product_ids") ?: s.error("category_ids") ?: s.error("modifier_group"))
        }
        FooterSave(s.saving, onSave = { vm.save(onDone) })
    }
}

@Composable
private fun Pick(label: String, source: List<Pair<Long, String>>, selected: Set<Long>, onChange: (Set<Long>) -> Unit) {
    var q by remember { mutableStateOf("") }
    Section(label) {
        Text(if (selected.isEmpty()) "Tidak ada" else "${selected.size} dipilih", fontWeight = FontWeight.SemiBold)
        attachSummary(selected, source)?.let { Muted(it) }
        OutlinedTextField(q, { q = it }, singleLine = true, placeholder = { Text("Cari $label".lowercase().replaceFirstChar { it.uppercase() }) }, modifier = Modifier.fillMaxWidth())
        source.filter { q.isBlank() || it.second.contains(q, ignoreCase = true) }.take(50).forEach { (id, name) ->
            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth().clickable { onChange(if (id in selected) selected - id else selected + id) }) {
                Checkbox(id in selected, { on -> onChange(if (on) selected + id else selected - id) }); Text(name)
            }
        }
    }
}
