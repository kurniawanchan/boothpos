package com.warkop.manager.ui.purchase

import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
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
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.Button
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.createSavedStateHandle
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.PurchaseRepository
import com.warkop.manager.data.dto.MaterialDto
import com.warkop.manager.data.dto.VendorDto
import com.warkop.manager.domain.Format
import com.warkop.manager.domain.MaterialBalance
import com.warkop.manager.domain.MaterialKinds
import com.warkop.manager.domain.MaterialMovementTypes
import com.warkop.manager.domain.MaterialUnits
import com.warkop.manager.domain.VendorPriceForm
import com.warkop.manager.domain.cleanCode20
import com.warkop.manager.ui.components.Choice
import com.warkop.manager.ui.components.ConfirmDialog
import com.warkop.manager.ui.components.DecimalInput
import com.warkop.manager.ui.components.FormMessage
import com.warkop.manager.ui.components.ListDetail
import com.warkop.manager.ui.components.LoadingBox
import com.warkop.manager.ui.components.MessageBox
import com.warkop.manager.ui.components.MoneyInput
import com.warkop.manager.ui.components.PagedListContent
import com.warkop.manager.ui.components.SelectInput
import com.warkop.manager.ui.components.SwitchRow
import com.warkop.manager.ui.components.TextInput
import com.warkop.manager.ui.container
import com.warkop.manager.ui.managerViewModel
import com.warkop.manager.ui.products.Muted
import com.warkop.manager.ui.products.PaneHeader
import com.warkop.manager.ui.products.Section
import com.warkop.manager.ui.theme.ManagerColors

@Composable
internal fun FooterSave(saving: Boolean, label: String = "Simpan", onSave: () -> Unit, leading: @Composable () -> Unit = {}) {
    HorizontalDivider()
    Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
        leading(); Box(Modifier.weight(1f)); Button(onClick = onSave, enabled = !saving) { Text(if (saving) "Menyimpan…" else label) }
    }
}

@Composable
internal fun SearchField(q: String, onQ: (String) -> Unit, placeholder: String) =
    OutlinedTextField(q, onQ, singleLine = true, leadingIcon = { Icon(Icons.Outlined.Search, null) }, placeholder = { Text(placeholder) }, modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 8.dp))

// ---------------- Vendor (T078) ----------------

@Composable
fun VendorsScreen(onSessionError: (ApiError) -> Boolean) {
    val list = managerViewModel { c -> VendorsViewModel(PurchaseRepository(c::api), createSavedStateHandle(), onSessionError) }
    ListDetail(
        list = { open ->
            val q by list.q.collectAsState(); val state by list.list.collectAsState()
            Box(Modifier.fillMaxSize()) {
                Column(Modifier.fillMaxSize()) {
                    SearchField(q, list::setQuery, "Cari nama vendor…")
                    PagedListContent(state, list::reload, list::loadMore, "Belum ada vendor.") { rows -> items(rows, key = { it.id }) { VendorRow(it) { open(it.id.toString()) }; HorizontalDivider() } }
                }
                ExtendedFloatingActionButton(onClick = { open("new") }, icon = { Icon(Icons.Outlined.Add, null) }, text = { Text("Vendor") }, modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp))
            }
        },
        detail = { key, close, open ->
            val id = key.toLongOrNull()
            val vm = managerViewModel(key = "vendor-$key") { c -> VendorFormViewModel(PurchaseRepository(c::api), createSavedStateHandle(), id, onSessionError) }
            VendorPane(vm, close, onSaved = { list.reload(); vm.form.current.id?.let { if (id == null) open(it.toString()) } }, onDeleted = { list.reload(); close() })
        },
    )
}

