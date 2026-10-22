package com.warkop.pos.state

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.warkop.core.domain.ApiError
import com.warkop.pos.audio.QueueAnnouncer
import com.warkop.pos.audio.QueuePhrase
import com.warkop.pos.audio.SilentSpeech
import com.warkop.pos.data.PosBackend
import com.warkop.pos.data.PosSession
import com.warkop.pos.data.RemoteBackend
import com.warkop.pos.model.PrintableItem
import com.warkop.pos.print.Documents
import com.warkop.pos.print.PrintKind
import com.warkop.pos.print.PrintStatus
import com.warkop.pos.print.PrinterService
import com.warkop.pos.print.PrinterStatus
import com.warkop.pos.print.jobKey
import com.warkop.pos.data.QrisMapper
import com.warkop.pos.data.TaxDisplay
import com.warkop.pos.data.TerminalConfig
import com.warkop.pos.model.*
import com.warkop.core.network.IntentKeys
import com.warkop.core.network.retryNetwork
import com.warkop.pos.data.OrderMapper
import com.warkop.pos.data.ShiftMapper
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.addJsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonArray
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

enum class Dest { SETUP, PIN, OPEN_SHIFT, TABLES, EDITOR, TENDER, DONE, ORDERS, SHIFT, XREPORT, CLOSE_SHIFT, CLOSE_RESULT, SYNC }

/**
 * Layar setelah shift dimuat ulang dari server (GET /sessions/current): kasir tanpa shift terbuka → layar buka shift,
 * aturan yang sama dengan login. Di luar itu null = biarkan layar apa adanya.
 */
/** 058 / T006 — langkah setelah pembayaran QRIS tercatat: penjualan tutup → struk dan selesai; masih sisa → tampilkan sisa. */
internal sealed interface QrisNext {
    data object Finish : QrisNext
    data class ShowRemaining(val remaining: Int) : QrisNext
}

internal fun qrisAfterPayment(closed: Boolean, remaining: Int): QrisNext =
    if (closed) QrisNext.Finish else QrisNext.ShowRemaining(remaining)

internal fun shiftRecoveryDest(current: Shift?, roleCode: String?): Dest? =
    if (current == null && roleCode == "CSH") Dest.OPEN_SHIFT else null

/** Subjek persetujuan diskon (048 / S4): tab, lingkup, baris, persentase, dan alasan — apa adanya; server menghitung hash-nya. */
internal fun discountSubject(tabId: Long, scope: String, lineId: Int?, percent: Double, reasonCode: String): JsonObject = buildJsonObject {
    put("tab_id", tabId); put("scope", scope); lineId?.let { put("line_id", it) }; put("percent", percent); put("reason_code", reasonCode)
}

/** Subjek persetujuan cetak ulang salinan ke-[copyNo] (048 / S4). */
internal fun reprintSubject(orderId: Long, copyNo: Int): JsonObject = buildJsonObject {
    put("order_id", orderId); put("copy_no", copyNo)
}

/** Subjek persetujuan refund penuh (048 / S4): order, alasan, dan catatan bila ada. */
internal fun refundSubject(orderId: Long, reasonCode: String, reason: String?): JsonObject = buildJsonObject {
    put("order_id", orderId); put("reason_code", reasonCode); reason?.let { put("reason", it) }
}
/** 054 — UNKNOWN = belum ada pemeriksaan yang berhasil atau gagal; tidak boleh tampil sebagai "Online". */
enum class Conn(val label: String) { UNKNOWN("Memeriksa koneksi…"), ONLINE("Online"), OFFLINE("Offline"), OFFLINE_LIMITED("Offline terbatas") }

private const val PIN_PROBE_INTERVAL_MS = 10_000L

