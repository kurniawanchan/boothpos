package com.warkop.pos.state

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.warkop.core.domain.ApiError
import com.warkop.pos.data.PosBackend
import com.warkop.pos.data.PosSession
import com.warkop.pos.data.RemoteBackend
import com.warkop.pos.data.TerminalConfig
import com.warkop.pos.model.*
import com.warkop.core.network.IntentKeys
import com.warkop.core.network.retryNetwork
import com.warkop.pos.data.OrderMapper
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

enum class Dest { SETUP, PIN, OPEN_SHIFT, TABLES, EDITOR, TENDER, DONE, ORDERS, SHIFT, XREPORT, CLOSE_SHIFT, CLOSE_RESULT, SYNC }
enum class Conn(val label: String) { ONLINE("Online"), OFFLINE("Offline"), OFFLINE_LIMITED("Offline terbatas") }

/** Lembar/dialog di atas layar. Satu pada satu waktu — sama seperti prototipe (satu `sheet` aktif). */
sealed interface Sheet {
    data class Pax(val table: String) : Sheet
    data class Modifier(val product: Product, val replaceLineId: Int? = null) : Sheet
    data class LineActions(val lineId: Int) : Sheet
    data object OrderActions : Sheet
    data object Split : Sheet
    data object Discount : Sheet
    data class Void(val lineId: Int?) : Sheet // null = batalkan seluruh order
    data class Override(val summary: String, val minDiscount: Int? = null, val onApproved: () -> Unit) : Sheet
    data object Edc : Sheet
    data object QrisRecord : Sheet
    data class QrisVerify(val tenderId: Int) : Sheet
    data class Receipt(val orderId: String) : Sheet
    /** Kas masuk ([inflow] = true) atau keluar; arah menentukan daftar alasan dan teks ambang. */
    data class CashOut(val inflow: Boolean) : Sheet
    data class Confirm(val title: String, val body: String, val confirm: String, val onConfirm: () -> Unit) : Sheet
}

/** Keadaan satu sumber data: belum dimuat / memuat / siap / GAGAL (bukan "kosong") — kontrak galat di android-client.md. */
sealed interface Load<out T> {
    data object Idle : Load<Nothing>
    data object Loading : Load<Nothing>
    data class Ready<T>(val value: T) : Load<T>
    data class Failed(val error: ApiError) : Load<Nothing>
}