@Composable
private fun VendorRow(v: VendorDto, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(v.code, fontWeight = FontWeight.Bold, modifier = Modifier.padding(end = 12.dp))
        Column(Modifier.weight(1f)) {
            Text(v.name, style = MaterialTheme.typography.bodyLarge)
            Muted(listOfNotNull(v.contactPhone, v.contactEmail, "${v.materialPriceCount ?: 0} bahan").joinToString(" · "))
        }
        if (!v.isActive) Text("Nonaktif", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun VendorPane(vm: VendorFormViewModel, onClose: () -> Unit, onSaved: () -> Unit, onDeleted: () -> Unit) {
    val f by vm.form.value.collectAsState(); val s by vm.status.collectAsState(); val loading by vm.loading.collectAsState()
    var confirm by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxSize()) {
        PaneHeader(if (f.id == null) "Vendor baru" else "Ubah vendor", onClose)
        if (loading) { LoadingBox(); return@Column }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            TextInput("Kode vendor", f.code, { v -> vm.edit { it.copy(code = cleanCode20(v)) } }, s.error("code"), enabled = f.id == null, caps = KeyboardCapitalization.Characters, helper = if (f.id != null) "Permanen setelah dibuat." else "Huruf, angka, - dan _; maks 20.")
            TextInput("Nama vendor", f.name, { v -> vm.edit { it.copy(name = v) } }, s.error("name"), maxLength = 150)
            TextInput("Telepon", f.phone, { v -> vm.edit { it.copy(phone = v) } }, s.error("contact_phone"), maxLength = 30, keyboard = KeyboardType.Phone)
            TextInput("Email", f.email, { v -> vm.edit { it.copy(email = v) } }, s.error("contact_email"), maxLength = 100, keyboard = KeyboardType.Email, caps = KeyboardCapitalization.None)
            TextInput("Catatan", f.notes, { v -> vm.edit { it.copy(notes = v) } }, s.error("notes"), singleLine = false)
            SwitchRow("Aktif", f.isActive, { v -> vm.edit { it.copy(isActive = v) } })
            FormMessage(s.message)
        }
        FooterSave(s.saving, onSave = { vm.save(onSaved) }) {
            if (f.id != null) TextButton(onClick = { confirm = true }, enabled = !s.saving) { Text("Hapus", color = MaterialTheme.colorScheme.error) }
        }
    }
    if (confirm) ConfirmDialog("Hapus vendor", "Hapus ${f.name}? Ditolak bila masih memiliki harga bahan yang terdaftar.", "Ya, hapus", { confirm = false; vm.delete(onDeleted) }, { confirm = false }, destructive = true)
}

// ---------------- Bahan (T079–T081) ----------------

@Composable
fun MaterialsScreen(onSessionError: (ApiError) -> Boolean) {
    val outlet = container().session.selectedOutlet() ?: run { MessageBox("Pilih outlet dulu"); return }
    val list = managerViewModel { c -> MaterialsViewModel(PurchaseRepository(c::api), createSavedStateHandle(), onSessionError) }
    ListDetail(
        list = { open ->
            val q by list.q.collectAsState(); val kind by list.kind.collectAsState(); val stock by list.stock.collectAsState(); val state by list.list.collectAsState()
            Box(Modifier.fillMaxSize()) {
                Column(Modifier.fillMaxSize()) {
                    SearchField(q, list::setQuery, "Cari nama bahan…")
                    Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        FilterChip(kind == null, { list.setKind(null) }, { Text("Semua jenis") })
                        MaterialKinds.LABELS.forEach { (k, l) -> FilterChip(kind == k, { list.setKind(k) }, { Text(l) }) }
                        listOf("available" to "Ada stok", "out" to "Stok habis", "negative" to "Hanya yang minus").forEach { (k, l) -> FilterChip(stock == k, { list.setStock(if (stock == k) null else k) }, { Text(l) }) }
                    }
                    PagedListContent(state, list::reload, list::loadMore, "Belum ada bahan baku.") { rows -> items(rows, key = { it.id }) { MaterialRow(it, outlet.id) { open(it.id.toString()) }; HorizontalDivider() } }
                }
                ExtendedFloatingActionButton(onClick = { open("new") }, icon = { Icon(Icons.Outlined.Add, null) }, text = { Text("Bahan") }, modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp))
            }
        },
        detail = { key, close, open ->
            val id = key.toLongOrNull()
            val vm = managerViewModel(key = "material-$key") { c -> MaterialViewModel(PurchaseRepository(c::api), createSavedStateHandle(), id, outlet.id, onSessionError) }
            MaterialPane(vm, outlet.id, outlet.name, close, onSaved = { savedId -> list.reload(); if (id == null) open(savedId.toString()) }, onDeleted = { list.reload(); close() }, onStockChanged = list::reload)
        },
    )
}

