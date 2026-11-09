/*
 * ==================================================================================
 * LABSEKYU: High-Speed Zero-Latency Solenoid Lock Controller
 * Board: ESP32 DevKit / NodeMCU-32S
 * Target: LABSEKYU Web Application (Local XAMPP or Cloud)
 * ==================================================================================
 * Hardware Wiring Summary:
 * - Pin D5  (GPIO 5)  --> Relay Signal Input (IN / IN1)
 * - Pin D13 (GPIO 13) --> Push Button Terminal 1 (Terminal 2 to GND)
 * - 5V / VIN Pin      --> Regulated 5V Power from Buck Converter OUT+
 * - GND Pin           --> Common Ground (Buck OUT-, Relay GND, 12V GND)
 * 
 * CRITICAL WIRING FOR FAIL-SECURE SOLENOID (Prevents Overheating Burnout):
 * - Solenoid (+)  --> 12V DC Power Supply (+)
 * - Solenoid (-)  --> Relay NO (Normally Open) Terminal
 * - Relay COM     --> 12V DC Power Supply (-) / GND
 * 
 * ⚠️ RULES TO PREVENT SOLENOID OVERHEATING:
 * 1. NEVER wire the solenoid to the 'NC' (Normally Closed) terminal!
 *    Using NC keeps 12V energized 24/7 during idle, which burns and melts the coil!
 * 2. In standby (LOCKED), relay must be DE-ENERGIZED (0 Volts to Solenoid).
 *    Current = 0 Amps, Power = 0 Watts -> Coil stays completely COLD.
 * ==================================================================================
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <WebServer.h>

// ==================================================================================
// --- 1. Network & Server Configuration ---
// ==================================================================================
const char *ssid     = "Converge_2.4GHz_5325";
const char *password = "5akPEYt4";
const char *hostIp   = "192.168.1.61"; 

// Target Room ID assigned to this solenoid controller (matches the room in the Web Dashboard):
// 🌟 Set to "ALL" (default) so this solenoid dynamically responds to ANY room unlocked on the website!
// Or set to a specific room (e.g. "101", "301", "307") if binding strictly to a single door:
const char *roomName = "ALL";

// Assembled endpoint URL:
String lockStatusApiUrl = String("http://") + hostIp + "/labsekyu2/public/api/get_lock_status.php?room=" + roomName;

// ==================================================================================
// --- 2. Hardware Pin Definitions ---
// ==================================================================================
const int RELAY_PIN  = 5;   // ESP32 Pin D5 controls the Relay
const int BUTTON_PIN = 13;  // ESP32 Pin D13 for Physical Push Button

// ==================================================================================
// --- 3. Relay Logic Configuration (Active-LOW vs Active-HIGH) ---
// ==================================================================================
// Standard Arduino/ESP32 5V Songle relay modules with optocoupler are ACTIVE-LOW:
// - Active-LOW (true):  LOW energizes relay (12V ON / UNLOCK), HIGH de-energizes (0V / LOCK).
// - Active-HIGH (false): HIGH energizes relay, LOW de-energizes.
const bool RELAY_ACTIVE_LOW = true; 

const int RELAY_UNLOCK_STATE = RELAY_ACTIVE_LOW ? LOW  : HIGH; // Energizes relay (12V ON / UNLOCK)
const int RELAY_LOCK_STATE   = RELAY_ACTIVE_LOW ? HIGH : LOW;  // De-energizes relay (0V / COLD / LOCK)

// ==================================================================================
// --- 4. Thermal Protection & Operating Modes ---
// ==================================================================================
// Mode 1: TOGGLE MODE (Default - Synchronized 1:1 with Web Dashboard)
// When unlocked (from web or physical button), the door stays UNLOCKED until locked again.
// To prevent coil burnout if someone forgets the door unlocked for hours,
// this emergency thermal cutoff timer safely re-locks the door after 10 minutes.
const bool ENABLE_THERMAL_CUTOFF = true;
const unsigned long MAX_CONTINUOUS_UNLOCK_MS = 10UL * 60UL * 1000UL; // 10 minutes safety limit

// Mode 2: MOMENTARY ACCESS PULSE (5-Second Anti-Overheating Cutoff)
// 🌟 Set to true to automatically lock after 5 seconds and notify the database
const bool AUTO_RELOCK_PULSE = false;  // false = continuous toggle mode (matches solenoid_lock.ino policy)
const unsigned long UNLOCK_PULSE_MS = 5000; // 5-second hardware pulse (only active if AUTO_RELOCK_PULSE=true)

// Fast Polling Interval: 300ms for virtually instant response from website clicks
const unsigned long pollInterval = 300; 

// ==================================================================================
// --- 5. State Tracking Variables ---
// ==================================================================================
volatile bool isUnlocked = false;                   // Physical lock state
volatile bool buttonActionPending = false;          // Signals loop() to sync with web server
volatile unsigned long lastButtonInterruptTime = 0; // Debounce timer
unsigned long lastManualActionTime = 0;              // Grace window preventing remote override
unsigned long unlockStartTime = 0;                  // Timestamp when door was unlocked
unsigned long lastPollTime = 0;                     // Polling timer
int lastButtonState = HIGH;                         // Edge-detection tracking

// Reusable HTTP client with persistent connection for ultra-fast polling
HTTPClient pollHttp;
WiFiClient pollClient;

// Embedded Direct WebServer for Instant Direct Control (<20ms response)
WebServer localServer(80);

// ==================================================================================
// --- 6. Hardware Actuation Functions ---
// ==================================================================================
void triggerUnlock(const char* sourceName) {
  isUnlocked = true;
  unlockStartTime = millis();

  digitalWrite(RELAY_PIN, RELAY_UNLOCK_STATE); // Energizes relay -> 12V to Solenoid
  Serial.printf("\n[UNLOCK] (%s) -> Relay PIN %d = %s (Solenoid RETRACTED / OPEN)\n", 
                sourceName, RELAY_PIN, (RELAY_UNLOCK_STATE == HIGH ? "HIGH" : "LOW"));
}

void triggerLock(const char* reason = "Normal Lock") {
  isUnlocked = false;

  digitalWrite(RELAY_PIN, RELAY_LOCK_STATE); // De-energizes relay -> 0V to Solenoid
  Serial.printf("[LOCK] (%s) -> Relay PIN %d = %s (Coil 0V, ZERO Heat, LOCKED)\n",
                reason, RELAY_PIN, (RELAY_LOCK_STATE == HIGH ? "HIGH" : "LOW"));
}

// ==================================================================================
// --- 7. Zero-Latency Hardware Push Button Interrupt ---
// ==================================================================================
void IRAM_ATTR handleEmergencyButton() {
  unsigned long now = millis();
  if (now - lastButtonInterruptTime > 250) { // 250ms debounce
    lastButtonInterruptTime = now;
    
    // ⚡ ZERO LATENCY: Toggle relay pin INSTANTLY in hardware interrupt (Microseconds!)
    isUnlocked = !isUnlocked;
    digitalWrite(RELAY_PIN, isUnlocked ? RELAY_UNLOCK_STATE : RELAY_LOCK_STATE);
    
    buttonActionPending = true;   // Flag loop() to notify PHP server
    lastManualActionTime = now;   // Grace window to ignore stale polls
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

  // Notify update_lock_status.php — single authoritative write, no double sync
  String statusParam = (action == "unlock") ? "UNLOCKED" : "LOCKED";
  String updateUrl = String("http://") + hostIp + "/labsekyu2/public/api/update_lock_status.php?status=" + statusParam + "&room=" + roomName;
  if (syncHttp.begin(syncClient, updateUrl)) {
    int code = syncHttp.GET();
    Serial.printf("[SERVER SYNC] update_lock_status -> HTTP %d (%s)\n", code, statusParam.c_str());
    syncHttp.end();
  }
}

// ==================================================================================
// --- 9. Hardware & Network Setup ---
// ==================================================================================
void setup() {
  Serial.begin(115200);
  delay(300);

  // Initialize Relay Pin FIRST to de-energized LOCK state (0V / Cold / Safe)
  digitalWrite(RELAY_PIN, RELAY_LOCK_STATE); 
  pinMode(RELAY_PIN, OUTPUT);

  // Initialize Push Button with Internal Pull-Up Resistor
  pinMode(BUTTON_PIN, INPUT_PULLUP);
  attachInterrupt(digitalPinToInterrupt(BUTTON_PIN), handleEmergencyButton, FALLING);

  // Connect to Wi-Fi
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
    Serial.print("ESP32 Local IP: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("\n[WARNING] Wi-Fi Connection failed. Will retry in loop.");
  }

  // Setup Direct Embedded WebServer (Zero-Hop Direct API on port 80)
  localServer.on("/unlock", HTTP_GET, []() {
    triggerUnlock("Direct HTTP WebServer");
    localServer.sendHeader("Access-Control-Allow-Origin", "*");
    localServer.send(200, "text/plain", "UNLOCKED");
    sendActionToServer("unlock");
  });

  localServer.on("/lock", HTTP_GET, []() {
    triggerLock("Direct HTTP WebServer");
    localServer.sendHeader("Access-Control-Allow-Origin", "*");
    localServer.send(200, "text/plain", "LOCKED");
    sendActionToServer("lock");
  });

  localServer.on("/toggle", HTTP_GET, []() {
    if (isUnlocked) {
      triggerLock("Direct HTTP WebServer");
      sendActionToServer("lock");
    } else {
      triggerUnlock("Direct HTTP WebServer");
      sendActionToServer("unlock");
    }
    localServer.sendHeader("Access-Control-Allow-Origin", "*");
    localServer.send(200, "text/plain", isUnlocked ? "UNLOCKED" : "LOCKED");
  });

  localServer.on("/status", HTTP_GET, []() {
    localServer.sendHeader("Access-Control-Allow-Origin", "*");
    localServer.send(200, "text/plain", isUnlocked ? "UNLOCKED" : "LOCKED");
  });

  localServer.begin();

  // Configure Fast Persistent HTTP Client
  pollHttp.setReuse(true);          // Reuse TCP socket for ~2ms fast polling
  pollHttp.setTimeout(800);         // 800ms max timeout
  pollHttp.setConnectTimeout(800);

  Serial.println("==================================================");
  Serial.println("   LABSEKYU Ultra-Fast Solenoid Controller Ready  ");
  Serial.printf ("   Room ID:     %s\n", roomName);
  Serial.printf ("   XAMPP Host:  %s\n", hostIp);
  Serial.printf ("   Relay Logic: %s\n", RELAY_ACTIVE_LOW ? "ACTIVE-LOW (LOW=Unlock, HIGH=Lock)" : "ACTIVE-HIGH (HIGH=Unlock, LOW=Lock)");
  Serial.printf ("   Poll Rate:   %lu ms (Instant response)\n", pollInterval);
  Serial.printf ("   Direct API:  http://%s/unlock\n", WiFi.localIP().toString().c_str());
  Serial.println("==================================================");
}

// ==================================================================================
// --- 10. Main Loop (Non-Blocking, High-Speed) ---
// ==================================================================================
void loop() {
  unsigned long now = millis();

  // Handle incoming direct HTTP requests from WebServer (<1ms)
  localServer.handleClient();

  // 1. Hardware Button Edge-Detection Fallback (Prevents double-click when held down)
  int currentButtonState = digitalRead(BUTTON_PIN);
  if (currentButtonState == LOW && lastButtonState == HIGH && (now - lastButtonInterruptTime > 250)) {
    lastButtonInterruptTime = now;
    isUnlocked = !isUnlocked;
    digitalWrite(RELAY_PIN, isUnlocked ? RELAY_UNLOCK_STATE : RELAY_LOCK_STATE);
    buttonActionPending = true;
    lastManualActionTime = now;
    if (isUnlocked) {
      unlockStartTime = now;
    }
  }
  lastButtonState = currentButtonState;

  // 2. Synchronize Physical Button Action with Backend Database
  if (buttonActionPending) {
    buttonActionPending = false;
    Serial.printf("\n[BUTTON EVENT] Solenoid toggled to: %s\n", isUnlocked ? "UNLOCKED" : "LOCKED");
    sendActionToServer(isUnlocked ? "unlock" : "lock");
  }

  // 3. Momentary Access Pulse Mode (if enabled)
  if (AUTO_RELOCK_PULSE && isUnlocked && (now - unlockStartTime >= UNLOCK_PULSE_MS)) {
    Serial.println("\n[AUTO-RELOCK] 5-second pulse elapsed. Relocking door.");
    triggerLock("Pulse Elapsed");
    sendActionToServer("lock");
  }

  // 4. Emergency Continuous Thermal Cutoff
  // If door is left continuously unlocked for over 10 minutes, cut power to prevent coil burnout
  if (ENABLE_THERMAL_CUTOFF && isUnlocked && (now - unlockStartTime >= MAX_CONTINUOUS_UNLOCK_MS)) {
    Serial.println("\n⚠️ [THERMAL CUTOFF] Solenoid reached continuous 10-minute limit!");
    Serial.println("   Safely cutting power to 0V to prevent overheating burnout.");
    triggerLock("Thermal Cutoff");
    sendActionToServer("lock");
  }

  // 5. Ultra-Fast Backend Polling (Teacher & Tech Support Dashboard)
  if (now - lastPollTime >= pollInterval) {
    lastPollTime = now;

    // Grace period: If physical button was pressed within 2.5 seconds,
    // let our sync commit to the server before accepting incoming poll overrides
    if (now - lastManualActionTime < 2500) {
      // Skip poll override
    } else if (WiFi.status() == WL_CONNECTED) {
      if (pollHttp.begin(pollClient, lockStatusApiUrl)) {
        int httpCode = pollHttp.GET();
        if (httpCode == HTTP_CODE_OK) {
          String payload = pollHttp.getString();
          payload.trim();
          payload.toUpperCase();

          // ✅ EXACT MATCH: "UNLOCKED" contains "LOCKED" as a substring.
          // Using indexOf() causes "UNLOCKED" to trigger the LOCKED branch too → relay chatters.
          // Exact equality comparison is the only safe check here.
          if (payload == "UNLOCKED") {
            if (!isUnlocked) {
              triggerUnlock("Web Dashboard");
            }
          } else if (payload == "LOCKED") {
            if (isUnlocked) {
              triggerLock("Web Dashboard");
            }
          }
        }
        pollHttp.end(); // ✅ Always release TCP socket to prevent connection exhaustion
      }
    } else {
      WiFi.reconnect();
    }
  }

  delay(5); // Ultra-light 5ms yield for smooth multi-tasking
}
