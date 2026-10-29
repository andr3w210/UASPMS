# Trip ticket SMS gateway

Trip ticket creation can queue SMS notifications for up to 10 phone numbers. An office Android phone sends queued messages through its SIM; the PHP server does not call a paid SMS provider.

## Setup

1. Apply `database/122_trip_ticket_sms_gateway.sql` to the `uaspms_tripdb` database.
2. Build and install the updated Android app on the office phone with an active SIM and SMS service.
3. Open UASPMS in the Android app and sign in as an Administrator or Transport Officer.
4. Choose **Start SMS Gateway** from the app menu and allow SMS permission. Keep the phone powered, online, and logged in. The ongoing notification shows that the gateway is running; use its **Stop** action to pause it.
5. When creating a trip ticket, enter up to 10 mobile numbers in the optional SMS Notifications field. Philippine mobile numbers such as `09171234567` and `+639171234567` are accepted.

The server queues messages in the same transaction as a new trip ticket. The phone polls the queue while its gateway service is running, sends each SMS through the SIM, and reports the modem submission result. Delivery may be delayed while the phone is offline. Standard carrier SMS charges apply. The queue retries jobs that the phone claims but fails to acknowledge; messages explicitly rejected by the phone are marked failed. If the phone sends an SMS but loses connectivity before acknowledging it, a retry could deliver a duplicate.

The Android app uses the logged-in WebView session to authenticate to the queue. The gateway endpoints accept only Administrator and Transport Officer sessions. Sign out or an expired session pauses processing until the phone signs in again.

Google Play restricts SMS permissions to eligible app use cases and may reject a general-purpose app that requests `SEND_SMS`; this gateway is intended for an internally distributed office app unless its Play distribution qualifies under Google's policy. See [Google Play's SMS and Call Log permissions policy](https://support.google.com/googleplay/android-developer/answer/10208820).
