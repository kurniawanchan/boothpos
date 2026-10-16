package com.warkop.pos.state

import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlin.math.abs

/** 048 / US6 — jeda heartbeat saat aplikasi di depan. Di belakang, loop dihentikan (tidak ada beban latar). */
const val HEARTBEAT_INTERVAL_MS = 60_000L

/** Batas selisih jam (detik) sebelum layar memberi peringatan. */
const val SKEW_WARN_SECONDS = 5

/** Versi aplikasi yang dilaporkan. Harus sama dengan `versionName` di app/build.gradle.kts. */
const val APP_VERSION = "1.0.0"

/**
 * Keadaan dari server. Nilai terakhir yang baik tetap dipegang saat heartbeat gagal; [reachable] yang berubah.
 * [lastOkAtMs] = waktu perangkat saat heartbeat terakhir berhasil (null = belum pernah berhasil).
 */
data class HeartbeatState(
    val lastOkAtMs: Long? = null,
    val reachable: Boolean = false,
    val skewSeconds: Int? = null,
    val menuRelease: String? = null,
)

/** Hasil satu heartbeat yang berhasil, sudah dipetakan dari DTO. */
data class HeartbeatResult(val serverTimeMs: Long, val skewSeconds: Int, val menuRelease: String?)

/** Peringatan jam: hanya bila server sudah memberi selisih dan |selisih| melewati [SKEW_WARN_SECONDS]. */
fun skewWarns(skewSeconds: Int?): Boolean = skewSeconds != null && abs(skewSeconds) > SKEW_WARN_SECONDS

/** Heartbeat berhasil: semua nilai diganti dengan yang baru dari server. */
fun HeartbeatState.onSuccess(result: HeartbeatResult, nowMs: Long): HeartbeatState = copy(
    lastOkAtMs = nowMs,
    reachable = true,
    skewSeconds = result.skewSeconds,
    menuRelease = result.menuRelease,
)

/** Heartbeat gagal: hanya status keterjangkauan yang berubah; nilai terakhir yang baik dipertahankan. */
fun HeartbeatState.onFailure(): HeartbeatState = copy(reachable = false)

/**
 * Loop heartbeat. [send] mengirim satu heartbeat dan melempar bila gagal; kegagalan ditangani di [tick] dan TIDAK
 * pernah dilempar ke UI. [start] dipanggil saat aplikasi di depan, [stop] saat di belakang.
 */
class HeartbeatWorker(
    private val scope: CoroutineScope,
    private val intervalMs: Long = HEARTBEAT_INTERVAL_MS,
    private val tick: suspend () -> Unit,
) {
    private var job: Job? = null

    val running: Boolean get() = job?.isActive == true

    fun start() {
        if (running) return
        job = scope.launch {
            while (true) {
                tick()
                delay(intervalMs)
            }
        }
    }

    fun stop() {
        job?.cancel()
        job = null
    }

    /** Satu heartbeat segera (mis. tepat setelah masuk), tanpa menunggu jeda berikutnya. */
    fun now() {
        scope.launch { tick() }
    }
}
