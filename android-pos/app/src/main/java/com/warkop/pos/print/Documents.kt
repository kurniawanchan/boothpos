package com.warkop.pos.print

import com.warkop.pos.model.Order
import com.warkop.pos.model.PrintableItem
import com.warkop.pos.model.PrintableKot
import com.warkop.pos.model.ReceiptView
import com.warkop.pos.model.rp

/**
 * 050 / T013, T023, T028, T029, T037 — isi setiap dokumen cetak sebagai daftar [PrintLine] (contracts/print-documents.md
 * Part A). Murni dan tanpa Android, jadi isinya diuji langsung. Aturan yang dijaga di sini:
 *  - KOT dan BATAL tidak memuat harga (FR-014).
 *  - Struk tidak memuat telepon atau e-mail pelanggan (FR-015).
 *  - Angka struk dari server apa adanya; layout hanya memformat, tidak menghitung (Constitution IV).
 */
object Documents {
    private const val COLS = EscPos.COLUMNS

    private fun separator(): PrintLine = PrintLine(text = "-".repeat(COLS))

    /** Tes cetak: nama toko, waktu, dan baris contoh yang tepat [COLS] karakter (quickstart V1, V2). */
    fun testSlip(nowText: String): List<PrintLine> = listOf(
        PrintLine(text = "Tes cetak", align = PrintLine.Align.CENTER, bold = true),
        PrintLine(text = "Warkop POS", align = PrintLine.Align.CENTER),
        PrintLine(text = nowText, align = PrintLine.Align.CENTER),
        separator(),
        PrintLine(text = "ABCDEFGHIJKLMNOPQRSTUVWXYZ012345"),
        separator(),
        PrintLine(text = "Printer siap dipakai", align = PrintLine.Align.CENTER),
    )

    /** KOT: satu tiket per stasiun. Judul KOT besar dan kapital agar dapur mengenalinya (FR-026). Tanpa harga. */
    fun kot(k: PrintableKot, cashier: String?): List<PrintLine> = buildList {
        add(PrintLine(text = "KOT", align = PrintLine.Align.CENTER, bold = true, big = true))
        add(PrintLine(text = k.stationName ?: "Tanpa stasiun", align = PrintLine.Align.CENTER, bold = true))
        add(PrintLine(text = k.tableName ?: k.tabLabel ?: "-", right = k.orderNumber ?: ""))
        add(PrintLine(text = "Ronde ${k.roundNo}   ${k.sentAtText}"))
        cashier?.takeIf { it.isNotBlank() }?.let { add(PrintLine(text = "Dibuat oleh $it")) }
        add(separator())
        k.items.forEach { addItem(it) }
        add(separator())
    }

    /**
     * BATAL: item yang sudah dikirim lalu dibatalkan. Judul BATAL besar dan kapital (FR-026). Tanpa harga.
     * [station] null bila data tab tidak memuatnya (tab tidak membawa stasiun; lihat tasks.md catatan US2): baris
     * stasiun dilewati, bukan diisi tebakan.
     */
    fun batal(station: String?, table: String?, orderNumber: String?, item: PrintableItem, reason: String?): List<PrintLine> = buildList {
        add(PrintLine(text = "BATAL", align = PrintLine.Align.CENTER, bold = true, big = true))
        station?.let { add(PrintLine(text = it, align = PrintLine.Align.CENTER, bold = true)) }
        add(PrintLine(text = table ?: "-", right = orderNumber ?: ""))
        add(separator())
        add(PrintLine(text = "BATAL ${item.qty} x ${item.name}", bold = true))
        reason?.takeIf { it.isNotBlank() }?.let { add(PrintLine(text = "   Alasan: $it")) }
        add(separator())
    }

