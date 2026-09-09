package com.mhrgl.aipbx.engine

import android.annotation.SuppressLint
import android.content.Context
import android.net.http.SslError
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.webkit.ConsoleMessage
import android.webkit.JavascriptInterface
import android.webkit.PermissionRequest
import android.webkit.RenderProcessGoneDetail
import android.webkit.SslErrorHandler
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.webkit.WebViewAssetLoader
import com.mhrgl.aipbx.model.CallStatus
import com.mhrgl.aipbx.model.ConnectionStatus
import org.json.JSONObject

interface SipEngineListener {
    fun onConnectionStatusChanged(status: ConnectionStatus)
    fun onCallStatusChanged(status: CallStatus)
    fun onIncomingCall(callerName: String, callerNumber: String)
}

class SipWebRtcEngine(private val context: Context) {

    private val handler = Handler(Looper.getMainLooper())
    private var webView: WebView? = null
    var listener: SipEngineListener? = null

    @Volatile
    var isEngineReady: Boolean = false
        private set

    private var pendingRegisterAction: (() -> Unit)? = null
    private var lastRegisterAction: (() -> Unit)? = null

    @Volatile
    var currentCallStatus: CallStatus = CallStatus.IDLE
        private set
    @Volatile
    var currentConnectionStatus: ConnectionStatus = ConnectionStatus.DISCONNECTED
        private set

    @Volatile
    var activeCallerName: String = ""
        private set
    @Volatile
    var activeCallerNumber: String = ""
        private set

