package com.warkop.pos.ui

import com.warkop.pos.model.StaffUser

/**
 * 085 / US2 — bentuk layar PIN: dua kolom ([Info Outlet di atas, kartu pengguna di bawah] | PIN) atau satu kolom bertumpuk.
 * Tiga kolom (078) dihapus: permintaan pemilik dua kolom, dan kolom PIN kini lebih lebar.
 */
enum class PinLayout { TWO_COLUMN, STACKED }

/** Lebar minimum (dp) dua kolom: di bawah ini keypad besar (≈ 440 dp) dan kolom kiri tidak muat berdampingan. */
const val PIN_TWO_COLUMN_MIN_DP = 720f

/** Layar sempit (ponsel, tablet potret kecil) menumpuk kolom dalam urutan sama: Info Outlet, kartu pengguna, lalu PIN. */
fun pinLayoutFor(widthDp: Float): PinLayout = if (widthDp >= PIN_TWO_COLUMN_MIN_DP) PinLayout.TWO_COLUMN else PinLayout.STACKED

/** Alasan yang tampil pada staf tanpa PIN (redup, tidak bisa dipilih) — dulu hilang tanpa penjelasan. */
const val NO_PIN_REASON = "Belum ada PIN"

/**
 * 078/085 — satu kartu layar PIN. [expanded] = kartu TERPILIH (cincin tebal); sejak 085 ukurannya tidak berubah, karena semua kartu
 * harus seragam. Tidak ada blok "pengguna terpilih" terpisah, jadi tidak ada nama yang muncul dua kali. [selectable] false = tanpa
 * PIN: redup dan tidak bisa diketuk.
 */
data class StaffRow(val user: StaffUser, val expanded: Boolean, val selectable: Boolean)

/** Satu baris per ORANG (kunci = id): dua orang bernama sama tetap dua baris, dibedakan peran dan foto. */
fun staffRows(everyone: List<StaffUser>, selectedId: String?): List<StaffRow> =
    everyone.map { StaffRow(it, expanded = it.hasPin && it.id == selectedId, selectable = it.hasPin) }

/**
 * Pilihan awal/terjaga bila daftar dimuat ulang: pilihan lama dipertahankan (dengan data terbaru, mis. foto yang berubah), bila hilang
 * jatuh ke staf pertama yang bisa masuk, dan null bila tidak ada satu pun.
 */
fun selectedAfterRosterChange(selected: StaffUser?, usable: List<StaffUser>): StaffUser? =
    usable.firstOrNull { it.id == selected?.id } ?: usable.firstOrNull()
