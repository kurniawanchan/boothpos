package com.boothpos.customer.feature.catalog.contract

import com.boothpos.customer.core.data.testing.RealPayloads
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer

/**
 * Menyajikan jawaban rekaman (status + header + badan apa adanya) lewat MockWebServer. [status]
 * hanya diganti bila tes sengaja memakai badan rekaman untuk kode sukses lain, dan tes itu wajib
 * menjelaskan alasannya.
 */
internal fun MockWebServer.enqueueRecorded(recorded: RealPayloads.Recorded, status: Int = recorded.status) {
    val response = MockResponse.Builder().code(status)
    recorded.headers.forEach { (name, value) -> response.addHeader(name, value) }
    if (recorded.body.isNotEmpty()) response.body(recorded.body)
    enqueue(response.build())
}
