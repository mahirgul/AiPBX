package com.mhrgl.aipbx

import org.junit.Assert.*
import org.junit.Test

class SipFormattingTest {

    @Test
    fun testCallerNumberCleaningRegex() {
        val regex = Regex("-(mob-webrtc|webrtc|sip)$")
        assertEquals("1001", "1001-mob-webrtc".replace(regex, ""))
        assertEquals("1002", "1002-webrtc".replace(regex, ""))
        assertEquals("1003", "1003-sip".replace(regex, ""))
        assertEquals("05551234567", "05551234567".replace(regex, ""))
        assertEquals("1010", "1010".replace(regex, ""))
    }

    @Test
    fun testSipUsernameFormatting() {
        val ext = "1001"
        val formatted = "${ext}-mob-webrtc"
        assertEquals("1001-mob-webrtc", formatted)
        assertTrue(formatted.endsWith("-mob-webrtc"))
    }

    @Test
    fun testServerUrlSanitization() {
        val raw1 = "  https://santral.example.com/  "
        val clean1 = raw1.trim().trimEnd('/')
        assertEquals("https://santral.example.com", clean1)

        val raw2 = "http://192.168.1.50:8080///"
        val clean2 = raw2.trim().trimEnd('/')
        assertEquals("http://192.168.1.50:8080", clean2)

        assertTrue(clean1.startsWith("https://") || clean1.startsWith("http://"))
    }
}
