/*
 * ==================================================================================
 * LABSEKYU: Synchronized Solenoid Lock Controller (Continuous Toggle & Thermal Cutoff)
 * Board: ESP32 DevKit / NodeMCU-32S
 * Target: LABSEKYU Web Application (Local XAMPP or Cloud)
 * ==================================================================================
 * Hardware & Operating Policy:
 * - Solenoid: 12V DC fail-secure mechanical lock.
 * - Relay: 5V single-channel mechanical relay module (Active-LOW).
 * - Continuous Toggle Mode:
 *   - AUTO_RELOCK_PULSE = false (door remains unlocked continuously upon command).
 * - Emergency Thermal Safety:
 *   - ENABLE_THERMAL_CUTOFF = true.
 *   - MAX_CONTINUOUS_UNLOCK_MS = 600000 (10 minutes).
 *   - Because mechanical relays cannot modulate voltage, if the door remains unlocked
 *     for 10 continuous minutes, the firmware automatically de-energizes the relay (0V)
 *     and pushes a "LOCKED" sync update to the MySQL database via HTTP GET.
 * - Strict Lock Enforcement:
 *   - A "Lock" command (physical button or web UI) immediately de-energizes the relay.
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
const int RELAY_PIN  = 5;   // Relay control signal
const int BUTTON_PIN = 13;  // Push button with internal pullup

// ==================================================================================
// --- 3. Relay & Solenoid Configuration ---
// ==================================================================================
// Standard Arduino / ESP32 5V relay modules are ACTIVE-LOW:
// - Digital LOW (0V) closes relay contacts (NO connects to COM -> 12V to Solenoid -> UNLOCKED)
// - Digital HIGH (3.3V) opens relay contacts (0V to Solenoid -> ZERO HEAT -> LOCKED)
const bool RELAY_ACTIVE_LOW = true; 

const int RELAY_UNLOCK_STATE = RELAY_ACTIVE_LOW ? LOW  : HIGH; // Energize relay (12V)
const int RELAY_LOCK_STATE   = RELAY_ACTIVE_LOW ? HIGH : LOW;  // Cut power to 0V (LOCKED)

// Continuous Toggle Mode & Emergency Thermal Cutoff
const bool AUTO_RELOCK_PULSE = false;                  // Continuous unlocking allowed
const bool ENABLE_THERMAL_CUTOFF = true;               // Strict emergency cutoff
const unsigned long MAX_CONTINUOUS_UNLOCK_MS = 600000; // 10 minutes emergency limit (600,000 ms)

// ==================================================================================
// --- 4. Timing & State Tracking Variables ---
// ==================================================================================
const unsigned long pollInterval = 300; // Poll web status every 300ms

volatile bool isUnlocked = false;                   
volatile unsigned long unlockStartTime = 0;         
volatile bool buttonActionPending = false;          
volatile bool pendingActionIsUnlock = false;
volatile unsigned long lastButtonInterruptTime = 0; 
unsigned long lastManualActionTime = 0;              
unsigned long lastPollTime = 0;                     
int lastButtonState = HIGH;                         

HTTPClient pollHttp;
WiFiClient pollClient;
WebServer localServer(80);

// ==================================================================================
// --- 5. Hardware Actuation Functions ---
// ==================================================================================
void triggerUnlock(const char* sourceName) {
  isUnlocked = true;
  unlockStartTime = millis();
  digitalWrite(RELAY_PIN, RELAY_UNLOCK_STATE); 
  Serial.printf("\n[UNLOCK] (%s) -> Relay PIN %d = %s | Door UNLOCKED (Continuous Toggle Mode)\n", 
                sourceName, RELAY_PIN, (RELAY_UNLOCK_STATE == HIGH ? "HIGH" : "LOW"));
  if (ENABLE_THERMAL_CUTOFF) {
    Serial.printf("[SAFETY] 10-Minute Thermal Cutoff Timer Active (Cutoff in %lu seconds)\n", 
                  MAX_CONTINUOUS_UNLOCK_MS / 1000);
  }
}

void triggerLock(const char* reason = "Strict Lock") {
  isUnlocked = false;
  unlockStartTime = 0;
  digitalWrite(RELAY_PIN, RELAY_LOCK_STATE); 
  Serial.printf("[LOCK] (%s) -> Relay PIN %d = %s | Coil 0V (SECURELY LOCKED & COOL)\n", 
                reason, RELAY_PIN, (RELAY_LOCK_STATE == HIGH ? "HIGH" : "LOW"));
}

// ==================================================================================
// --- 6. Hardware Push Button Interrupt ---
// ==================================================================================
void IRAM_ATTR handleEmergencyButton() {
  unsigned long now = millis();
  if (now - lastButtonInterruptTime > 300) { 
    lastButtonInterruptTime = now;
    if (!isUnlocked) {
      // Toggle to UNLOCKED (Continuous)
      isUnlocked = true;
      unlockStartTime = now;
      digitalWrite(RELAY_PIN, RELAY_UNLOCK_STATE);
      pendingActionIsUnlock = true;
    } else {
      // Toggle to LOCKED (Cut power to 0V)
      isUnlocked = false;
      unlockStartTime = 0;
      digitalWrite(RELAY_PIN, RELAY_LOCK_STATE);
      pendingActionIsUnlock = false;
    }
    buttonActionPending = true;
    lastManualActionTime = now;
  }
}

// ==================================================================================
// --- 7. Server Sync Helper ---
// ==================================================================================
void sendActionToServer(const String& action) {
  if (WiFi.status() != WL_CONNECTED) return;
  
  HTTPClient syncHttp;
  WiFiClient syncClient;
  syncHttp.setTimeout(1500);
  syncHttp.setConnectTimeout(1500);

  String statusParam = (action == "unlock") ? "UNLOCKED" : "LOCKED";
  String updateUrl = String("http://") + hostIp + "/labsekyu2/public/api/update_lock_status.php?status=" + statusParam + "&room=" + roomName;
  if (syncHttp.begin(syncClient, updateUrl)) {
    int code = syncHttp.GET();
    Serial.printf("[SERVER SYNC] update_lock_status -> HTTP %d (%s)\n", code, statusParam.c_str());
    syncHttp.end();
  }
}

// ==================================================================================
// --- 8. Hardware & Network Setup ---
// ==================================================================================
void setup() {
  Serial.begin(115200);
  delay(300);

  // Guarantee relay starts in safe LOCKED state (0V to solenoid, safe & completely cool)
  digitalWrite(RELAY_PIN, RELAY_LOCK_STATE); 
  pinMode(RELAY_PIN, OUTPUT);
  digitalWrite(RELAY_PIN, RELAY_LOCK_STATE);

  pinMode(BUTTON_PIN, INPUT_PULLUP);
  attachInterrupt(digitalPinToInterrupt(BUTTON_PIN), handleEmergencyButton, FALLING);

  Serial.println("\n==============================================================");
  Serial.println("  LABSEKYU CONTINUOUS TOGGLE SOLENOID CONTROLLER ");
  Serial.println("==============================================================");
  Serial.printf("Relay Pin: %d | Button Pin: %d\n", RELAY_PIN, BUTTON_PIN);
  Serial.printf("Active-LOW: %s | Auto-Relock Pulse: %s\n", 
                RELAY_ACTIVE_LOW ? "YES" : "NO", 
                AUTO_RELOCK_PULSE ? "ENABLED" : "DISABLED (Continuous Toggle Mode)");
  Serial.printf("Thermal Cutoff: %s | Max Unlock Duration: %lu ms (10 Mins)\n", 
                ENABLE_THERMAL_CUTOFF ? "ENABLED" : "DISABLED", 
                MAX_CONTINUOUS_UNLOCK_MS);
  Serial.println("Initial State: LOCKED (0V, Cold)");

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
    Serial.printf("\nWi-Fi Connected! IP: %s\n", WiFi.localIP().toString().c_str());
  } else {
    Serial.println("\nWi-Fi Connection timeout, running in standalone hardware mode...");
  }

  // Local Direct HTTP Controls
  localServer.on("/unlock", HTTP_GET, []() {
    triggerUnlock("Direct HTTP /unlock");
    localServer.send(200, "text/plain", "UNLOCKED (Continuous Toggle Active - 10 Min Max)");
    sendActionToServer("unlock");
  });

  localServer.on("/lock", HTTP_GET, []() {
    triggerLock("Direct HTTP /lock");
    localServer.send(200, "text/plain", "LOCKED (0V, Cold)");
    sendActionToServer("lock");
  });

  localServer.on("/status", HTTP_GET, []() {
    localServer.send(200, "text/plain", isUnlocked ? "UNLOCKED" : "LOCKED");
  });

  localServer.begin();

  pollHttp.setReuse(true);  
  pollHttp.setTimeout(800); 
  pollHttp.setConnectTimeout(800);
}

// ==================================================================================
// --- 9. Main Loop (Non-Blocking, High-Speed) ---
// ==================================================================================
void loop() {
  unsigned long now = millis();
  localServer.handleClient();

  // Polling fallback for button press
  int currentButtonState = digitalRead(BUTTON_PIN);
  if (currentButtonState == LOW && lastButtonState == HIGH && (now - lastButtonInterruptTime > 300)) {
    lastButtonInterruptTime = now;
    if (!isUnlocked) {
      isUnlocked = true;
      unlockStartTime = now;
      digitalWrite(RELAY_PIN, RELAY_UNLOCK_STATE);
      pendingActionIsUnlock = true;
    } else {
      isUnlocked = false;
      unlockStartTime = 0;
      digitalWrite(RELAY_PIN, RELAY_LOCK_STATE);
      pendingActionIsUnlock = false;
    }
    buttonActionPending = true;
    lastManualActionTime = now;
  }
  lastButtonState = currentButtonState;

  // Sync button press to server immediately
  if (buttonActionPending) {
    buttonActionPending = false;
    Serial.printf("[BUTTON EVENT] Toggled to %s -> Syncing to Web...\n", pendingActionIsUnlock ? "UNLOCKED" : "LOCKED");
    sendActionToServer(pendingActionIsUnlock ? "unlock" : "lock");
  }

  // --- Strict Emergency Thermal Cutoff Enforcement (10 Minutes) ---
  // If the solenoid is held open continuously for 10 minutes (600,000 ms),
  // automatically cut power to 0V and push a lock update to the MySQL database.
  if (isUnlocked && ENABLE_THERMAL_CUTOFF) {
    if (now - unlockStartTime >= MAX_CONTINUOUS_UNLOCK_MS) {
      Serial.println("\n**************************************************************");
      Serial.println("  [EMERGENCY THERMAL CUTOFF TRIGGERED] 10 Minutes Reached!    ");
      Serial.println("  De-energizing relay to 0V to prevent solenoid coil burnout. ");
      Serial.println("  Synchronizing LOCKED status to database...                  ");
      Serial.println("**************************************************************\n");
      triggerLock("Emergency Thermal Cutoff (10-Minute Limit)");
      sendActionToServer("lock");
    }
  }

  // Poll web status every 300ms (pause for 2s after manual button press)
  if (now - lastPollTime >= pollInterval) {
    lastPollTime = now;
    if (now - lastManualActionTime >= 2000 && WiFi.status() == WL_CONNECTED) {
      if (pollHttp.begin(pollClient, lockStatusApiUrl)) {
        int httpCode = pollHttp.GET();
        if (httpCode == HTTP_CODE_OK) {
          String payload = pollHttp.getString();
          payload.trim();
          payload.toUpperCase();

          if (payload.indexOf("UNLOCKED") != -1 && !isUnlocked) {
            triggerUnlock("Web Dashboard Unlock Command");
          } else if (payload.indexOf("LOCKED") != -1 && isUnlocked) {
            // Strict lock command from web dashboard -> cut power immediately
            triggerLock("Web Dashboard Lock Command");
          }
        }
      }
    }
  }
  delay(5); 
}
