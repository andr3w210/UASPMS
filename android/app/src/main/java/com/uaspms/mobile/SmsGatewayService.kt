package com.uaspms.mobile

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.IBinder
import android.telephony.SmsManager
import android.webkit.CookieManager
import androidx.core.content.ContextCompat
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicInteger
import org.json.JSONObject

class SmsGatewayService : Service() {
    @Volatile private var running = false
    @Volatile private var worker: Thread? = null
    private var baseUrl: String = BuildConfig.BASE_URL

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopGateway()
            return START_NOT_STICKY
        }
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.SEND_SMS) != PackageManager.PERMISSION_GRANTED) {
            stopSelf()
            return START_NOT_STICKY
        }
        val suppliedBaseUrl = intent?.getStringExtra(EXTRA_BASE_URL)?.trim().orEmpty()
        val savedBaseUrl = getSharedPreferences(PREFERENCES, MODE_PRIVATE).getString(PREF_BASE_URL, null).orEmpty()
        baseUrl = normalizeSecureBaseUrl(suppliedBaseUrl)
            ?: normalizeSecureBaseUrl(savedBaseUrl)
            ?: normalizeSecureBaseUrl(baseUrl)
            ?: BuildConfig.BASE_URL
        getSharedPreferences(PREFERENCES, MODE_PRIVATE).edit().putString(PREF_BASE_URL, baseUrl).apply()
        ensureNotificationChannel()
        startForeground(NOTIFICATION_ID, makeNotification())
        if (worker?.isAlive != true) {
            running = true
            worker = Thread { pollQueue() }.apply {
                name = "UASPMS-SMS-Gateway"
                start()
            }
        }
        return START_STICKY
    }

    private fun normalizeSecureBaseUrl(value: String): String? {
        val trimmed = value.trim()
        val uri = Uri.parse(trimmed)
        val secure = uri.scheme.equals("https", ignoreCase = true)
        val officeLan = uri.scheme.equals("http", ignoreCase = true) && uri.host == "172.16.1.42"
        if ((!secure && !officeLan) || uri.host.isNullOrBlank()) {
            return null
        }
        return trimmed.trimEnd('/') + "/"
    }

    override fun onDestroy() {
        running = false
        worker?.interrupt()
        worker = null
        super.onDestroy()
    }

    private fun stopGateway() {
        running = false
        worker?.interrupt()
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    private fun pollQueue() {
        val gatewayPath = "modules/trip_tickets/"
        while (running) {
            try {
                val cookie = CookieManager.getInstance().getCookie(baseUrl)
                if (cookie.isNullOrBlank()) {
                    Thread.sleep(POLL_DELAY_MS)
                    continue
                }

                val bootstrap = request(baseUrl + gatewayPath + "sms_gateway_bootstrap.php", "GET", cookie)
                val csrf = JSONObject(bootstrap).optString("csrf_token")
                if (csrf.isBlank()) {
                    Thread.sleep(POLL_DELAY_MS)
                    continue
                }

                val claimBody = formBody(mapOf("action" to "claim", "_csrf" to csrf))
                val claim = JSONObject(request(baseUrl + gatewayPath + "sms_gateway_claim.php", "POST", cookie, claimBody))
                if (!claim.optBoolean("ok")) {
                    Thread.sleep(POLL_DELAY_MS)
                    continue
                }
                val job = claim.optJSONObject("job")
                if (job == null) {
                    Thread.sleep(POLL_DELAY_MS)
                    continue
                }

                val jobId = job.optLong("id")
                val leaseToken = job.optString("lease_token")
                val outcome = sendSms(job.optString("phone"), job.optString("message"))
                val ack = formBody(mapOf(
                    "action" to "ack",
                    "_csrf" to csrf,
                    "job_id" to jobId.toString(),
                    "lease_token" to leaseToken,
                    "status" to if (outcome.first) "sent" else "failed",
                    "result_message" to outcome.second
                ))
                request(baseUrl + gatewayPath + "sms_gateway_ack.php", "POST", cookie, ack)
            } catch (_: InterruptedException) {
                Thread.currentThread().interrupt()
                break
            } catch (_: Exception) {
                try {
                    Thread.sleep(POLL_DELAY_MS)
                } catch (_: InterruptedException) {
                    Thread.currentThread().interrupt()
                    break
                }
            }
        }
    }

    private fun sendSms(phone: String, message: String): Pair<Boolean, String> {
        if (phone.isBlank() || message.isBlank()) return false to "Phone number or message is empty."
        return try {
            val manager = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                getSystemService(SmsManager::class.java)
            } else {
                @Suppress("DEPRECATION")
                SmsManager.getDefault()
            }
            val parts = manager.divideMessage(message)
            val latch = CountDownLatch(parts.size)
            val failed = AtomicBoolean(false)
            val errors = AtomicInteger(0)
            val action = "$packageName.SMS_SENT.${System.nanoTime()}"
            val receiver = object : BroadcastReceiver() {
                override fun onReceive(context: Context?, intent: Intent?) {
                    if (resultCode != android.app.Activity.RESULT_OK) {
                        failed.set(true)
                        errors.set(resultCode)
                    }
                    latch.countDown()
                }
            }
            val filter = IntentFilter(action)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                registerReceiver(receiver, filter, Context.RECEIVER_NOT_EXPORTED)
            } else {
                @Suppress("DEPRECATION")
                registerReceiver(receiver, filter)
            }
            try {
                val sentIntents = ArrayList<PendingIntent>(parts.size)
                parts.indices.forEach { partIndex ->
                    val sentIntent = Intent(action).setPackage(packageName).setData(android.net.Uri.parse("uaspms-sms://$action/$partIndex"))
                    val pendingFlags = PendingIntent.FLAG_UPDATE_CURRENT or
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) PendingIntent.FLAG_IMMUTABLE else 0
                    sentIntents.add(PendingIntent.getBroadcast(this, partIndex, sentIntent, pendingFlags))
                }
                manager.sendMultipartTextMessage(phone, null, parts, sentIntents, null)
                val completed = latch.await(SMS_RESULT_TIMEOUT_SECONDS, TimeUnit.SECONDS)
                when {
                    !completed -> false to "Timed out waiting for the phone network."
                    failed.get() -> false to "The phone network rejected SMS part (result ${errors.get()})."
                    else -> true to ""
                }
            } finally {
                unregisterReceiver(receiver)
            }
        } catch (e: SecurityException) {
            false to "SMS permission is not available."
        } catch (e: Exception) {
            false to (e.message ?: "Phone could not submit the SMS.").take(255)
        }
    }

    private fun request(endpoint: String, method: String, cookie: String, body: String? = null): String {
        val connection = (URL(endpoint).openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 10000
            readTimeout = 15000
            useCaches = false
            setRequestProperty("Cookie", cookie)
            setRequestProperty("Accept", "application/json")
            if (body != null) {
                doOutput = true
                setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8")
            }
        }
        try {
            if (body != null) connection.outputStream.bufferedWriter(Charsets.UTF_8).use { it.write(body) }
            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream
            val text = stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }.orEmpty()
            if (status !in 200..299) throw IllegalStateException("Gateway HTTP $status")
            return text
        } finally {
            connection.disconnect()
        }
    }

    private fun formBody(fields: Map<String, String>): String = fields.entries.joinToString("&") {
        URLEncoder.encode(it.key, "UTF-8") + "=" + URLEncoder.encode(it.value, "UTF-8")
    }

    private fun ensureNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val manager = getSystemService(NotificationManager::class.java)
            manager.createNotificationChannel(NotificationChannel(CHANNEL_ID, "UASPMS SMS Gateway", NotificationManager.IMPORTANCE_LOW))
        }
    }

    private fun makeNotification(): Notification {
        val stopIntent = PendingIntent.getService(
            this,
            1,
            Intent(this, SmsGatewayService::class.java).setAction(ACTION_STOP),
            PendingIntent.FLAG_UPDATE_CURRENT or if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) PendingIntent.FLAG_IMMUTABLE else 0
        )
        val builder = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) Notification.Builder(this, CHANNEL_ID) else Notification.Builder(this)
        return builder
            .setSmallIcon(android.R.drawable.stat_notify_sync)
            .setContentTitle("UASPMS SMS Gateway active")
            .setContentText("This phone is sending queued trip ticket notifications.")
            .setOngoing(true)
            .addAction(android.R.drawable.ic_media_pause, "Stop", stopIntent)
            .build()
    }

    companion object {
        const val EXTRA_BASE_URL = "sms_gateway_base_url"
        const val ACTION_STOP = "com.uaspms.mobile.action.STOP_SMS_GATEWAY"
        private const val CHANNEL_ID = "uaspms_sms_gateway"
        private const val PREFERENCES = "sms_gateway"
        private const val PREF_BASE_URL = "base_url"
        private const val NOTIFICATION_ID = 7321
        private const val POLL_DELAY_MS = 12000L
        private const val SMS_RESULT_TIMEOUT_SECONDS = 90L
    }
}
