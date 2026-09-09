package com.mhrgl.aipbx

import com.google.gson.Gson
import com.mhrgl.aipbx.model.*
import org.junit.Assert.*
import org.junit.Test

class ModelParsingTest {

    private val gson = Gson()

    @Test
    fun testServerInfoParsing() {
        val json = """
            {
                "success": true,
                "server_time": 1757109600,
                "brand_title": "AiPBX",
                "brand_sub": "Smart PBX"
            }
        """.trimIndent()

        val info = gson.fromJson(json, ServerInfo::class.java)
        assertTrue(info.success)
        assertEquals("AiPBX", info.brandTitle)
        assertEquals("Smart PBX", info.brandSub)
    }

    @Test
    fun testLoginResponseParsing() {
        val json = """
            {
                "success": true,
                "token": "test-jwt-token-12345",
                "user": {
                    "id": 1,
                    "username": "1001",
                    "full_name": "Test User",
                    "extension": "1001",
                    "role": "admin"
                },
                "sip": {
                    "extension": "1001",
                    "sip_username": "1001-mob-webrtc",
                    "sip_password": "secretpassword",
                    "domain": "pbx.example.com",
                    "ws_url": "wss://pbx.example.com/ws",
                    "turn": {
                        "username": "turn-user",
                        "credential": "turn-pass",
                        "urls": ["turns:turn.example.com:443?transport=tcp"]
                    }
                }
            }
        """.trimIndent()

        val response = gson.fromJson(json, LoginResponse::class.java)
        assertTrue(response.success)
        assertEquals("test-jwt-token-12345", response.token)
        assertEquals("1001", response.user?.extension)
        assertEquals("1001-mob-webrtc", response.sip?.sipUsername)
        assertEquals("turns:turn.example.com:443?transport=tcp", response.sip?.turn?.urls?.firstOrNull())
    }

    @Test
    fun testCallHistoryParsing() {
        val json = """
            {
                "success": true,
                "total_returned": 2,
                "calls": [
                    {
                        "id": 101,
                        "calldate": "2026-09-05 14:30:00",
                        "direction": "in",
                        "party": "1002",
                        "party_name": "Ahmet Yilmaz",
                        "disposition": "ANSWERED",
                        "duration": 45,
                        "billsec": 42
                    },
                    {
                        "id": 102,
                        "calldate": "2026-09-05 15:00:00",
                        "direction": "missed",
                        "party": "05551234567",
                        "party_name": "",
                        "disposition": "NO ANSWER",
                        "duration": 20,
                        "billsec": 0
                    }
                ]
            }
        """.trimIndent()

        val response = gson.fromJson(json, CallHistoryResponse::class.java)
        assertTrue(response.success)
        assertEquals(2, response.totalReturned)
        assertNotNull(response.calls)
        assertEquals(2, response.calls!!.size)
        assertEquals("1002", response.calls!![0].party)
        assertEquals("in", response.calls!![0].direction)
        assertEquals(42, response.calls!![0].billsec)
        assertEquals("missed", response.calls!![1].direction)
    }

    @Test
    fun testFeaturesParsing() {
        val json = """
            {
                "success": true,
                "features": {
                    "extension": "1001",
                    "dnd_enabled": true,
                    "call_forward_number": "1005",
                    "allowed_phone_mode": "both"
                }
            }
        """.trimIndent()

        val response = gson.fromJson(json, FeaturesResponse::class.java)
        assertTrue(response.success)
        assertNotNull(response.features)
        assertTrue(response.features!!.dndEnabled)
        assertEquals("1005", response.features!!.callForwardNumber)
        assertEquals("both", response.features!!.allowedPhoneMode)
    }

