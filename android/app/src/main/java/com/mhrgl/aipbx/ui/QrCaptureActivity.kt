package com.mhrgl.aipbx.ui

import android.content.Context
import android.graphics.Color
import android.os.Bundle
import android.view.KeyEvent
import android.view.View
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import com.journeyapps.barcodescanner.CaptureManager
import com.journeyapps.barcodescanner.DecoratedBarcodeView

/**
 * Edge-to-edge QR scanner activity replacing ZXing's non-ComponentActivity CaptureActivity
 * so every activity in the manifest supports Android 15+ edge-to-edge and WindowInsets.
 */
class QrCaptureActivity : BaseActivity() {

    private lateinit var capture: CaptureManager
    private lateinit var barcodeScannerView: DecoratedBarcodeView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(com.google.zxing.client.android.R.layout.zxing_capture)

        barcodeScannerView = findViewById(com.google.zxing.client.android.R.id.zxing_barcode_scanner)
        val statusView = findViewById<View>(com.google.zxing.client.android.R.id.zxing_status_view)
        // Base paddings: insets are dispatched more than once and must not add up.
        val baseLeftPadding = statusView?.paddingLeft ?: 0
        val baseRightPadding = statusView?.paddingRight ?: 0
        val baseBottomPadding = statusView?.paddingBottom ?: 0
        ViewCompat.setOnApplyWindowInsetsListener(barcodeScannerView) { _, insets ->
            val bars = insets.getInsets(
                WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout()
            )
            statusView?.setPadding(
                baseLeftPadding + bars.left,
                statusView.paddingTop,
                baseRightPadding + bars.right,
                baseBottomPadding + bars.bottom
            )
            insets
        }

        capture = CaptureManager(this, barcodeScannerView)
        capture.initializeFromIntent(intent, savedInstanceState)
        capture.decode()
    }

    override fun onResume() {
        super.onResume()
        capture.onResume()
    }

    override fun onPause() {
        super.onPause()
        capture.onPause()
    }

    override fun onDestroy() {
        super.onDestroy()
        capture.onDestroy()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        capture.onSaveInstanceState(outState)
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        capture.onRequestPermissionsResult(requestCode, permissions, grantResults)
    }

    override fun onKeyDown(keyCode: Int, event: KeyEvent?): Boolean {
        return barcodeScannerView.onKeyDown(keyCode, event) || super.onKeyDown(keyCode, event)
    }
}
