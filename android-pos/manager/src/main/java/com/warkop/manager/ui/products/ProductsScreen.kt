package com.warkop.manager.ui.products

import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedCard
import androidx.compose.material3.OutlinedTextField
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
import com.warkop.manager.data.ImageUpload
import com.warkop.manager.data.ManagerSession
import com.warkop.manager.data.ProductRepository
import com.warkop.manager.data.dto.ProductDetailDto
import com.warkop.manager.data.dto.ProductRowDto
import com.warkop.manager.domain.Format
import com.warkop.manager.ui.components.Choice
import com.warkop.manager.ui.components.FormMessage
import com.warkop.manager.ui.components.ListDetail
import com.warkop.manager.ui.components.LoadContent
import com.warkop.manager.ui.components.MessageBox
import com.warkop.manager.ui.components.PagedListContent
import com.warkop.manager.ui.components.SelectInput
import com.warkop.manager.ui.components.TextInput
import com.warkop.manager.ui.container
import com.warkop.manager.ui.managerViewModel
import com.warkop.manager.ui.theme.ManagerColors

/**
 * 094 / T063–T067 — Produk: daftar (panel kiri), detail / formulir / harga outlet (panel kanan).
 * Kunci detail: "<id>", "<id>/edit", "new", "price/<variantId>".
 */
@Composable
fun ProductsScreen(session: ManagerSession, onSessionError: (ApiError) -> Boolean) {
    val outlet = container().session.selectedOutlet() ?: run { MessageBox("Pilih outlet dulu"); return }
    val resolver = LocalContext.current.contentResolver
    val repoOf = { c: com.warkop.manager.AppContainer -> ProductRepository(c::api) { uri -> ImageUpload.part(resolver, uri) } }
    val canSeeCost = "products" in session.menuKeys || "reports" in session.menuKeys
    val list = managerViewModel { c -> ProductsViewModel(repoOf(c), createSavedStateHandle(), outlet.id, onSessionError) }
    val canEditRecipe = "menu.recipe.edit" in session.permissions
    var notice by remember { mutableStateOf<String?>(null) }
    ListDetail(
        list = { open -> ProductList(list, canCreate = "products" in session.menuKeys, open) },
        detail = { key, close, open ->
            when {
                key == "new" || key.endsWith("/edit") -> {
                    val id = key.removeSuffix("/edit").toLongOrNull()
                    val vm = managerViewModel(key = "product-form-$key") { c -> ProductFormViewModel(repoOf(c), createSavedStateHandle(), id, outlet.id, canSeeCost, onSessionError) }
                    ProductFormPane(vm, canSeeCost, onClose = close, onSaved = { savedId -> list.refresh(); open(savedId.toString()) }, openPrices = { open("price/$it") })
                }
                key.startsWith("links/") -> {
                    val pid = key.removePrefix("links/").toLong()
                    ModifierLinksPane(managerViewModel(key = "links-$pid") { c -> ModifierLinksViewModel(repoOf(c), createSavedStateHandle(), pid, onSessionError) }, onClose = close, onSaved = { msg -> notice = msg; open(pid.toString()) })
                }
                key.startsWith("recipes/") -> {
                    val pid = key.removePrefix("recipes/").toLong()
                    RecipesPane(managerViewModel(key = "recipes-$pid") { c -> RecipesViewModel(repoOf(c), pid, onSessionError) }, canEditRecipe, onClose = close, open = { r -> open("recipe/$pid/$r") })
                }
                key.startsWith("recipe/") -> {
                    val (pid, rid) = key.removePrefix("recipe/").split("/").let { it[0].toLong() to it[1].toLongOrNull() }
                    RecipeFormPane(managerViewModel(key = "recipe-$key") { c -> RecipeFormViewModel(repoOf(c), createSavedStateHandle(), pid, rid, onSessionError) }, onClose = close, onSaved = { open("recipes/$pid") })
                }
                key.startsWith("price/") -> {
                    val variantId = key.removePrefix("price/").toLong()
                    OutletPricesPane(managerViewModel(key = "price-$variantId") { c -> OutletPricesViewModel(repoOf(c), variantId, onSessionError) }, onClose = close)
                }
                else -> {
                    val id = key.toLong()
                    ProductDetailPane(managerViewModel(key = "product-$id") { c -> ProductDetailViewModel(repoOf(c), id, onSessionError) }, canSeeCost, notice, onClose = close, onEdit = { open("$id/edit") }, onChanged = list::refresh, openPrices = { open("price/$it") }, openLinks = { notice = null; open("links/$id") }, openRecipes = { open("recipes/$id") })
                }
            }
        },
    )
}