/** Lembar/dialog di atas layar. Satu pada satu waktu — sama seperti prototipe (satu `sheet` aktif). */
sealed interface Sheet {
    data class Pax(val table: String) : Sheet
    data class Modifier(val product: Product, val replaceLineId: Int? = null) : Sheet
    data class LineActions(val lineId: Int) : Sheet
    data object OrderActions : Sheet
    data object Split : Sheet
    /** 048 / S4 — diskon order ([lineId] null) atau satu baris (lineId = id baris server). */
    data class Discount(val lineId: Int? = null) : Sheet
    /** 048 / S4 — refund penuh order lunas: pilih alasan dari server dan catatan. */
    data class Refund(val orderId: String) : Sheet
    data class Void(val lineId: Int?) : Sheet // null = batalkan seluruh order
    /**
     * 048 / S3 — persetujuan satu aksi. [subject] = parameter aksi apa adanya (server yang menghitung hash-nya);
     * [onApproved] menjalankan aksi lagi dengan token sekali pakai yang diterima.
     */
    data class Override(val summary: String, val action: String, val subject: JsonObject, val onApproved: (token: String) -> Unit) : Sheet
    /** 86: pilih alasan dari server sebelum item ditandai habis. */
    data class SoldOut(val product: Product) : Sheet
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
    /** 050 — printer untuk KOT/struk/pra-bill. null pada build uji tanpa printer; penjualan tidak bergantung padanya. */
    val printer: PrinterService? = null,
    /** 054 — menulis URL server yang dipilih admin ke penyimpanan terminal. Bisa diganti pada uji (TokenVault butuh Context). */
    private val onServerUrl: (String) -> Unit = { url -> remote?.adoptServerUrl(url) },
    /** 055 / T037 — pilihan tampil/sembunyi total; penyimpanan perangkat di Android, in-memory di uji. */
    private val totalsPref: TotalsPreference = TotalsPreference({ null }, {}),
    /** 061 / T011 — suara panggilan antrean; default diam supaya uji tidak butuh mesin ucap. */
    private val announcer: QueueAnnouncer = QueueAnnouncer(SilentSpeech),
) : ViewModel() {
    /** 055 / T037 — area total (Subtotal, pajak, Total, Kirim, Bayar) tampil atau tersembunyi. */
    var totalsVisible by mutableStateOf(totalsPref.visible); private set
    fun changeTotalsVisibility(v: Boolean) { totalsVisible = v; totalsPref.visible = v }

    /** 055 / T036 — label dan tarif pajak dari server; null sampai dimuat. */
    var tax by mutableStateOf<TaxDisplay?>(null); private set

    /** 055 / T026 — true selama tarik-untuk-segarkan berjalan. */
    var refreshing by mutableStateOf(false); private set
    fun refreshCounter() {
        refreshing = true
        viewModelScope.launch { try { loadMenu(silent = true).join() } finally { refreshing = false } }
    }

    /**
     * 055 / T028–T031 — tarik-untuk-segarkan untuk layar yang tampil sekarang. Setiap layar memuat ulang datanya sendiri;
     * polling denah tetap berjalan. Perangkat (SYNC) memeriksa ulang printer. Indikator selesai saat muatan selesai.
     */
    fun refreshCurrent() {
        val job: Job = when (dest) {
            Dest.TABLES -> fetchFloorNow()
            Dest.ORDERS -> loadClosed()
            Dest.SHIFT, Dest.XREPORT, Dest.CLOSE_SHIFT -> refreshShiftReport()
            Dest.SYNC -> viewModelScope.launch { printer?.checkConnection() }
            else -> Job().apply { complete() }
        }
        refreshing = true
        viewModelScope.launch { try { job.join() } finally { refreshing = false } }
    }

    /** Satu tarikan denah sekarang; polling 3 dtk tetap jalan dan galat sesaat tidak menghapus denah terakhir. */
    private fun fetchFloorNow(): Job = viewModelScope.launch {
        val b = backend ?: return@launch
        try {
            b.floor(null)?.let { snap -> floorVersion = snap.version; floor = Load.Ready(snap) }
            conn = Conn.ONLINE
        } catch (e: ApiError) {
            if (e is ApiError.Network) conn = Conn.OFFLINE
            if (floor !is Load.Ready) floor = Load.Failed(e)
        }
    }
    var dest by mutableStateOf(Dest.PIN); private set
    /** Terminal yang sudah disiapkan (URL + outlet + perangkat); null → layar penyiapan. */
    var config by mutableStateOf<TerminalConfig?>(null); private set
    var session by mutableStateOf<PosSession?>(null); private set
    var roster by mutableStateOf<Load<List<StaffUser>>>(Load.Idle); private set
    var user by mutableStateOf<StaffUser?>(null); private set
    var shift by mutableStateOf<Shift?>(null); private set
    /** 054 — awalnya UNKNOWN: dulu ONLINE, sehingga layar masuk selalu menampilkan "Online" walau Wi-Fi mati. */
    var conn by mutableStateOf(Conn.UNKNOWN)
    /** null = otomatis (malam hari gelap); dipakai preset debug untuk memaksa tema. */
    var darkOverride by mutableStateOf<Boolean?>(null)
    var ordersTab by mutableIntStateOf(0)
    var orders by mutableStateOf<List<Order>>(emptyList()); private set
    var activeId by mutableStateOf<String?>(null); private set
    var sheet by mutableStateOf<Sheet?>(null); private set
    var toast by mutableStateOf<String?>(null); private set
    var pinFails by mutableIntStateOf(0); private set
    var lockedUntil by mutableStateOf<Long?>(null); private set
    // 048 / US6 — keadaan dari POST /devices/heartbeat. Tidak ada antrean atau cetak tiruan: yang tampil hanya angka server.
    var heartbeat by mutableStateOf(HeartbeatState()); private set
    private val heartbeatWorker = HeartbeatWorker(viewModelScope, tick = { heartbeatOnce() })

    /** Dipanggil saat aplikasi masuk ke latar depan. */
    fun startHeartbeat() = heartbeatWorker.start()

    /** Dipanggil saat aplikasi ke latar belakang. */
    fun stopHeartbeat() = heartbeatWorker.stop()

    /** Heartbeat segera: sesudah masuk, atau saat kasir menekan "Periksa koneksi". */
    fun heartbeatNow() = heartbeatWorker.now()

    /** Satu heartbeat. Tanpa sesi (belum masuk) tidak dikirim. Kegagalan tidak pernah dilempar ke UI. */
    private suspend fun heartbeatOnce() {
        val b = backend ?: return
        if (session == null) return
        try {
            // Antrean lokal tidak ada (online-first): queue_depth dilaporkan 0.
            val result = b.heartbeat(System.currentTimeMillis(), queueDepth = 0)
            heartbeat = heartbeat.onSuccess(result, System.currentTimeMillis())
            // 064 — tablet yang dipasang sebelum fitur nama-saja belum tahu namanya; heartbeat mengisinya (RemoteBackend
            // sudah menyimpannya di brankas), dan ganti-nama dari admin ikut terbaca.
            result.terminalName?.let { name -> config?.takeIf { it.deviceName != name }?.let { config = it.copy(deviceName = name) } }
            conn = Conn.ONLINE
        } catch (e: ApiError) {
            heartbeat = heartbeat.onFailure()
            if (e is ApiError.Network) conn = Conn.OFFLINE
        }
    }

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
    /** Dipanggil sekali saat aplikasi mulai: selalu langsung ke layar PIN, membaca alamat server dari vault. */
    fun bootstrap() {
        val saved = remote?.savedConfig()
        config = saved
        // 050 / T066 — selalu langsung ke PIN; jika belum ada konfigurasi, serverUrl
        // diambil dari vault (atau nilai default bila ada), roster tetap dimuat.
        dest = Dest.PIN
        loadRoster()
    }

    /**
     * Roster staf dari server. Gagal = keadaan galat dengan Coba lagi, BUKAN daftar kosong.
     * 054 / US2: roster juga satu-satunya sumber status koneksi di layar PIN (belum ada sesi, jadi heartbeat tidak
     * berjalan). Hanya galat jaringan yang berarti OFFLINE; 401/5xx membuktikan server terjangkau (FR-006).
     */
    fun loadRoster(silent: Boolean = false): Job {
        val b = backend ?: return Job().apply { complete() }
        // `silent`: muat ulang tanpa mengganti daftar dengan 'memuat' (dipakai setelah PIN salah dan oleh probe PIN agar tidak berkedip).
        if (!silent) roster = Load.Loading
        return viewModelScope.launch {
            val fresh = try {
                Load.Ready(b.roster()).also { conn = Conn.ONLINE }
            } catch (e: ApiError) {
                // 054 / FR-006 — hanya galat jaringan yang berarti tidak terjangkau (OFFLINE).
                // Galat lain (401/403/404/5xx) membuktikan server menjawab, jadi terjangkau (ONLINE).
                if (e is ApiError.Network) conn = Conn.OFFLINE else conn = Conn.ONLINE
                if (silent && roster is Load.Ready) roster else Load.Failed(e)
            }
            roster = fresh
        }
    }

    private var pinProbe: Job? = null

    /** 054 / US2 — selama layar PIN tampil, muat roster diam-diam tiap [PIN_PROBE_INTERVAL_MS] agar chip mengikuti jaringan. */
    fun startPinProbe() {
        if (pinProbe?.isActive == true) return
        pinProbe = viewModelScope.launch {
            while (true) {
                // Tunggu hasil roster dulu: status koneksi dan roster baru terisi setelah panggilan selesai.
                loadRoster(silent = true).join()
                adoptSelectedServer()
                delay(PIN_PROBE_INTERVAL_MS)
            }
        }
    }

    /**
     * 054 / US3 — hanya setelah roster berhasil (opsi A, research.md R3): tanya server alamat yang dipilih admin
     * dan pindah bila berbeda. Galat jaringan atau tanpa koneksi berarti tidak ada yang dipindah.
     */
    private suspend fun adoptSelectedServer() {
        if (roster !is Load.Ready || conn != Conn.ONLINE) return
        val current = config ?: return
        val url = backend?.serverEndpoint()?.url ?: return
        if (url == current.serverUrl) return
        // 054 / opsi A yang diperketat: jangan memindahkan tablet ke alamat yang belum terbukti
        // terjangkau. Admin boleh salah mengetik alamat lokal/online (atau jaringan ke alamat itu
        // sedang mati); kalau kita pindah buta, layar setelah login gagal dengan "Tidak dapat
        // menjangkau server". Uji (roster) pada alamat kandidat dulu; kalau gagal, tetap di
        // alamat lama yang memang terjangkau.
        val savedUrl = backend?.serverUrl()
        var adopted = false
        try {
            backend?.setServerUrl(url)
            backend?.roster()
            onServerUrl(url)
            config = current.copy(serverUrl = url)
            adopted = true
        } catch (t: Throwable) {
            // Pembatalan coroutine harus diteruskan agar struktur pembatalan tetap benar.
            if (t is kotlinx.coroutines.CancellationException) throw t
        } finally {
            // Kembalikan (atau hapus) alamat tersimpan bila adopsi tidak benar-benar sukses,
            // termasuk bila await dibatalkan atau gagal di luar ApiError.
            if (!adopted) {
                backend?.setServerUrl(savedUrl ?: "")
            }
        }
    }

    fun stopPinProbe() { pinProbe?.cancel(); pinProbe = null }

    /** Hanya untuk layar penyiapan terminal. */
    internal val backendForSetup: PosBackend? get() = backend

    /** Baca alamat server dari vault (untuk tampilan di layar PIN). */
    fun serverUrl(): String? = backend?.serverUrl()

    /** Simpan alamat server ke vault (untuk penyiapan yang disederhanakan). */
    fun setServerUrl(url: String) { backend?.setServerUrl(url) }

    /** Navigasi ke layar penyiapan terminal dari PIN (untuk mengganti server/alamat). */
    fun goToSetup() { dest = Dest.SETUP }

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
    fun show(s: Sheet?) {
        // Hasil bagi dari percobaan sebelumnya tidak ikut terbawa saat layar bagi dibuka lagi.
        if (s == Sheet.Split) { splitResult = Load.Idle; itemSplitResult = Load.Idle }
        sheet = s
    }
    fun say(msg: String) { toast = msg }

    /** 061 / T011 — "Panggil pelanggan": nomor diucapkan sekali. Pesan layar tetap muncul walau suara gagal (FR-007). */
    fun callQueue(o: Order) {
        val queue = o.queue?.takeIf { it.isNotBlank() } ?: return
        announcer.announce(QueuePhrase.spoken(queue))
        say("Dipanggil $queue")
    }
    fun clearToast() { toast = null }
    fun lock() {
        availabilityJob?.cancel()
        floorJob?.cancel(); floor = Load.Idle; closedCache = emptyList(); keys.clear()
        session = null; user = null; shift = null; activeId = null; catalog = Load.Idle; soldOut = emptySet(); dest = Dest.PIN; sheet = null
        heartbeat = HeartbeatState()
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
                heartbeatNow()
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

    /**
     * 055 / T026 — `silent`: segarkan tanpa mengosongkan katalog yang sudah tampil. Galat saat menyegarkan
     * mempertahankan data terakhir (FR-018); pilihan order dan jumlah bayar tidak disentuh (FR-019).
     */
    fun loadMenu(silent: Boolean = false): Job {
        val b = backend ?: return Job().apply { complete() }
        if (!silent || catalog !is Load.Ready) catalog = Load.Loading
        return viewModelScope.launch {
            catalog = try { Load.Ready(b.loadMenu()) } catch (e: ApiError) { if (silent && catalog is Load.Ready) catalog else Load.Failed(e) }
            // 055 / T036 — pajak ikut dimuat bersama katalog; galat pajak tidak menggagalkan katalog.
            tax = try { b.taxDisplay() } catch (_: ApiError) { tax }
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
                onShiftRefused(e)
                say(writeErrorText(e))
            }
        }
    }

    /** Server menolak tulis tab karena shift tidak terbuka: pesan khusus (pembayaran ditolak), bukan galat umum. */
    private fun writeErrorText(e: ApiError): String =
        if (e is ApiError.Conflict && e.code == "shift_required") "Pembayaran ditolak: shift sudah ditutup. Buka shift untuk menerima pembayaran."
        else e.message

    /**
     * Server menolak karena shift tidak terbuka: header "Shift dibuka …" tidak boleh basi. Muat ulang shift dari
     * server; tanpa shift terbuka kasir diarahkan ke layar buka shift. Gangguan jaringan membiarkan keadaan apa adanya.
     */
    private fun onShiftRefused(e: ApiError) {
        if (!(e is ApiError.Conflict && e.code == "shift_required")) return
        val b = backend ?: return
        viewModelScope.launch {
            val current = try { b.currentShift() } catch (_: ApiError) { return@launch }
            shift = current
            shiftRecoveryDest(current, user?.code)?.let { dest = it }
        }
    }

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

    /** Simpan jumlah dan catatan baris. Diskon baris dikirim lewat [applyDiscount] (server yang menghitung). */
    fun saveLine(lineId: Int, qty: Int, note: String) {
        val o = active ?: return
        val line = o.lines.firstOrNull { it.id == lineId } ?: return
        sheet = null
        if (qty == line.qty && note.trim() == line.note.trim()) return
        if (line.fired) { say("Item sudah dikirim — perubahan lewat void."); return }
        updateLine(o.id, line, qty, note.trim())
    }



    /** Kirim semua baris belum terkirim ke dapur. Kunci mengikuti himpunan baris, jadi item baru = niat baru. */
    fun fire() {
        val b = backend ?: return
        val o = active ?: return
        val n = o.unsent.size
        if (n == 0) return
        val intent = "send:${o.id}:${o.unsent.map { it.id }.sorted()}"
        val key = keys.keyFor(intent)
        send(intent, {
            // 050 / T031 — KOT masuk antrean dari respons yang sama. Antrean dedupe per id tiket, jadi percobaan ulang
            // jaringan tidak mencetak dua kali, dan kegagalan cetak tidak menggagalkan kirim (FR-017).
            val sent = b.sendToKitchen(o.id, key)
            printer?.enqueueKot(sent.tickets, user?.name, o.id)
            sent.tab
        }) { _ ->
            say(kotMessage(n))
        }
    }

    /** Pesan jujur: KOT "dikirim ke printer" berarti masuk antrean, bukan sudah tercetak (lihat antrean cetak). */
    private fun kotMessage(n: Int): String =
        if (printer?.status?.value == PrinterStatus.Connected) "$n item dikirim ke dapur · KOT dikirim ke printer"
        else "$n item dikirim ke dapur · KOT belum tercetak, printer belum tersambung"

    /** Pra-bill: penanda di server, lalu cetak lewat antrean. Penanda tidak diulang otomatis agar hitungan cetak tidak ganda. */
    fun printPrebill() {
        val b = backend ?: return
        val o = active ?: return
        sheet = null
        send("prebill:${o.id}", { b.prebill(o.id) }, retry = false) { tab ->
            // 050 / T052 — penanda di server sudah tersimpan; sekarang kertasnya. Salinan dihitung per tab (cetak ulang
            // pra-bill memang disengaja), dan hanya job cetak yang diulang, bukan panggilan server.
            val copy = (prebillCopies[tab.id] ?: 0) + 1
            prebillCopies[tab.id] = copy
            printer?.enqueueRendered(PrintKind.PREBILL, tab.id, copy, Documents.prebill(tab), tab.id)
            say("Pra-bill masuk antrean cetak · BELUM LUNAS.")
        }
    }

    private val prebillCopies = mutableMapOf<String, Int>()

    /**
     * 050 / T051 — "Cetak struk" pada penjualan selesai. Salinan 1 sudah dicetak otomatis saat bayar; bila salinan itu
     * masih menunggu di antrean, tombol ini MENGULANG job tersebut (bukan membuat salinan 2 yang membingungkan).
     * Selain itu, ini cetak ulang biasa lewat server (salinan 2+, persetujuan sesuai aturan 048).
     */
    fun printReceipt(orderId: String) {
        val p = printer ?: return
        val firstCopy = jobKey(PrintKind.STRUK, orderId, 1)
        val job = p.jobs.value.firstOrNull { it.key == firstCopy }
        if (job != null && job.status != PrintStatus.PRINTED) p.retry(firstCopy) else reprint(orderId)
    }

    /** 050 / T054 — "Cetak ulang KOT" untuk tab terbuka. KOT dicetak lagi dari data yang tersimpan (tanpa server). */
    fun reprintKot() {
        val p = printer ?: return
        val o = active ?: return
        val queued = p.reprintKot(o.id)
        say(if (queued == 0) "Belum ada KOT untuk tab ini." else "$queued KOT masuk antrean cetak ulang.")
    }

    /** 048 / S4 — alasan diskon dari daftar server (`GET /reason-codes?category=discount`). */
    var discountReasons by mutableStateOf<Load<List<ReasonCode>>>(Load.Idle); private set

    fun loadDiscountReasons() {
        val b = backend ?: return
        discountReasons = Load.Loading
        viewModelScope.launch {
            discountReasons = try { Load.Ready(retryNetwork { b.reasonCodes("discount") }) } catch (e: ApiError) { Load.Failed(e) }
        }
    }

    /**
     * Diskon order ([lineId] null) atau baris. Persentase dan total dikirim apa adanya; yang tampil adalah total dari
     * server. Di atas batas peran server menjawab 409 override_required → persetujuan `discount` dengan subjek lengkap.
     * Kunci Idempotency-Key dilepas saat ditolak agar percobaan ulang setelah persetujuan memakai kunci baru.
     */
    fun applyDiscount(percent: Double, reason: ReasonCode, lineId: Int?, overrideToken: String? = null) {
        val b = backend ?: return
        val o = active ?: return
        val scope = if (lineId == null) "order" else "line"
        val intent = "discount:${o.id}:$scope:${lineId ?: 0}:$percent:${reason.code}"
        val key = keys.keyFor(intent)
        viewModelScope.launch {
            try {
                val updated = retryNetwork { b.setDiscount(o.id, percent, reason.code, scope, lineId, overrideToken, key) }
                keys.settled(intent)
                conn = Conn.ONLINE
                upsert(updated)
                sheet = null
                say(if (lineId == null) "Diskon order diterapkan." else "Diskon item diterapkan.")
            } catch (e: ApiError) {
                if (e is ApiError.Network) { conn = Conn.OFFLINE; say(e.message); return@launch }
                keys.settled(intent)
                if (e.code == "override_required") {
                    sheet = Sheet.Override(
                        summary = "Diskon ${percent.toInt()}% · ${reason.label}", action = "discount",
                        subject = discountSubject(o.id.toLong(), scope, lineId, percent, reason.code),
                    ) { token -> applyDiscount(percent, reason, lineId, token) }
                } else say(e.message)
            }
        }
    }

    /** Hapus diskon order (DELETE /tabs/{id}/discounts). Total ulang dari server. */
    fun clearOrderDiscount() {
        val b = backend ?: return
        val o = active ?: return
        val intent = "discount-clear:${o.id}"
        val key = keys.keyFor(intent)
        viewModelScope.launch {
            try {
                val updated = retryNetwork { b.clearDiscount(o.id, key) }
                keys.settled(intent)
                conn = Conn.ONLINE
                upsert(updated)
                sheet = null
                say("Diskon order dihapus.")
            } catch (e: ApiError) {
                if (e is ApiError.Network) { conn = Conn.OFFLINE; say(e.message); return@launch }
                keys.settled(intent)
                say(e.message)
            }
        }
    }

    /** 048 / S4 — bagi rata tanpa pembayaran. Jumlah tiap bagian dari server; tab asal disegarkan dari server. */
    var splitResult by mutableStateOf<Load<List<SplitPart>>>(Load.Idle); private set

    fun splitEqual(parts: Int) {
        val b = backend ?: return
        val o = active ?: return
        val intent = "split-equal:${o.id}:$parts"
        val key = keys.keyFor(intent)
        splitResult = Load.Loading
        viewModelScope.launch {
            try {
                val result = retryNetwork { b.splitEqual(o.id, parts, key) }
                keys.settled(intent)
                conn = Conn.ONLINE
                splitResult = Load.Ready(result)
                runCatching { b.tab(o.id) }.getOrNull()?.let { upsert(it) }
            } catch (e: ApiError) {
                if (e is ApiError.Network) conn = Conn.OFFLINE
                keys.settled(intent)
                splitResult = Load.Idle
                say(e.message)
            }
        }
    }

    /** 048 — bagi per item (POST /tabs/{id}/split, kontrak yang ada). Baris yang dipilih dibayar tunai sebesar jumlahnya; bagian dan sisa dari server. */
    var itemSplitResult by mutableStateOf<Load<SplitItemsResult>>(Load.Idle); private set

    fun splitByItem(lineIds: Set<Int>) {
        val b = backend ?: return
        val o = active ?: return
        val picked = o.live.filter { it.id in lineIds }
        if (picked.isEmpty()) return
        // Tunai sebesar jumlah baris yang dipilih; server tetap memverifikasi bagian dan menolak bila kurang.
        val cash = picked.sumOf { it.net }
        val lines = picked.map { it.id to it.qty }
        val intent = "split-items:${o.id}:" + lines.joinToString(",") { "${it.first}x${it.second}" }
        val key = keys.keyFor(intent)
        itemSplitResult = Load.Loading
        viewModelScope.launch {
            try {
                val result = retryNetwork { b.splitItems(o.id, lines, "$cash.00", key) }
                keys.settled(intent)
                conn = Conn.ONLINE
                itemSplitResult = Load.Ready(result)
                upsert(result.remaining)
            } catch (e: ApiError) {
                if (e is ApiError.Network) { conn = Conn.OFFLINE; itemSplitResult = Load.Idle; say(e.message); return@launch }
                keys.settled(intent)
                itemSplitResult = Load.Idle
                say(e.message)
            }
        }
    }

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
                onSaleClosed(o2)
                dest = Dest.DONE
            } else {
                say("Sisa ${rp(o2.remaining)}.")
            }
        }
    }

    /**
     * 050 / T039 — penjualan selesai: struk masuk antrean cetak (salinan 1). Tidak menunda, tidak mengubah pembayaran,
     * dan tidak menunggu printer (FR-005, FR-017). Kunci antrean mencegah struk ganda bila layar dibuka ulang.
     */
    private fun onSaleClosed(order: Order) {
        printer?.enqueueStruk(order.id, 1)
    }





    // ---------- aksi tab & pembayaran (048 / S3: T056) ----------
    // Aksi yang perlu persetujuan: server menjawab 409 override_required → dialog Sheet.Override mengambil token, lalu
    // aksi DIULANG dengan parameter yang sama + token. Subjek = identitas objek + parameter aksi (sesuai kontrak server).

    fun voidLine(lineId: Int, reason: String, made: Boolean, overrideToken: String? = null) {
        val b = backend ?: return
        val o = active ?: return
        val line = o.lines.firstOrNull { it.id == lineId } ?: return
        val code = PosCatalog.serverReasonCode(reason)
        val kitchen = if (made) "made" else "not_started"
        val stock = if (made) "waste" else "return"
        viewModelScope.launch {
            try {
                upsert(b.voidLine(o.id, lineId, code, kitchen, stock, null, overrideToken))
                // 050 / T032 — item yang sudah dikirim ke dapur menghasilkan slip BATAL (FR-008). Hanya setelah void sukses.
                if (line.fired) {
                    printer?.enqueueBatal(
                        tabId = o.id, lineId = lineId, station = null, table = o.table, orderNumber = null,
                        item = PrintableItem(qty = line.qty, name = line.name, modifiers = line.mods.map { it.name }, note = line.note.ifBlank { null }),
                        reason = reason,
                    )
                }
                conn = Conn.ONLINE
                sheet = null
                say("${line.name} di-void.")
            } catch (e: ApiError) {
                if (e is ApiError.Network) conn = Conn.OFFLINE
                if (e.code == "override_required") {
                    sheet = Sheet.Override(summary = "Void ${line.qty}× ${line.name} · $reason", action = "void_line",
                        subject = voidSubject(o.id, lineId, code, kitchen, stock)) { token -> voidLine(lineId, reason, made, token) }
                } else say(e.message)
            }
        }
    }

    fun cancelOrder(reason: String, overrideToken: String? = null) {
        val b = backend ?: return
        val o = active ?: return
        val code = PosCatalog.serverReasonCode(reason)
        viewModelScope.launch {
            try {
                upsert(b.cancelTab(o.id, code, null, overrideToken))
                conn = Conn.ONLINE
                sheet = null
                say("Order dibatalkan.")
            } catch (e: ApiError) {
                if (e is ApiError.Network) conn = Conn.OFFLINE
                // has_payments tidak pernah menawarkan persetujuan: pesannya langsung ditampilkan.
                if (e.code == "override_required") {
                    sheet = Sheet.Override(summary = "Batalkan order · $reason", action = "cancel_order",
                        subject = cancelSubject(o.id, code)) { token -> cancelOrder(reason, token) }
                } else say(e.message)
            }
        }
    }

    /** 048 — bank mesin EDC dari `GET /payment-channels` (bank_transfer). Dimuat setiap dialog EDC dibuka. */
    var edcBanks by mutableStateOf<Load<List<BankChannel>>>(Load.Idle); private set

    fun loadEdcBanks() {
        val b = backend ?: return
        edcBanks = Load.Loading
        viewModelScope.launch {
            edcBanks = try { Load.Ready(retryNetwork { b.bankChannels() }) } catch (e: ApiError) { Load.Failed(e) }
        }
    }

    /**
     * EDC: nominal = sisa tab, kode approval dari slip, [channelId] = bank mesin EDC (wajib; tidak pernah null).
     * Duplikat 2 menit → konfirmasi kasir, lalu kirim ulang dengan confirm_duplicate.
     */
    fun payEdc(channelId: Long, code: String, confirmDuplicate: Boolean = false) {
        val b = backend ?: return
        val o = active ?: return
        val amount = o.remaining
        if (amount <= 0) return
        val intent = "edc:${o.id}:$channelId:$code:$amount:$confirmDuplicate"
        val ref = keys.keyFor(intent)
        viewModelScope.launch {
            try {
                val updated = retryNetwork { b.pay(o.id, "edc", amount, ref, code, confirmDuplicate.takeIf { it }, channelId) }
                keys.settled(intent)
                conn = Conn.ONLINE
                upsert(updated)
                sheet = null
                if (updated.closed) {
                    onSaleClosed(updated)
                    dest = Dest.DONE
                } else say("Sisa ${rp(updated.remaining)}.")
            } catch (e: ApiError) {
                if (e is ApiError.Network) { conn = Conn.OFFLINE; say(e.message); return@launch }
                keys.settled(intent)
                onShiftRefused(e)
                if (e.code == "duplicate_edc") {
                    sheet = Sheet.Confirm("Kode approval sudah dipakai?", "Kode $code sudah dicatat dalam 2 menit terakhir. Lanjutkan jika ini transaksi berbeda.", "Lanjutkan") {
                        payEdc(channelId, code, confirmDuplicate = true)
                    }
                } else say(e.message)
            }
        }
    }

    /** Nama QRIS untuk tombol dan dialog: provider dari kanal qr_ewallet server; tanpa kanal → "QRIS" (tipe kanal), bukan merek. */
    var qrisLabel by mutableStateOf("QRIS"); private set
    /** 056 / T008 — kanal QRIS statis yang dimuat; null sampai dimuat atau bila server belum mengatur kanal. */
    var qrisChannel by mutableStateOf<QrisChannel?>(null); private set

    /** Judul lembar QRIS: "penyedia pemilik" (056 US1). */
    val qrisTitle: String get() = QrisMapper.sheetTitle(qrisChannel?.provider ?: qrisLabel, qrisChannel?.accountName)

    /** 056 amandemen — simpan gambar QR dari layar ini ke server, lalu muat ulang kanal. Galat ditampilkan sebagai pesan. */
    fun saveQrImage(bytes: ByteArray, mime: String) {
        val b = backend ?: return
        val channel = qrisChannel ?: return
        viewModelScope.launch {
            try {
                b.uploadQrImage(channel.id, bytes, mime)
                channel.qrImageUrl?.let { com.warkop.pos.data.QrImageLoader.evict(it) }
                loadQrisLabel()
                say("Gambar QR disimpan.")
            } catch (e: ApiError) {
                say(e.message)
            }
        }
    }

    fun loadQrisLabel() {
        val b = backend ?: return
        viewModelScope.launch {
            // 056 / T008 — kanal disimpan utuh (judul, nama pemilik, QR); qrisLabel tetap penyedia untuk layar lain.
            qrisChannel = try { b.qrisChannel() } catch (e: ApiError) { null }
            qrisLabel = qrisChannel?.provider ?: "QRIS"
        }
    }

    /** QRIS statis: dicatat menunggu verifikasi (server menyimpan pending). Kanal QRIS dari daftar kanal server. */
    fun recordQris() {
        val b = backend ?: return
        val o = active ?: return
        val amount = o.remaining
        if (amount <= 0) return
        val intent = "qris:${o.id}:$amount"
        val ref = keys.keyFor(intent)
        viewModelScope.launch {
            try {
                val channel = b.qrisChannel() ?: run { say("Kanal QRIS belum diatur di server."); return@launch }
                val updated = retryNetwork { b.pay(o.id, "qr_ewallet", amount, ref, null, null, channel.id) }
                keys.settled(intent)
                conn = Conn.ONLINE
                upsert(updated)
                sheet = null
                when (val next = qrisAfterPayment(updated.closed, updated.remaining)) {
                    // 058 / US2 — penjualan tutup: struk masuk antrean cetak sekali (kunci antrean), lalu selesai.
                    QrisNext.Finish -> { onSaleClosed(updated); dest = Dest.DONE; say("QRIS dicatat · menunggu verifikasi.") }
                    is QrisNext.ShowRemaining -> say("Sisa ${rp(next.remaining)}.")
                }
            } catch (e: ApiError) {
                if (e is ApiError.Network) { conn = Conn.OFFLINE; say(e.message); return@launch }
                keys.settled(intent)
                onShiftRefused(e)
                say(e.message)
            }
        }
    }

    fun verifyQris(tenderId: Int, ref: String, overrideToken: String? = null) {
        val b = backend ?: return
        val o = active ?: return
        val reference = ref.trim()
        viewModelScope.launch {
            try {
                upsert(b.verifyPayment(o.id, tenderId.toLong(), reference, overrideToken))
                conn = Conn.ONLINE
                sheet = null
                say("QRIS terverifikasi.")
            } catch (e: ApiError) {
                if (e is ApiError.Network) conn = Conn.OFFLINE
                if (e.code == "override_required") {
                    sheet = Sheet.Override(summary = "Verifikasi QRIS · ref $reference", action = "qris_verify",
                        subject = qrisSubject(o.id, tenderId, reference)) { token -> verifyQris(tenderId, reference, token) }
                } else say(e.message)
            }
        }
    }

    fun failQris(tenderId: Int, overrideToken: String? = null) {
        val b = backend ?: return
        val o = active ?: return
        viewModelScope.launch {
            try {
                upsert(b.failPayment(o.id, tenderId.toLong(), overrideToken))
                conn = Conn.ONLINE
                sheet = null
                say("Pembayaran QRIS ditandai tidak ditemukan.")
            } catch (e: ApiError) {
                if (e is ApiError.Network) conn = Conn.OFFLINE
                if (e.code == "override_required") {
                    sheet = Sheet.Override(summary = "QRIS tidak ditemukan", action = "qris_fail",
                        subject = qrisSubject(o.id, tenderId, null)) { token -> failQris(tenderId, token) }
                } else say(e.message)
            }
        }
    }

    /** 86: alasan dipilih kasir dari daftar server (`GET /reason-codes?category=sold_out`). Tidak ada id yang dikarang di klien. */
    var soldOutReasons by mutableStateOf<Load<List<ReasonCode>>>(Load.Idle); private set

    fun markSoldOut(p: Product) {
        sheet = Sheet.SoldOut(p)
        loadSoldOutReasons()
    }

    fun loadSoldOutReasons() {
        val b = backend ?: return
        soldOutReasons = Load.Loading
        viewModelScope.launch {
            soldOutReasons = try { Load.Ready(b.reasonCodes("sold_out")) } catch (e: ApiError) { Load.Failed(e) }
        }
    }

    /** Tandai habis dengan alasan yang dipilih. Server menjawab 409 override_required bila peminta tak punya izin. */
    fun confirmSoldOut(p: Product, reasonCodeId: Long, overrideToken: String? = null) {
        val b = backend ?: return
        viewModelScope.launch {
            try {
                b.soldOut(p.id, reasonCodeId, null, overrideToken)
                conn = Conn.ONLINE
                sheet = null
                say("${p.name} ditandai habis.")
            } catch (e: ApiError) {
                if (e is ApiError.Network) conn = Conn.OFFLINE
                if (e.code == "override_required") {
                    sheet = Sheet.Override(summary = "Tandai ${p.name} habis", action = "availability",
                        subject = availabilitySubject(p.id.toLong(), reasonCodeId)) { token -> confirmSoldOut(p, reasonCodeId, token) }
                } else say(e.message)
            }
        }
    }

    // Subjek persetujuan (identitas objek + parameter, sama dengan permintaan aksi; field yang tidak dikirim juga tidak ada di sini).
    private fun voidSubject(tabId: String, lineId: Int, reasonCode: String, kitchen: String, stock: String): JsonObject = buildJsonObject {
        put("tab_id", tabId.toLong()); put("line_id", lineId); put("reason_code", reasonCode); put("kitchen_status", kitchen); put("stock_decision", stock)
    }

    private fun cancelSubject(tabId: String, reasonCode: String): JsonObject = buildJsonObject {
        put("tab_id", tabId.toLong()); put("reason_code", reasonCode)
    }

    private fun qrisSubject(tabId: String, paymentId: Int, reference: String?): JsonObject = buildJsonObject {
        put("tab_id", tabId.toLong()); put("payment_id", paymentId.toLong()); reference?.let { put("reference", it) }
    }

    private fun availabilitySubject(productId: Long, reasonCodeId: Long): JsonObject = buildJsonObject {
        put("product_id", productId); put("reason_code_id", reasonCodeId)
    }

    /** Jumlah salinan yang sudah dicetak ulang per order (ulang dari server pada tiap respons). */

    /**
     * 048 / S4 — cetak ulang. Salinan pertama gratis; berikutnya server menjawab 409 override_required → persetujuan
     * `reprint` dengan subjek order_id + copy_no. Nomor salinan berikutnya = salinan terakhir yang tercatat + 1.
     */
    /**
     * 057 / US3 — nomor salinan = jumlah cetak ulang yang diterima server + 2 (salinan asli = 1). Dihitung dari order
     * yang dimuat, bukan dari penghitung lokal. 409 `reprint_copy_changed` (ada cetak lain) memuat ulang order sekali.
     */
    fun reprint(orderId: String, overrideToken: String? = null, retried: Boolean = false) {
        val b = backend ?: return
        val copyNo = ReprintRules.nextCopyNo(serverReprintCount(orderId))
        val intent = "reprint:$orderId:$copyNo"
        val key = keys.keyFor(intent)
        viewModelScope.launch {
            try {
                val printed = retryNetwork { b.reprintOrder(orderId, copyNo, overrideToken, key) }
                keys.settled(intent)
                conn = Conn.ONLINE
                // 050 / T050 — salinan yang sudah diterima server masuk antrean; nomornya dari server (copy_no).
                printer?.enqueueStruk(orderId, printed)
                say("Salinan ke-$printed masuk antrean cetak.")
            } catch (e: ApiError) {
                if (e is ApiError.Network) { conn = Conn.OFFLINE; say(e.message); return@launch }
                keys.settled(intent)
                if (e.code == "reprint_copy_changed" && !retried) {
                    loadClosed().join()
                    reprint(orderId, overrideToken, retried = true)
                    return@launch
                }
                if (e.code == "override_required") {
                    sheet = Sheet.Override(
                        summary = "Cetak ulang struk · salinan $copyNo", action = "reprint",
                        subject = reprintSubject(orderId.toLong(), copyNo),
                    ) { token -> reprint(orderId, token) }
                } else say(e.message)
            }
        }
    }

    /** 057 — jumlah cetak ulang order dari data server yang sudah dimuat (daftar selesai atau order aktif). */
    private fun serverReprintCount(orderId: String): Int =
        (closedCache + orders).firstOrNull { it.id == orderId }?.reprints ?: 0

    /** 048 / S4 — alasan refund dari daftar server (`GET /reason-codes?category=refund`). */
    var refundReasons by mutableStateOf<Load<List<ReasonCode>>>(Load.Idle); private set

    fun loadRefundReasons() {
        val b = backend ?: return
        refundReasons = Load.Loading
        viewModelScope.launch {
            refundReasons = try { Load.Ready(retryNetwork { b.reasonCodes("refund") }) } catch (e: ApiError) { Load.Failed(e) }
        }
    }

    /**
     * 048 / S4 — refund penuh order lunas (online saja). Di luar izin server menjawab 409 override_required → persetujuan
     * `refund` dengan subjek order_id, reason_code, reason. Total dan status dari server.
     */
    fun refundOrder(orderId: String, reason: ReasonCode, note: String?, overrideToken: String? = null) {
        val b = backend ?: return
        val intent = "refund:$orderId:${reason.code}"
        val key = keys.keyFor(intent)
        viewModelScope.launch {
            try {
                val updated = retryNetwork { b.refundOrder(orderId, reason.code, note?.takeIf { it.isNotBlank() }, overrideToken, key) }
                keys.settled(intent)
                conn = Conn.ONLINE
                upsert(updated)
                sheet = null
                say("Order direfund penuh.")
            } catch (e: ApiError) {
                if (e is ApiError.Network) { conn = Conn.OFFLINE; say(e.message); return@launch }
                keys.settled(intent)
                if (e.code == "override_required") {
                    sheet = Sheet.Override(
                        summary = "Refund order · ${reason.label}", action = "refund",
                        subject = refundSubject(orderId.toLong(), reason.code, note?.takeIf { it.isNotBlank() }),
                    ) { token -> refundOrder(orderId, reason, note, token) }
                } else say(e.message)
            }
        }
    }


    /** Keadaan memuat daftar penjualan selesai (GET /orders). Gagal → ErrorState di layar Order, bukan toast. */
    var closedLoad by mutableStateOf<Load<Unit>>(Load.Idle); private set

    /** Daftar penjualan selesai dari server (layar Order → Selesai). */
    /** 055 / T029 — data yang sudah tampil tetap saat menyegarkan; galat hanya mengganti keadaan bila belum ada data. */
    fun loadClosed(): Job {
        val b = backend ?: return Job().apply { complete() }
        if (closedLoad !is Load.Ready) closedLoad = Load.Loading
        return viewModelScope.launch {
            closedLoad = try {
                closedCache = retryNetwork { b.closedOrders() }
                Load.Ready(Unit)
            } catch (e: ApiError) {
                if (closedLoad is Load.Ready) closedLoad else Load.Failed(e)
            }
        }
    }

    /** Struk per order dari GET /orders/{id}/receipt (T038). Dimuat saat lembar struk dibuka. */
    var receipts by mutableStateOf<Map<String, Load<com.warkop.pos.model.ReceiptView>>>(emptyMap()); private set

    fun loadReceipt(orderId: String) {
        val b = backend ?: return
        receipts = receipts + (orderId to Load.Loading)
        viewModelScope.launch {
            val next = try { Load.Ready(retryNetwork { b.receipt(orderId) }) } catch (e: ApiError) { Load.Failed(e) }
            receipts = receipts + (orderId to next)
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
    /** 055 / T030 — laporan shift disegarkan tanpa menghapus angka yang sudah tampil. */
    fun refreshShiftReport(): Job {
        val b = backend ?: return Job().apply { complete() }
        val sid = shift?.id ?: return Job().apply { complete() }
        if (report !is Load.Ready) report = Load.Loading
        return viewModelScope.launch {
            report = try { Load.Ready(retryNetwork { b.shiftReport(sid) }) } catch (e: ApiError) { if (report is Load.Ready) report else Load.Failed(e) }
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
    fun cashMove(inflow: Boolean, reason: String, note: String?, amount: Int, overrideToken: String? = null) {
        val b = backend ?: return
        val sid = shift?.id ?: run { say("Buka shift dulu sebelum mencatat kas."); return }
        val intent = "cash:$sid:${if (inflow) "in" else "out"}:$reason:$amount:${note.orEmpty()}"
        val key = keys.keyFor(intent)
        viewModelScope.launch {
            try {
                retryNetwork { b.recordCashMovement(sid, inflow, reason, note, amount, key, overrideToken) }
                keys.settled(intent)
                conn = Conn.ONLINE
                sheet = null
                say("${if (inflow) "Kas masuk" else "Kas keluar"} ${rp(amount)} dicatat.")
                refreshCashMovements(); refreshShiftReport()
            } catch (e: ApiError) {
                if (e is ApiError.Network) { conn = Conn.OFFLINE; say(e.message); return@launch }
                keys.settled(intent)
                if (e is ApiError.Conflict && e.code == "override_required") {
                    // Di atas ambang: minta persetujuan untuk kas ini; aksi diulang dengan token sekali pakai.
                    sheet = Sheet.Override(
                        summary = "Kas ${if (inflow) "masuk" else "keluar"} ${rp(amount)} · ${ShiftMapper.reasonLabel(reason)}",
                        action = "cash_out",
                        subject = cashSubject(sid, inflow, reason, amount),
                    ) { token -> cashMove(inflow, reason, note, amount, token) }
                } else {
                    say(e.message)
                }
            }
        }
    }

    // ---------- persetujuan (048 / S3: US4) ----------
    /** Penyetuju untuk aksi yang sedang meminta persetujuan. Gagal = keadaan galat dengan Coba lagi. */
    var approvers by mutableStateOf<Load<List<Approver>>>(Load.Idle); private set

    fun loadApprovers(action: String) {
        val b = backend ?: return
        approvers = Load.Loading
        viewModelScope.launch {
            approvers = try { Load.Ready(retryNetwork { b.approvers(action) }) } catch (e: ApiError) { Load.Failed(e) }
        }
    }

    /**
     * Minta token untuk satu aksi. Token hanya hidup di memori dan langsung diserahkan ke aksi yang disetujui; PIN
     * tidak disimpan di state apa pun (dialog mengosongkannya sebelum memanggil ini).
     */
    fun requestOverride(s: Sheet.Override, approverId: Long, pin: String, onError: (String) -> Unit) {
        val b = backend ?: return
        viewModelScope.launch {
            try {
                val token = b.issueOverride(s.action, s.subject, approverId, pin)
                sheet = null
                s.onApproved(token)
            } catch (e: ApiError) {
                onError(overrideErrorText(e))
            }
        }
    }

    private fun overrideErrorText(e: ApiError): String {
        val left = e.attemptsRemaining
        return if (e.code == "invalid_pin" && left != null) "PIN salah. Sisa $left percobaan." else e.message
    }

    /**
     * Subjek persetujuan kas keluar: identitas objek (shift) + parameter yang sama dengan permintaan aksi. Server
     * mencocokkan token dengan subjek ini; token untuk shift/kas lain tidak berlaku (422 override_mismatch bila beda).
     */
    private fun cashSubject(sessionId: Long, inflow: Boolean, reason: String, amount: Int): JsonObject = buildJsonObject {
        put("session_id", sessionId)
        put("direction", if (inflow) "in" else "out")
        put("amount", amount)
        put("reason_code", reason)
    }

    /** Subjek persetujuan selisih tutup shift: identitas shift + hitungan pecahan yang dikirim ke POST close. */
    private fun countSubject(sessionId: Long, rows: List<Pair<Int, Int>>): JsonObject = buildJsonObject {
        put("session_id", sessionId)
        putJsonArray("counted_by_denomination") {
            rows.forEach { (d, q) -> addJsonObject { put("denomination", d); put("qty", q) } }
        }
    }

    /**
     * Tutup shift dengan hitungan buta. Server yang menjumlahkan dan menilai selisih. Penutupan tidak idempoten di
     * server, jadi tidak ada coba-ulang otomatis: jika koneksi putus, status dianggap belum diketahui.
     */
    fun submitCount(counts: Map<Int, Int>, edcBatch: Int?, overrideToken: String? = null) {
        val b = backend ?: return
        val sid = shift?.id ?: return
        val rows = counts.filter { it.value > 0 }.map { it.key to it.value }
        // Satu kunci per niat tutup: coba-ulang jaringan memakai kunci yang sama, server menjawab dengan hasil tersimpan.
        val intent = "close:$sid"
        val key = keys.keyFor(intent)
        viewModelScope.launch {
            try {
                val outcome = retryNetwork { b.closeShift(sid, rows, edcBatch, key, overrideToken) }
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
                    return@launch
                }
                keys.settled(intent)
                if (e is ApiError.Conflict && e.code == "override_required") {
                    // Selisih di atas toleransi: minta persetujuan untuk tutup ini, lalu kirim hitungan yang sama dengan token.
                    sheet = Sheet.Override(summary = "Selisih kas di atas toleransi", action = "variance", subject = countSubject(sid, rows)) { token ->
                        submitCount(counts, edcBatch, token)
                    }
                } else if (e is ApiError.Conflict) {
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
