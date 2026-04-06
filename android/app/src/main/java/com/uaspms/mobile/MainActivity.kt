package com.uaspms.mobile

import android.annotation.SuppressLint
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.MediaStore
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.FileProvider
import java.io.File
import java.io.IOException

class MainActivity : AppCompatActivity() {

    private lateinit var webView: WebView
    private var filePathCallback: ValueCallback<Array<Uri>>? = null
    private var cameraImageUri: Uri? = null

    private lateinit var fileChooserLauncher: ActivityResultLauncher<Intent>

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        webView = findViewById(R.id.webView)

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

        if (savedInstanceState == null) {
            webView.loadUrl(BuildConfig.BASE_URL)
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
        settings.cacheMode = WebSettings.LOAD_DEFAULT

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                return false
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
}
