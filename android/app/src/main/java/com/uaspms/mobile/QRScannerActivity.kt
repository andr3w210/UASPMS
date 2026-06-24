package com.uaspms.mobile

import android.Manifest
import android.animation.ValueAnimator
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Bundle
import android.util.Size
import android.view.View
import android.view.animation.LinearInterpolator
import android.widget.Button
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.camera.core.Camera
import androidx.camera.core.CameraSelector
import androidx.camera.core.FocusMeteringAction
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import com.google.mlkit.vision.barcode.BarcodeScannerOptions
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import kotlin.math.max
import kotlin.math.min

class QRScannerActivity : AppCompatActivity() {

    private lateinit var previewView: PreviewView
    private lateinit var scannerFrame: View
    private lateinit var scannerLine: View
    private lateinit var scannerHint: TextView
    private lateinit var torchButton: Button
    private lateinit var zoomOutButton: Button
    private lateinit var zoomInButton: Button
    private lateinit var cameraExecutor: ExecutorService
    private var scanLineAnimator: ValueAnimator? = null
    private var lastScannedValue = ""
    private var lastScannedTime = 0L
    private var baseUrl = BuildConfig.BASE_URL
    private var camera: Camera? = null
    private var torchEnabled = false
    private var zoomRatio = 1.0f
    private var minZoomRatio = 1.0f
    private var maxZoomRatio = 1.0f

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_qr_scanner)
        baseUrl = intent.getStringExtra("BASE_URL") ?: BuildConfig.BASE_URL

        previewView = findViewById(R.id.previewView)
        scannerFrame = findViewById(R.id.scannerFrame)
        scannerLine = findViewById(R.id.scannerLine)
        scannerHint = findViewById(R.id.scannerHint)
        torchButton = findViewById(R.id.torchButton)
        zoomOutButton = findViewById(R.id.zoomOutButton)
        zoomInButton = findViewById(R.id.zoomInButton)
        cameraExecutor = Executors.newSingleThreadExecutor()
        configureScannerControls()
        configureScannerFrameSize()
        scannerFrame.post {
            startScanLineAnimation()
        }

        if (allPermissionsGranted()) {
            startCamera()
        } else {
            ActivityCompat.requestPermissions(this, REQUIRED_PERMISSIONS, REQUEST_CODE_PERMISSIONS)
        }
    }

    private fun startCamera() {
        val cameraProviderFuture = ProcessCameraProvider.getInstance(this)

        cameraProviderFuture.addListener({
            val cameraProvider: ProcessCameraProvider = cameraProviderFuture.get()

            val preview = Preview.Builder()
                .build()
                .also {
                    it.setSurfaceProvider(previewView.surfaceProvider)
                }

            val imageAnalyzer = ImageAnalysis.Builder()
                .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                .setTargetResolution(Size(1280, 720))
                .build()
                .also {
                    it.setAnalyzer(cameraExecutor, BarcodeAnalyzer { barcode ->
                        handleScannedBarcode(barcode)
                    })
                }

            val cameraSelector = CameraSelector.DEFAULT_BACK_CAMERA

            try {
                cameraProvider.unbindAll()
                camera = cameraProvider.bindToLifecycle(
                    this, cameraSelector, preview, imageAnalyzer
                )
                configureCameraAfterBind()
            } catch (exc: Exception) {
                exc.printStackTrace()
                Toast.makeText(this, "Camera binding failed", Toast.LENGTH_SHORT).show()
                finish()
            }
        }, ContextCompat.getMainExecutor(this))
    }

    private fun handleScannedBarcode(barcode: String) {
        val currentTime = System.currentTimeMillis()
        if (barcode == lastScannedValue && currentTime - lastScannedTime < 1000) {
            return
        }
        lastScannedValue = barcode
        lastScannedTime = currentTime

        if (barcode.isNotBlank()) {
            scannerHint.text = "QR detected. Opening asset..."
            navigateToScan(barcode)
        }
    }

    private fun navigateToScan(ref: String) {
        val scanUrl = "${baseUrl}modules/property/scan.php?ref=${Uri.encode(ref)}"
        val intent = Intent(this, MainActivity::class.java)
        intent.putExtra("SCAN_URL", scanUrl)
        intent.addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP)
        startActivity(intent)
        finish()
    }

    private fun allPermissionsGranted() = REQUIRED_PERMISSIONS.all {
        ContextCompat.checkSelfPermission(baseContext, it) == PackageManager.PERMISSION_GRANTED
    }

    override fun onRequestPermissionsResult(
        requestCode: Int, permissions: Array<String>, grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == REQUEST_CODE_PERMISSIONS) {
            if (allPermissionsGranted()) {
                startCamera()
            } else {
                Toast.makeText(this, "Camera permission required", Toast.LENGTH_SHORT).show()
                finish()
            }
        }
    }

    override fun onDestroy() {
        scanLineAnimator?.cancel()
        scanLineAnimator = null
        super.onDestroy()
        cameraExecutor.shutdown()
    }

    private fun startScanLineAnimation() {
        val frameHeight = scannerFrame.height
        val lineHeight = scannerLine.height
        if (frameHeight <= 0 || lineHeight <= 0) {
            return
        }

        val topPadding = 20f
        val bottomPadding = 20f
        val travelDistance = frameHeight - lineHeight - topPadding - bottomPadding
        if (travelDistance <= 0f) {
            return
        }

        scanLineAnimator?.cancel()
        scanLineAnimator = ValueAnimator.ofFloat(topPadding, topPadding + travelDistance).apply {
            duration = 1800L
            repeatCount = ValueAnimator.INFINITE
            repeatMode = ValueAnimator.REVERSE
            interpolator = LinearInterpolator()
            addUpdateListener { animation ->
                scannerLine.translationY = animation.animatedValue as Float
            }
            start()
        }
    }

    private fun configureScannerFrameSize() {
        val density = resources.displayMetrics.density
        val availableWidth = max(0, resources.displayMetrics.widthPixels - (48 * density).toInt())
        val availableHeight = max(0, (resources.displayMetrics.heightPixels * 0.42f).toInt())
        val maxFrame = (280 * density).toInt()
        val minFrame = min((220 * density).toInt(), availableWidth)
        val frameSize = min(maxFrame, min(availableWidth, availableHeight)).coerceAtLeast(minFrame)

        scannerFrame.layoutParams = scannerFrame.layoutParams.apply {
            width = frameSize
            height = frameSize
        }
    }

    private fun configureScannerControls() {
        torchButton.setOnClickListener {
            val activeCamera = camera ?: return@setOnClickListener
            torchEnabled = !torchEnabled
            activeCamera.cameraControl.enableTorch(torchEnabled)
            torchButton.text = if (torchEnabled) "Light On" else "Light"
        }

        zoomOutButton.setOnClickListener {
            setZoomRatio(zoomRatio - 0.25f)
        }

        zoomInButton.setOnClickListener {
            setZoomRatio(zoomRatio + 0.25f)
        }

        previewView.setOnClickListener {
            focusAtCenter()
        }
    }

    private fun configureCameraAfterBind() {
        val activeCamera = camera ?: return
        val zoomState = activeCamera.cameraInfo.zoomState.value
        if (zoomState != null) {
            minZoomRatio = zoomState.minZoomRatio
            maxZoomRatio = min(zoomState.maxZoomRatio, 3.0f)
            zoomRatio = min(max(1.25f, minZoomRatio), maxZoomRatio)
            activeCamera.cameraControl.setZoomRatio(zoomRatio)
        }
        updateZoomButtons()
        focusAtCenter()
    }

    private fun setZoomRatio(value: Float) {
        val activeCamera = camera ?: return
        zoomRatio = min(max(value, minZoomRatio), maxZoomRatio)
        activeCamera.cameraControl.setZoomRatio(zoomRatio)
        scannerHint.text = "Hold steady. Move closer if the QR is small."
        updateZoomButtons()
    }

    private fun updateZoomButtons() {
        zoomOutButton.isEnabled = zoomRatio > minZoomRatio + 0.01f
        zoomInButton.isEnabled = zoomRatio < maxZoomRatio - 0.01f
    }

    private fun focusAtCenter() {
        val activeCamera = camera ?: return
        val factory = previewView.meteringPointFactory
        val point = factory.createPoint(previewView.width / 2f, previewView.height / 2f)
        val action = FocusMeteringAction.Builder(point, FocusMeteringAction.FLAG_AF)
            .setAutoCancelDuration(2, TimeUnit.SECONDS)
            .build()
        activeCamera.cameraControl.startFocusAndMetering(action)
    }

    private inner class BarcodeAnalyzer(private val onBarcodeDetected: (String) -> Unit) :
        ImageAnalysis.Analyzer {
        private val scanner = BarcodeScanning.getClient(
            BarcodeScannerOptions.Builder()
                .setBarcodeFormats(Barcode.FORMAT_QR_CODE)
                .build()
        )

        override fun analyze(imageProxy: ImageProxy) {
            val mediaImage = imageProxy.image
            if (mediaImage != null) {
                val image = InputImage.fromMediaImage(mediaImage, imageProxy.imageInfo.rotationDegrees)
                scanner.process(image)
                    .addOnSuccessListener { barcodes ->
                        for (barcode in barcodes) {
                            barcode.rawValue?.let { onBarcodeDetected(it) }
                        }
                    }
                    .addOnFailureListener {
                        it.printStackTrace()
                    }
                    .addOnCompleteListener {
                        imageProxy.close()
                    }
            } else {
                imageProxy.close()
            }
        }
    }

    companion object {
        private const val REQUEST_CODE_PERMISSIONS = 10
        private val REQUIRED_PERMISSIONS = arrayOf(Manifest.permission.CAMERA)
    }
}
