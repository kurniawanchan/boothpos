package com.warkop.pos.print

import android.annotation.SuppressLint
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothManager
import android.bluetooth.BluetoothSocket
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.content.ContextCompat
import com.warkop.pos.data.OrderMapper
import com.warkop.pos.model.PrintableItem
import com.warkop.pos.model.PrintableKot
import com.warkop.pos.model.ReceiptView
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.runInterruptible
import kotlinx.coroutines.withTimeoutOrNull
import java.io.IOException
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.util.UUID

/** Keadaan link printer untuk layar Perangkat (FR-001). Diturunkan dari percobaan terakhir, tidak disimpan. */
sealed interface PrinterStatus {
    data object NotConfigured : PrinterStatus
    data object Disconnected : PrinterStatus
    data object Connected : PrinterStatus
    data class Error(val error: PrintError) : PrinterStatus
}

/** Perangkat yang sudah dipasangkan di Android, untuk daftar pilihan. */
data class PrinterChoice(val name: String, val address: String)

/**
 * 050 / T014–T016, T042–T044 — SATU-SATUNYA kode yang menulis ke printer (Constitution I, research D6).
 *
 * Alur: setiap pencetakan masuk [PrintQueue] dulu (agar bertahan jika aplikasi mati), lalu satu worker berurutan
 * mencetak job PENDING/FAILED satu per satu. Worker berhenti di kegagalan pertama supaya urutan tetap dan job lain
 * tidak gagal beruntun ke printer yang sama. Pencetakan tidak pernah menggagalkan penjualan: pemanggil hanya
 * memasukkan job, tidak menunggu kertas.
 */
