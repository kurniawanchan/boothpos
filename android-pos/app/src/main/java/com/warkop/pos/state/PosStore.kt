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
import com.warkop.pos.data.OutletChoice
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

/** 073 / FR-018 — layar alur "menyelesaikan order": terminal yang dicabut tidak memotongnya di tengah jalan. */
internal val FINISHING_ORDER = setOf(Dest.EDITOR, Dest.TENDER, Dest.DONE)

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

/** 078 — branding/mode dibaca tiap putaran ke-N probe PIN (putaran 0 selalu), roster tiap putaran. */
const val BRANDING_EVERY_N_TICKS = 3

internal fun brandingDueOnTick(tick: Int): Boolean = tick % BRANDING_EVERY_N_TICKS == 0

/** 077 — batas catatan order; sama dengan server (UpdateTabNoteRequest). */
const val ORDER_NOTE_MAX = 1000

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
    /** 075 — daftar varian satu produk; menyimpan id supaya muat-ulang menu diam-diam memperbarui tanda habis. */
    data class VariantPicker(val entryId: String) : Sheet
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
    /** 055 / T037 — pilihan tampil/sembunyi total; penyimpanan perangkat di Android, in-memory di uji. */
    private val totalsPref: TotalsPreference = TotalsPreference({ null }, {}),
    /** 077 / US5 — lebar area produk vs panel order di Counter; penyimpanan perangkat di Android, in-memory di uji. */
    private val splitPref: SplitPreference = SplitPreference({ null }, {}),
    /** 061 / T011 — suara panggilan antrean; default diam supaya uji tidak butuh mesin ucap. */
    private val announcer: QueueAnnouncer = QueueAnnouncer(SilentSpeech),
    /** 084 — tampil/sembunyi sidebar (hamburger); penyimpanan perangkat di Android, in-memory di uji. */
    private val railPref: RailPreference = RailPreference({ null }, {}),
) : ViewModel() {
    /** 084 — sidebar navigasi tampil (layar lebar). Pilihan tersimpan per perangkat. */
    var railVisible by mutableStateOf(railPref.visible); private set
    fun toggleRail() { railVisible = !railVisible; railPref.visible = railVisible }

    /**
     * 084 — menu Kasir: kembali ke tab counter yang sedang dikerjakan; bila belum ada, memakai tab kosong atau membuka
     * yang baru ([landOnKasir]). Dipakai rail DAN bilah bawah supaya keduanya tidak membuka tab baru tiap ketukan.
     */
    fun openKasir() {
        // 084 — hanya tab counter yang MASIH TERBUKA yang dilanjutkan: setelah bayar, pesanan selesai masih `active` dan tidak
        // boleh dibuka lagi di layar Kasir. Selain itu memakai tab kosong yang ada atau membuka yang baru (landOnKasir).
        val a = active
        if (a != null && !a.closed && a.kind == OrderKind.COUNTER) go(Dest.EDITOR) else landOnKasir()
    }

    /** 055 / T037 — area total (Subtotal, pajak, Total, Kirim, Bayar) tampil atau tersembunyi. */
    var totalsVisible by mutableStateOf(totalsPref.visible); private set
    fun changeTotalsVisibility(v: Boolean) { totalsVisible = v; totalsPref.visible = v }

    /** 077 / US5 — porsi lebar area produk di Counter (0..1). Berubah selama digeser; disimpan saat jari diangkat. */
    var splitFraction by mutableStateOf(splitPref.fraction); private set
    /** Geser pembatas [deltaPx] dari total lebar [totalPx]/[totalDp]; hasil selalu dalam batas (kedua sisi tetap terpakai). */
    fun dragSplit(deltaPx: Float, totalPx: Float, totalDp: Float) {
        if (totalPx <= 0f) return
        splitFraction = clampSplit(splitFraction + deltaPx / totalPx, totalDp)
    }
    fun commitSplit() { splitPref.fraction = splitFraction }
    fun resetSplit() { splitFraction = SPLIT_DEFAULT; splitPref.fraction = SPLIT_DEFAULT }

    /** 055 / T036 — label dan tarif pajak dari server; null sampai dimuat. */
    var tax by mutableStateOf<TaxDisplay?>(null); private set

    /** 055 / T026 — true selama tarik-untuk-segarkan berjalan. */
    var refreshing by mutableStateOf(false); private set
    fun refreshCounter() {
        refreshing = true
        // 075 — tarik-untuk-segarkan sengaja (bukan polling diam-diam): foto yang ditimpa di back office ikut diambil ulang.
        com.warkop.pos.data.MenuImageLoader.clear()
        viewModelScope.launch { try { if (!sessionModeChanged()) { refreshUser(); loadMenu(silent = true).join() } } finally { refreshing = false } }
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
        viewModelScope.launch { try { refreshUser(); job.join() } finally { refreshing = false } }
    }

    /**
     * 078 — baca ulang pengguna yang masuk supaya foto/nama yang diubah di web ikut tampil pada penyegaran berikutnya (bukan hanya saat masuk
     * lagi). Hanya nama dan foto yang diperbarui — peran, izin, dan sesi tetap; id yang berbeda diabaikan; gagal baca = tetap yang lama.
     */
    private suspend fun refreshUser() {
        val b = backend ?: return
        val current = user ?: return
        val fresh = try { b.me() } catch (_: ApiError) { return }
        if (fresh.id == current.id) user = current.copy(name = fresh.name, photoUrl = fresh.photoUrl)
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
    /** 077 — mode data yang sedang berlaku (dari server; terakhir diketahui bila offline). Tablet tidak pernah mengubahnya sendiri. */
    var mode by mutableStateOf(SystemMode.LIVE); private set
    /** 077 — mode BARU yang terdeteksi saat sesi berjalan: menampilkan dialog "Mode berubah" dan memblokir tulis order (FR-004). */
    var modeChanged by mutableStateOf<SystemMode?>(null); private set
    /** 077 — nama/logo toko dari server (sebelum login: pos-setup/branding; sesudahnya: features). */
    var branding by mutableStateOf<Branding?>(null); private set
    private var beats = 0
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
    /** 073 — pesan di layar penyiapan saat tablet dikembalikan ke sana (terminal dicabut / tak berlaku lagi); null = tanpa pesan. */
    var setupNotice by mutableStateOf<String?>(null); private set
    /** 073 / FR-018 — server menjawab 422 `device_id`: terminal ini dicabut. Tab yang sedang dikerjakan boleh selesai; order baru tidak. */
    var terminalRevoked by mutableStateOf(false); private set
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
            // 077 — mode diperiksa tiap 3 heartbeat (±3 menit) selain saat galat: pergantian mode terlihat tanpa menunggu kasir gagal menulis.
            if (++beats % 3 == 0) sessionModeChanged()
        } catch (e: ApiError) {
            heartbeat = heartbeat.onFailure()
            if (e is ApiError.Network) conn = Conn.OFFLINE
            // 077 — 422 device_id juga muncul bila MODE berganti (terminal mode lama tak ada di mode baru): pastikan dulu, jangan langsung "dicabut".
            if (isTerminalRevoked(e) && !isModeChange(e)) markTerminalRevoked()
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
    // 084 — `orders` DULU: pesanan selesai yang sudah dimuat lengkap (GET /tabs/{id}) menang atas ringkasan daftar
    // (GET /orders) yang tidak membawa item, meja, kasir, subtotal, dan pajak — sebab rincian Selesai dulu hanya menampilkan total.
    val closedOrders: List<Order> get() =
        (orders.filter { it.closed } + closedCache).distinctBy { it.id }.sortedByDescending { it.id.toLongOrNull() ?: 0L }

    /** 084 — keadaan memuat rincian lengkap per pesanan selesai (kunci = id pesanan). */
    var closedDetailLoad by mutableStateOf<Map<String, Load<Unit>>>(emptyMap()); private set

    /** Ringkasan daftar tidak punya subtotal dari server; rincian lengkap selalu punya. */
    fun hasFullDetail(id: String): Boolean = orders.any { it.id == id && it.serverSubtotal != null }

    /**
     * 084 — muat rincian LENGKAP satu pesanan selesai (item + modifier + catatan, meja, kasir, subtotal, diskon, pajak,
     * pembayaran) supaya transaksi bisa ditinjau ulang. Pesanan yang dibatalkan tidak punya rincian tab (404) dan dilewati;
     * penjualan web (bukan tab) juga 404 → keadaan gagal, dan "Lihat struk" tetap tersedia sebagai rincian.
     */
    fun loadClosedDetail(id: String, force: Boolean = false) {
        val b = backend ?: return
        if (!force && (hasFullDetail(id) || closedDetailLoad[id] is Load.Loading)) return
        if (closedCache.firstOrNull { it.id == id }?.cancelledReason != null) return
        closedDetailLoad = closedDetailLoad + (id to Load.Loading)
        viewModelScope.launch {
            val next: Load<Unit> = try {
                upsert(retryNetwork { b.tab(id) })
                Load.Ready(Unit)
            } catch (e: ApiError) {
                if (e is ApiError.Network) conn = Conn.OFFLINE
                Load.Failed(e)
            }
            closedDetailLoad = closedDetailLoad + (id to next)
        }
    }

    // ---------- awal & sesi ----------
    /** Dipanggil sekali saat aplikasi mulai: selalu langsung ke layar PIN, membaca alamat server dari vault. */
    /**
     * 084 — bootstrap hanya SEKALI per store. Memutar layar membuat ulang Activity; komposisi baru memanggil bootstrap lagi dan
     * dulu mengembalikan layar ke PIN (pengguna seperti ter-logout) padahal sesi masih berjalan di ViewModel.
     */
    private var bootstrapped = false

    fun bootstrap() {
        if (bootstrapped) return
        bootstrapped = true
        val b = backend
        mode = b?.currentMode() ?: SystemMode.LIVE
        val saved = b?.savedConfig() ?: remote?.savedConfig()
        config = saved
        // 073 — tablet yang belum punya outlet + terminal mulai dari layar penyiapan (pilih outlet → terminal), bukan dari layar
        // PIN. Yang sudah punya langsung ke pilih-staf; terminal yang ternyata sudah tidak berlaku diperiksa di latar.
        if (saved == null) {
            dest = Dest.SETUP
            refreshBrandingPreLogin()
            return
        }
        dest = Dest.PIN
        roster = Load.Loading
        viewModelScope.launch {
            // 077 — mode dibaca DULU: outlet/terminal tersimpan milik mode lama tidak ada di mode baru (HasDataMode di server),
            // jadi memuat staf atau memeriksa terminal sebelum tahu mode akan menolak dan membuang pilihan yang masih sah.
            if (pullBranding() == BrandingStep.SWITCHED) return@launch
            loadRoster().join()
            validateSavedTerminal(saved)
        }
    }

    private enum class BrandingStep { SAME, SWITCHED, UNKNOWN }

    /** Baca identitas toko + mode sebelum login. Gagal (offline/server lama) = UNKNOWN: tetap pada mode terakhir yang diketahui. */
    private suspend fun pullBranding(): BrandingStep {
        val b = backend ?: return BrandingStep.UNKNOWN
        val fresh = try { b.branding() } catch (_: ApiError) { return BrandingStep.UNKNOWN }
        branding = fresh
        if (fresh.mode == mode) return BrandingStep.SAME
        switchMode(fresh.mode)
        return BrandingStep.SWITCHED
    }

    private fun refreshBrandingPreLogin() { viewModelScope.launch { pullBranding() } }

    /**
     * 077 / FR-001–002 — pakai mode [new] sebelum/tanpa sesi: pilihan terminal mode itu dimuat (atau penyiapan bila belum ada),
     * dan semua keadaan terikat-mode dikosongkan supaya tak ada item mode lama yang tersisa. Tidak ada data yang dihapus
     * di sisi mana pun, dan pilihan mode lama tetap tersimpan untuk saat mode kembali.
     */
    private fun switchMode(new: SystemMode) {
        val b = backend ?: return
        b.useMode(new)
        mode = new
        com.warkop.pos.data.MenuImageLoader.clear()
        catalog = Load.Idle; soldOut = emptySet(); orders = emptyList(); floor = Load.Idle; closedCache = emptyList()
        terminalRevoked = false
        val saved = b.savedConfig()
        config = saved
        if (saved == null) {
            roster = Load.Idle
            setupNotice = "Mode sistem sekarang ${new.name}. Pilih outlet dan terminal untuk mode ini."
            dest = Dest.SETUP
        } else {
            setupNotice = null
            dest = Dest.PIN
            viewModelScope.launch { loadRoster().join(); validateSavedTerminal(saved) }
        }
    }

    /**
     * 077 — baca mode dari server saat sesi berjalan. true = berbeda dari mode sesi ini: dialog "Mode berubah" tampil dan tulis
     * order diblokir sampai kasir keluar. Gagal membaca (offline) = tidak ada kesimpulan, bukan "tidak berubah".
     */
    private suspend fun sessionModeChanged(): Boolean {
        val b = backend ?: return false
        val fresh = try { b.sessionBranding() } catch (_: ApiError) { return false }
        branding = fresh
        if (fresh.mode == mode) return false
        modeChanged = fresh.mode
        return true
    }

    /** Galat yang bisa berarti "mode berubah" (outlet/terminal mode lama tidak ditemukan) — dipastikan lewat [sessionModeChanged]. */
    private suspend fun isModeChange(e: ApiError): Boolean =
        (isTerminalRevoked(e) || e is ApiError.NotFound || (e is ApiError.Forbidden && e.code == "outlet_forbidden")) && sessionModeChanged()

    /** Kasir menekan Keluar pada dialog "Mode berubah": sesi ditutup, lalu pilihan terminal mode baru dipakai (atau penyiapan). */
    fun leaveForNewMode() {
        val new = modeChanged ?: return
        val b = backend
        clearSessionState()
        modeChanged = null
        viewModelScope.launch {
            try { b?.logout() } catch (_: ApiError) { }
            switchMode(new)
        }
    }

    /** Tulis order diblokir selama mode berubah belum diakui; dialog sudah menjelaskan alasannya. */
    private fun modeBlocked(): Boolean = modeChanged != null

    /**
     * 073 / FR-013 — outlet atau terminal tersimpan bisa sudah tidak ada (terminal dicabut, outlet dinonaktifkan). Dicek sekali saat
     * mulai lewat daftar terminal outlet itu. Hanya jawaban server yang menentukan: gangguan jaringan membiarkan tablet apa adanya
     * (tidak ada alasan membuang pilihan yang mungkin masih sah).
     */
    private suspend fun validateSavedTerminal(saved: TerminalConfig) {
        val b = backend ?: return
        val stillValid = try {
            b.terminals(OutletChoice(saved.outletId, saved.outletName)).any { it.id == saved.deviceId }
        } catch (e: ApiError.NotFound) { false } catch (_: ApiError) { return }
        if (!stillValid) returnToSetup("Terminal ini tidak berlaku lagi. Pilih outlet dan terminal lagi.")
    }

    /** Lupakan pilihan tersimpan, tutup sesi, dan kembali ke layar penyiapan dengan [notice]. */
    private fun returnToSetup(notice: String) {
        val b = backend
        clearSessionState()
        // Melupakan terminal TIDAK boleh bergantung pada keberhasilan logout (server bisa tak terjangkau, token sudah mati).
        viewModelScope.launch { try { b?.logout() } catch (_: ApiError) { } finally { b?.forgetTerminal() } }
        config = null; terminalRevoked = false; roster = Load.Idle
        setupNotice = notice
        dest = Dest.SETUP
    }

    /**
     * 073 / FR-018 — terminal dicabut saat staf masuk. Tab yang sedang dikerjakan (editor/bayar/selesai) boleh diselesaikan; begitu
     * staf keluar dari alur itu — atau bila sedang tidak mengerjakan order — tablet kembali ke layar penyiapan. Order BARU ditolak
     * di sini dan oleh server (422 `device_id` pada buka tab/shift).
     */
    private fun markTerminalRevoked() {
        if (terminalRevoked) return
        terminalRevoked = true
        if (revokedStepAt(dest) == RevokedStep.FINISH_ORDER) say("Terminal ini dicabut. Selesaikan pesanan ini; setelah itu pilih terminal lagi.")
        else returnToSetup("Terminal ini sudah dicabut. Pilih outlet dan terminal lagi.")
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
            var tick = 0
            while (true) {
                // Tunggu hasil roster dulu: status koneksi dan roster baru terisi setelah panggilan selesai.
                // 077 — owner bisa mengganti mode saat tablet berada di layar PIN: baca mode dulu, baru roster (outlet milik mode lama 404).
                // 078 — mode/branding cukup tiap [BRANDING_EVERY_N_TICKS] putaran (±30 dtk): polling 12 permintaan/menit per tablet dulu
                // menghabiskan jatah login; sekarang ±8, dan pembatas tablet punya hitungan sendiri.
                val switched = brandingDueOnTick(tick++) && pullBranding() == BrandingStep.SWITCHED
                if (!switched) loadRoster(silent = true).join()
                delay(PIN_PROBE_INTERVAL_MS)
            }
        }
    }

    // 065 / research D8 — TIDAK ada lagi perpindahan alamat otomatis (adoptSelectedServer, 054): alamat server dibaca SEKALI
    // saat "Siapkan terminal" (ServerDiscovery), disimpan di perangkat, dan dipakai semua permintaan sampai data dihapus.
    // Konsekuensi yang diterima pemilik: memindahkan tablet antara lokal dan online berarti menghapus data tablet.

    fun stopPinProbe() { pinProbe?.cancel(); pinProbe = null }

    /** Hanya untuk layar penyiapan terminal. */
    internal val backendForSetup: PosBackend? get() = backend

    /** Baca alamat server dari vault (untuk tampilan di layar PIN). */
    fun serverUrl(): String? = backend?.serverUrl()

    /** Simpan alamat server ke vault (untuk penyiapan yang disederhanakan). */
    fun setServerUrl(url: String) { backend?.setServerUrl(url) }

    /** Navigasi ke layar penyiapan terminal dari PIN (untuk mengganti server/alamat). */
    fun goToSetup() { dest = Dest.SETUP }

    internal fun setConfig(c: TerminalConfig) { config = c; setupNotice = null; terminalRevoked = false; dest = Dest.PIN; loadRoster() }

    /** Hanya untuk preset pembandingan visual (src/debug); tidak ada jalur produk yang memanggilnya. */
    internal fun debugInstall(
        user: StaffUser?, shift: Shift?, orders: List<Order>, activeId: String?, dest: Dest, sheet: Sheet?, conn: Conn,
        dark: Boolean?, ordersTab: Int, closeResult: CloseOutcome?,
    ) {
        this.user = user; this.shift = shift; this.orders = orders; this.activeId = activeId; this.dest = dest; this.sheet = sheet
        this.conn = conn; this.darkOverride = dark; this.ordersTab = ordersTab; this.closeResult = closeResult; this.toast = null
    }

    // ---------- navigasi & umpan balik ----------
    fun go(d: Dest) {
        // 073 — terminal dicabut: tab yang dikerjakan boleh selesai, tetapi keluar dari alur itu berarti kembali ke penyiapan.
        if (exitsOnNavigate(terminalRevoked, d)) { returnToSetup("Terminal ini sudah dicabut. Pilih outlet dan terminal lagi."); return }
        dest = d; sheet = null
    }
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
        val empties = emptyCounterTabs().map { it.id }
        clearSessionState()
        // Daftar staf dimuat SETELAH logout selesai: sebelum itu token sesi lama masih terpasang dan server menolaknya (401).
        // 084 — tab kosong dibuang dulu (token masih berlaku), supaya login berikutnya/tutup shift tidak terhalang.
        viewModelScope.launch { discardEmptyCounterTabs(empties); try { backend?.logout() } catch (_: ApiError) { }; loadRoster() }
    }

    /** Bagian lokal dari keluar: semua keadaan sesi dikosongkan; logout ke server dan muat ulang staf dilakukan pemanggil. */
    private fun clearSessionState() {
        availabilityJob?.cancel()
        floorJob?.cancel(); floor = Load.Idle; closedCache = emptyList(); keys.clear()
        session = null; user = null; shift = null; activeId = null; catalog = Load.Idle; soldOut = emptySet(); dest = Dest.PIN; sheet = null
        heartbeat = HeartbeatState()
        report = Load.Idle; cashMovements = Load.Idle; closeResult = null; limits = null
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
                    // 084 — kasir mendarat di Kasir. Itu membuka tab counter kosong; tab kosong menghalangi tutup shift
                    // (409 open_tabs), jadi tab kosong dibuang saat kunci/keluar dan sebelum tutup shift (discardEmptyCounterTabs).
                    s.user.code == "CSH" -> Dest.EDITOR // langsung Kasir (memuat); gagal membuka tab → landOnKasir() jatuh ke Meja
                    s.user.code == "WTR" -> Dest.TABLES
                    else -> Dest.ORDERS
                }
                if (s.user.code == "CSH" && shift != null) landOnKasir()
                // 077 — nama/logo toko + mode sesudah login; mode sesi = mode saat masuk (pergantian sesudahnya terdeteksi di sini).
                viewModelScope.launch { sessionModeChanged() }
                loadMenu()
                startAvailabilityPolling()
                startFloorPolling()
                loadLimits()
            } catch (e: ApiError) {
                // 073 — terminal tersimpan ditolak server (dicabut / bukan milik outlet): pilihan lama tidak berlaku, ulangi penyiapan.
                if (isTerminalRevoked(e)) { onFailure(e.message); returnToSetup("Terminal ini tidak berlaku lagi. Pilih outlet dan terminal lagi."); return@launch }
                onFailure(pinMessage(e))
                if (e is ApiError.TooMany || e is ApiError.Unauthorized) loadRoster(silent = true)
            }
        }
    }

    private fun pinMessage(e: ApiError): String = when {
        e is ApiError.Unauthorized && e.code == "invalid_pin" -> e.attemptsRemaining?.let { "PIN salah. Sisa $it percobaan." } ?: "PIN salah."
        // 073 — lama kunci dari server (`locked_until`), bukan angka yang ditulis di sini.
        e is ApiError.TooMany && e.code == "pin_locked" -> PinLockMessage.text(e.lockedUntil, System.currentTimeMillis())
        else -> e.message
    }

    // ---------- menu & ketersediaan ----------
    var catalog by mutableStateOf<Load<MenuCatalog>>(Load.Idle); private set
    var soldOut by mutableStateOf<Set<String>>(emptySet()); internal set
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
    /**
     * Buka shift dengan kas awal per pecahan (server menurunkan jumlahnya) ATAU total ketikan (065). Rp 0 = modal nol.
     */
    fun openShift(count: com.warkop.pos.model.ShiftCount) {
        val b = backend ?: return
        val modal = count.total
        viewModelScope.launch {
            try {
                shift = b.openShift(count)
                report = Load.Idle; cashMovements = Load.Idle
                say("Shift dibuka dengan modal ${rp(modal)}.")
                if (user?.code == "CSH") landOnKasir() else go(Dest.TABLES)
            } catch (e: ApiError) {
                // Koneksi putus SETELAH server menerima: periksa dulu agar tidak membuka shift kedua.
                if (e is ApiError.Network) {
                    conn = Conn.OFFLINE
                    val opened = try { b.currentShift() } catch (_: ApiError) { null }
                    if (opened != null) {
                        shift = opened; if (user?.code == "CSH") landOnKasir() else go(Dest.TABLES)
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
    private fun send(intent: String, action: suspend () -> Order, retry: Boolean = true, onFail: () -> Unit = {}, after: (Order) -> Unit = {}) {
        if (modeBlocked()) return
        viewModelScope.launch {
            try {
                val o = if (retry) retryNetwork(block = action) else action()
                keys.settled(intent)
                conn = Conn.ONLINE
                upsert(o)
                after(o)
            } catch (e: ApiError) {
                onFail()
                if (e is ApiError.Network) conn = Conn.OFFLINE
                onShiftRefused(e)
                // 077 — mode berganti: dialog menggantikan pesan galat/"dicabut" (tulis tidak pernah masuk ke mode lain; server menolaknya).
                if (isModeChange(e)) return@launch
                if (isTerminalRevoked(e)) markTerminalRevoked()
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

    /**
     * 077 / US4 — simpan catatan kasir untuk seluruh order lewat jalur tulis yang sama dengan baris (`send`): gangguan jaringan
     * dicoba ulang, dan penyetelan keadaan (PUT) aman diulang sehingga tidak ada catatan ganda. [onSaved] hanya dipanggil bila
     * server menerima — layar mempertahankan teks ketikan bila gagal, jadi catatan tidak hilang diam-diam.
     */
    fun saveOrderNote(text: String, onSaved: () -> Unit = {}) {
        val b = backend ?: return
        val o = active ?: return
        val clean = text.trim()
        if (clean.length > ORDER_NOTE_MAX) { say("Catatan maksimal $ORDER_NOTE_MAX karakter."); return }
        if (o.closed) { say("Pesanan sudah ditutup. Catatan tidak bisa diubah."); return }
        send("note:${o.id}:$clean", { b.setNote(o.id, clean) }) { onSaved() }
    }

    /**
     * 084 — layar awal kasir TANPA membuat pesanan kosong: bila ada tab counter kosong milik terminal ini (sisa), dipakai
     * ulang; selain itu Kasir terbuka dalam keadaan draf (`activeId` null, lihat [kasirDraft]) dan tab counter baru baru dibuat
     * di server saat item pertama ditambahkan ([withActiveTab]). Tidak ada panggilan server, jadi tidak ada layar tunggu.
     */
    fun landOnKasir() {
        activeId = emptyCounterTabs().firstOrNull()?.id
        dest = Dest.EDITOR; sheet = null
    }

    /** 084 — pesanan counter kosong untuk TAMPILAN Kasir sebelum tab dibuat di server (id kosong; tidak pernah dikirim). */
    val kasirDraft: Order get() = Order("", OrderKind.COUNTER, null, 0, null, user?.name.orEmpty(), "")

    /** 084 — jalankan [onReady] dengan id tab aktif; bila Kasir masih draf, tab counter dibuat dulu (sekali, idempoten). */
    private fun withActiveTab(onReady: (String) -> Unit) {
        val id = activeId
        if (id != null) { onReady(id); return }
        newCounter(onOpened = { o -> onReady(o.id) })
    }

    /** Tab counter milik terminal ini yang belum berisi baris; server tetap memutuskan boleh-tidaknya dibuang (422 bila terisi). */
    private fun emptyCounterTabs(): List<Order> =
        orders.filter { !it.closed && it.kind == OrderKind.COUNTER && it.live.isEmpty() && it.otherDevice == null }

    /**
     * 084 — shift ditutup: pesanan shift itu keluar dari daftar Pesanan aktif (terbuka maupun selesai) dan cache-nya
     * dikosongkan. Hanya tampilan klien; datanya tetap utuh di server (Penjualan, laporan).
     */
    private fun clearClosedShiftOrders() {
        closedCache = emptyList(); closedLoad = Load.Idle; closedDetailLoad = emptyMap()
        orders = emptyList()
        activeId = null
    }

    /** 084 — membuang tab kosong supaya tidak memblokir tutup shift (409 open_tabs). Galat diabaikan: server yang berwenang. */
    private suspend fun discardEmptyCounterTabs(ids: List<String> = emptyCounterTabs().map { it.id }) {
        val b = backend ?: return
        ids.forEach { id ->
            try { b.closeEmptyTab(id); orders = orders.filter { it.id != id }; if (activeId == id) activeId = null } catch (_: ApiError) { }
        }
    }

    /**
     * 084 — mengubah meja pesanan (terbuka: pindah ke meja bebas; selesai: koreksi penempatan). Server menolak meja
     * terisi/nonaktif/beda outlet dengan pesan sendiri; denah dan daftar selesai disegarkan supaya Meja, Kasir, dan Pesanan sejalan.
     */
    fun changeTable(orderId: String, tableId: Long) {
        // Kasir masih draf (belum ada tab di server): buat tab counter dulu, lalu pindahkan ke meja itu.
        if (orderId.isBlank()) { withActiveTab { id -> changeTable(id, tableId) }; return }
        val b = backend ?: return
        send("move:$orderId", { b.moveTab(orderId, tableId) }, retry = false) { o ->
            fetchFloorNow()
            if (o.closed) loadClosed()
            say("Meja diubah.")
        }
    }

    fun newCounter(onFail: () -> Unit = {}, onOpened: (Order) -> Unit = {}) {
        if (modeBlocked()) return
        val b = backend ?: return
        if (terminalRevoked) { say("Terminal ini dicabut. Tidak bisa membuka pesanan baru."); return }
        val sid = shift?.id ?: run { say("Buka shift dulu sebelum membuka pesanan."); return }
        val intent = "open:counter"
        val key = keys.keyFor(intent)
        send(intent, { b.openTab(sid, null, null, key) }, onFail = onFail) { o -> activeId = o.id; dest = Dest.EDITOR; sheet = null; onOpened(o) }
    }

    /** Pilih jumlah tamu: tab terbuka di meja itu dibuka; meja kosong membuka tab baru. */
    fun openTable(table: String, pax: Int) {
        if (modeBlocked()) return
        val b = backend ?: return
        val ft = tables.firstOrNull { it.name == table } ?: run { say("Meja $table tidak ditemukan."); return }
        ft.tab?.let { openOrder(it.id.toString()); sheet = null; return }
        if (terminalRevoked) { say("Terminal ini dicabut. Tidak bisa membuka pesanan baru."); return }
        val sid = shift?.id ?: run { say("Buka shift dulu sebelum membuka pesanan."); return }
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

    /** 075 — kartu produk: satu varian langsung masuk jalur lama; beberapa varian membuka daftar varian. */
    fun tapEntry(e: ProductEntry) {
        if (e.isSingle) { tapProduct(e.variants.first()); return }
        if (e.isSoldOut(soldOut)) { say("${e.name} sedang habis."); return }
        sheet = Sheet.VariantPicker(e.id)
    }

    /** 075 — varian dipilih dari daftar varian: tutup daftar lalu pakai jalur lama (cek habis, langkah opsi, addLine). */
    fun pickVariant(p: Product) { sheet = null; tapProduct(p) }

    fun tapProduct(p: Product) {
        if (p.id in soldOut) { say("${p.name} sedang habis."); return }
        if (p.groups.isEmpty()) addLine(p, emptyList(), "") else sheet = Sheet.Modifier(p)
    }

    /** Tambah baris ke tab aktif. Server menggabungkan baris identik (belum terkirim) sesuai aturannya sendiri. */
    fun addLine(p: Product, mods: List<ModOption>, note: String, qty: Int = 1) {
        val b = backend ?: return
        sheet = null
        // 084 — Kasir draf: tab counter dibuat saat item pertama ditambahkan, bukan saat layar dibuka.
        withActiveTab { tab ->
            val intent = "line:add:$tab:${p.id}:$qty:${mods.map { it.id }}:${note.trim()}"
            val key = keys.keyFor(intent)
            send(intent, { b.addLine(tab, p.id, qty, note.trim(), mods.map { it.id }, key) })
        }
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
        if (modeBlocked()) return
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

    // ---------- 084: cetak laporan shift ----------

    private fun shiftOutletText(): String =
        config?.let { c -> listOf(c.outletName, c.deviceName).filter { it.isNotBlank() }.joinToString(" · ") }.orEmpty()

    private fun nowText(): String =
        java.time.ZonedDateTime.now(OrderMapper.STORE_ZONE).format(java.time.format.DateTimeFormatter.ofPattern("dd/MM/yyyy HH:mm"))

    /** Pesan bila printer belum bisa dipakai; null = lanjut. Cetakan tetap masuk antrean walau printer sedang terputus. */
    private fun printerOrExplain(): PrinterService? =
        printer ?: run { say("Printer belum tersedia. Pasang printer di menu Koneksi."); null }

    /** X-report: ringkasan yang sedang tampil (angka server) dikirim ke antrean cetak. Tiap penekanan = satu cetakan baru. */
    fun printXReport() {
        val p = printerOrExplain() ?: return
        val sid = shift?.id ?: run { say("Tidak ada shift terbuka."); return }
        val r = (report as? Load.Ready)?.value ?: run { say("Laporan belum dimuat. Coba lagi sebentar."); return }
        val lines = Documents.xReport(r, shiftOutletText(), user?.name.orEmpty(), shift?.openedAt.orEmpty(), nowText())
        if (p.enqueueRendered(PrintKind.LAPORAN, "x:$sid:${System.currentTimeMillis()}", 1, lines)) say("X-report masuk antrean cetak.")
    }

    /** Data Z-report diambil saat shift ditutup (angka shift sebelum cache laporan dikosongkan). */
    private var zSnapshot: ZSnapshot? = null
    private data class ZSnapshot(val sessionId: Long, val report: ShiftReport?, val cashier: String, val openedAt: String, val outlet: String)

    /** Z-report shift yang baru ditutup. Bila angka shift tak sempat termuat, diambil dari server (laporan shift tertutup tetap terbaca). */
    fun printZReport() {
        val p = printerOrExplain() ?: return
        val b = backend ?: return
        val z = zSnapshot ?: run { say("Tidak ada shift yang baru ditutup."); return }
        val close = closeResult ?: run { say("Hasil penutupan tidak tersedia."); return }
        viewModelScope.launch {
            val r = z.report ?: try { retryNetwork { b.shiftReport(z.sessionId) } } catch (e: ApiError) { say("Laporan belum bisa dimuat: ${e.message}"); return@launch }
            val lines = Documents.zReport(r, close, z.outlet, z.cashier, z.openedAt, nowText())
            if (p.enqueueRendered(PrintKind.LAPORAN, "z:${z.sessionId}:${System.currentTimeMillis()}", 1, lines)) say("Z-report masuk antrean cetak.")
        }
    }

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
                say(if (lineId == null) "Diskon pesanan diterapkan." else "Diskon item diterapkan.")
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
                say("Diskon pesanan dihapus.")
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
        if (modeBlocked()) return
        val o = active ?: return
        if (user?.code == "WTR") { printPrebill(); return }
        if (o.live.isEmpty()) return
        if (shift == null) {
            sheet = Sheet.Confirm("Belum ada shift terbuka", "Pembayaran hanya bisa diterima setelah shift terbuka. Pesanan tetap tersimpan.", "Buka shift") { dest = Dest.OPEN_SHIFT; sheet = null }
            return
        }
        dest = Dest.TENDER
    }

    /** Tunai: server menghitung kembalian sendiri. Pembayaran ber-`client_ref`, aman diulang. */
    fun payCash(received: Int) {
        if (modeBlocked()) return
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
                say("Pesanan dibatalkan.")
            } catch (e: ApiError) {
                if (e is ApiError.Network) conn = Conn.OFFLINE
                // has_payments tidak pernah menawarkan persetujuan: pesannya langsung ditampilkan.
                if (e.code == "override_required") {
                    sheet = Sheet.Override(summary = "Batalkan pesanan · $reason", action = "cancel_order",
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
        if (modeBlocked()) return
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
            // 065 — gagal memuat ulang tidak menghapus kanal yang sudah termuat (sebelumnya null → "QR belum diatur").
            qrisChannel = try { b.qrisChannel() } catch (e: ApiError) { qrisChannel }
            qrisLabel = qrisChannel?.provider ?: "QRIS"
        }
    }

    /** QRIS statis: dicatat langsung terverifikasi (084; server lama menyimpan pending). Kanal QRIS dari daftar kanal server. */
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
                    QrisNext.Finish -> {
                        onSaleClosed(updated); dest = Dest.DONE
                        // 084 — server baru mencatat QRIS langsung terverifikasi; pesan "menunggu" hanya untuk server lama yang masih pending.
                        say(if (updated.hasPendingQris) "QRIS dicatat · menunggu verifikasi." else "QRIS dicatat.")
                    }
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
                say("Pesanan direfund penuh.")
            } catch (e: ApiError) {
                if (e is ApiError.Network) { conn = Conn.OFFLINE; say(e.message); return@launch }
                keys.settled(intent)
                if (e.code == "override_required") {
                    sheet = Sheet.Override(
                        summary = "Refund pesanan · ${reason.label}", action = "refund",
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
        // 084 — daftar Selesai hanya milik shift yang sedang terbuka; tanpa shift tidak ada yang ditampilkan (pesanan shift
        // yang sudah ditutup tetap ada di Penjualan dan laporan, bukan di daftar aktif ini).
        val sid = shift?.id ?: run { closedCache = emptyList(); closedLoad = Load.Ready(Unit); return Job().apply { complete() } }
        if (closedLoad !is Load.Ready) closedLoad = Load.Loading
        return viewModelScope.launch {
            closedLoad = try {
                closedCache = retryNetwork { b.closedOrders(sid) }
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

    /**
     * Subjek persetujuan selisih tutup shift: identitas shift + hitungan yang dikirim ke POST close — pecahan ATAU
     * total ketikan (065); token terikat ke angka yang SEBENARNYA dikirim.
     */
    private fun countSubject(sessionId: Long, count: com.warkop.pos.model.ShiftCount): JsonObject = buildJsonObject {
        put("session_id", sessionId)
        when (count) {
            is com.warkop.pos.model.ShiftCount.Typed -> put("counted_total", count.total)
            is com.warkop.pos.model.ShiftCount.ByDenomination -> putJsonArray("counted_by_denomination") {
                count.rows.forEach { (d, q) -> addJsonObject { put("denomination", d); put("qty", q) } }
            }
        }
    }

    /**
     * Tutup shift dengan hitungan buta. Server yang menjumlahkan dan menilai selisih. Penutupan tidak idempoten di
     * server, jadi tidak ada coba-ulang otomatis: jika koneksi putus, status dianggap belum diketahui.
     */
    fun submitCount(count: com.warkop.pos.model.ShiftCount, overrideToken: String? = null) {
        val b = backend ?: return
        val sid = shift?.id ?: return
        // Satu kunci per niat tutup: coba-ulang jaringan memakai kunci yang sama, server menjawab dengan hasil tersimpan.
        val intent = "close:$sid"
        val key = keys.keyFor(intent)
        viewModelScope.launch {
            try {
                discardEmptyCounterTabs() // 084 — tab kosong dari layar awal Kasir tidak boleh menggagalkan tutup shift
                val outcome = retryNetwork { b.closeShift(sid, count, key, overrideToken) }
                keys.settled(intent)
                conn = Conn.ONLINE
                closeResult = outcome
                zSnapshot = ZSnapshot(sid, (report as? Load.Ready)?.value, user?.name.orEmpty(), shift?.openedAt.orEmpty(), shiftOutletText())
                report = Load.Idle
                clearClosedShiftOrders()
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
                    sheet = Sheet.Override(summary = "Selisih kas di atas toleransi", action = "variance", subject = countSubject(sid, count)) { token ->
                        submitCount(count, token)
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
