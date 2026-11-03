package com.warkop.pos.state

import com.warkop.pos.model.SelfServiceAlerts
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** 090 / US8 — jeda polling saat aplikasi di depan: cukup rapat untuk pengumuman ≤ 10 detik (SC-012), cukup longgar untuk server toko. */
const val SELF_SERVICE_POLL_MS = 5_000L
const val SELF_SERVICE_MAX_BACKOFF_MS = 60_000L

/**
 * 090 / US8 — polling pesanan mandiri baru. Hanya berjalan saat aplikasi di depan (seperti [HeartbeatWorker]: tidak ada beban latar).
 *
 * ATURAN INTI (FR-040): panggilan PERTAMA sesudah [start], dan yang pertama sesudah gangguan jaringan, adalah "catch-up":
 * kursor dimajukan ke pesanan terbaru TANPA mengumumkan apa pun, dan hanya jumlah menunggu yang dilaporkan. Dengan begitu
 * tablet yang baru dibuka atau baru tersambung kembali tidak memutar ulang suara semua pesanan lama. Hanya pesanan yang masuk
 * SESUDAH itu diumumkan. Kursor tidak disimpan di disk: tiap awal adalah catch-up, jadi tidak ada kursor basi.
 *
 * [fetch] mengembalikan null bila belum bisa polling (mis. belum masuk): tidak dihitung gagal dan kursor tidak disentuh.
 * Kegagalan lain menaikkan jeda secara eksponensial (maks 1 menit) dan tidak pernah dilempar ke layar.
 */
class SelfServicePoller(
    private val scope: CoroutineScope,
    private val intervalMs: Long = SELF_SERVICE_POLL_MS,
    private val maxBackoffMs: Long = SELF_SERVICE_MAX_BACKOFF_MS,
    private val fetch: suspend (afterId: Long?) -> SelfServiceAlerts?,
    private val onResult: (result: SelfServiceAlerts, announce: Boolean) -> Unit,
) {
    private var job: Job? = null
    private var cursor: Long? = null
    private var catchUp = true
    private var failures = 0

    val running: Boolean get() = job?.isActive == true

    fun start() {
        if (running) return
        catchUp = true
        job = scope.launch {
            while (true) {
                tick()
                delay(nextDelayMs())
            }
        }
    }

    fun stop() {
        job?.cancel()
        job = null
    }

    /** Satu putaran polling. `internal` supaya uji dapat menggerakkannya tanpa jam sungguhan. */
    internal suspend fun tick() {
        try {
            if (catchUp) {
                val r = fetch(null) ?: return
                cursor = r.lastId
                catchUp = false
                failures = 0
                onResult(r.copy(items = emptyList()), false)
            } else {
                val r = fetch(cursor) ?: return
                cursor = maxOf(cursor ?: 0L, r.lastId)
                failures = 0
                onResult(r, true)
            }
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            failures++
            catchUp = true // sesudah gangguan, hasil pertama hanya catch-up (tanpa suara)
        }
    }

    internal fun nextDelayMs(): Long = minOf(intervalMs shl minOf(failures, 5), maxBackoffMs)
}
