package com.warkop.pos.setup

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.core.domain.ApiError
import com.warkop.pos.data.DiscoveryResult
import com.warkop.pos.data.OutletChoice
import com.warkop.pos.data.PosBackend
import com.warkop.pos.data.ServerDiscovery
import com.warkop.pos.data.TerminalChoice
import com.warkop.pos.data.TerminalConfig
import com.warkop.pos.state.Load
import com.warkop.pos.ui.ErrorState
import com.warkop.pos.ui.PrimaryPill
import kotlinx.coroutines.launch

/**
 * Penyiapan terminal sekali pakai (073): cari alamat server (otomatis, dari pilihan admin di web — 065) → pilih outlet → kolom
 * Terminal muncul: pilih terminal yang ada ATAU ketik nama baru → selesai, lanjut ke pilih staf + PIN. TANPA login owner/admin
 * (spec Q1): semua panggilan di layar ini tanpa token, dan server mendaftarkan terminal baru di bawah outlet yang dipilih.
 *
 * [onCancel] hanya diberikan bila tablet SUDAH punya outlet + terminal tersimpan (dibuka dari ikon "Atur terminal" di layar PIN):
 * pilihan lama tetap berlaku sampai pilihan baru benar-benar dibuat.
 */
@Composable
fun TerminalSetupScreen(
    backend: PosBackend,
    discoveryOverride: ServerDiscovery? = null,
    /** 073 — mengapa tablet kembali ke sini (terminal dicabut / tak berlaku lagi); null = pembukaan biasa. */
    notice: String? = null,
    onCancel: (() -> Unit)? = null,
    /** 077 — identitas toko + lencana mode di atas formulir; kosong di uji/preset yang tidak punya PosStore. */
    header: @Composable () -> Unit = {},
    onDone: (TerminalConfig) -> Unit,
) {
    val context = androidx.compose.ui.platform.LocalContext.current
    val discovery = remember { discoveryOverride ?: ServerDiscovery.fromContext(context) }
    val scope = rememberCoroutineScope()
    // 065 / US6 — tidak ada kolom "Alamat server": alamat dari pilihan admin di web, dicari lewat alamat bawaan aplikasi.
    // Bila tablet SUDAH menyimpan alamat (penyiapan ulang tanpa hapus data) alamat itu dipakai; server baru ditanya lagi
    // hanya setelah data dihapus (keputusan pemilik).
    var found by remember { mutableStateOf<DiscoveryResult?>(backend.serverUrl()?.takeIf { it.isNotBlank() }?.let { DiscoveryResult.Found(it) }) }
    var attempt by remember { mutableStateOf(0) }
    LaunchedEffect(attempt) {
        if (found == null) found = discovery.discover()
    }
    val url = (found as? DiscoveryResult.Found)?.url.orEmpty()

    var outlets by remember { mutableStateOf<Load<List<OutletChoice>>>(Load.Idle) }
    var outletTry by remember { mutableStateOf(0) }
    var chosen by remember { mutableStateOf<OutletChoice?>(null) }
    var terminals by remember { mutableStateOf<Load<List<TerminalChoice>>>(Load.Idle) }
    var terminalTry by remember { mutableStateOf(0) }
    var picked by remember { mutableStateOf<TerminalChoice?>(null) }
    // 064 — kolom dikosongkan: nama kini identitas terminal dan harus unik per outlet, jadi nama bawaan yang sama untuk semua
    // tablet akan selalu bentrok pada tablet kedua.
    var typedName by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    // Alamat server baru dipakai setelah ditemukan; outlet dimuat sekali per percobaan.
    LaunchedEffect(url, outletTry) {
        if (url.isBlank()) return@LaunchedEffect
        backend.setServerUrl(url)
        outlets = Load.Loading
        outlets = try {
            Load.Ready(backend.outlets()).also { ready -> if (chosen == null) chosen = SetupRules.preselect(ready.value) }
        } catch (e: ApiError) { Load.Failed(e) }
    }
    // Terminal dimuat tiap kali outlet berganti; pilihan/ketikan lama dibuang karena milik outlet lain.
    LaunchedEffect(chosen?.id, terminalTry) {
        val o = chosen ?: return@LaunchedEffect
        terminals = Load.Loading
        terminals = try { Load.Ready(backend.terminals(o)) } catch (e: ApiError) { Load.Failed(e) }
    }

    Box(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background), contentAlignment = Alignment.Center) {
        Surface(Modifier.widthIn(max = 560.dp).fillMaxWidth().padding(20.dp), shape = RoundedCornerShape(28.dp), color = MaterialTheme.colorScheme.surfaceContainer) {
            Column(Modifier.padding(28.dp).verticalScroll(rememberScrollState())) {
                header()
                Spacer(Modifier.height(12.dp))
                Text("Siapkan terminal", fontSize = 28.sp)
                Text(
                    "Pilih outlet, lalu pilih atau ketik nama terminal ini.",
                    Modifier.padding(top = 6.dp, bottom = 18.dp), color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                notice?.let { Text(it, Modifier.padding(bottom = 12.dp), color = MaterialTheme.colorScheme.error, fontSize = 15.sp) }
                when (val f = found) {
                    null -> Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp); Spacer(Modifier.width(12.dp)); Text("Mencari server…")
                    }
                    is DiscoveryResult.NotConfigured -> DiscoveryProblem("Alamat server belum diatur. Minta admin mengisinya di web (Pengaturan › Server address), lalu coba lagi.") { found = null; attempt++ }
                    DiscoveryResult.Unreachable -> DiscoveryProblem("Server tidak terjangkau. Pastikan tablet satu jaringan dengan server outlet atau punya internet, lalu coba lagi.") { found = null; attempt++ }
                    DiscoveryResult.NoDefaults -> DiscoveryProblem("Aplikasi ini dibuat tanpa alamat server bawaan. Hubungi admin untuk versi aplikasi yang benar.", retry = null)
                    is DiscoveryResult.Found -> when (val o = outlets) {
                        is Load.Idle, is Load.Loading -> Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                            CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp); Spacer(Modifier.width(12.dp)); Text("Memuat outlet…")
                        }
                        is Load.Failed -> ErrorState(o.error.message, onRetry = { outletTry++ }, modifier = Modifier.fillMaxWidth())
                        is Load.Ready -> if (o.value.isEmpty()) {
                            Text("Belum ada outlet aktif di server. Minta admin menambahkannya di web, lalu coba lagi.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            TextButton({ outletTry++ }) { Text("Coba lagi", fontWeight = FontWeight.SemiBold) }
                        } else {
                            Text("Outlet", fontWeight = FontWeight.SemiBold)
                            o.value.forEach { ou ->
                                Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                                    RadioButton(chosen?.id == ou.id, {
                                        if (chosen?.id != ou.id) { chosen = ou; picked = null; typedName = ""; error = null }
                                    }, enabled = !busy)
                                    Text(ou.name, fontSize = 17.sp)
                                }
                            }
                        }
                    }
                }

                if (SetupRules.showTerminalField(chosen)) {
                    Spacer(Modifier.height(14.dp))
                    Text("Terminal", fontWeight = FontWeight.SemiBold)
                    when (val t = terminals) {
                        is Load.Idle, is Load.Loading -> Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                            CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp); Spacer(Modifier.width(12.dp)); Text("Memuat terminal…")
                        }
                        is Load.Failed -> ErrorState(t.error.message, onRetry = { terminalTry++ }, modifier = Modifier.fillMaxWidth())
                        is Load.Ready -> {
                            t.value.forEach { te ->
                                Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                                    RadioButton(picked?.id == te.id && typedName.isBlank(), { picked = te; typedName = ""; error = null }, enabled = !busy)
                                    Text(te.name, fontSize = 17.sp)
                                }
                            }
                            OutlinedTextField(
                                typedName, { typedName = it; error = null }, Modifier.fillMaxWidth().padding(top = 8.dp),
                                label = { Text(if (t.value.isEmpty()) "Nama terminal (mis. Kasir Depan)" else "Atau ketik nama terminal baru") },
                                singleLine = true, enabled = !busy,
                            )
                        }
                    }
                }

                error?.let { Text(it, Modifier.padding(top = 12.dp), color = MaterialTheme.colorScheme.error, fontSize = 14.sp) }
                Spacer(Modifier.height(18.dp))
                if (busy) CircularProgressIndicator(Modifier.align(Alignment.CenterHorizontally))
                else {
                    PrimaryPill("Lanjut", {
                        val o = chosen ?: return@PrimaryPill
                        busy = true; error = null
                        scope.launch {
                            try { onDone(backend.registerTerminal(o, SetupRules.effectiveName(typedName, picked))) }
                            catch (e: ApiError) { error = e.message } finally { busy = false }
                        }
                    }, Modifier.fillMaxWidth(), enabled = terminals is Load.Ready && SetupRules.canSubmit(chosen, typedName, picked), icon = Icons.Filled.Check)
                    onCancel?.let { TextButton(it, Modifier.align(Alignment.CenterHorizontally).padding(top = 8.dp)) { Text("Batal") } }
                }
            }
        }
    }
}

/** 065 — pesan jelas saat alamat server tidak bisa ditemukan; tanpa kolom ketik sebagai jalan keluar (keputusan pemilik). */
@Composable
private fun DiscoveryProblem(message: String, retry: (() -> Unit)?) {
    Column(Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
        Text(message, color = MaterialTheme.colorScheme.error, fontSize = 15.sp)
        retry?.let { TextButton(it, Modifier.padding(top = 4.dp)) { Text("Coba lagi", fontWeight = androidx.compose.ui.text.font.FontWeight.SemiBold) } }
    }
}