    /**
     * Struk pelanggan dari data server ([ReceiptView]). Semua angka diformat dari server; tidak ada aritmetika di sini.
     * Telepon dan e-mail PELANGGAN tidak pernah masuk ke [ReceiptView] (ReceiptMapper). Kontak yang tercetak adalah
     * kontak TOKO (059), dari pengaturan admin.
     */
    fun struk(r: ReceiptView, logo: PrintLine? = null): List<PrintLine> = buildList {
        // 059 — logo di atas nama toko; tanpa logo (dimatikan, gagal dimuat) susunannya tetap seperti semula.
        logo?.let { add(it) }
        add(PrintLine(text = r.storeName, align = PrintLine.Align.CENTER, bold = true))
        r.storeAddress?.let { add(PrintLine(text = it, align = PrintLine.Align.CENTER)) }
        // 059 — kontak toko (bukan pelanggan); setiap baris hanya bila terisi.
        r.storeContactPerson?.let { add(PrintLine(text = it, align = PrintLine.Align.CENTER)) }
        r.storeContactPhone?.let { add(PrintLine(text = it, align = PrintLine.Align.CENTER)) }
        r.storeContactEmail?.let { add(PrintLine(text = it, align = PrintLine.Align.CENTER)) }
        // 079 — catatan outlet; tiap baris sendiri supaya pembungkus kertas 58 mm tidak memotong teks.
        r.storeNotes?.lines()?.map { it.trim() }?.filter { it.isNotEmpty() }?.forEach { add(PrintLine(text = it, align = PrintLine.Align.CENTER)) }
        add(separator())
        add(PrintLine(text = "No. struk", right = r.receiptNo ?: r.orderNumber))
        r.paidAtText?.let { add(PrintLine(text = "Tanggal", right = it)) }
        r.cashier?.let { add(PrintLine(text = "Kasir", right = it)) }
        r.queueNo?.let { add(PrintLine(text = "Antrean", right = it)) }
        // 064 — nama terminal yang mengambil order (null bila tanpa terminal). SENGAJA satu baris kiri ("Terminal: nama"),
        // bukan pasangan kiri/kanan: pada pasangan, nama panjang mendorong sisi kiri menjadi 1 huruf per baris dan baris
        // melebihi 32 kolom TM-58V. Baris kiri tunggal dibungkus apa adanya oleh EscPos.wrap.
        r.terminalName?.let { add(PrintLine(text = "Terminal: $it")) }
        add(separator())
        r.items.forEach { line ->
            add(PrintLine(text = "${line.qty} x ${line.name}", right = rp(line.lineTotal)))
            line.note?.let { add(PrintLine(text = "   Catatan: $it")) }
        }
        add(separator())
        add(PrintLine(text = "Subtotal", right = rp(r.subtotal)))
        if (r.discount > 0) add(PrintLine(text = "Diskon", right = "-" + rp(r.discount)))
        add(PrintLine(text = "${r.taxLabel} ${r.taxRatePercent}%", right = rp(r.tax)))
        add(PrintLine(text = "TOTAL", right = rp(r.total), bold = true))
        add(separator())
        r.payments.forEach { (method, amount) -> add(PrintLine(text = method, right = rp(amount))) }
        add(PrintLine(text = "Bayar", right = rp(r.paid)))
        if (r.change > 0) add(PrintLine(text = "Kembali", right = rp(r.change)))
        if (r.copyNo > 1) add(PrintLine(text = "SALINAN ${r.copyNo}", align = PrintLine.Align.CENTER, bold = true))
    }

    /**
     * Pra-bill (contracts A3): tagihan sementara dengan tanda BELUM LUNAS. Tanpa baris pembayaran atau kembalian.
     * Angka dari server (line total dan total tab); tidak dihitung ulang.
     */
    fun prebill(o: Order): List<PrintLine> = buildList {
        add(PrintLine(text = "PRA-BILL", align = PrintLine.Align.CENTER, bold = true, big = true))
        add(PrintLine(text = "BELUM LUNAS", align = PrintLine.Align.CENTER, bold = true))
        add(PrintLine(text = o.table ?: "Counter", right = o.queue ?: ""))
        add(separator())
        o.live.forEach { line -> // baris batal tidak ikut tercetak
            add(PrintLine(text = "${line.qty} x ${line.name}", right = rp(line.lineTotal ?: line.net)))
            line.mods.forEach { add(PrintLine(text = "   ${it.name}")) }
            line.note.takeIf { it.isNotBlank() }?.let { add(PrintLine(text = "   Catatan: $it")) }
        }
        add(separator())
        add(PrintLine(text = "TOTAL", right = rp(o.total), bold = true))
        add(PrintLine(text = "Sisa tagihan", right = rp(o.remaining)))
    }

    /** Baris satu item KOT/BATAL: "qty x nama", lalu modifier dan catatan menjorok. */
    private fun MutableList<PrintLine>.addItem(item: PrintableItem) {
        add(PrintLine(text = "${item.qty} x ${item.name}", bold = true))
        item.modifiers.forEach { add(PrintLine(text = "   $it")) }
        item.note?.let { add(PrintLine(text = "   Catatan: $it")) }
    }
}
