package com.warkop.manager.ui.products

import android.net.Uri
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.adaptive.currentWindowAdaptiveInfo
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.unit.dp
import androidx.window.core.layout.WindowWidthSizeClass
import com.warkop.manager.domain.ProductForm
import com.warkop.manager.ui.components.Choice
import com.warkop.manager.ui.components.FormMessage
import com.warkop.manager.ui.components.IntInput
import com.warkop.manager.ui.components.LoadContent
import com.warkop.manager.ui.components.LoadingBox
import com.warkop.manager.ui.components.MoneyInput
import com.warkop.manager.ui.components.PhotoPicker
import com.warkop.manager.ui.components.SelectInput
import com.warkop.manager.ui.components.SwitchRow
import com.warkop.manager.ui.components.TextInput
import com.warkop.manager.ui.theme.ManagerColors

/**
 * 094 / T065 — formulir produk berbagian (Info, Varian & harga, Stok). Tautan modifier (T068) dan resep (T069) dikelola
 * layar masing-masing, seperti laci terpisah di web. Dua kolom di layar lebar (FR-028).
 */
@Composable
fun ProductFormPane(vm: ProductFormViewModel, canSeeCost: Boolean, onClose: () -> Unit, onSaved: (Long) -> Unit, openPrices: (Long) -> Unit) {
    val f by vm.form.value.collectAsState()
    val status by vm.status.collectAsState()
    val loading by vm.loading.collectAsState()
    val warnings by vm.warnings.collectAsState()
    val wide = currentWindowAdaptiveInfo().windowSizeClass.windowWidthSizeClass == WindowWidthSizeClass.EXPANDED
    Column(Modifier.fillMaxSize()) {
        PaneHeader(if (f.editing) "Ubah item" else "Item baru", onClose)
        if (loading) { LoadingBox(); return@Column }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (wide) Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(12.dp)) { InfoSection(vm) }
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(12.dp)) { VariantSection(vm, canSeeCost, openPrices); StockSection(vm) }
            } else {
                InfoSection(vm); VariantSection(vm, canSeeCost, openPrices); StockSection(vm)
            }
            warnings.forEach { Text(it, color = ManagerColors.warning.fg, style = MaterialTheme.typography.bodySmall) }
            FormMessage(status.message)
        }
        HorizontalDivider()
        Row(Modifier.fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.End) {
            Button(onClick = { vm.save(onSaved) }, enabled = !status.saving) { Text(if (status.saving) "Menyimpan…" else "Simpan") }
        }
    }
}