@Composable
private fun ProductList(vm: ProductsViewModel, canCreate: Boolean, open: (String) -> Unit) {
    val tab by vm.tab.collectAsState()
    val q by vm.q.collectAsState()
    val status by vm.status.collectAsState()
    val counts by vm.counts.collectAsState()
    val summary by vm.summary.collectAsState()
    val rows by vm.rows.collectAsState()
    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            TabRow(selectedTabIndex = tab.ordinal) {
                ProductTab.entries.forEach { t ->
                    val n = if (t == ProductTab.MENU) counts.menu else counts.retail
                    Tab(selected = t == tab, onClick = { vm.setTab(t) }, text = { Text("${t.label} ($n)") })
                }
            }
            summary?.let { s ->
                Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Fig("Menu item aktif", "${s.active.count}", "dari ${s.active.total} · ${s.active.draft} draft · ${s.active.discontinued} discontinued")
                    Fig("86 hari ini", "${s.soldOutToday.count}", if (s.soldOutToday.count == 0) "Semua item tersedia" else null)
                    Fig("Kualitas data", "${s.quality.count}", if (s.quality.count == 0) "Semua data lengkap" else QUALITY[s.quality.kind])
                }
            }
            OutlinedTextField(q, vm::setQuery, singleLine = true, leadingIcon = { Icon(Icons.Outlined.Search, null) }, placeholder = { Text("Cari nama atau SKU") }, modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp))
            Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(selected = status == null, onClick = { vm.setStatus(null) }, label = { Text("Semua status") })
                PRODUCT_STATUS.forEach { (k, label) -> FilterChip(selected = status == k, onClick = { vm.setStatus(k) }, label = { Text(label) }) }
            }
            PagedListContent(rows, onRefresh = vm::refresh, onEnd = vm::loadMore, empty = "Belum ada item.") { items ->
                items(items, key = { it.id }) { ProductRow(it) { open(it.id.toString()) }; HorizontalDivider() }
            }
        }
        if (canCreate) ExtendedFloatingActionButton(onClick = { open("new") }, icon = { Icon(Icons.Outlined.Add, null) }, text = { Text("Item") }, modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp))
    }
}

private val QUALITY = mapOf("no_station" to "Tanpa station dapur", "no_price" to "Tanpa harga", "no_cost" to "Tanpa biaya modal")

@Composable
private fun Fig(label: String, value: String, note: String?) {
    OutlinedCard {
        Column(Modifier.padding(horizontal = 12.dp, vertical = 8.dp)) {
            Muted(label)
            Text(value, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.ExtraBold)
            note?.let { Muted(it) }
        }
    }
}

