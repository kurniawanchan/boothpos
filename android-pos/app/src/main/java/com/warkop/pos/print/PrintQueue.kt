package com.warkop.pos.print

import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.Json
import java.io.File
import java.io.IOException
import java.nio.file.Files
import java.nio.file.StandardCopyOption

/**
 * 050 / T011 — antrean cetak yang tahan restart (research D6). Satu file JSON, ditulis atomik (tulis sementara lalu
 * ganti). Kunci yang sudah PRINTED atau DISMISSED tidak pernah diterima ulang: itulah aturan "cetak sekali" (FR-007,
 * FR-019). File yang rusak dipindahkan ke samping, bukan membuat aplikasi berhenti.
 */
class PrintQueue(private val file: File, private val now: () -> Long = System::currentTimeMillis) {
    private val lock = Any()
    private val jobs = LinkedHashMap<String, PrintJob>()
    private val json = Json { ignoreUnknownKeys = true }
    private val listSerializer = ListSerializer(PrintJob.serializer())

    init { load() }

    /** true bila dokumen baru masuk antrean; false bila kunci sudah ada (dicetak, ditutup, atau sedang menunggu). */
    fun enqueue(kind: PrintKind, refId: String, copyNo: Int, lines: List<PrintLine>? = null, tabId: String? = null): Boolean {
        synchronized(lock) {
            val key = jobKey(kind, refId, copyNo)
            if (jobs.containsKey(key)) return false
            jobs[key] = PrintJob(key = key, kind = kind, refId = refId, copyNo = copyNo, createdAtMillis = now(), lines = lines, tabId = tabId)
            persist()
            return true
        }
    }

    fun get(key: String): PrintJob? = synchronized(lock) { jobs[key] }

    /** Semua job, terbaru dahulu. */
    fun all(): List<PrintJob> = synchronized(lock) { jobs.values.sortedByDescending { it.createdAtMillis } }

    /** Job yang belum selesai (PENDING atau FAILED), urut antrean lama dulu agar dicetak berurutan. */
    fun open(): List<PrintJob> = synchronized(lock) {
        jobs.values.filter { it.status == PrintStatus.PENDING || it.status == PrintStatus.FAILED }.sortedBy { it.createdAtMillis }
    }

    fun markAttempt(key: String) = update(key) { it.copy(attempts = it.attempts + 1) }

    fun markPrinted(key: String) = update(key) {
        it.copy(status = PrintStatus.PRINTED, lastError = null, printedAtMillis = now())
    }

    fun markFailed(key: String, error: PrintError) = update(key) {
        it.copy(status = PrintStatus.FAILED, lastError = error)
    }

    fun dismiss(key: String, reason: String?) = update(key) {
        it.copy(status = PrintStatus.DISMISSED, dismissReason = reason)
    }

    private fun update(key: String, change: (PrintJob) -> PrintJob) {
        synchronized(lock) {
            val current = jobs[key] ?: return
            jobs[key] = change(current)
            persist()
        }
    }

    private fun load() {
        if (!file.exists()) return
        try {
            val loaded = json.decodeFromString(listSerializer, file.readText())
            loaded.forEach { jobs[it.key] = it }
        } catch (e: Exception) {
            // Jangan hapus diam-diam: simpan dulu untuk diperiksa, lalu mulai dari antrean kosong.
            val aside = File(file.parentFile, "print-queue.corrupt-${now()}.json")
            file.renameTo(aside)
            jobs.clear()
        }
    }

    private fun persist() {
        val tmp = File(file.parentFile, file.name + ".tmp")
        try {
            tmp.writeText(json.encodeToString(listSerializer, jobs.values.toList()))
            Files.move(tmp.toPath(), file.toPath(), StandardCopyOption.REPLACE_EXISTING)
        } catch (e: IOException) {
            // Antrean tetap ada di memori; penulisan berikutnya mencoba lagi. Galat tidak boleh menggagalkan penjualan.
            tmp.delete()
        }
    }
}