@Composable
private fun InfoSection(vm: ProductFormViewModel) {
    val f by vm.form.value.collectAsState()
    val s by vm.status.collectAsState()
    val categories by vm.categories.collectAsState()
    val stations by vm.stations.collectAsState()
    val outlets by vm.outlets.collectAsState()
    val multi by vm.multi.collectAsState()
    Section("Info") {
        TextInput("Nama", f.name, { v -> vm.edit { it.copy(name = v) } }, s.error("name"), maxLength = ProductForm.NAME_MAX)
        SelectInput("Kategori", categories.map { Choice(it.id, it.name) }, f.categoryId, { v -> vm.edit { it.copy(categoryId = v) } }, s.error("category_id"))
        SelectInput("Outlet pemilik", outlets.map { Choice(it.id, it.name) }, f.homeOutletId, { v -> vm.edit { it.copy(homeOutletId = v) } }, s.error("home_outlet_id"))
        if (multi) {
            Muted("Dijual di outlet" + if (!f.editing) " (wajib minimal satu)" else "")
            outlets.forEach { o ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Checkbox(checked = o.id in f.outletIds, onCheckedChange = { on -> vm.edit { it.copy(outletIds = if (on) it.outletIds + o.id else it.outletIds - o.id) } })
                    Text(o.name)
                }
            }
            (s.errorUnder("outlet_ids"))?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
        }
        if (!f.editing) TextInput("Segmen", f.segment, { v -> vm.edit { it.copy(segment = ProductForm.cleanSegment(v)) } }, s.error("product_segment"), caps = KeyboardCapitalization.Characters, helper = "3 huruf; kosong = otomatis. Tidak bisa diubah setelah disimpan.")
        TextInput("Nama pendek", f.shortName, { v -> vm.edit { it.copy(shortName = v) } }, s.error("short_name"), maxLength = ProductForm.SHORT_NAME_MAX)
        TextInput("Deskripsi", f.description, { v -> vm.edit { it.copy(description = v) } }, s.error("description"), singleLine = false)
        SelectInput("Kategori pajak", ProductForm.TAX_CATEGORIES.map { (k, l) -> Choice(k, l) }, f.taxCategory, { v -> vm.edit { it.copy(taxCategory = v) } }, s.error("tax_category"), allowNone = true)
        IntInput("Waktu siap (menit, 0–600)", f.prepMinutes, { v -> vm.edit { it.copy(prepMinutes = v) } }, s.error("prep_minutes"))
        if (stations.isNotEmpty()) SelectInput("Station", stations.map { Choice(it.id, it.name) }, f.stationId, { v -> vm.edit { it.copy(stationId = v) } }, s.error("station_id"), allowNone = true, noneLabel = "Ikut kategori")
        SwitchRow("Item ritel", f.isRetail, { v -> vm.edit { it.copy(isRetail = v) } })
        Muted("Sumber stok")
        listOf("variant" to "Varian", "modifier" to "Modifier").forEach { (k, label) ->
            Row(verticalAlignment = Alignment.CenterVertically) { RadioButton(selected = f.stockSource == k, onClick = { vm.edit { it.copy(stockSource = k) } }); Text(label) }
        }
        s.error("stock_source")?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
        SwitchRow("Pakai sebagai bahan resep", f.useAsIngredient, { v -> vm.edit { it.copy(useAsIngredient = v) } })
        if (f.useAsIngredient) TextInput("Satuan bahan", f.ingredientUnit, { v -> vm.edit { it.copy(ingredientUnit = v) } }, s.error("ingredient_unit"), maxLength = ProductForm.INGREDIENT_UNIT_MAX)
        s.error("use_as_ingredient")?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
        // Draft/Aktif hanya di formulir UBAH (web: saat rilis menu mati; bila rilis aktif server yang menentukan).
        if (f.editing) SwitchRow("Aktif (bukan draft)", f.isActive, { v -> vm.edit { it.copy(isActive = v) } })
        PhotoPicker(f.photo?.let(Uri::parse), hasCurrent = f.editing, onPicked = { uri -> vm.edit { it.copy(photo = uri?.toString()) } }, error = s.error("image"))
    }
}

@Composable
private fun VariantSection(vm: ProductFormViewModel, canSeeCost: Boolean, openPrices: (Long) -> Unit) {
    val f by vm.form.value.collectAsState()
    val s by vm.status.collectAsState()
    val multi by vm.multi.collectAsState()
    Section("Varian & harga") {
        f.variants.forEachIndexed { i, r ->
            if (i > 0) HorizontalDivider()
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Varian ${i + 1}${if (!r.isActive) " · nonaktif" else ""}", fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                    if (r.isActive && (r.id != null || f.variants.size > 1)) IconButton(onClick = { vm.removeVariant(r.key) }) { Icon(Icons.Outlined.Close, if (r.id != null) "Nonaktifkan varian" else "Hapus baris") }
                    if (!r.isActive) TextButton(onClick = { vm.editVariant(r.key) { it.copy(isActive = true) } }) { Text("Aktifkan") }
                }
                TextInput("Nama varian", r.name, { v -> vm.editVariant(r.key) { it.copy(name = v) } }, s.error("variants.$i.variant_name"), maxLength = ProductForm.VARIANT_NAME_MAX, helper = if (f.variants.size == 1) "Kosong = \"Standard\"" else null)
                MoneyInput("Harga jual", r.sellPrice, { v -> vm.editVariant(r.key) { it.copy(sellPrice = v) } }, s.error("variants.$i.sell_price"))
                if (canSeeCost || !f.editing) MoneyInput(
                    "Harga modal", r.costPrice, { v -> vm.editVariant(r.key) { it.copy(costPrice = v) } }, s.error("variants.$i.cost_price"),
                    enabled = !r.bomComplete, helper = if (r.bomComplete) "Mengikuti BOM/resep lengkap — tidak bisa diubah manual." else null,
                )
                IntInput("Ambang stok menipis", r.lowStockAlert, { v -> vm.editVariant(r.key) { it.copy(lowStockAlert = v) } }, s.error("variants.$i.low_stock_alert"))
                if (f.stockSource != "modifier") SwitchRow("Lacak stok", r.trackStock, { v -> vm.editVariant(r.key) { it.copy(trackStock = v) } })
                PhotoPicker(r.photo?.let(Uri::parse), hasCurrent = r.id != null, onPicked = { uri -> vm.editVariant(r.key) { it.copy(photo = uri?.toString()) } }, error = s.error("variants.$i.image"))
                if (multi && r.id != null) OutlinedButton(onClick = { openPrices(r.id) }) { Text("Harga per outlet") }
            }
        }
        OutlinedButton(onClick = vm::addVariant) { Text("+ Tambah varian") }
    }
}