@Composable
private fun ProductRow(p: ProductRowDto, onClick: () -> Unit) {
    val soldOut = p.availability.any { it.state == "sold_out" }
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(p.name, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
            Muted(listOfNotNull(p.code, p.category?.name, p.variantsSummary.takeIf { it.isNotBlank() }).joinToString(" · "))
            if (p.quality.isNotEmpty()) Text(p.quality.mapNotNull { QUALITY[it] }.joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = ManagerColors.warning.fg)
        }
        Column(horizontalAlignment = Alignment.End) {
            Text(price(p), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
            Text(if (soldOut) "86" else PRODUCT_STATUS[p.status] ?: p.status, style = MaterialTheme.typography.labelMedium, color = if (soldOut || p.status == "discontinued") ManagerColors.danger.fg else MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

private fun price(p: ProductRowDto): String {
    val r = p.priceRange
    return if (r?.min != null && r.max != null && r.min != r.max) "${Format.rupiah(r.min)} – ${Format.rupiah(r.max)}" else p.basePrice?.let(Format::rupiah) ?: "—"
}

@Composable
internal fun Muted(text: String) = Text(text, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)

@Composable
internal fun PaneHeader(title: String, onClose: () -> Unit, actions: @Composable () -> Unit = {}) {
    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth().padding(4.dp)) {
        IconButton(onClick = onClose) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, "Kembali") }
        Text(title, style = MaterialTheme.typography.titleLarge, modifier = Modifier.weight(1f))
        actions()
    }
}

/** 094 / T064 — detail produk, cermin `ProductDetailView.vue`. */
@Composable
private fun ProductDetailPane(vm: ProductDetailViewModel, canSeeCost: Boolean, notice: String?, onClose: () -> Unit, onEdit: () -> Unit, onChanged: () -> Unit, openPrices: (Long) -> Unit, openLinks: () -> Unit, openRecipes: () -> Unit) {
    val state by vm.detail.collectAsState()
    val history by vm.history.collectAsState()
    val status by vm.status.collectAsState()
    var dialog by remember { mutableStateOf<String?>(null) } // "sold_out" | "discontinue"
    androidx.compose.runtime.LaunchedEffect(Unit) { vm.load() }
    LoadContent(state, onRetry = vm::load) { d ->
        val soldOut = d.availability.any { it.state == "sold_out" }
        Column(Modifier.fillMaxSize()) {
            PaneHeader(d.name, onClose) { if (d.can.edit) TextButton(onClick = onEdit) { Text("Edit item") } }
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Muted(listOfNotNull(d.codePrefix, d.categoryName, PRODUCT_STATUS[d.status ?: ""], if (soldOut) "86" else null).joinToString(" · "))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (d.can.mark86 && d.status != "discontinued") {
                        if (soldOut) OutlinedButton(onClick = { vm.undoSoldOut(onChanged) }, enabled = !status.saving) { Text("Batalkan 86") }
                        else OutlinedButton(onClick = { vm.loadReasons("sold_out"); dialog = "sold_out" }, enabled = !status.saving) { Text("Tandai 86") }
                    }
                    if (d.can.discontinue) {
                        if (d.status == "discontinued") OutlinedButton(onClick = { vm.reactivate(onChanged) }, enabled = !status.saving) { Text("Aktifkan lagi") }
                        else OutlinedButton(onClick = { vm.loadReasons("discontinue"); dialog = "discontinue" }, enabled = !status.saving) { Text("Discontinue") }
                    }
                }
                FormMessage(status.message)
                notice?.let { Text(it, color = ManagerColors.success.fg, style = MaterialTheme.typography.bodySmall) }
                Section("Info") { DetailInfo(d) }
                Section("Varian") {
                    d.variants.forEach { v ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(v.variantName + if (!v.isActive) " (nonaktif)" else "", fontWeight = FontWeight.SemiBold)
                                Muted(listOfNotNull(v.sku, v.outletStock?.let { "stok $it" }, if (canSeeCost) v.costPrice?.let { "modal ${Format.rupiah(it)}" + if (v.bomComplete) " (BOM)" else "" } else null).joinToString(" · "))
                            }
                            Column(horizontalAlignment = Alignment.End) {
                                Text(Format.rupiah(v.outletPrice ?: v.sellPrice), fontWeight = FontWeight.SemiBold)
                                if (d.outlets != null && d.can.edit) TextButton(onClick = { openPrices(v.id) }) { Text("Harga outlet") }
                            }
                        }
                    }
                }
                Section("Modifier") {
                    if (d.modifierLinks.isEmpty()) Muted("Tidak ada grup modifier.")
                    d.modifierLinks.forEach { l -> Muted("${l.name}${if (l.source == "category") " (dari kategori)" else ""} · ${if (l.isRequired) "wajib" else "opsional"} · ${l.minSelect}–${l.maxSelect ?: "∞"}") }
                    d.modifierExcluded.forEach { l -> Muted("${l.name} — dilepas dari kategori") }
                    if (d.can.edit) TextButton(onClick = openLinks) { Text("Kelola modifier") }
                }
                Section("Resep") {
                    Muted(d.recipe?.let { "${it.name ?: "Resep"} · ${it.lineCount} bahan · ${it.recipeCount} resep aktif" } ?: "Belum ada resep.")
                    TextButton(onClick = openRecipes) { Text("Lihat resep") }
                }
                if (history.isNotEmpty()) Section("Riwayat harga") {
                    history.take(10).forEach { h -> Muted("${h.variant ?: "—"}${h.outletCode?.let { " @$it" } ?: ""}: ${h.oldPrice?.let(Format::rupiah) ?: "—"} → ${Format.rupiah(h.newPrice)} · ${h.by ?: ""}") }
                }
            }
        }
    }
    dialog?.let { kind -> ReasonDialog(vm, kind, onDismiss = { dialog = null }, onDone = { dialog = null; onChanged() }) }
}

@Composable
private fun DetailInfo(d: ProductDetailDto) {
    listOfNotNull(
        "Outlet pemilik" to d.homeOutletName,
        d.outlets?.let { o -> "Dijual di" to o.filter { it.carried }.joinToString { it.name } },
        "Nama pendek" to d.shortName,
        "Station" to d.station?.let { "${it.name}${if (it.source == "category") " (ikut kategori)" else ""}" },
        "Waktu siap" to d.prepMinutes?.let { "$it menit" },
        "Sumber stok" to if (d.stockSource == "modifier") "Modifier" else "Varian",
        "Bahan resep" to if (d.useAsIngredient) "Ya (${d.ingredientUnit ?: "—"})" else null,
    ).filter { it.second != null }.forEach { (k, v) -> Row { Text("$k: ", style = MaterialTheme.typography.bodySmall); Muted(v!!) } }
}

@Composable
internal fun Section(title: String, content: @Composable () -> Unit) {
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(title, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
            content()
        }
    }
}

@Composable
private fun ReasonDialog(vm: ProductDetailViewModel, kind: String, onDismiss: () -> Unit, onDone: () -> Unit) {
    val reasons by vm.reasons.collectAsState()
    val status by vm.status.collectAsState()
    var reasonId by remember { mutableStateOf<Long?>(null) }
    var note by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(if (kind == "sold_out") "Tandai 86" else "Discontinue item") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                SelectInput("Alasan", reasons.map { Choice(it.id, it.label.ifBlank { it.code }) }, reasonId, { reasonId = it }, status.error("reason_code_id"))
                TextInput("Catatan (opsional)", note, { note = it }, status.error("note"), maxLength = 255)
                FormMessage(status.message)
            }
        },
        confirmButton = {
            Button(onClick = {
                val id = reasonId ?: return@Button
                if (kind == "sold_out") vm.markSoldOut(id, note, onDone) else vm.discontinue(id, note, onDone)
            }, enabled = reasonId != null && !status.saving) { Text("Simpan") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Batal") } },
    )
}