class PrinterService(
    private val context: Context,
    private val queue: PrintQueue,
    private val settings: PrinterSettings,
    /** Mengambil struk dari server saat job STRUK dicetak (research D2). */
    private val fetchReceipt: suspend (orderId: String) -> ReceiptView,
    scope: CoroutineScope,
) {
    private val wake = Channel<Unit>(Channel.CONFLATED)
    private val _status = MutableStateFlow<PrinterStatus>(initialStatus())
    val status: StateFlow<PrinterStatus> = _status.asStateFlow()

    private val _jobs = MutableStateFlow(queue.all())
    /** Isi antrean untuk layar; diperbarui setiap kali antrean berubah. */
    val jobs: StateFlow<List<PrintJob>> = _jobs.asStateFlow()

    init {
        scope.launch(Dispatchers.IO) { for (ignored in wake) drain() }
    }

    // --- pemasangan (US1) ---

    /** Daftar perangkat yang sudah dipasangkan. null = izin Bluetooth belum diberikan (Android 12+). */
    @SuppressLint("MissingPermission")
    fun bondedPrinters(): List<PrinterChoice>? {
        if (!hasConnectPermission()) return null
        val adapter = adapter() ?: return emptyList()
        return try {
            adapter.bondedDevices.orEmpty().map { PrinterChoice(it.name ?: it.address, it.address) }.sortedBy { it.name }
        } catch (e: SecurityException) {
            null
        }
    }

    fun choose(choice: PrinterChoice) {
        settings.save(PrinterConfig(name = choice.name, address = choice.address))
        _status.value = PrinterStatus.Disconnected
    }

    fun forget() {
        settings.clear()
        _status.value = PrinterStatus.NotConfigured
    }

    /** Pengecekan koneksi sekali saat aplikasi dibuka (FR-004). Tidak mencetak apa pun. */
    suspend fun checkConnection() {
        val config = settings.load()
        if (config == null) {
            _status.value = PrinterStatus.NotConfigured
            return
        }
        val socket = openSocket(config.address)
        _status.value = if (socket == null) PrinterStatus.Disconnected else PrinterStatus.Connected
        socket?.let { runCatching { it.close() } }
    }

    /** Tes cetak (quickstart V1, V2): baris contoh dengan lebar kertas yang sebenarnya. */
    fun testPrint(): Boolean {
        val now = ZonedDateTime.now(OrderMapper.STORE_ZONE).format(DateTimeFormatter.ofPattern("dd/MM/yyyy HH:mm"))
        return enqueueRendered(PrintKind.STRUK, "test:${System.currentTimeMillis()}", 1, Documents.testSlip(now))
    }

    // --- antrean (US2–US4) ---

    /** Satu job KOT per tiket dapur (FR-006). Kunci = id tiket, sehingga kirim ulang tidak mencetak dua kali. */
    fun enqueueKot(kots: List<PrintableKot>, cashier: String?, tabId: String) {
        var added = false
        kots.forEach { k ->
            if (queue.enqueue(PrintKind.KOT, k.ticketId.toString(), 1, Documents.kot(k, cashier), tabId)) added = true
        }
        if (added) afterQueueChange()
    }

    /**
     * Cetak ulang KOT untuk satu tab: setiap tiket KOT tab itu dicetak lagi dengan salinan berikutnya. Isi diambil dari
     * job yang sudah tersimpan (tidak memanggil server; research/open item 1). Mengembalikan jumlah job yang masuk antrean.
     */
    fun reprintKot(tabId: String): Int {
        val kots = queue.all().filter { it.kind == PrintKind.KOT && it.tabId == tabId && it.lines != null }
        val tickets = kots.groupBy { it.refId }.mapValues { (_, jobs) -> jobs.maxBy { it.copyNo } }
        var queued = 0
        tickets.forEach { (ticketId, latest) ->
            val nextCopy = (kots.filter { it.refId == ticketId }.maxOfOrNull { it.copyNo } ?: 1) + 1
            if (queue.enqueue(PrintKind.KOT, ticketId, nextCopy, latest.lines, tabId)) queued++
        }
        if (queued > 0) afterQueueChange()
        return queued
    }

    /**
     * BATAL untuk satu baris yang sudah dikirim. Kunci = tab + id baris: satu baris hanya bisa di-void sekali, jadi
     * tidak ada dua slip untuk pembatalan yang sama.
     */
    fun enqueueBatal(tabId: String, lineId: Int, station: String?, table: String?, orderNumber: String?, item: PrintableItem, reason: String?) {
        val lines = Documents.batal(station, table, orderNumber, item, reason)
        if (queue.enqueue(PrintKind.BATAL, "$tabId:$lineId", 1, lines)) afterQueueChange()
    }

    /** Struk pelanggan. Data diambil dari server saat dicetak; [copyNo] dari server (copy 1 = asli). */
    fun enqueueStruk(orderId: String, copyNo: Int) {
        if (queue.enqueue(PrintKind.STRUK, orderId, copyNo)) afterQueueChange()
    }

    /** Dokumen yang sudah dirender (pra-bill, tes cetak). Baris disimpan di job agar bertahan restart. */
    fun enqueueRendered(kind: PrintKind, refId: String, copyNo: Int, lines: List<PrintLine>, tabId: String? = null): Boolean {
        val added = queue.enqueue(kind, refId, copyNo, lines, tabId)
        if (added) afterQueueChange()
        return added
    }

    /** Coba lagi satu job. Job PRINTED tidak pernah dikirim ulang (FR-019). */
    fun retry(key: String) {
        val job = queue.get(key) ?: return
        if (job.status == PrintStatus.FAILED || job.status == PrintStatus.PENDING) wake.trySend(Unit)
    }

    fun retryAll() {
        if (queue.open().isNotEmpty()) wake.trySend(Unit)
    }

    /** Tutup satu job. KOT wajib alasan (FR-020); dokumen lain tidak. */
    fun dismiss(key: String, reason: String?): Boolean {
        val job = queue.get(key) ?: return false
        if (job.kind == PrintKind.KOT && reason.isNullOrBlank()) return false
        queue.dismiss(key, reason?.trim()?.takeIf { it.isNotEmpty() })
        refresh()
        return true
    }

    private fun afterQueueChange() {
        refresh()
        wake.trySend(Unit)
    }

    private fun refresh() {
        _jobs.value = queue.all()
    }

    // --- pencetakan ---

    private suspend fun drain() {
        for (job in queue.open()) {
            val error = printOne(job)
            if (error == null) {
                queue.markPrinted(job.key)
                refresh()
                _status.value = PrinterStatus.Connected
                continue
            }
            queue.markFailed(job.key, error)
            refresh()
            _status.value = PrinterStatus.Error(error)
            return // urutan dijaga: job berikutnya menunggu printer pulih
        }
    }

    /** null = berhasil dicetak; selain itu kode galat. */
    private suspend fun printOne(job: PrintJob): PrintError? {
        queue.markAttempt(job.key)
        refresh()
        val config = settings.load() ?: return PrintError.NOT_CONFIGURED
        if (!hasConnectPermission()) return PrintError.PERMISSION_DENIED
        val bluetooth = adapter() ?: return PrintError.BLUETOOTH_OFF
        if (!bluetooth.isEnabled) return PrintError.BLUETOOTH_OFF

        val lines: List<PrintLine> = job.lines ?: try {
            // Label "SALINAN n" mengikuti nomor salinan yang dicatat server saat job dibuat, bukan nilai receipt saat ini.
            Documents.struk(fetchReceipt(job.refId).copy(copyNo = job.copyNo))
        } catch (e: Exception) {
            // Gagal mengambil struk dari server: dicoba ulang nanti. Pesan generik; detail hanya ke log.
            return PrintError.WRITE_ERROR
        }
        val bytes = EscPos.render(lines)

        val socket = openSocket(config.address) ?: return PrintError.OUT_OF_RANGE
        return try {
            if (paperOut(socket)) return PrintError.PAPER_OUT
            socket.outputStream.write(bytes)
            socket.outputStream.flush()
            null
        } catch (e: IOException) {
            PrintError.WRITE_ERROR
        } finally {
            runCatching { socket.close() }
        }
    }

    /**
     * Status kertas lewat DLE EOT. UNVERIFIED pada VSC TM-58V: bila printer tidak menjawab, dianggap ada kertas dan
     * kegagalan sebenarnya muncul sebagai WRITE_ERROR. Diuji pada perangkat (quickstart V9).
     */
    private suspend fun paperOut(socket: BluetoothSocket): Boolean {
        return try {
            socket.outputStream.write(byteArrayOf(0x10, 0x04, 0x04))
            socket.outputStream.flush()
            val answer = withTimeoutOrNull(500) {
                runInterruptible(Dispatchers.IO) {
                    while (socket.inputStream.available() == 0) Thread.sleep(20)
                    socket.inputStream.read()
                }
            } ?: return false
            answer and 0x20 != 0
        } catch (e: IOException) {
            false
        }
    }

    /** Koneksi dengan batas waktu 5 dtk (contracts Part B). null = tidak tersambung. */
    @SuppressLint("MissingPermission")
    private suspend fun openSocket(address: String): BluetoothSocket? {
        if (!hasConnectPermission()) return null
        val adapter = adapter() ?: return null
        if (!adapter.isEnabled) return null
        val device: BluetoothDevice = try {
            adapter.getRemoteDevice(address)
        } catch (e: IllegalArgumentException) {
            return null
        }
        val socket = try {
            device.createRfcommSocketToServiceRecord(SPP_UUID)
        } catch (e: IOException) {
            return null
        }
        val connected = withTimeoutOrNull(CONNECT_TIMEOUT_MS) {
            runInterruptible(Dispatchers.IO) { socket.connect() }
            true
        }
        if (connected == true) return socket
        // Batas waktu habis atau gagal: tutup soket agar connect yang macet ikut berhenti.
        runCatching { socket.close() }
        return null
    }

    private fun adapter() = context.getSystemService(BluetoothManager::class.java)?.adapter

    private fun hasConnectPermission(): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.S ||
            ContextCompat.checkSelfPermission(context, android.Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED

    private fun initialStatus(): PrinterStatus = if (settings.load() == null) PrinterStatus.NotConfigured else PrinterStatus.Disconnected

    private companion object {
        /** Serial Port Profile — UUID standar untuk printer Bluetooth klasik (UNVERIFIED pada TM-58V, research D4). */
        val SPP_UUID: UUID = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")
        const val CONNECT_TIMEOUT_MS = 5_000L
    }
}

/** 050 / T042 — pesan Indonesia untuk tiap galat (contracts Part C). Tidak pernah memuat teks eksepsi. */
fun printErrorMessage(error: PrintError): String = when (error) {
    PrintError.NOT_CONFIGURED -> "Printer belum dipasang"
    PrintError.PERMISSION_DENIED -> "Izin Bluetooth diperlukan"
    PrintError.BLUETOOTH_OFF -> "Bluetooth mati"
    PrintError.OUT_OF_RANGE -> "Printer di luar jangkauan"
    PrintError.PAPER_OUT -> "Kertas habis"
    PrintError.WRITE_ERROR -> "Gagal mencetak, coba lagi"
}