@Composable
private fun StockSection(vm: ProductFormViewModel) {
    val f by vm.form.value.collectAsState()
    val s by vm.status.collectAsState()
    if (f.stockSource == "modifier") {
        Section("Stok") { Muted("Sumber stok Modifier: stok varian tidak dilacak, bahan dipotong dari pilihan modifier.") }
        return
    }
    Section("Stok di outlet terpilih") {
        Muted(if (f.editing) "Ubah angka stok untuk mengoreksi; alasan wajib bila ada yang berubah." else "Stok awal (opsional). Dicatat dengan alasan \"${ProductForm.OPENING_REASON}\".")
        f.variants.forEachIndexed { i, r ->
            if (!r.trackStock) return@forEachIndexed
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(r.name.ifBlank { "Varian ${i + 1}" }, modifier = Modifier.weight(1f))
                Box(Modifier.weight(1f)) { IntInput("Stok", r.currentStock, { v -> vm.editVariant(r.key) { it.copy(currentStock = v) } }, helper = if (r.originalStock.isNotEmpty()) "sebelumnya ${r.originalStock}" else null) }
            }
        }
        if (f.editing && !f.createdHere && f.stockChanged) TextInput("Alasan perubahan stok", f.stockReason, { v -> vm.edit { it.copy(stockReason = v) } }, s.error("stock_adjustment_reason"), maxLength = 255)
    }
}

/** 094 / T067 — harga per outlet satu varian. Kosong = ikut harga dasar. */
@Composable
fun OutletPricesPane(vm: OutletPricesViewModel, onClose: () -> Unit) {
    val state by vm.data.collectAsState()
    val inputs by vm.inputs.collectAsState()
    val note by vm.note.collectAsState()
    val status by vm.status.collectAsState()
    val result by vm.result.collectAsState()
    Column(Modifier.fillMaxSize()) {
        PaneHeader("Harga per outlet", onClose)
        LoadContent(state, onRetry = vm::load) { d ->
            Column(Modifier.fillMaxSize()) {
                Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Muted("Harga dasar ${com.warkop.manager.domain.Format.rupiah(d.basePrice)}. Kosongkan untuk ikut harga dasar.")
                    d.outlets.forEach { o ->
                        MoneyInput(o.name, inputs[o.id].orEmpty(), { v -> vm.inputs.value = inputs + (o.id to v) }, helper = if (inputs[o.id].isNullOrBlank()) "Ikut harga dasar" else null)
                    }
                    TextInput("Catatan (opsional)", note, { vm.note.value = it }, maxLength = 255)
                    result?.let { Text(it, color = ManagerColors.success.fg, style = MaterialTheme.typography.bodySmall) }
                    FormMessage(status.message)
                }
                HorizontalDivider()
                Row(Modifier.fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.End) {
                    Button(onClick = vm::save, enabled = !status.saving) { Text(if (status.saving) "Menyimpan…" else "Simpan harga") }
                }
            }
        }
    }
}
