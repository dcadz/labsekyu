/*
 * ==================================================================================
 * LABSEKYU: High-Speed Zero-Latency Solenoid Lock Controller
 * Board: ESP32 DevKit / NodeMCU-32S
 * Target: LABSEKYU Web Application (Local XAMPP or Cloud)
 * ==================================================================================
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <WebServer.h>

// ==================================================================================
// --- 1. Network & Server Configuration ---
// ==================================================================================
const char *ssid     = "Saint Laurent";
const char *password = "Intherealmofscienceandtechnologywearehereaswego12.";
const char *hostIp   = "192.168.0.35"; 
const char *roomName = "ALL";

String lockStatusApiUrl = String("http://") + hostIp + "/labsekyu2/public/api/get_lock_status.php?room=" + roomName;

// ==================================================================================
// --- 2. Hardware Pin Definitions ---
// ==================================================================================
const int RELAY_PIN  = 5;   
const int BUTTON_PIN = 13;  

// ==================================================================================
// --- 3. Relay Logic Configuration (Active-LOW vs Active-HIGH) ---
// ==================================================================================
// CHANGED TO FALSE: Fixes the inverted Lock/Unlock status between Web and Hardware
const bool RELAY_ACTIVE_LOW = false; 

const int RELAY_UNLOCK_STATE = RELAY_ACTIVE_LOW ? LOW  : HIGH; 
const int RELAY_LOCK_STATE   = RELAY_ACTIVE_LOW ? HIGH : LOW;  

// ==================================================================================
// --- 4. Thermal Protection & Operating Modes ---
// ==================================================================================
const bool ENABLE_THERMAL_CUTOFF = true;
const unsigned long MAX_CONTINUOUS_UNLOCK_MS = 10UL * 60UL * 1000UL; 

// Mode 2: MOMENTARY ACCESS PULSE (5-Second Anti-Overheating Cutoff)
// 🌟 Set to true to automatically lock after 5 seconds and notify the database
const bool AUTO_RELOCK_PULSE = true;
const unsigned long UNLOCK_PULSE_MS = 5000; // 5-second hardware pulse

const unsigned long pollInterval = 300; 

// ==================================================================================
// --- 5. State Tracking Variables ---
// ==================================================================================
volatile bool isUnlocked = false;                   
volatile bool buttonActionPending = false;          
volatile unsigned long lastButtonInterruptTime = 0; 
unsigned long lastManualActionTime = 0;              
unsigned long unlockStartTime = 0;                  
unsigned long lastPollTime = 0;                     
int lastButtonState = HIGH;                         

HTTPClient pollHttp;
WiFiClient pollClient;
WebServer localServer(80);

// ==================================================================================
// --- 6. Hardware Actuation Functions ---
// ==================================================================================
void triggerUnlock(const char* sourceName) {
  isUnlocked = true;
  unlockStartTime = millis();
  digitalWrite(RELAY_PIN, RELAY_UNLOCK_STATE); 
  Serial.printf("\n[UNLOCK] (%s) -> Relay PIN %d = %s (Solenoid RETRACTED / OPEN)\n", 
                sourceName, RELAY_PIN, (RELAY_UNLOCK_STATE == HIGH ? "HIGH" : "LOW"));
}

void triggerLock(const char* reason = "Normal Lock") {
  isUnlocked = false;
  digitalWrite(RELAY_PIN, RELAY_LOCK_STATE); 
  Serial.printf("[LOCK] (%s) -> Relay PIN %d = %s (Coil 0V, ZERO Heat, LOCKED)\n",
                reason, RELAY_PIN, (RELAY_LOCK_STATE == HIGH ? "HIGH" : "LOW"));
}

// ==================================================================================
// --- 7. Zero-Latency Hardware Push Button Interrupt ---
// ==================================================================================
void IRAM_ATTR handleEmergencyButton() {
  unsigned long now = millis();
  if (now - lastButtonInterruptTime > 250) { 
    lastButtonInterruptTime = now;
    isUnlocked = !isUnlocked;
    digitalWrite(RELAY_PIN, isUnlocked ? RELAY_UNLOCK_STATE : RELAY_LOCK_STATE);
    
    buttonActionPending = true;   
    lastManualActionTime = now;   
    if (isUnlocked) {
      unlockStartTime = now;
    }
  }
}

// ==================================================================================
// --- 8. Safe Server Sync Action Helper ---
// ==================================================================================
void sendActionToServer(const String& action) {
  if (WiFi.status() != WL_CONNECTED) return;
  
  HTTPClient syncHttp;
  WiFiClient syncClient;
  syncHttp.setTimeout(800);
  syncHttp.setConnectTimeout(800);

  // 1. Notify update_lock_status.php endpoint
  String statusParam = (action == "unlock") ? "UNLOCKED" : "LOCKED";
  String updateUrl = String("http://") + hostIp + "/labsekyu2/public/api/update_lock_status.php?status=" + statusParam + "&room=" + roomName;
  if (syncHttp.begin(syncClient, updateUrl)) {
    int code = syncHttp.GET();
    Serial.printf("[SERVER SYNC] update_lock_status -> HTTP %d (%s)\n", code, statusParam.c_str());
    syncHttp.end();
  }

  // 2. Also report action to get_lock_status.php
  // Directly call the update endpoint to force the database to match hardware
  String fullUrl = String("http://") + hostIp + "/labsekyu2/public/api/update_lock_status.php?status=" + (action == "unlock" ? "UNLOCKED" : "LOCKED");
  if (syncHttp.begin(syncClient, fullUrl)) {
    syncHttp.GET();
    syncHttp.end();
  }
}

// ==================================================================================
// --- 9. Hardware & Network Setup ---
// ==================================================================================
void setup() {
  Serial.begin(115200);
  delay(300);

  digitalWrite(RELAY_PIN, RELAY_LOCK_STATE); 
  pinMode(RELAY_PIN, OUTPUT);
  pinMode(BUTTON_PIN, INPUT_PULLUP);
  attachInterrupt(digitalPinToInterrupt(BUTTON_PIN), handleEmergencyButton, FALLING);

  Serial.print("Connecting to Wi-Fi: ");
  Serial.println(ssid);
  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 25) {
    delay(300);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nWi-Fi Connected successfully!");
  }

  localServer.on("/unlock", HTTP_GET, []() {
    triggerUnlock("Direct HTTP WebServer");
    localServer.send(200, "text/plain", "UNLOCKED");
    sendActionToServer("unlock");
  });

  localServer.on("/lock", HTTP_GET, []() {
    triggerLock("Direct HTTP WebServer");
    localServer.send(200, "text/plain", "LOCKED");
    sendActionToServer("lock");
  });

  localServer.begin();

  pollHttp.setReuse(true);  
  pollHttp.setTimeout(800); 
  pollHttp.setConnectTimeout(800);
}

// ==================================================================================
// --- 10. Main Loop (Non-Blocking, High-Speed) ---
// ==================================================================================
void loop() {
  unsigned long now = millis();
  localServer.handleClient();

  int currentButtonState = digitalRead(BUTTON_PIN);
  if (currentButtonState == LOW && lastButtonState == HIGH && (now - lastButtonInterruptTime > 250)) {
    lastButtonInterruptTime = now;
    isUnlocked = !isUnlocked;
    digitalWrite(RELAY_PIN, isUnlocked ? RELAY_UNLOCK_STATE : RELAY_LOCK_STATE);
    buttonActionPending = true;
    lastManualActionTime = now;
    if (isUnlocked) unlockStartTime = now;
  }
  lastButtonState = currentButtonState;

  if (buttonActionPending) {
    buttonActionPending = false;
    sendActionToServer(isUnlocked ? "unlock" : "lock");
  }

  // 5-second pulse elapsed: Hardware automatically cuts power and tells database to lock
  if (AUTO_RELOCK_PULSE && isUnlocked && (now - unlockStartTime >= UNLOCK_PULSE_MS)) {
    triggerLock("Pulse Elapsed");
    sendActionToServer("lock");
  }

  if (ENABLE_THERMAL_CUTOFF && isUnlocked && (now - unlockStartTime >= MAX_CONTINUOUS_UNLOCK_MS)) {
    triggerLock("Thermal Cutoff");
    sendActionToServer("lock");
  }

  if (now - lastPollTime >= pollInterval) {
    lastPollTime = now;
    if (now - lastManualActionTime >= 2500 && WiFi.status() == WL_CONNECTED) {
      if (pollHttp.begin(pollClient, lockStatusApiUrl)) {
        int httpCode = pollHttp.GET();
        if (httpCode == HTTP_CODE_OK) {
          String payload = pollHttp.getString();
          payload.trim();
          payload.toUpperCase();

          if (payload.indexOf("UNLOCKED") != -1 && !isUnlocked) {
            triggerUnlock("Web Dashboard");
          } else if (payload.indexOf("LOCKED") != -1 && isUnlocked) {
            triggerLock("Web Dashboard");
          }
        }
      }
    }
  }
  delay(5); 
}