@Composable
private fun MaterialRow(m: MaterialDto, outletId: Long, onClick: () -> Unit) {
    val negative = MaterialBalance.of(m, outletId).signum() < 0
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(m.name, style = MaterialTheme.typography.bodyLarge)
            Muted(listOfNotNull(m.code, MaterialKinds.LABELS[m.kind], "${m.vendorPriceCount ?: 0} vendor", if (!m.isActive) "Nonaktif" else null).joinToString(" · "))
        }
        Column(horizontalAlignment = Alignment.End) {
            Text(MaterialBalance.text(m, outletId), fontWeight = FontWeight.SemiBold, color = if (negative) ManagerColors.danger.fg else MaterialTheme.colorScheme.onSurface)
            if (negative) Text("Minus", style = MaterialTheme.typography.labelSmall, color = ManagerColors.danger.fg)
        }
    }
}

@Composable
private fun MaterialPane(vm: MaterialViewModel, outletId: Long, outletName: String, onClose: () -> Unit, onSaved: (Long) -> Unit, onDeleted: () -> Unit, onStockChanged: () -> Unit) {
    val f by vm.form.value.collectAsState(); val s by vm.status.collectAsState(); val loading by vm.loading.collectAsState()
    val detail by vm.detail.collectAsState(); val moves by vm.movements.collectAsState()
    var confirm by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxSize()) {
        PaneHeader(if (f.id == null) "Bahan baru" else f.name, onClose)
        if (loading) { LoadingBox(); return@Column }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Section(if (f.id == null) "Bahan baru" else "Ubah bahan") {
                TextInput("Kode bahan", f.code, { v -> vm.edit { it.copy(code = cleanCode20(v)) } }, s.error("code"), enabled = f.id == null, caps = KeyboardCapitalization.Characters, helper = if (f.id != null) "Permanen setelah dibuat." else null)
                TextInput("Nama bahan", f.name, { v -> vm.edit { it.copy(name = v) } }, s.error("name"), maxLength = 150)
                SelectInput("Jenis bahan", MaterialKinds.LABELS.map { (k, l) -> Choice(k, l) }, f.kind, { v -> vm.edit { it.copy(kind = v ?: "ingredient") } }, s.error("kind"))
                SelectInput(
                    "Satuan", MaterialUnits.PRESETS.map { Choice(it, it) } + Choice(MaterialUnits.OTHER, "Lainnya…"),
                    if (f.customUnit) MaterialUnits.OTHER else f.unit.ifEmpty { null },
                    { v -> vm.edit { if (v == MaterialUnits.OTHER) it.copy(customUnit = true, unit = "") else it.copy(customUnit = false, unit = v.orEmpty()) } },
                    if (f.customUnit) null else s.error("unit"),
                )
                if (f.customUnit) TextInput("Satuan lainnya (mis. lusin)", f.unit, { v -> vm.edit { it.copy(unit = v) } }, s.error("unit"), maxLength = 20)
                if (f.kind == "semi_finished") DecimalInput("Biaya standar per satuan", f.standardCost, { v -> vm.edit { it.copy(standardCost = v) } }, s.error("standard_cost"), helper = "Dipakai di resep bila bahan setengah jadi ini tidak punya harga vendor.")
                TextInput("Catatan", f.notes, { v -> vm.edit { it.copy(notes = v) } }, s.error("notes"), singleLine = false)
                SwitchRow("Aktif", f.isActive, { v -> vm.edit { it.copy(isActive = v) } })
                FormMessage(s.message)
            }
            if (f.id != null) {
                MaterialAdjustSection(vm, detail, outletId, outletName, onStockChanged)
                VendorPricesSection(vm)
                Section("Riwayat") {
                    if (moves.isEmpty()) Muted("Belum ada pergerakan.")
                    moves.take(20).forEach { mv ->
                        Muted("${MaterialMovementTypes.LABELS[mv.type] ?: mv.type} · ${mv.outletCode ?: ""} · ${mv.qtyChange} → ${Format.qty(mv.stockAfter)}${mv.reference?.orderNumber?.let { " · $it" } ?: ""}${mv.reason?.let { " · $it" } ?: ""}")
                    }
                }
                detail?.usedByModifierOptions?.takeIf { it.isNotEmpty() }?.let { uses ->
                    Section("Dipakai oleh") { uses.forEach { Muted("${it.group ?: "—"} → ${it.option ?: "—"} · ${Format.qty(it.quantity)} per porsi") } }
                }
            }
        }
        FooterSave(s.saving, onSave = { vm.save(onSaved) }) {
            if (f.id != null) TextButton(onClick = { confirm = true }, enabled = !s.saving) { Text("Hapus", color = MaterialTheme.colorScheme.error) }
        }
    }
    if (confirm) ConfirmDialog("Hapus bahan", "Hapus ${f.name}? Ditolak bila masih memiliki harga vendor, baris BOM, atau dipakai pilihan modifier.", "Ya, hapus", { confirm = false; vm.delete(onDeleted) }, { confirm = false }, destructive = true)
}

