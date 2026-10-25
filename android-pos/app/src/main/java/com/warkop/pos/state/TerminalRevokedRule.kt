package com.warkop.pos.state

import com.warkop.core.domain.ApiError

/**
 * 073 / FR-018 — server menjawab 422 pada kolom `device_id` bila terminal tablet ini dicabut (heartbeat, buka tab, buka shift,
 * login PIN). Itu satu-satunya sinyal "terminal dicabut"; galat 422 pada kolom lain tidak berarti itu.
 */
fun isTerminalRevoked(e: ApiError): Boolean = e is ApiError.Validation && e.fields.containsKey("device_id")

/** Apa yang dilakukan saat pencabutan terdeteksi: tab yang sedang dikerjakan boleh selesai, selain itu keluar sekarang. */
enum class RevokedStep { FINISH_ORDER, EXIT_NOW }

fun revokedStepAt(dest: Dest): RevokedStep = if (dest in FINISHING_ORDER) RevokedStep.FINISH_ORDER else RevokedStep.EXIT_NOW

/** Terminal sudah dicabut dan staf pindah ke [target]: keluar dari alur menyelesaikan order berarti kembali ke penyiapan. */
fun exitsOnNavigate(revoked: Boolean, target: Dest): Boolean = revoked && target !in FINISHING_ORDER && target != Dest.SETUP
