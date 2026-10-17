package com.warkop.pos.ui.screens

import android.Manifest
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.print.PrintError
import com.warkop.pos.print.PrintJob
import com.warkop.pos.print.PrintKind
import com.warkop.pos.print.PrintStatus
import com.warkop.pos.print.PrinterChoice
import com.warkop.pos.print.PrinterService
import com.warkop.pos.print.PrinterStatus
import com.warkop.pos.print.printErrorMessage
import com.warkop.pos.ui.Pill
import com.warkop.pos.ui.OutlinedPill
import com.warkop.pos.ui.PrimaryPill
import com.warkop.pos.ui.SectionLabel
import com.warkop.pos.ui.TonalPill

/**
 * 050 / T020, T021, T045 — panel printer di layar Perangkat. Hanya memanggil [PrinterService]; tidak ada logika cetak
 * di sini. Semua teks Indonesia, warna dari tema (tanpa hex).
 */
@Composable
fun PrinterPanel(printer: PrinterService) {
    val status by printer.status.collectAsState()
    val jobs by printer.jobs.collectAsState()
    var choices by remember { mutableStateOf<List<PrinterChoice>?>(null) }
    var askedForPermission by remember { mutableStateOf(false) }
    var testSent by remember { mutableStateOf(false) }

    // Android 12+ memerlukan izin runtime untuk daftar dan koneksi Bluetooth. Diminta saat pengguna memilih printer,
    // bukan saat aplikasi dibuka, supaya penjualan tidak terganggu dialog izin.
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        askedForPermission = true
        choices = if (granted) printer.bondedPrinters() else null
    }

    SectionLabel("Printer Bluetooth")
    Spacer(Modifier.height(6.dp))
    Text(statusLabel(status), fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
    Text(statusNote(status), color = MaterialTheme.colorScheme.onSurfaceVariant, fontSize = 14.sp)
    Spacer(Modifier.height(12.dp))

    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        TonalPill("Pilih printer", {
            val needsPermission = Build.VERSION.SDK_INT >= Build.VERSION_CODES.S
            if (!needsPermission) {
                choices = printer.bondedPrinters()
            } else {
                permission.launch(Manifest.permission.BLUETOOTH_CONNECT)
            }
        })
        PrimaryPill("Tes cetak", { testSent = printer.testPrint() }, enabled = status !is PrinterStatus.NotConfigured)
        if (status !is PrinterStatus.NotConfigured) OutlinedPill("Lupakan printer", { printer.forget(); choices = null })
    }
    if (testSent) Text("Tes cetak masuk antrean.", Modifier.padding(top = 8.dp), color = MaterialTheme.colorScheme.onSurfaceVariant, fontSize = 14.sp)

    // Daftar perangkat yang sudah dipasangkan di Android.
    val list = choices
    if (list != null) {
        Spacer(Modifier.height(14.dp))
        if (list.isEmpty()) {
            Text("Belum ada printer yang dipasangkan. Pasangkan dulu lewat pengaturan Bluetooth Android.", color = MaterialTheme.colorScheme.onSurfaceVariant)
        } else {
            list.forEach { choice ->
                Row(
                    Modifier.fillMaxWidth().clickable { printer.choose(choice); choices = null }.padding(vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(choice.name, fontWeight = FontWeight.SemiBold)
                    Spacer(Modifier.width(10.dp))
                    Text(choice.address, color = MaterialTheme.colorScheme.onSurfaceVariant, fontSize = 13.sp)
                }
            }
        }
    } else if (askedForPermission && status is PrinterStatus.Error) {
        Text("Izin Bluetooth diperlukan untuk memilih printer.", Modifier.padding(top = 8.dp), color = MaterialTheme.colorScheme.error)
    }

    Spacer(Modifier.height(22.dp))
    PrintQueueSection(printer, jobs)
}