/** 094 / T080 — koreksi stok bahan di outlet terpilih. Saldo detail dari daftar (show tidak memuat `outlet_stock`). */
@Composable
private fun MaterialAdjustSection(vm: MaterialViewModel, detail: MaterialDto?, outletId: Long, outletName: String, onStockChanged: () -> Unit) {
    val a by vm.adjust.value.collectAsState(); val s by vm.adjustStatus.collectAsState()
    var done by remember { mutableStateOf<String?>(null) }
    Section("Koreksi stok — $outletName") {
        detail?.outletStock?.let { Muted("Saldo: ${MaterialBalance.text(detail, outletId)}") }
        DecimalInput("Selisih (+/−)", a.qty, { v -> vm.editAdjust { it.copy(qty = v) } }, s.error("qty_change"), signed = true)
        TextInput("Alasan", a.reason, { v -> vm.editAdjust { it.copy(reason = v) } }, s.error("reason"), maxLength = 255)
        FormMessage(s.message ?: s.error("outlet_id"))
        done?.let { Text(it, color = ManagerColors.success.fg, style = MaterialTheme.typography.bodySmall) }
        OutlinedButton(onClick = { vm.submitAdjust { done = "Stok bahan dikoreksi"; onStockChanged() } }, enabled = !s.saving) { Text("Simpan koreksi") }
    }
}

/** 094 / T081 — harga vendor per bahan (cermin `MaterialVendorPricesModal.vue`). */
@Composable
private fun VendorPricesSection(vm: MaterialViewModel) {
    val detail by vm.detail.collectAsState(); val p by vm.priceForm.value.collectAsState(); val s by vm.priceStatus.collectAsState(); val vendors by vm.vendors.collectAsState()
    var editing by remember { mutableStateOf(false) }
    LaunchedEffect(editing) { if (editing) vm.loadVendors() }
    Section("Harga vendor") {
        Muted("Vendor Preferred menjadi acuan modal bahan ini. Jika tidak ada yang preferred, harga termurah dipakai sebagai estimasi.")
        val prices = detail?.vendorPrices.orEmpty()
        if (prices.isEmpty()) Muted("Belum ada harga vendor untuk bahan ini.")
        prices.forEach { vp ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("${vp.vendorName ?: "#${vp.vendorId}"}${if (vp.isPreferred) " · Preferred" else ""}")
                    Muted(listOfNotNull(Format.rupiah(vp.price), vp.notes).joinToString(" · "))
                }
                TextButton(onClick = { vm.editPrice { VendorPriceForm.of(vp) }; editing = true }) { Text("Ubah") }
                TextButton(onClick = { vm.deletePrice(vp.id) }, enabled = !s.saving) { Text("Hapus", color = MaterialTheme.colorScheme.error) }
            }
        }
        if (editing) {
            if (p.id == null) SelectInput("Vendor", vendors.map { Choice(it.id, it.name) }, p.vendorId, { v -> vm.editPrice { it.copy(vendorId = v) } }, s.error("vendor_id"))
            MoneyInput("Harga per unit", p.price, { v -> vm.editPrice { it.copy(price = v) } }, s.error("price"))
            SwitchRow("Jadikan vendor preferred untuk bahan ini", p.preferred, { v -> vm.editPrice { it.copy(preferred = v) } })
            TextInput("Catatan", p.notes, { v -> vm.editPrice { it.copy(notes = v) } }, s.error("notes"))
            FormMessage(s.message)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                TextButton(onClick = { vm.editPrice { VendorPriceForm() }; editing = false }) { Text("Batal") }
                OutlinedButton(onClick = { vm.savePrice { editing = false } }, enabled = !s.saving) { Text("Simpan harga") }
            }
        } else OutlinedButton(onClick = { vm.editPrice { VendorPriceForm() }; editing = true }) { Text("Tambah harga vendor") }
    }
}
