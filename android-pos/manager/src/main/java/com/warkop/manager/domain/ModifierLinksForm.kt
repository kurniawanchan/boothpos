package com.warkop.manager.domain

import com.warkop.manager.data.dto.ModifierLinkDto
import kotlinx.serialization.EncodeDefault
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** Grup modifier yang bisa ditautkan (`GET /modifier-groups`, ringkas). */
@Serializable data class GroupChoice(val id: Long, val name: String, val single: Boolean, val isRequired: Boolean, val minSelect: Int, val maxSelect: Int?, val active: Boolean = true)

@Serializable data class LinkRow(val groupId: Long, val name: String, val single: Boolean, val isRequired: Boolean, val min: String, val max: String)

/** Grup warisan kategori (ditampilkan; bisa dilepas/dipulihkan, tidak bisa diubah aturannya di sini). */
@Serializable data class InheritedRow(val groupId: Long, val name: String, val rule: String)

@Serializable data class LinkBody(
    @SerialName("group_id") val groupId: Long,
    @SerialName("is_required") val isRequired: Boolean,
    @SerialName("min_select") val minSelect: Int,
    @OptIn(ExperimentalSerializationApi::class) @EncodeDefault @SerialName("max_select") val maxSelect: Int?,
    @SerialName("display_order") val displayOrder: Int,
)

@Serializable data class LinksBody(val links: List<LinkBody>, @SerialName("excluded_group_ids") val excludedGroupIds: List<Long>? = null)

/**
 * 094 / T068 — editor tautan modifier, cermin `ModifierLinksDrawer.vue` + `InheritedModifierList.vue` +
 * `modifierExclusion.js`. Server (`ModifierLinkService::validate`, termasuk aturan C1 pilihan tunggal) tetap penegak.
 */
@Serializable data class ModifierLinksForm(
    val rows: List<LinkRow> = emptyList(),
    val inherited: List<InheritedRow> = emptyList(),
    val excluded: List<InheritedRow> = emptyList(),
    /** Pengecualian saat dibuka — `excluded_group_ids` dikirim HANYA bila berbeda (klien lama aman). */
    val originalExcluded: List<Long> = emptyList(),
) {
    fun excludedIds(): List<Long> = excluded.map { it.groupId }.sorted()

    /** Daftar grup termuat belakangan: tandai baris yang grupnya pilihan tunggal. */
    fun markSingle(groups: List<GroupChoice>) = copy(rows = rows.map { r -> groups.firstOrNull { it.id == r.groupId }?.let { r.copy(single = it.single) } ?: r })

    /** Grup aktif yang belum tertaut langsung. */
    fun available(groups: List<GroupChoice>) = groups.filter { it.active && rows.none { r -> r.groupId == it.id } }

    /** Bawaan saat grup ditambah (`rowFromGroup`): tunggal → max 1, min 1 bila wajib; lainnya salin aturan grup. */
    fun add(g: GroupChoice) = copy(
        rows = rows + if (g.single) LinkRow(g.id, g.name, true, g.isRequired, if (g.isRequired) "1" else "0", "1")
        else LinkRow(g.id, g.name, false, g.isRequired, g.minSelect.toString(), g.maxSelect?.toString().orEmpty()),
    )

    /** Mencentang Wajib menaikkan min ke 1 bila kosong/di bawah 1; melepas tidak mengubah min. */
    fun setRequired(groupId: Long, on: Boolean) = copy(rows = rows.map {
        if (it.groupId != groupId) it else it.copy(isRequired = on, min = if (on && (it.min.toIntOrNull() ?: 0) < 1) "1" else it.min)
    })

    fun move(index: Int, delta: Int): ModifierLinksForm {
        val to = index + delta
        if (to !in rows.indices) return this
        val list = rows.toMutableList(); val r = list.removeAt(index); list.add(to, r)
        return copy(rows = list)
    }

    fun release(groupId: Long) = inherited.firstOrNull { it.groupId == groupId }?.let { r -> copy(inherited = inherited - r, excluded = (excluded + r).sortedBy { it.name }) } ?: this
    fun restore(groupId: Long) = excluded.firstOrNull { it.groupId == groupId }?.let { r -> copy(excluded = excluded - r, inherited = (inherited + r).sortedBy { it.name }) } ?: this

    /** Kunci galat = kunci 422 server (`links.N.min_select`, `links.N.max_select`). */
    fun localErrors(): Map<String, String> = buildMap {
        rows.forEachIndexed { i, r ->
            val min = r.min.toIntOrNull()
            val max = if (r.max.isBlank()) null else r.max.toIntOrNull()
            var ok = true
            if (min == null || min < 0) { put("links.$i.min_select", "Minimum harus bilangan bulat 0 atau lebih."); ok = false }
            if (r.max.isNotBlank() && (max == null || max < 1 || (min != null && max < min))) {
                put("links.$i.max_select", "Maksimum dikosongkan (tanpa batas) atau bilangan bulat 1 atau lebih dan tidak kurang dari minimum."); ok = false
            }
            if (ok && r.single && (max == null || max > 1 || min!! > 1)) put("links.$i.max_select", "Grup pilihan tunggal: maksimum 1 dan minimum 0 atau 1.")
        }
        if (rows.size > MAX_LINKS) put("links", "Maksimal $MAX_LINKS grup per item.")
    }

    fun toBody() = LinksBody(
        links = rows.mapIndexed { i, r -> LinkBody(r.groupId, r.isRequired, r.min.toInt(), r.max.takeIf { it.isNotBlank() }?.toInt(), i + 1) },
        excludedGroupIds = excludedIds().takeIf { it != originalExcluded.sorted() },
    )

    companion object {
        const val MAX_LINKS = 30

        fun rule(required: Boolean, min: Int, max: Int?) = "${if (required) "Wajib" else "Opsional"} · " + if (max == null) "$min+" else "$min–$max"

        /** Dari detail produk: baris `source=product` diedit; `source=category` = warisan; `modifier_excluded` = dilepas. */
        fun of(links: List<ModifierLinkDto>, excluded: List<ModifierLinkDto>, singleIds: Set<Long>) = ModifierLinksForm(
            rows = links.filter { it.source != "category" }.sortedWith(compareBy({ it.displayOrder }, { it.groupId })).map {
                LinkRow(it.groupId, it.name, it.groupId in singleIds, it.isRequired, it.minSelect.toString(), it.maxSelect?.toString().orEmpty())
            },
            inherited = links.filter { it.source == "category" }.map { InheritedRow(it.groupId, it.name, rule(it.isRequired, it.minSelect, it.maxSelect)) },
            excluded = excluded.map { InheritedRow(it.groupId, it.name, rule(it.isRequired, it.minSelect, it.maxSelect)) },
            originalExcluded = excluded.map { it.groupId }.sorted(),
        )
    }
}
