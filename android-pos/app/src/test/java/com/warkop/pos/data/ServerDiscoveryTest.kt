package com.warkop.pos.data

import com.warkop.core.network.dto.ServerAddressDto
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Test

/** 065 / US6 — mencari alamat server lewat alamat bawaan: urutan, hasil, dan tidak pernah mengarang alamat. */
class ServerDiscoveryTest {
    private val local = "http://192.168.1.219:8010"
    private val online = "https://pos.example.com"

    private fun discovery(answers: Map<String, ServerAddressDto?>, defaults: List<String> = listOf(local, online), asked: MutableList<String> = mutableListOf()) =
        ServerDiscovery(defaults) { base -> asked += base; answers[base] }

    @Test fun `bawaan lokal menjawab dengan alamat pilihan admin`() = runTest {
        val r = discovery(mapOf(local to ServerAddressDto("local", "http://192.168.1.219:8010/"))).discover()
        assertEquals(DiscoveryResult.Found("http://192.168.1.219:8010"), r)
    }

    @Test fun `alamat yang disimpan adalah jawaban server, bukan alamat bawaan yang dihubungi`() = runTest {
        // Admin memilih online; tablet menghubungi bawaan lokal tetapi menyimpan alamat online dari jawaban.
        val r = discovery(mapOf(local to ServerAddressDto("online", online), online to ServerAddressDto("online", online))).discover()
        assertEquals(DiscoveryResult.Found(online), r)
    }

    @Test fun `alamat pilihan admin yang tidak terjangkau tidak disimpan`() = runTest {
        val r = discovery(mapOf(local to ServerAddressDto("online", online), online to null)).discover()
        assertEquals(DiscoveryResult.Unreachable, r)
    }

    @Test fun `lokal tidak terjangkau lalu online menjawab`() = runTest {
        val asked = mutableListOf<String>()
        val r = discovery(mapOf(local to null, online to ServerAddressDto("online", online)), asked = asked).discover()
        assertEquals(DiscoveryResult.Found(online), r)
        assertEquals(listOf(local, online), asked)
    }

    @Test fun `server menjawab tetapi pilihan admin belum punya alamat berhenti tanpa menebak`() = runTest {
        val asked = mutableListOf<String>()
        val r = discovery(mapOf(local to ServerAddressDto("online", null)), asked = asked).discover()
        assertEquals(DiscoveryResult.NotConfigured("online"), r)
        assertEquals("bawaan lain tidak ditanyai", listOf(local), asked)
    }

    @Test fun `alamat kosong atau hanya spasi dianggap belum diatur`() = runTest {
        assertEquals(DiscoveryResult.NotConfigured("local"), discovery(mapOf(local to ServerAddressDto("local", "  "))).discover())
    }

    @Test fun `tak satu pun bawaan menjawab berarti tidak terjangkau`() = runTest {
        assertEquals(DiscoveryResult.Unreachable, discovery(mapOf(local to null, online to null)).discover())
    }

    @Test fun `bawaan kosong dilewati dan tanpa bawaan sama sekali ada hasil tersendiri`() = runTest {
        val asked = mutableListOf<String>()
        discovery(mapOf(local to ServerAddressDto("local", local)), defaults = listOf("", "  ", local), asked = asked).discover()
        assertEquals(listOf(local), asked)
        assertEquals(DiscoveryResult.NoDefaults, discovery(emptyMap(), defaults = listOf("", " ")).discover())
    }

    @Test fun `bawaan ganda hanya ditanyai sekali dan garis miring akhir diabaikan`() = runTest {
        val asked = mutableListOf<String>()
        discovery(mapOf(local to null), defaults = listOf("$local/", local), asked = asked).discover()
        assertEquals(listOf(local), asked)
    }
}
