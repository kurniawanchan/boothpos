package com.warkop.pos.print

import kotlinx.serialization.Serializable

/**
 * 050 / T010 — satu dokumen yang akan/sudah dicetak (data-model.md). Waktu disimpan sebagai epoch milidetik agar
 * file antrean sederhana dan tidak bergantung pada format tanggal.
 */
@Serializable
data class PrintJob(
    val key: String,
    val kind: PrintKind,
    val refId: String,
    val copyNo: Int,
    val status: PrintStatus = PrintStatus.PENDING,
    val attempts: Int = 0,
    val lastError: PrintError? = null,
    val createdAtMillis: Long,
    val printedAtMillis: Long? = null,
    val dismissReason: String? = null,
    /**
     * Baris dokumen yang sudah dirender saat dibuat (KOT, BATAL, pra-bill, dan struk yang sudah punya data).
     * Null hanya untuk STRUK, yang mengambil struk dari server saat dicetak (research D2).
     */
    val lines: List<PrintLine>? = null,
    /** Tab asal (KOT, BATAL, pra-bill). Dipakai "Cetak ulang KOT" untuk mencari tiket milik tab yang sama. */
    val tabId: String? = null,
)

/** 084 — LAPORAN = X-report / Z-report shift (baris sudah dirender, tanpa ambil dari server saat cetak). */
@Serializable enum class PrintKind { STRUK, KOT, PREBILL, BATAL, LAPORAN }

/** PENDING = "Belum tercetak"; FAILED = "Gagal"; PRINTED = "Tercetak"; DISMISSED = ditutup pengguna. */
@Serializable enum class PrintStatus { PENDING, FAILED, PRINTED, DISMISSED }

/** Kode galat tetap; pesan Indonesia dipetakan di PrinterService (contracts Part C), bukan teks eksepsi. */
@Serializable enum class PrintError { NOT_CONFIGURED, PERMISSION_DENIED, BLUETOOTH_OFF, OUT_OF_RANGE, PAPER_OUT, WRITE_ERROR }

/** Kunci identitas dokumen (FR-007): satu dokumen fisik = satu kunci. */
fun jobKey(kind: PrintKind, refId: String, copyNo: Int): String = "${kind.name.lowercase()}:$refId:$copyNo"
