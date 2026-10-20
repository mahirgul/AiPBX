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

    @Test
    fun testDialNumberNormalization() {
        fun normalize(num: String, sendPlusPrefix: Boolean = false): String {
            var clean = num.replace(Regex("[^0-9*#+]"), "")
            if (clean.startsWith("+") && !sendPlusPrefix) {
                clean = "00" + clean.substring(1)
            }
            return clean
        }

        // Clean formatting characters
        assertEquals("05321234567", normalize("0532 123 45 67"))
        assertEquals("05321234567", normalize("0 (532) 123-45-67"))

        // International + prefix when sendPlusPrefix is false converts to 00
        assertEquals("00905321234567", normalize("+905321234567", sendPlusPrefix = false))
        assertEquals("00905321234567", normalize("+90 532 123 45 67", sendPlusPrefix = false))
        assertEquals("00491711234567", normalize("+491711234567", sendPlusPrefix = false))
        assertEquals("00447911123456", normalize("+44 7911 123456", sendPlusPrefix = false))

        // International + prefix when sendPlusPrefix is true keeps + for server normalization
        assertEquals("+905321234567", normalize("+905321234567", sendPlusPrefix = true))
        assertEquals("+905321234567", normalize("+90 532 123 45 67", sendPlusPrefix = true))
        assertEquals("+12125550199", normalize("+1 212 555 0199", sendPlusPrefix = true))

        // Internal extensions and feature codes remain untouched
        assertEquals("101", normalize("101", sendPlusPrefix = true))
        assertEquals("101", normalize("101", sendPlusPrefix = false))
        assertEquals("*97", normalize("*97"))
        assertEquals("#31#05321234567", normalize("#31#05321234567"))
    }
}