    @Test
    fun testFullCallForwardingFeaturesParsing() {
        val json = """
            {
                "success": true,
                "features": {
                    "extension": "1001",
                    "dnd_enabled": false,
                    "call_forward_number": "1002",
                    "cf_busy_number": "1003",
                    "cf_noanswer_number": "1004",
                    "cf_noanswer_timeout": 25,
                    "allowed_phone_mode": "both"
                }
            }
        """.trimIndent()

        val response = gson.fromJson(json, FeaturesResponse::class.java)
        assertTrue(response.success)
        val f = response.features
        assertNotNull(f)
        assertFalse(f!!.dndEnabled)
        assertEquals("1002", f.callForwardNumber)
        assertEquals("1003", f.cfBusyNumber)
        assertEquals("1004", f.cfNoAnswerNumber)
        assertEquals(25, f.cfNoAnswerTimeout)
        assertEquals("both", f.allowedPhoneMode)
    }

    @Test
    fun testLoginResponseWithPushConfigParsing() {
        val json = """
            {
                "success": true,
                "token": "test-jwt-token-with-push",
                "user": {
                    "id": 1,
                    "username": "1001",
                    "extension": "1001"
                },
                "push_config": {
                    "enabled": true,
                    "provider": "fcm",
                    "fcm_project_id": "aipbx-project",
                    "fcm_app_id": "1:123456789:android:abcdef",
                    "fcm_api_key": "AIzaSyDummyApiKeyForTesting12345",
                    "fcm_sender_id": "123456789"
                }
            }
        """.trimIndent()

        val response = gson.fromJson(json, LoginResponse::class.java)
        assertTrue(response.success)
        assertNotNull(response.pushConfig)
        assertEquals(true, response.pushConfig?.enabled)
        assertEquals("fcm", response.pushConfig?.provider)
        assertEquals("aipbx-project", response.pushConfig?.fcmProjectId)
        assertEquals("1:123456789:android:abcdef", response.pushConfig?.fcmAppId)
        assertEquals("AIzaSyDummyApiKeyForTesting12345", response.pushConfig?.fcmApiKey)
        assertEquals("123456789", response.pushConfig?.fcmSenderId)
    }

    @Test
    fun testLoginResponseWithoutPushConfigParsing() {
        val json = """
            {
                "success": true,
                "token": "test-jwt-token-no-push",
                "user": {
                    "id": 1,
                    "username": "1001",
                    "extension": "1001"
                },
                "push_config": null
            }
        """.trimIndent()

        val response = gson.fromJson(json, LoginResponse::class.java)
        assertTrue(response.success)
        assertNull(response.pushConfig)
    }

    @Test
    fun testChatModelsParsing() {
        val convJson = """
            {
                "success": true,
                "conversations": [
                    {
                        "id": 1,
                        "type": "direct",
                        "direct_key": "1001:1002",
                        "created_by": "1001",
                        "last_message_text": "Selam!",
                        "last_message_at": "2026-09-06 14:30:00",
                        "unread_count": 2,
                        "target_ext": "1002",
                        "target_name": "Ahmet Yilmaz",
                        "target_online": true
                    }
                ]
            }
        """.trimIndent()

        val convRes = gson.fromJson(convJson, ChatConversationsResponse::class.java)
        assertTrue(convRes.success)
        assertEquals(1, convRes.conversations?.size)
        val c = convRes.conversations!![0]
        assertEquals(1, c.id)
        assertEquals("1002", c.targetExt)
        assertEquals("Ahmet Yilmaz", c.targetName)
        assertEquals(2, c.unreadCount)
        assertTrue(c.targetOnline)

        val msgJson = """
            {
                "success": true,
                "messages": [
                    {
                        "id": 105,
                        "conversation_id": 1,
                        "sender_ext": "1002",
                        "sender_name": "Ahmet Yilmaz",
                        "msg_type": "image",
                        "message": "Fatura ekte",
                        "attachment_url": "/chat/media/images/fatura.jpg",
                        "file_name": "fatura.jpg",
                        "file_size": 245000,
                        "mime_type": "image/jpeg",
                        "created_at": "2026-09-06 14:35:00"
                    }
                ]
            }
        """.trimIndent()

        val msgRes = gson.fromJson(msgJson, ChatMessagesResponse::class.java)
        assertTrue(msgRes.success)
        assertEquals(1, msgRes.messages?.size)
        val m = msgRes.messages!![0]
        assertEquals(105L, m.id)
        assertEquals("image", m.msgType)
        assertEquals("/chat/media/images/fatura.jpg", m.attachmentUrl)
    }
}
