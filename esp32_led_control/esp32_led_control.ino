/*
 * ESP32 IoT LED Controller
 * ========================
 * Controls the built-in blue LED via HTTP polling from a web dashboard.
 *
 * Hardware: ESP32 (built-in LED on GPIO 2)
 * Protocol: HTTP GET polling (checks server every 2 seconds)
 *
 * Libraries needed (install via Arduino Library Manager):
 *   - WiFi (built-in with ESP32 board package)
 *   - HTTPClient (built-in with ESP32 board package)
 *   - ArduinoJson by Benoit Blanchon
 *
 * Board Setup:
 *   1. Install "esp32" by Espressif Systems from Boards Manager
 *   2. Select: Tools > Board > ESP32 Arduino > ESP32 Dev Module
 *   3. Set Upload Speed: 115200
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>

// ─────────────────────────────────────────────
//  CONFIGURATION — Edit these before uploading
// ─────────────────────────────────────────────
const char* WIFI_SSID     = "FTTH-DEARBC";       // Your Wi-Fi network name
const char* WIFI_PASSWORD = "wakoylabot737871";   // Your Wi-Fi password

// Your server URL (local or hosted)
// Examples:
//   Local XAMPP:  "http://192.168.1.100/esp32-iot/backend/api.php"
//   Firebase:     handled differently (see firebase_version.ino)
const char* SERVER_URL = "http://192.168.1.61/esp32-iot/backend/api.php";

// LED pin (GPIO 2 is the built-in blue LED on most ESP32 dev boards)
#define LED_PIN 2

// Polling interval in milliseconds (2000 = check every 2 seconds)
#define POLL_INTERVAL 2000
// ─────────────────────────────────────────────

// Track last known LED state to avoid redundant writes
String lastLedState = "";
unsigned long lastPollTime = 0;

void setup() {
  Serial.begin(115200);
  delay(1000);

  // Configure LED pin
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW); // Start with LED off

  Serial.println("\n=============================");
  Serial.println("  ESP32 IoT LED Controller   ");
  Serial.println("=============================");

  // Connect to Wi-Fi
  connectToWiFi();
}

void loop() {
  // Reconnect if Wi-Fi dropped
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[WiFi] Connection lost. Reconnecting...");
    connectToWiFi();
    return;
  }

  // Poll server at defined interval
  unsigned long now = millis();
  if (now - lastPollTime >= POLL_INTERVAL) {
    lastPollTime = now;
    pollServer();
  }
}

// ─────────────────────────────────────────────
//  Wi-Fi Connection
// ─────────────────────────────────────────────
void connectToWiFi() {
  Serial.print("[WiFi] Connecting to: ");
  Serial.println(WIFI_SSID);

  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n[WiFi] Connected!");
    Serial.print("[WiFi] IP Address: ");
    Serial.println(WiFi.localIP());
    Serial.print("[WiFi] Signal Strength (RSSI): ");
    Serial.print(WiFi.RSSI());
    Serial.println(" dBm");
  } else {
    Serial.println("\n[WiFi] Failed to connect. Retrying in 5 seconds...");
    delay(5000);
  }
}

// ─────────────────────────────────────────────
//  Poll Server for LED Command
// ─────────────────────────────────────────────
void pollServer() {
  HTTPClient http;

  // Build URL with action=status to get current LED state
  String url = String(SERVER_URL) + "?action=status";
  http.begin(url);
  http.setTimeout(5000); // 5 second timeout

  int httpCode = http.GET();

  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    Serial.print("[HTTP] Response: ");
    Serial.println(payload);

    // Parse JSON response
    StaticJsonDocument<256> doc;
    DeserializationError error = deserializeJson(doc, payload);

    if (!error) {
      String ledStatus = doc["status"].as<String>();

      // Only update if state changed (reduces LED flicker)
      if (ledStatus != lastLedState) {
        applyLedState(ledStatus);
        lastLedState = ledStatus;
        reportStatusToServer(ledStatus);
      }
    } else {
      Serial.print("[JSON] Parse error: ");
      Serial.println(error.c_str());
    }
  } else {
    Serial.print("[HTTP] Error code: ");
    Serial.println(httpCode);
    Serial.println("[HTTP] Check your SERVER_URL and ensure server is running.");
  }

  http.end();
}

// ─────────────────────────────────────────────
//  Apply LED State
// ─────────────────────────────────────────────
void applyLedState(String state) {
  if (state == "ON") {
    digitalWrite(LED_PIN, HIGH);
    Serial.println("[LED] Turned ON ✓");
  } else if (state == "OFF") {
    digitalWrite(LED_PIN, LOW);
    Serial.println("[LED] Turned OFF ✓");
  } else {
    Serial.print("[LED] Unknown state received: ");
    Serial.println(state);
  }
}

// ─────────────────────────────────────────────
//  Report LED State Back to Server
//  (Optional: confirms ESP32 executed the command)
// ─────────────────────────────────────────────
void reportStatusToServer(String state) {
  HTTPClient http;

  String url = String(SERVER_URL) + "?action=confirm&state=" + state;
  http.begin(url);
  http.setTimeout(5000);

  int httpCode = http.GET();

  if (httpCode == HTTP_CODE_OK) {
    Serial.println("[HTTP] State confirmed to server.");
  }

  http.end();
}