/** Antrean cetak (FR-018, FR-019, FR-020, FR-025): hanya yang belum selesai, dengan Coba lagi dan Tutup. */
@Composable
private fun PrintQueueSection(printer: PrinterService, jobs: List<PrintJob>) {
    SectionLabel("Antrean cetak")
    Spacer(Modifier.height(6.dp))
    val open = jobs.filter { it.status == PrintStatus.PENDING || it.status == PrintStatus.FAILED }
    if (open.isEmpty()) {
        Text("Antrean kosong", color = MaterialTheme.colorScheme.onSurfaceVariant)
        return
    }
    var dismissing by remember { mutableStateOf<PrintJob?>(null) }
    open.forEach { job ->
        Column(Modifier.fillMaxWidth().padding(vertical = 8.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(kindLabel(job.kind), fontWeight = FontWeight.SemiBold)
                Spacer(Modifier.width(10.dp))
                Pill(jobWord(job.status), MaterialTheme.colorScheme.secondaryContainer, MaterialTheme.colorScheme.onSecondaryContainer)
            }
            job.lastError?.let { Text(printErrorMessage(it), color = MaterialTheme.colorScheme.error, fontSize = 14.sp) }
            Row(Modifier.padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                TonalPill("Coba lagi", { printer.retry(job.key) })
                OutlinedPill("Tutup", {
                    // KOT wajib alasan (FR-020); dokumen lain langsung ditutup.
                    if (job.kind == PrintKind.KOT) dismissing = job else printer.dismiss(job.key, null)
                })
            }
        }
    }

    dismissing?.let { job ->
        DismissKotDialog(
            onConfirm = { reason -> printer.dismiss(job.key, reason); dismissing = null },
            onCancel = { dismissing = null },
        )
    }
}

@Composable
private fun DismissKotDialog(onConfirm: (String) -> Unit, onCancel: () -> Unit) {
    var reason by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text("Tutup KOT yang belum tercetak") },
        text = {
            Column {
                Text("Tulis alasannya, misalnya sudah diberitahukan langsung ke dapur.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                OutlinedTextField(value = reason, onValueChange = { reason = it }, label = { Text("Alasan") }, modifier = Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            TonalPill("Tutup KOT", { onConfirm(reason.trim()) }, enabled = reason.isNotBlank())
        },
        dismissButton = { OutlinedPill("Batal", onCancel) },
    )
}

private fun statusLabel(status: PrinterStatus): String = when (status) {
    PrinterStatus.NotConfigured -> "Belum dipasang"
    PrinterStatus.Disconnected -> "Tidak terhubung"
    PrinterStatus.Connected -> "Terhubung"
    is PrinterStatus.Error -> printErrorMessage(status.error)
}

private fun statusNote(status: PrinterStatus): String = when (status) {
    PrinterStatus.NotConfigured -> "Pilih printer VSC TM-58V (kertas 58 mm) yang sudah dipasangkan di Bluetooth."
    PrinterStatus.Disconnected -> "Printer dipilih tetapi belum tersambung. Cetakan tetap masuk antrean."
    PrinterStatus.Connected -> "Printer siap. Struk dan KOT tercetak otomatis."
    is PrinterStatus.Error -> "Cetakan yang gagal tetap ada di antrean dan bisa dicoba lagi."
}

private fun kindLabel(kind: PrintKind): String = when (kind) {
    PrintKind.STRUK -> "Struk"
    PrintKind.KOT -> "KOT"
    PrintKind.PREBILL -> "Pra-bill"
    PrintKind.BATAL -> "BATAL"
}

/** FR-025: setiap status dijelaskan dengan kata biasa. */
private fun jobWord(status: PrintStatus): String = when (status) {
    PrintStatus.PENDING -> "Belum tercetak"
    PrintStatus.FAILED -> "Gagal"
    PrintStatus.PRINTED -> "Tercetak"
    PrintStatus.DISMISSED -> "Ditutup"
}
