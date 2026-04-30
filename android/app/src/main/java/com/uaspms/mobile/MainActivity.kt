package com.uaspms.mobile

import android.annotation.SuppressLint
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.MediaStore
import android.view.Menu
import android.view.MenuItem
import android.view.MotionEvent
import android.view.ViewConfiguration
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.FileProvider
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import com.google.android.material.floatingactionbutton.FloatingActionButton
import java.io.File
import java.io.IOException
import kotlin.math.abs
import kotlin.math.max
import kotlin.math.min

class MainActivity : AppCompatActivity() {

    private lateinit var webView: WebView
    private lateinit var swipeRefreshLayout: SwipeRefreshLayout
    private lateinit var scanFab: FloatingActionButton
    private var filePathCallback: ValueCallback<Array<Uri>>? = null
    private var cameraImageUri: Uri? = null
    private var currentBaseUrlIndex = 0

    private lateinit var fileChooserLauncher: ActivityResultLauncher<Intent>

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        webView = findViewById(R.id.webView)
        swipeRefreshLayout = findViewById(R.id.swipeRefreshLayout)
        scanFab = findViewById(R.id.scanFab)
        clearLegacyServerSettings()
        scanFab.setOnClickListener {
            launchQrScanner()
        }
        configureMovableScanFab()

        fileChooserLauncher = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            val callback = filePathCallback
            if (callback == null) {
                return@registerForActivityResult
            }

            val results = mutableListOf<Uri>()

            if (result.resultCode == RESULT_OK) {
                val dataUri = result.data?.data
                if (dataUri != null) {
                    results.add(dataUri)
                } else if (cameraImageUri != null) {
                    results.add(cameraImageUri!!)
                }
            }

