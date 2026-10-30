package com.warkop.pos.ui

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import kotlinx.coroutines.delay
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * 084 — milidetik yang harus ditunggu sampai batas [stepMs] berikutnya (menit/detik), supaya jam berganti TEPAT saat
 * menit berganti, bukan sampai satu menit terlambat. Murni agar bisa diuji. Selalu 1..[stepMs].
 */
fun nextTickDelayMs(nowMs: Long, stepMs: Long): Long {
    val rest = stepMs - Math.floorMod(nowMs, stepMs)
    return if (rest <= 0L) stepMs else rest
}

/** 084 — teks jam dari [nowMs] dengan [pattern] (zona waktu perangkat; sama dengan jam dinding yang dilihat kasir). */
fun clockText(nowMs: Long, pattern: String = "HH:mm"): String = SimpleDateFormat(pattern, Locale.US).format(Date(nowMs))

/**
 * 084 — jam berdetak: dulu jam hanya dihitung sekali (`remember { format(Date()) }`) sehingga membeku di waktu layar dibuka.
 * Sekarang diperbarui tiap batas menit selama komposabel tampil; berhenti otomatis saat keluar layar.
 */
@Composable
fun liveClockText(pattern: String = "HH:mm", stepMs: Long = 60_000L): String {
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(stepMs) {
        while (true) {
            delay(nextTickDelayMs(System.currentTimeMillis(), stepMs))
            now = System.currentTimeMillis()
        }
    }
    return clockText(now, pattern)
}
