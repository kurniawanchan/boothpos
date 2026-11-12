package com.boothpos.customer.security

import java.io.File
import javax.xml.parsers.DocumentBuilderFactory
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.w3c.dom.Document
import org.w3c.dom.Element

/**
 * Mengunci konfigurasi keamanan yang berupa berkas (bukan kode), supaya perubahan yang
 * melonggarkannya gagal di tes JVM, bukan baru ketahuan di perangkat. Direktori kerja tes unit
 * Gradle adalah direktori modul `app/`.
 *
 * Risiko yang dijaga: transport (HTTPS saja di rilis), data-at-rest (tanpa cadangan), komponen
 * yang diekspor, dan logging HTTP yang hanya ada di build debug.
 */
class SecurityConfigurationTest {

    private val androidNs = "http://schemas.android.com/apk/res/android"
    private val toolsNs = "http://schemas.android.com/tools"

    private fun xml(path: String): Document {
        val file = File(path)
        assertTrue("$path tidak ditemukan dari ${File(".").absolutePath}", file.isFile)
        return DocumentBuilderFactory.newInstance()
            .apply { isNamespaceAware = true }
            .newDocumentBuilder()
            .parse(file)
    }

    private fun Document.elements(tag: String): List<Element> =
        getElementsByTagName(tag).let { nodes -> (0 until nodes.length).map { nodes.item(it) as Element } }

    private fun Element.android(name: String): String = getAttributeNS(androidNs, name)

    @Test
    fun `konfigurasi jaringan rilis melarang teks biasa dan hanya mempercayai CA sistem`() {
        val config = xml("src/main/res/xml/network_security_config.xml")

        val base = config.elements("base-config").single()
        assertEquals("false", base.getAttribute("cleartextTrafficPermitted"))
        assertTrue("Tidak boleh ada pengecualian domain di rilis", config.elements("domain-config").isEmpty())
        assertTrue("Tidak boleh ada debug-overrides di rilis", config.elements("debug-overrides").isEmpty())
        assertEquals(listOf("system"), config.elements("certificates").map { it.getAttribute("src") })
    }

    @Test
    fun `konfigurasi jaringan debug hanya mengizinkan teks biasa ke server pengembangan lokal`() {
        val config = xml("src/debug/res/xml/network_security_config.xml")

        assertEquals("false", config.elements("base-config").single().getAttribute("cleartextTrafficPermitted"))
        val cleartext = config.elements("domain-config").filter { it.getAttribute("cleartextTrafficPermitted") == "true" }
        val domains = cleartext.flatMap { domainConfig ->
            val nodes = domainConfig.getElementsByTagName("domain")
            (0 until nodes.length).map { nodes.item(it) as Element }
        }
        assertEquals(setOf("10.0.2.2", "localhost"), domains.map { it.textContent.trim() }.toSet())
        assertTrue("Subdomain tidak ikut diizinkan", domains.all { it.getAttribute("includeSubdomains") == "false" })
        assertEquals(listOf("system"), config.elements("certificates").map { it.getAttribute("src") })
    }

    @Test
    fun `tidak ada source set rilis yang menimpa konfigurasi jaringan utama`() {
        assertFalse(File("src/release/res/xml/network_security_config.xml").exists())
    }

    @Test
    fun `manifest mematikan cadangan dan teks biasa serta memakai konfigurasi jaringan`() {
        val application = xml("src/main/AndroidManifest.xml").elements("application").single()

        assertEquals("false", application.android("allowBackup"))
        assertEquals("false", application.android("usesCleartextTraffic"))
        assertEquals("@xml/network_security_config", application.android("networkSecurityConfig"))
        assertEquals("@xml/data_extraction_rules", application.android("dataExtractionRules"))
        assertEquals("@xml/backup_rules", application.android("fullBackupContent"))
    }

    @Test
    fun `hanya activity peluncur yang diekspor dan tanpa deep link`() {
        val manifest = xml("src/main/AndroidManifest.xml")

        val components = listOf("activity", "activity-alias", "service", "receiver", "provider")
            .flatMap { tag -> manifest.elements(tag) }
            .filter { it.getAttributeNS(toolsNs, "node") != "remove" }
        val exported = components.filter { it.android("exported") == "true" }
        assertEquals(listOf(".MainActivity"), exported.map { it.android("name") })
        val launcher = exported.single().getElementsByTagName("category")
        assertEquals("android.intent.category.LAUNCHER", (launcher.item(0) as Element).android("name"))
        // Tanpa deep link: tidak ada data/BROWSABLE yang bisa membawa masukan dari luar.
        assertEquals(0, manifest.elements("data").size)
    }

    /**
     * Izin yang DIMINTA APK = gabungan manifest semua modul + pustaka. Tes ini memeriksa sisi sumber
     * proyek: manifest `app` sendiri hanya meminta INTERNET, dan `core/data` menambah
     * ACCESS_NETWORK_STATE (untuk `ConnectivityObserver`); tidak ada modul lain yang meminta izin.
     * Gabungan sesungguhnya (termasuk izin yang dibawa pustaka androidx) diperiksa
     * `MergedManifestPermissionsTest` lewat PackageManager.
     */
    @Test
    fun `izin pada manifest sumber semua modul hanya internet dan status jaringan`() {
        val permission = { manifest: Document -> manifest.elements("uses-permission").map { it.android("name") } }

        assertEquals(
            "Manifest app sendiri",
            listOf("android.permission.INTERNET"),
            permission(xml("src/main/AndroidManifest.xml")),
        )
        val bySource = File("..").walkTopDown()
            .onEnter { it.name != "build" && it.name != ".gradle" && it.name != ".git" }
            .filter { it.name == "AndroidManifest.xml" && it.parentFile.parentFile.name == "src" }
            .associate { file -> file.relativeTo(File("..")).path to permission(xml(file.path)) }
            .filterValues { it.isNotEmpty() }

        assertEquals(
            mapOf(
                "app/src/main/AndroidManifest.xml" to listOf("android.permission.INTERNET"),
                "core/data/src/main/AndroidManifest.xml" to listOf("android.permission.ACCESS_NETWORK_STATE"),
            ),
            bySource,
        )
    }

    @Test
    fun `aturan cadangan mengecualikan semua domain data aplikasi`() {
        val domains = setOf("root", "file", "database", "sharedpref", "external")
        val extraction = xml("src/main/res/xml/data_extraction_rules.xml")
        listOf("cloud-backup", "device-transfer").forEach { section ->
            val excluded = (extraction.elements(section).single().getElementsByTagName("exclude"))
                .let { nodes -> (0 until nodes.length).map { (nodes.item(it) as Element).getAttribute("domain") } }
            assertEquals(section, domains, excluded.toSet())
        }
        val legacy = xml("src/main/res/xml/backup_rules.xml").elements("exclude").map { it.getAttribute("domain") }
        assertEquals(domains, legacy.toSet())
        assertTrue(xml("src/main/res/xml/backup_rules.xml").elements("include").isEmpty())
    }

    @Test
    fun `logging HTTP hanya ada di build debug`() {
        val networkBuild = File("../core/network/build.gradle.kts").readText()
        val loggingLines = networkBuild.lines().filter { "okhttp.logging" in it }
        assertEquals(listOf("debugImplementation(libs.okhttp.logging)"), loggingLines.map { it.trim() })

        val release = File("../core/network/src/release/kotlin/com/boothpos/customer/core/network/BuildTypeInterceptors.kt")
            .readText()
        assertTrue(release.contains("emptyList()"))
        assertFalse(release.contains("Logging"))
    }
}