            callback.onReceiveValue(if (results.isEmpty()) null else results.toTypedArray())
            filePathCallback = null
            cameraImageUri = null
        }

        configureWebView()
        configurePullToRefresh()

        if (savedInstanceState == null) {
            val scanUrl = intent.getStringExtra("SCAN_URL")
            if (scanUrl != null) {
                webView.loadUrl(scanUrl)
            } else {
                loadHome()
            }
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun configureWebView() {
        val settings = webView.settings
        settings.javaScriptEnabled = true
        settings.domStorageEnabled = true
        settings.allowFileAccess = true
        settings.loadsImagesAutomatically = true
        settings.mixedContentMode = WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE
        settings.cacheMode = WebSettings.LOAD_NO_CACHE

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                return false
            }

            override fun onPageFinished(view: WebView?, url: String?) {
                super.onPageFinished(view, url)
                swipeRefreshLayout.isRefreshing = false
            }

            override fun onReceivedError(
                view: WebView?,
                request: WebResourceRequest?,
                error: WebResourceError?
            ) {
                super.onReceivedError(view, request, error)
                val failingUrl = request?.url?.toString() ?: return
                if (request.isForMainFrame && tryFallbackUrl(failingUrl)) {
                    return
                }
                if (request.isForMainFrame) {
                    swipeRefreshLayout.isRefreshing = false
                }
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onShowFileChooser(
                webView: WebView?,
                filePathCallback: ValueCallback<Array<Uri>>?,
                fileChooserParams: FileChooserParams?
            ): Boolean {
                this@MainActivity.filePathCallback?.onReceiveValue(null)
                this@MainActivity.filePathCallback = filePathCallback

                val contentSelectionIntent = Intent(Intent.ACTION_GET_CONTENT).apply {
                    addCategory(Intent.CATEGORY_OPENABLE)
                    type = "*/*"
                    putExtra(Intent.EXTRA_MIME_TYPES, arrayOf("image/*", "application/pdf"))
                }

                val captureIntent = Intent(MediaStore.ACTION_IMAGE_CAPTURE)
                if (captureIntent.resolveActivity(packageManager) != null) {
                    val imageUri = createImageUri()
                    if (imageUri != null) {
                        cameraImageUri = imageUri
                        captureIntent.putExtra(MediaStore.EXTRA_OUTPUT, imageUri)
                        captureIntent.addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION)
                        captureIntent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                    }
                }

                val chooserIntents = mutableListOf<Intent>()
                if (cameraImageUri != null) {
                    chooserIntents.add(captureIntent)
                }

                val chooser = Intent(Intent.ACTION_CHOOSER).apply {
                    putExtra(Intent.EXTRA_INTENT, contentSelectionIntent)
                    putExtra(Intent.EXTRA_TITLE, getString(R.string.file_chooser_title))
                    putExtra(Intent.EXTRA_INITIAL_INTENTS, chooserIntents.toTypedArray())
                }

                fileChooserLauncher.launch(chooser)
                return true
            }
        }
    }

    @SuppressLint("ClickableViewAccessibility")
    private fun configureMovableScanFab() {
        val touchSlop = ViewConfiguration.get(this).scaledTouchSlop
        var downRawX = 0f
        var downRawY = 0f
        var startX = 0f
        var startY = 0f
        var dragged = false

        scanFab.setOnTouchListener { view, event ->
            when (event.actionMasked) {
                MotionEvent.ACTION_DOWN -> {
                    downRawX = event.rawX
                    downRawY = event.rawY
                    startX = view.x
                    startY = view.y
                    dragged = false
                    true
                }
                MotionEvent.ACTION_MOVE -> {
                    val deltaX = event.rawX - downRawX
                    val deltaY = event.rawY - downRawY
                    if (!dragged && (abs(deltaX) > touchSlop || abs(deltaY) > touchSlop)) {
                        dragged = true
                    }

                    val parentView = view.parent as? android.view.View ?: return@setOnTouchListener true
                    val maxX = max(0f, (parentView.width - view.width).toFloat())
                    val maxY = max(0f, (parentView.height - view.height).toFloat())
                    view.x = min(max(0f, startX + deltaX), maxX)
                    view.y = min(max(0f, startY + deltaY), maxY)
                    true
                }
                MotionEvent.ACTION_UP -> {
                    if (!dragged) {
                        view.performClick()
                    }
                    true
                }
                MotionEvent.ACTION_CANCEL -> true
                else -> false
            }
        }
    }

    private fun configurePullToRefresh() {
        swipeRefreshLayout.setOnRefreshListener {
            webView.reload()
        }
        swipeRefreshLayout.setOnChildScrollUpCallback { _, _ ->
            webView.scrollY > 0
        }
    }

    private fun loadHome() {
        val urls = availableBaseUrls()
        currentBaseUrlIndex = 0
        webView.loadUrl(urls[currentBaseUrlIndex])
    }

    private fun tryFallbackUrl(failingUrl: String): Boolean {
        val urls = availableBaseUrls()
        val failingBaseUrl = urls.getOrNull(currentBaseUrlIndex)
        if (failingBaseUrl == null || !failingUrl.startsWith(failingBaseUrl)) {
            return false
        }

        val nextBaseUrl = urls.getOrNull(currentBaseUrlIndex + 1) ?: return false
        currentBaseUrlIndex += 1
        webView.loadUrl(failingUrl.replaceFirst(failingBaseUrl, nextBaseUrl))
        return true
    }

    private fun currentBaseUrl(): String {
        val urls = availableBaseUrls()
        val currentUrl = webView.url.orEmpty()
        return urls.firstOrNull { currentUrl.startsWith(it) }
            ?: urls.getOrElse(currentBaseUrlIndex) { BuildConfig.BASE_URL }
    }

    private fun availableBaseUrls(): List<String> {
        return listOf(
            BuildConfig.BASE_URL,
            BuildConfig.TAILSCALE_IP_BASE_URL,
            BuildConfig.LAN_BASE_URL,
            BuildConfig.LOCAL_BASE_URL
        ).distinct()
    }

    private fun clearLegacyServerSettings() {
        deleteSharedPreferences("server_settings")
    }

    private fun createImageUri(): Uri? {
        return try {
            val imagesDir = File(cacheDir, "images").apply {
                if (!exists()) {
                    mkdirs()
                }
            }
            val imageFile = File.createTempFile("capture_", ".jpg", imagesDir)
            FileProvider.getUriForFile(this, "${applicationContext.packageName}.fileprovider", imageFile)
        } catch (_: IOException) {
            null
        }
    }

    override fun onBackPressed() {
        if (webView.canGoBack()) {
            webView.goBack()
        } else {
            super.onBackPressed()
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        webView.saveState(outState)
    }

    override fun onRestoreInstanceState(savedInstanceState: Bundle) {
        super.onRestoreInstanceState(savedInstanceState)
        webView.restoreState(savedInstanceState)
    }

    override fun onCreateOptionsMenu(menu: Menu?): Boolean {
        menuInflater.inflate(R.menu.main_menu, menu)
        return true
    }

    override fun onOptionsItemSelected(item: MenuItem): Boolean {
        return when (item.itemId) {
            R.id.menu_refresh -> {
                webView.reload()
                true
            }
            R.id.menu_scan_qr -> {
                launchQrScanner()
                true
            }
            else -> super.onOptionsItemSelected(item)
        }
    }

    private fun launchQrScanner() {
        startActivity(Intent(this, QRScannerActivity::class.java).apply {
            putExtra("BASE_URL", currentBaseUrl())
        })
    }

}
