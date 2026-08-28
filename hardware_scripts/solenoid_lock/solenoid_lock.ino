#include <WiFi.h> // Use <ESP8266WiFi.h> if you are using an ESP8266 NodeMCU
#include <HTTPClient.h>

// 1. Replace with your actual WiFi credentials
const char* ssid = "YOUR_WIFI_SSID";
const char* password = "YOUR_WIFI_PASSWORD";

// 2. Replace with the IP address of your Computer (where XAMPP is running)
// Example: "http://192.168.1.15/labsekyu2/get_lock_status.php"
const String serverName = "http://YOUR_COMPUTER_IP/labsekyu2/get_lock_status.php";

// 3. Define the pin connected to your Relay Module
const int relayPin = 4; // Change this to your chosen GPIO pin

void setup() {
  Serial.begin(115200);
  
  // Set relay pin as output and lock it by default
  pinMode(relayPin, OUTPUT);
  digitalWrite(relayPin, LOW); 
  
  // Connect to WiFi
  WiFi.begin(ssid, password);
  Serial.print("Connecting to WiFi");
  while(WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nConnected to WiFi!");
}

void loop() {
  // Check if we are still connected to WiFi
  if(WiFi.status() == WL_CONNECTED){
    HTTPClient http;
    http.begin(serverName);
    
    // Make the request to the PHP API we just created
    int httpResponseCode = http.GET();
    
    if (httpResponseCode > 0) {
      String payload = http.getString();
      payload.trim(); // Clean up any invisible spaces/newlines
      
      // Control the Solenoid based on Dashboard Database Status
      if (payload == "UNLOCKED") {
        digitalWrite(relayPin, HIGH); // Triggers relay to OPEN solenoid
        Serial.println("Door is UNLOCKED");
      } 
      else if (payload == "LOCKED") {
        digitalWrite(relayPin, LOW); // Triggers relay to CLOSE solenoid
        Serial.println("Door is LOCKED");
      }
    } else {
      Serial.print("Error communicating with PHP: ");
      Serial.println(httpResponseCode);
    }
    http.end(); // Free resources
  }
  
  // Wait 1 second before asking the server again
  delay(1000); 
}
