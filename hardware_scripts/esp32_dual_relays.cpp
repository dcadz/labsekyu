#include <Arduino.h>

// ESP32 Arduino IDE Logic for Independent Relays
// Pin definitions (change according to your wiring)
const int RELAY_ENTRANCE = 26; // Door A (Entrance)
const int RELAY_EXIT = 27;     // Door B (Exit)

void setup() {
  Serial.begin(115200);
  
  // Set relay pins as outputs
  pinMode(RELAY_ENTRANCE, OUTPUT);
  pinMode(RELAY_EXIT, OUTPUT);
  
  // Default to locked (assuming LOW is locked state for your relays)
  digitalWrite(RELAY_ENTRANCE, LOW);
  digitalWrite(RELAY_EXIT, LOW);
}

// Function to handle HTTP request or MQTT message for door control
// targetDoor should be "entrance" or "exit"
// action should be "lock" or "unlock"
void handleDoorControl(String targetDoor, String action) {
  int targetPin = -1;
  
  if (targetDoor == "entrance") {
    targetPin = RELAY_ENTRANCE;
  } else if (targetDoor == "exit") {
    targetPin = RELAY_EXIT;
  }
  
  if (targetPin != -1) {
    if (action == "unlock") {
      digitalWrite(targetPin, HIGH); // Unlock specific door
      Serial.println("Door " + targetDoor + " UNLOCKED");
      
      // Optional: keep open for a few seconds then auto-lock
      // NOTE: In production code, avoid using delay() if you have other tasks running.
      // Use millis() based non-blocking timers instead.
      delay(5000); 
      
      digitalWrite(targetPin, LOW);  // Lock it again
      Serial.println("Door " + targetDoor + " AUTO-LOCKED");
      
    } else if (action == "lock") {
      digitalWrite(targetPin, LOW); // Force lock specific door
      Serial.println("Door " + targetDoor + " LOCKED manually");
    }
  }
}

void loop() {
  // Your existing HTTP server or MQTT client loop goes here
  // Call handleDoorControl() when you receive a message.
}