class PosStore(
    private val backend: PosBackend? = null,
    private val remote: RemoteBackend? = null,
) : ViewModel() {
    var dest by mutableStateOf(Dest.PIN); private set
    /** Terminal yang sudah disiapkan (URL + outlet + perangkat); null → layar penyiapan. */
    var config by mutableStateOf<TerminalConfig?>(null); private set
    var session by mutableStateOf<PosSession?>(null); private set
    var roster by mutableStateOf<Load<List<StaffUser>>>(Load.Idle); private set
    var user by mutableStateOf<StaffUser?>(null); private set
    var shift by mutableStateOf<Shift?>(null); private set
    var conn by mutableStateOf(Conn.ONLINE)
    /** null = otomatis (malam hari gelap); dipakai preset debug untuk memaksa tema. */
    var darkOverride by mutableStateOf<Boolean?>(null)
    var ordersTab by mutableIntStateOf(0)
    var orders by mutableStateOf<List<Order>>(emptyList()); private set
    var activeId by mutableStateOf<String?>(null); private set
    var sheet by mutableStateOf<Sheet?>(null); private set
    var toast by mutableStateOf<String?>(null); private set
    var pinFails by mutableIntStateOf(0); private set
    var lockedUntil by mutableStateOf<Long?>(null); private set
    // Antrean sinkron & cetak (status perangkat). Saat online, event langsung dianggap terkirim; saat offline menumpuk
    // di antrean — sampai outbox sungguhan (api-gap B16) dibangun, ini hanya cermin tampilan, bukan penyimpan data.
    data class OutboxEvent(val title: String, val type: String, val at: String, val synced: Boolean)
    data class PrintJob(val title: String, val at: String, val ok: Boolean)
    var outbox by mutableStateOf<List<OutboxEvent>>(emptyList()); private set
    var printJobs by mutableStateOf<List<PrintJob>>(emptyList()); private set
    private fun emit(title: String, type: String) { outbox = outbox + OutboxEvent(title, type, clock(), conn == Conn.ONLINE) }
    private fun printed(title: String) { printJobs = printJobs + PrintJob(title, clock(), true) }
    fun syncNow(): Boolean {
        if (conn != Conn.ONLINE) return false
        outbox = outbox.map { it.copy(synced = true) }; return true
    }
    val pendingEvents: List<OutboxEvent> get() = outbox.filter { !it.synced }

    private var lineSeq = 100
    private var tenderSeq = 1
    private var counterSeq = 23
    private var receiptSeq = 124
    private var closedCount = 0

    val active: Order? get() = orders.firstOrNull { it.id == activeId }
    /** Tab terbuka: yang sudah dibuka di terminal ini, ditambah tab lain dari denah (ringkasan sampai dimuat penuh). */
    val openOrders: List<Order> get() {
        val mine = orders.filter { !it.closed }
        val known = mine.map { it.id }.toSet()
        val fromFloor = (floor as? Load.Ready)?.value?.let { s -> s.takeaway + s.tables.mapNotNull { it.tab } }.orEmpty()
        val others = fromFloor.filter { it.id.toString() !in known }.map { OrderMapper.stub(it) }
        return (mine + others).sortedBy { it.id.toLongOrNull() ?: 0L }
    }
    val closedOrders: List<Order> get() =
        (closedCache + orders.filter { it.closed }).distinctBy { it.id }.sortedByDescending { it.id.toLongOrNull() ?: 0L }

    // ---------- awal & sesi ----------
    /** Dipanggil sekali saat aplikasi mulai: terminal belum disiapkan → penyiapan; sudah → PIN dengan roster dimuat. */
    fun bootstrap() {
        val saved = remote?.savedConfig()
        config = saved
        if (saved == null) { dest = Dest.SETUP; return }
        if (dest == Dest.SETUP) dest = Dest.PIN
        loadRoster()
    }

    /** Roster staf dari server. Gagal = keadaan galat dengan Coba lagi, BUKAN daftar kosong. */
    fun loadRoster(silent: Boolean = false) {
        val b = backend ?: return
        // `silent`: muat ulang tanpa mengganti daftar dengan 'memuat' (dipakai setelah PIN salah agar pilihan staf tak hilang).
        if (!(silent && roster is Load.Ready)) roster = Load.Loading
        viewModelScope.launch {
            val fresh = try { Load.Ready(b.roster()) } catch (e: ApiError) { if (silent && roster is Load.Ready) roster else Load.Failed(e) }
            roster = fresh
        }
    }

    /** Hanya untuk layar penyiapan terminal. */
    internal val backendForSetup: PosBackend? get() = backend

    internal fun setConfig(c: TerminalConfig) { config = c; dest = Dest.PIN; loadRoster() }

    /** Hanya untuk preset pembandingan visual (src/debug); tidak ada jalur produk yang memanggilnya. */
    internal fun debugInstall(
        user: StaffUser?, shift: Shift?, orders: List<Order>, activeId: String?, dest: Dest, sheet: Sheet?, conn: Conn,
        dark: Boolean?, ordersTab: Int, closeResult: CloseOutcome?,
    ) {
        this.user = user; this.shift = shift; this.orders = orders; this.activeId = activeId; this.dest = dest; this.sheet = sheet
        this.conn = conn; this.darkOverride = dark; this.ordersTab = ordersTab; this.closeResult = closeResult; this.toast = null
    }

    // ---------- navigasi & umpan balik ----------
    fun go(d: Dest) { dest = d; sheet = null }
    fun show(s: Sheet?) { sheet = s }
    fun say(msg: String) { toast = msg }
    fun clearToast() { toast = null }
    fun lock() {
        availabilityJob?.cancel()
        floorJob?.cancel(); floor = Load.Idle; closedCache = emptyList(); keys.clear()
        session = null; user = null; shift = null; activeId = null; catalog = Load.Idle; soldOut = emptySet(); dest = Dest.PIN; sheet = null
        report = Load.Idle; cashMovements = Load.Idle; closeResult = null; limits = null
        // Daftar staf dimuat SETELAH logout selesai: sebelum itu token sesi lama masih terpasang dan server menolaknya (401).
        viewModelScope.launch { try { backend?.logout() } catch (_: ApiError) { }; loadRoster() }
    }

    // ---------- PIN (server) ----------
    /** Pesan galat login PIN untuk layar PIN: salah (sisa percobaan), terkunci, jaringan. */
    fun loginPin(staff: StaffUser, pin: String, onFailure: (String) -> Unit) {
        val b = backend ?: return
        viewModelScope.launch {
            try {
                val s = b.pinLogin(staff, pin)
                session = s
                user = s.user
                shift = try { b.currentShift() } catch (_: ApiError) { null }
                dest = when {
                    s.user.code == "CSH" && shift == null -> Dest.OPEN_SHIFT
                    // Tidak membuka tab otomatis saat login: tab kosong yang terbuka akan menghalangi tutup shift (409 open_tabs).
                    // Counter dibuka hanya saat kasir menekan tombol order baru.
                    s.user.code == "CSH" -> Dest.TABLES
                    s.user.code == "WTR" -> Dest.TABLES
                    else -> Dest.ORDERS
                }
                loadMenu()
                startAvailabilityPolling()
                startFloorPolling()
                loadLimits()
            } catch (e: ApiError) {
                onFailure(pinMessage(e))
                if (e is ApiError.TooMany || e is ApiError.Unauthorized) loadRoster(silent = true)
            }
        }
    }

    private fun pinMessage(e: ApiError): String = when {
        e is ApiError.Unauthorized && e.code == "invalid_pin" -> e.attemptsRemaining?.let { "PIN salah. Sisa $it percobaan." } ?: "PIN salah."
        e is ApiError.TooMany && e.code == "pin_locked" -> "Login dikunci 15 menit karena PIN salah 5 kali."
        else -> e.message
    }

    // ---------- menu & ketersediaan ----------
    var catalog by mutableStateOf<Load<MenuCatalog>>(Load.Idle); private set
    var soldOut by mutableStateOf<Set<String>>(emptySet()); private set
    private var availabilityEtag: String? = null
    private var availabilityJob: kotlinx.coroutines.Job? = null

    val menu: MenuCatalog? get() = (catalog as? Load.Ready)?.value

    fun loadMenu() {
        val b = backend ?: return
        catalog = Load.Loading
        viewModelScope.launch {
            catalog = try { Load.Ready(b.loadMenu()) } catch (e: ApiError) { Load.Failed(e) }
        }
    }

    /** Poll ±5 dtk selama ada sesi (SC-008); 304 = tak berubah. Gagal sesaat diabaikan: poll berikutnya mencoba lagi. */
    private fun startAvailabilityPolling() {
        val b = backend ?: return
        availabilityJob?.cancel()
        availabilityEtag = null
        availabilityJob = viewModelScope.launch {
            while (true) {
                try {
                    b.availability(availabilityEtag)?.let { a -> availabilityEtag = a.etag; soldOut = a.soldOutVariantIds }
                } catch (_: ApiError) { /* poll berikutnya */ }
                kotlinx.coroutines.delay(5_000)
            }
        }
    }

    // ---------- shift ----------
    /** Buka shift dengan rincian pecahan kas awal (server menurunkan jumlahnya). Kosong = modal Rp 0. */
    fun openShift(denominations: Map<Int, Int>) {
        val b = backend ?: return
        val rows = denominations.filter { it.value > 0 }.map { it.key to it.value }
        val modal = rows.sumOf { it.first * it.second }
        viewModelScope.launch {
            try {
                shift = b.openShift(rows)
                report = Load.Idle; cashMovements = Load.Idle
                say("Shift dibuka dengan modal ${rp(modal)}.")
                go(Dest.TABLES)
            } catch (e: ApiError) {
                // Koneksi putus SETELAH server menerima: periksa dulu agar tidak membuka shift kedua.
                if (e is ApiError.Network) {
                    conn = Conn.OFFLINE
                    val opened = try { b.currentShift() } catch (_: ApiError) { null }
                    if (opened != null) {
                        shift = opened; go(Dest.TABLES)
                        say("Shift sudah terbuka di server.")
                        return@launch
                    }
                }
                say(e.message)
            }
        }
    }

    // ---------- pesanan di server (048 / S1: US2) ----------
    // Semua tulis pesanan lewat server (Konstitusi IV). Angka di layar diganti jawaban server; tidak ada hitung uang
    // otoritatif di sini. Kunci per niat (IntentKeys) dipakai ulang saat coba-ulang sehingga tidak ada ganda.
    var floor by mutableStateOf<Load<FloorSnapshot>>(Load.Idle); private set
    var closedCache by mutableStateOf<List<Order>>(emptyList()); private set
    private var floorVersion: String? = null
    private var floorJob: Job? = null
    private val keys = IntentKeys()

    /** Meja (dengan tab bila ada) dari denah terakhir. */
    val tables: List<FloorTable> get() = (floor as? Load.Ready)?.value?.tables.orEmpty()

    /** Fitur yang belum tersambung ke server (cerita berikutnya). Tidak mengubah data lokal. */
    private fun notWired(what: String) {
        say("$what belum tersambung ke server — dikerjakan di cerita berikutnya.")
        sheet = null
    }

    /** Denah diperbarui tiap 3 dtk (penanda `version`; tak berubah = tanpa beban). */
    fun startFloorPolling() {
        val b = backend ?: return
        floorJob?.cancel()
        if (floor !is Load.Ready) floor = Load.Loading
        floorJob = viewModelScope.launch {
            while (true) {
                try {
                    b.floor(floorVersion)?.let { snap -> floorVersion = snap.version; floor = Load.Ready(snap) }
                    conn = Conn.ONLINE
                } catch (e: ApiError) {
                    if (e is ApiError.Network) conn = Conn.OFFLINE
                    if (floor !is Load.Ready) floor = Load.Failed(e)
                }
                delay(3_000)
            }
        }
    }

    private fun upsert(o: Order) { orders = orders.filter { it.id != o.id } + o }

    /**
     * Satu niat tulis ke server. Gangguan jaringan dicoba ulang dengan kunci yang sama (server mengembalikan jawaban
     * tersimpan); galat server ditampilkan apa adanya. Kunci dilepas hanya bila niat terkonfirmasi.
     */
    private fun send(intent: String, action: suspend () -> Order, retry: Boolean = true, after: (Order) -> Unit = {}) {
        viewModelScope.launch {
            try {
                val o = if (retry) retryNetwork(block = action) else action()
                keys.settled(intent)
                conn = Conn.ONLINE
                upsert(o)
                after(o)
            } catch (e: ApiError) {
                if (e is ApiError.Network) conn = Conn.OFFLINE
                say(writeErrorText(e))
            }
        }
    }

    /** Server menolak tulis tab karena shift tidak terbuka: pesan khusus (pembayaran ditolak), bukan galat umum. */
    private fun writeErrorText(e: ApiError): String =
        if (e is ApiError.Conflict && e.code == "shift_required") "Pembayaran ditolak: shift sudah ditutup. Buka shift untuk menerima pembayaran."
        else e.message

    fun newCounter() {
        val b = backend ?: return
        val sid = shift?.id ?: run { say("Buka shift dulu sebelum membuka order."); return }
        val intent = "open:counter"
        val key = keys.keyFor(intent)
        send(intent, { b.openTab(sid, null, null, key) }) { o -> activeId = o.id; dest = Dest.EDITOR; sheet = null }
    }

    /** Pilih jumlah tamu: tab terbuka di meja itu dibuka; meja kosong membuka tab baru. */
    fun openTable(table: String, pax: Int) {
        val b = backend ?: return
        val ft = tables.firstOrNull { it.name == table } ?: run { say("Meja $table tidak ditemukan."); return }
        ft.tab?.let { openOrder(it.id.toString()); sheet = null; return }
        val sid = shift?.id ?: run { say("Buka shift dulu sebelum membuka order."); return }
        val intent = "open:table:${ft.id}:$pax"
        val key = keys.keyFor(intent)
        send(intent, { b.openTab(sid, ft.id, pax, key) }) { o -> activeId = o.id; dest = Dest.EDITOR; sheet = null }
    }

    fun openOrder(id: String) {
        val b = backend ?: return
        viewModelScope.launch {
            try {
                val o = retryNetwork { b.tab(id) }
                upsert(o)
                activeId = o.id
                dest = if (o.hasPendingQris) Dest.TENDER else Dest.EDITOR
            } catch (e: ApiError) { say(e.message) }
        }
    }

    fun tapProduct(p: Product) {
        if (p.id in soldOut) { say("${p.name} sedang habis."); return }
        if (p.groups.isEmpty()) addLine(p, emptyList(), "") else sheet = Sheet.Modifier(p)
    }

    /** Tambah baris ke tab aktif. Server menggabungkan baris identik (belum terkirim) sesuai aturannya sendiri. */
    fun addLine(p: Product, mods: List<ModOption>, note: String, qty: Int = 1) {
        val b = backend ?: return
        val tab = activeId ?: return
        val intent = "line:add:$tab:${p.id}:$qty:${mods.map { it.id }}:${note.trim()}"
        val key = keys.keyFor(intent)
        sheet = null
        send(intent, { b.addLine(tab, p.id, qty, note.trim(), mods.map { it.id }, key) })
    }

    private fun updateLine(tabId: String, line: OrderLine, qty: Int, note: String) {
        val b = backend ?: return
        val intent = "line:patch:$tabId:${line.id}:$qty:$note"
        val key = keys.keyFor(intent)
        send(intent, { b.updateLine(tabId, line.id, qty, note, key) })
    }

    fun qtyDelta(lineId: Int, d: Int) {
        val o = active ?: return
        val line = o.lines.firstOrNull { it.id == lineId } ?: return
        if (line.fired && d < 0) { say("Mengurangi jumlah dilakukan lewat void."); return }
        val next = line.qty + d
        if (next < 1) { removeLine(lineId); return }
        if (next > 999) return
        updateLine(o.id, line, next, line.note)
    }

    fun removeLine(lineId: Int) {
        val b = backend ?: return
        val o = active ?: return
        val line = o.lines.firstOrNull { it.id == lineId } ?: return
        sheet = null
        if (line.fired) { say("Item sudah dikirim ke dapur — batalkan lewat void."); return }
        val intent = "line:del:${o.id}:$lineId"
        val key = keys.keyFor(intent)
        send(intent, { b.removeLine(o.id, lineId, key) })
    }

    /** Simpan jumlah dan catatan baris. Diskon item menunggu cerita diskon (5). */
    fun saveLine(lineId: Int, qty: Int, note: String, discPct: Int) {
        val o = active ?: return
        val line = o.lines.firstOrNull { it.id == lineId } ?: return
        sheet = null
        if (discPct != line.discPct) say("Diskon item belum tersambung ke server.")
        if (qty == line.qty && note.trim() == line.note.trim()) return
        if (line.fired) { say("Item sudah dikirim — perubahan lewat void."); return }
        updateLine(o.id, line, qty, note.trim())
    }

    fun voidLine(lineId: Int, reason: String, made: Boolean) = notWired("Void item")

    fun cancelOrder(reason: String) = notWired("Batalkan order")

    /** Kirim semua baris belum terkirim ke dapur. Kunci mengikuti himpunan baris, jadi item baru = niat baru. */
    fun fire() {
        val b = backend ?: return
        val o = active ?: return
        val n = o.unsent.size
        if (n == 0) return
        val intent = "send:${o.id}:${o.unsent.map { it.id }.sorted()}"
        val key = keys.keyFor(intent)
        send(intent, { b.sendToKitchen(o.id, key) }) { _ ->
            emit("${o.label} · $n item", "order.fired")
            say("$n item dikirim ke dapur. Cetak KOT belum tersedia.")
        }
    }

    /** Pra-bill: penanda di server (tanpa cetak fisik). Tidak diulang otomatis agar hitungan cetak tidak ganda. */
    fun printPrebill() {
        val b = backend ?: return
        val o = active ?: return
        sheet = null
        send("prebill:${o.id}", { b.prebill(o.id) }, retry = false) { _ ->
            say("Pra-bill ditandai · BELUM LUNAS. Cetak fisik belum tersedia.")
        }
    }

    fun applyOrderDiscount(p: DiscountPreset?) = notWired("Diskon order")

    fun splitByItem(lineIds: Set<Int>) = notWired("Bagi bill")

    fun goPay() {
        val o = active ?: return
        if (user?.code == "WTR") { printPrebill(); return }
        if (o.live.isEmpty()) return
        if (shift == null) {
            sheet = Sheet.Confirm("Belum ada shift terbuka", "Pembayaran hanya bisa diterima setelah shift terbuka. Order tetap tersimpan.", "Buka shift") { dest = Dest.OPEN_SHIFT; sheet = null }
            return
        }
        dest = Dest.TENDER
    }

    /** Tunai: server menghitung kembalian sendiri. Pembayaran ber-`client_ref`, aman diulang. */
    fun payCash(received: Int) {
        val b = backend ?: return
        val o = active ?: return
        if (received <= 0) return
        val intent = "pay:${o.id}:cash:$received"
        val ref = keys.keyFor(intent)
        send(intent, { b.pay(o.id, "cash", received, ref) }) { o2 ->
            if (o2.closed) {
                emit("${o2.receiptNo.orEmpty()} · ${rp(o2.total)}", "payment.recorded")
                dest = Dest.DONE
            } else {
                say("Sisa ${rp(o2.remaining)}.")
            }
        }
    }

    fun payEdc(code: String): Boolean { notWired("Pembayaran EDC"); return false }

    fun recordQris() = notWired("QRIS")

    fun verifyQris(tenderId: Int, ref: String) = notWired("Verifikasi QRIS")

    fun failQris(tenderId: Int) = notWired("Verifikasi QRIS")

    fun reprint(orderId: String) = notWired("Cetak ulang struk")

    fun refund(orderId: String) = notWired("Refund")

    fun approve(pin: String, approver: StaffUser): String? { notWired("Persetujuan"); return null }

    /** Daftar penjualan selesai dari server (layar Order → Selesai). */
    fun loadClosed() {
        val b = backend ?: return
        viewModelScope.launch {
            try { closedCache = retryNetwork { b.closedOrders() } } catch (e: ApiError) { say(e.message) }
        }
    }

    // ---------- kas & shift (048 / S2: US3) ----------
    // Semua angka shift berasal dari server (X-report, daftar kas, jawaban tutup). Tidak ada hitungan kas di klien:
    // layar yang belum berhasil memuat menampilkan keadaan memuat/galat, bukan angka lama.
    var report by mutableStateOf<Load<ShiftReport>>(Load.Idle); private set
    var cashMovements by mutableStateOf<Load<List<CashMovement>>>(Load.Idle); private set
    var closeResult by mutableStateOf<CloseOutcome?>(null); private set
    /** Batas dari pengaturan server; null sampai terbaca. Layar tidak memakai angka tetap sebagai gantinya. */
    var limits by mutableStateOf<ShiftLimits?>(null); private set

    /** Batas toleransi dan ambang kas keluar. Gagal dimuat = tetap null (teks umum di layar). */
    fun loadLimits() {
        val b = backend ?: return
        viewModelScope.launch { limits = try { b.shiftLimits() } catch (_: ApiError) { null } }
    }

    /** X-report shift terbuka. Gagal = keadaan galat dengan Coba lagi. */
    fun refreshShiftReport() {
        val b = backend ?: return
        val sid = shift?.id ?: return
        report = Load.Loading
        viewModelScope.launch {
            report = try { Load.Ready(retryNetwork { b.shiftReport(sid) }) } catch (e: ApiError) { Load.Failed(e) }
        }
    }

    fun refreshCashMovements() {
        val b = backend ?: return
        val sid = shift?.id ?: return
        cashMovements = Load.Loading
        viewModelScope.launch {
            cashMovements = try { Load.Ready(retryNetwork { b.cashMovements(sid) }) } catch (e: ApiError) { Load.Failed(e) }
        }
    }

    /**
     * Kas masuk/keluar lewat server. Kunci per niat (arah, alasan, jumlah, catatan): coba-ulang jaringan memakai kunci
     * yang sama sehingga tidak tercatat dua kali. Jawaban galat server (mis. override_required) melepas kunci dan
     * membiarkan lembar terbuka: kasir mengubah isian, bukan menekan ulang.
     */
    fun cashMove(inflow: Boolean, reason: String, note: String?, amount: Int) {
        val b = backend ?: return
        val sid = shift?.id ?: run { say("Buka shift dulu sebelum mencatat kas."); return }
        val intent = "cash:$sid:${if (inflow) "in" else "out"}:$reason:$amount:${note.orEmpty()}"
        val key = keys.keyFor(intent)
        viewModelScope.launch {
            try {
                retryNetwork { b.recordCashMovement(sid, inflow, reason, note, amount, key) }
                keys.settled(intent)
                conn = Conn.ONLINE
                sheet = null
                say("${if (inflow) "Kas masuk" else "Kas keluar"} ${rp(amount)} dicatat.")
                refreshCashMovements(); refreshShiftReport()
            } catch (e: ApiError) {
                if (e is ApiError.Network) conn = Conn.OFFLINE else keys.settled(intent)
                say(e.message)
            }
        }
    }

    /**
     * Tutup shift dengan hitungan buta. Server yang menjumlahkan dan menilai selisih. Penutupan tidak idempoten di
     * server, jadi tidak ada coba-ulang otomatis: jika koneksi putus, status dianggap belum diketahui.
     */
    fun submitCount(counts: Map<Int, Int>, edcBatch: Int?) {
        val b = backend ?: return
        val sid = shift?.id ?: return
        val rows = counts.filter { it.value > 0 }.map { it.key to it.value }
        // Satu kunci per niat tutup: coba-ulang jaringan memakai kunci yang sama, server menjawab dengan hasil tersimpan.
        val intent = "close:$sid"
        val key = keys.keyFor(intent)
        viewModelScope.launch {
            try {
                val outcome = retryNetwork { b.closeShift(sid, rows, edcBatch, key) }
                keys.settled(intent)
                conn = Conn.ONLINE
                closeResult = outcome
                report = Load.Idle
                sheet = null
                dest = Dest.CLOSE_RESULT
            } catch (e: ApiError) {
                if (e is ApiError.Network) {
                    conn = Conn.OFFLINE
                    say("Koneksi terputus. Kirim hitungan lagi; server tidak akan menutup dua kali.")
                } else if (e is ApiError.Conflict) {
                    keys.settled(intent)
                    // Penutupan pertama mungkin sudah diterima server (jawaban hilang di jalan): cek status dulu.
                    val stillOpen = try { b.currentShift() != null } catch (_: ApiError) { true }
                    if (stillOpen) {
                        say(e.message)
                    } else {
                        shift = null; report = Load.Idle; cashMovements = Load.Idle; sheet = null
                        go(Dest.SHIFT)
                        say("Shift sudah tertutup di server. Angka penutupan ada di back office.")
                    }
                } else {
                    say(e.message)
                }
            }
        }
    }

    fun hasPendingPayment() = orders.any { !it.closed && it.hasPendingQris }

    fun finishShift() { shift = null; closeResult = null; report = Load.Idle; cashMovements = Load.Idle; lock() }

    private fun clock(): String = SimpleDateFormat("HH:mm", Locale.US).format(Date())
}