    init {
        handler.post { initWebView() }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun initWebView() {
        try {
            val wv = WebView(context.applicationContext)
            wv.settings.apply {
                javaScriptEnabled = true
                domStorageEnabled = true
                databaseEnabled = true
                mediaPlaybackRequiresUserGesture = false
                cacheMode = WebSettings.LOAD_NO_CACHE
                // Dosya-URL izinleri KAPALI (2026-09-05).
                // İçerik file:// ile değil, WebViewAssetLoader üzerinden
                // https://appassets.androidplatform.net/... sanal adresinden
                // geliyor; bu izinlerin hiçbirine ihtiyaç yok.
                // allowUniversalAccessFromFileURLs ayrıca Google Play'in
                // taradığı bir maddedir.
                allowFileAccess = false
                allowContentAccess = false
                allowFileAccessFromFileURLs = false
                allowUniversalAccessFromFileURLs = false
            }

            val assetLoader = WebViewAssetLoader.Builder()
                .addPathHandler("/assets/", WebViewAssetLoader.AssetsPathHandler(context.applicationContext))
                .build()

            wv.webViewClient = object : WebViewClient() {
                override fun shouldInterceptRequest(view: WebView?, request: WebResourceRequest?): WebResourceResponse? {
                    val url = request?.url ?: return null
                    return assetLoader.shouldInterceptRequest(url)
                }

                override fun onPageFinished(view: WebView?, url: String?) {
                    super.onPageFinished(view, url)
                    Log.d(TAG, "WebView onPageFinished: $url")
                    triggerEngineReady()
                }

                // SSL hatasında yükleme İPTAL EDİLİR — asla izin verilmez (proceed edilmez).
                //
                // Eski kod handler->proceed çağırıyordu ve Google Play bunu
                // "Unsafe Implementation of WebView SSL Error Handler" olarak
                // reddetti (2026-09-05). Zaten hiçbir işe yaramıyordu: WebView'in
                // yüklediği tek adres https://appassets.androidplatform.net/... ve
                // onu WebViewAssetLoader yakalayıp APK assets'inden veriyor, yani
                // gerçek bir TLS el sıkışması olmuyor. WSS ve TURNS de bu geri
                // çağrıyı tetiklemez.
                //
                // Buraya bir hata düşüyorsa gerçekten yanlış bir şey vardır ve
                // görünür olmalıdır; log satırı teşhis için bilerek bırakıldı.
                override fun onReceivedSslError(view: WebView?, handler: SslErrorHandler?, error: SslError?) {
                    Log.e(TAG, "WebView SSL hatasi (${error?.primaryError}) - yukleme iptal edildi: ${error?.url}")
                    handler?.cancel()
                }

                override fun onReceivedError(view: WebView?, errorCode: Int, description: String?, failingUrl: String?) {
                    Log.e(TAG, "WebView error ($errorCode): $description on $failingUrl")
                }

                override fun onRenderProcessGone(view: WebView?, detail: RenderProcessGoneDetail?): Boolean {
                    val crashed = detail?.didCrash() ?: false
                    Log.e(TAG, "WebView render process gone! didCrash=$crashed. Recreating engine...")
                    isEngineReady = false
                    try {
                        webView?.destroy()
                    } catch (e: Exception) {
                        Log.e(TAG, "Error destroying gone webView", e)
                    }
                    webView = null
                    handler.postDelayed({
                        initWebView()
                    }, 500)
                    return true
                }
            }

            wv.webChromeClient = object : WebChromeClient() {
                override fun onPermissionRequest(request: PermissionRequest?) {
                    Log.d(TAG, "WebView onPermissionRequest: ${request?.resources?.joinToString()}")
                    request?.grant(request.resources ?: arrayOf(PermissionRequest.RESOURCE_AUDIO_CAPTURE))
                }

                override fun onConsoleMessage(consoleMessage: ConsoleMessage?): Boolean {
                    Log.d(TAG, "[WebView JS] ${consoleMessage?.message()} (line ${consoleMessage?.lineNumber()})")
                    return true
                }
            }

            wv.addJavascriptInterface(AndroidBridge(), "AndroidBridge")
            wv.loadUrl("https://appassets.androidplatform.net/assets/phone_engine.html")
            webView = wv
            Log.d(TAG, "Headless WebRTC engine initialized successfully with secure context")
        } catch (e: Exception) {
            Log.e(TAG, "Failed to initialize WebView engine", e)
        }
    }

    private fun triggerEngineReady() {
        if (!isEngineReady) {
            isEngineReady = true
            Log.d(TAG, "Engine marked as READY. Executing pending or last register action...")
            handler.post {
                val act = pendingRegisterAction ?: lastRegisterAction
                act?.invoke()
                pendingRegisterAction = null
            }
        }
    }

    fun register(
        wsUrl: String,
        sipUsername: String,
        sipPassword: String,
        domain: String,
        displayName: String,
        turnUrl: String? = null,
        turnUser: String? = null,
        turnPass: String? = null
    ) {
        val action = {
            val script = "initUA(${JSONObject.quote(wsUrl)}, ${JSONObject.quote(sipUsername)}, ${JSONObject.quote(sipPassword)}, " +
                    "${JSONObject.quote(domain)}, ${JSONObject.quote(displayName)}, " +
                    "${turnUrl?.let { JSONObject.quote(it) } ?: "null"}, " +
                    "${turnUser?.let { JSONObject.quote(it) } ?: "null"}, " +
                    "${turnPass?.let { JSONObject.quote(it) } ?: "null"});"
            Log.d(TAG, "Evaluating initUA script in WebView")
            webView?.evaluateJavascript(script, null)
            Unit
        }
        lastRegisterAction = action

        handler.post {
            if (isEngineReady) {
                action()
            } else {
                Log.d(TAG, "Engine not ready yet, queuing register action")
                pendingRegisterAction = action
            }
        }
    }

    fun reRegister() {
        handler.post {
            Log.d(TAG, "Triggering reRegister in WebView")
            webView?.evaluateJavascript("reRegister();", null)
        }
    }

    fun checkEngineStatus() {
        handler.post {
            webView?.evaluateJavascript("checkEngineStatus();", null)
        }
    }

    fun makeCall(
        targetNumber: String,
        domain: String,
        turnUrl: String? = null,
        turnUser: String? = null,
        turnPass: String? = null,
        displayName: String? = null
    ) {
        activeCallerName = if (!displayName.isNullOrEmpty()) displayName else targetNumber
        activeCallerNumber = targetNumber
        currentCallStatus = CallStatus.CONNECTING
        listener?.onCallStatusChanged(CallStatus.CONNECTING)

        handler.post {
            val script = "makeCall(${JSONObject.quote(targetNumber)}, ${JSONObject.quote(domain)}, " +
                    "${turnUrl?.let { JSONObject.quote(it) } ?: "null"}, " +
                    "${turnUser?.let { JSONObject.quote(it) } ?: "null"}, " +
                    "${turnPass?.let { JSONObject.quote(it) } ?: "null"});"
            webView?.evaluateJavascript(script, null)
        }
    }

    fun answerCall() {
        handler.post {
            webView?.evaluateJavascript("answerCall();", null)
        }
    }

    fun hangupCall() {
        handler.post {
            webView?.evaluateJavascript("hangupCall();", null)
        }
        currentCallStatus = CallStatus.IDLE
        listener?.onCallStatusChanged(CallStatus.IDLE)
    }

    fun rejectCall() {
        handler.post {
            webView?.evaluateJavascript("rejectCall();", null)
        }
        currentCallStatus = CallStatus.IDLE
        listener?.onCallStatusChanged(CallStatus.IDLE)
    }

    fun sendDtmf(digit: String) {
        handler.post {
            val script = "sendDtmf(${JSONObject.quote(digit)});"
            webView?.evaluateJavascript(script, null)
        }
    }

    fun toggleMute(isMuted: Boolean) {
        handler.post {
            webView?.evaluateJavascript("toggleMute($isMuted);", null)
        }
    }

    fun toggleHold(isHold: Boolean) {
        handler.post {
            webView?.evaluateJavascript("toggleHold($isHold);", null)
        }
        if (isHold) {
            currentCallStatus = CallStatus.ON_HOLD
            listener?.onCallStatusChanged(CallStatus.ON_HOLD)
        } else {
            currentCallStatus = CallStatus.ACTIVE
            listener?.onCallStatusChanged(CallStatus.ACTIVE)
        }
    }

    fun transferCall(targetNumber: String, domain: String) {
        handler.post {
            val clean = targetNumber.replace(Regex("[^0-9*#+]"), "")
            val script = "transferCall(${JSONObject.quote(clean)}, ${JSONObject.quote(domain)});"
            Log.d(TAG, "Evaluating transferCall script: $script")
            webView?.evaluateJavascript(script, null)
        }
    }

    fun destroy() {
        handler.post {
            isEngineReady = false
            pendingRegisterAction = null
            lastRegisterAction = null
            val wv = webView
            webView = null
            if (wv != null) {
                wv.evaluateJavascript("destroyUA();") {
                    handler.postDelayed({
                        try {
                            wv.destroy()
                        } catch (e: Exception) {
                            Log.e(TAG, "Error destroying WebView", e)
                        }
                    }, 300)
                }
            }
        }
    }

    inner class AndroidBridge {
        @JavascriptInterface
        fun log(message: String) {
            Log.d(TAG, "[JsSIP] $message")
        }

        @JavascriptInterface
        fun onEngineReady() {
            Log.d(TAG, "AndroidBridge.onEngineReady received from JS")
            triggerEngineReady()
        }

        @JavascriptInterface
        fun onStatusChanged(status: String) {
            val connStatus = when (status) {
                "CONNECTED" -> ConnectionStatus.CONNECTED
                "CONNECTING" -> ConnectionStatus.CONNECTING
                else -> ConnectionStatus.DISCONNECTED
            }
            currentConnectionStatus = connStatus
            handler.post { listener?.onConnectionStatusChanged(connStatus) }
        }

        @JavascriptInterface
        fun onStatusQueryResponse(isRegistered: Boolean, isConnected: Boolean) {
            Log.d(TAG, "onStatusQueryResponse: isRegistered=$isRegistered, isConnected=$isConnected")
            if (!isRegistered && currentConnectionStatus == ConnectionStatus.CONNECTED) {
                Log.w(TAG, "Watchdog detected mismatch: native status was CONNECTED but JsSIP is NOT registered! Updating to DISCONNECTED")
                currentConnectionStatus = ConnectionStatus.DISCONNECTED
                handler.post { listener?.onConnectionStatusChanged(ConnectionStatus.DISCONNECTED) }
            } else if (isRegistered && currentConnectionStatus != ConnectionStatus.CONNECTED) {
                currentConnectionStatus = ConnectionStatus.CONNECTED
                handler.post { listener?.onConnectionStatusChanged(ConnectionStatus.CONNECTED) }
            }
        }

        @JavascriptInterface
        fun onIncomingCall(callerName: String, callerNumber: String) {
            activeCallerName = callerName
            activeCallerNumber = callerNumber
            currentCallStatus = CallStatus.RINGING_INCOMING
            handler.post {
                listener?.onCallStatusChanged(CallStatus.RINGING_INCOMING)
                listener?.onIncomingCall(callerName, callerNumber)
            }
        }

        @JavascriptInterface
        fun onCallProgress() {
            if (currentCallStatus != CallStatus.RINGING_INCOMING) {
                currentCallStatus = CallStatus.RINGING_OUTGOING
                handler.post { listener?.onCallStatusChanged(CallStatus.RINGING_OUTGOING) }
            }
        }

        @JavascriptInterface
        fun onCallConfirmed() {
            currentCallStatus = CallStatus.ACTIVE
            handler.post { listener?.onCallStatusChanged(CallStatus.ACTIVE) }
        }

        @JavascriptInterface
        fun onRemoteAudioPlaying() {
            Log.d(TAG, "Remote audio started playing in WebView")
            if (currentCallStatus == CallStatus.RINGING_OUTGOING || currentCallStatus == CallStatus.CONNECTING) {
                currentCallStatus = CallStatus.ACTIVE
                handler.post { listener?.onCallStatusChanged(CallStatus.ACTIVE) }
            }
        }

        @JavascriptInterface
        fun onCallEnded(cause: String) {
            Log.d(TAG, "Call ended with cause: $cause")
            currentCallStatus = CallStatus.ENDED
            handler.post {
                listener?.onCallStatusChanged(CallStatus.ENDED)
                // Return to idle after a brief moment
                handler.postDelayed({
                    if (currentCallStatus == CallStatus.ENDED) {
                        currentCallStatus = CallStatus.IDLE
                        listener?.onCallStatusChanged(CallStatus.IDLE)
                    }
                }, 1000)
            }
        }
    }

    companion object {
        private const val TAG = "SipWebRtcEngine"
    }
}
