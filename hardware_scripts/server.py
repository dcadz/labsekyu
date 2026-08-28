from flask import Flask, request, jsonify, Response
import os
from datetime import datetime, timedelta
import cv2
import numpy as np
import face_recognition
import pickle
import json
import threading
import time
import requests

app = Flask(__name__)

# Configuration
DATA_FOLDER = 'entrance_monitoring'
ENCODINGS_FILE = os.path.join(DATA_FOLDER, 'face_encodings.pkl')
DETECTIONS_FILE = os.path.join(DATA_FOLDER, 'detections.json')
FACES_FOLDER = os.path.join(DATA_FOLDER, 'faces')
MAX_ENCODINGS_PER_PERSON = 20
TOLERANCE = 0.5
MIN_FACE_SIZE = 50
MODEL = 'hog'
BACKUP_INTERVAL = 60

ESP32_IN_URL = "http://192.168.1.33:81/stream"
ESP32_OUT_URL = "http://ESP-OUT-IP:81/stream"
PHP_LOG_URL = "http://localhost/labsekyu2/log.php"

for folder in [DATA_FOLDER, FACES_FOLDER]:
    if not os.path.exists(folder):
        os.makedirs(folder)

person_detections = {}
backup_lock = threading.Lock()
stream_active = False
mjpeg_thread_in = None
mjpeg_thread_out = None
latest_frame_in = b''
latest_frame_out = b''

# Cooldown logic to prevent rapid log toggling
last_ping_times = {}
COOLDOWN_SECONDS = 60

def load_encodings():
    if os.path.exists(ENCODINGS_FILE):
        with open(ENCODINGS_FILE, 'rb') as f:
            return pickle.load(f)
    return {}

def save_encodings(encodings):
    with open(ENCODINGS_FILE, 'wb') as f:
        pickle.dump(encodings, f)

def load_detections():
    if os.path.exists(DETECTIONS_FILE):
        with open(DETECTIONS_FILE, 'r') as f:
            data = json.load(f)
            result = {}
            for person_id, timestamps in data.items():
                result[person_id] = [datetime.fromisoformat(ts) for ts in timestamps]
            return result
    return {}

def save_detections():
    with backup_lock:
        data = {}
        for person_id, timestamps in person_detections.items():
            data[person_id] = [ts.isoformat() for ts in timestamps]
        with open(DETECTIONS_FILE, 'w') as f:
            json.dump(data, f, indent=2)

def backup_thread():
    while True:
        time.sleep(BACKUP_INTERVAL)
        save_detections()

def mjpeg_consumer_thread(stream_url, device_id):
    global stream_active
    print(f"Starting MJPEG consumer thread for {device_id} at {stream_url}")
    while stream_active:
        try:
            response = requests.get(stream_url, stream=True, timeout=10)
            if response.status_code != 200:
                print(f"Failed to connect to {device_id} stream: {response.status_code}")
                time.sleep(5)
                continue
            print(f"Connected to {device_id} stream")
            bytes_data = bytes()
            for chunk in response.iter_content(chunk_size=1024):
                if not stream_active:
                    break
                bytes_data += chunk
                a = bytes_data.find(b'\xff\xd8')
                b = bytes_data.find(b'\xff\xd9')
                if a != -1 and b != -1:
                    jpg = bytes_data[a:b+2]
                    bytes_data = bytes_data[b+2:]
                    
                    global latest_frame_in, latest_frame_out
                    if device_id == "IN":
                        latest_frame_in = jpg
                    else:
                        latest_frame_out = jpg
                        
                    process_frame(jpg, device_id)
        except Exception as e:
            time.sleep(5)

def is_face_large_enough(face_location):
    top, right, bottom, left = face_location
    width = right - left
    height = bottom - top
    return width >= MIN_FACE_SIZE and height >= MIN_FACE_SIZE

def detect_and_encode_faces(image):
    rgb_image = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
    face_locations = face_recognition.face_locations(rgb_image, model=MODEL)
    valid_locations = [loc for loc in face_locations if is_face_large_enough(loc)]
    if not valid_locations:
        return [], []
    face_encodings = face_recognition.face_encodings(rgb_image, valid_locations, num_jitters=2)
    return valid_locations, face_encodings

def find_matching_person(face_encoding):
    if not known_face_encodings:
        return None, 0.0
    best_person = None
    best_confidence = 1.0
    for person_id, encodings in known_face_encodings.items():
        matches = face_recognition.compare_faces(encodings, face_encoding, tolerance=TOLERANCE)
        if True in matches:
            face_distances = face_recognition.face_distance(encodings, face_encoding)
            min_distance = np.min(face_distances)
            match_count = sum(matches)
            match_ratio = match_count / len(encodings)
            confidence_score = (0.7 * min_distance) + (0.3 * (1 - match_ratio))
            if confidence_score < best_confidence:
                best_confidence = confidence_score
                best_person = person_id
    if best_person and best_confidence < 0.4:
        return best_person, best_confidence
    return None, best_confidence

def add_encoding_smart(person_id, new_encoding):
    if person_id not in known_face_encodings:
        known_face_encodings[person_id] = []
    encodings = known_face_encodings[person_id]
    if len(encodings) < 5:
        encodings.append(new_encoding)
        return True
    distances = face_recognition.face_distance(encodings, new_encoding)
    avg_distance = np.mean(distances)
    if avg_distance > 0.3 and len(encodings) < MAX_ENCODINGS_PER_PERSON:
        encodings.append(new_encoding)
        return True
    if len(encodings) >= MAX_ENCODINGS_PER_PERSON:
        max_dist_idx = np.argmax(distances)
        if distances[max_dist_idx] > 0.4:
            encodings[max_dist_idx] = new_encoding
            return True
    return False

def add_detection(person_id, timestamp):
    if person_id not in person_detections:
        person_detections[person_id] = []
    person_detections[person_id].append(timestamp)
    cutoff = datetime.now() - timedelta(days=7)
    person_detections[person_id] = [ts for ts in person_detections[person_id] if ts > cutoff]

def process_frame(jpg_bytes, device_id):
    try:
        nparr = np.frombuffer(jpg_bytes, np.uint8)
        image = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        if image is None:
            return
        timestamp = datetime.now()
        face_locations, face_encodings = detect_and_encode_faces(image)
        if not face_encodings:
            return
        
        detected_persons = []
        for idx, (location, encoding) in enumerate(zip(face_locations, face_encodings)):
            person_id, confidence = find_matching_person(encoding)
            if person_id is not None:
                add_encoding_smart(person_id, encoding)
                add_detection(person_id, timestamp)
                detected_persons.append(person_id)
                
                # Check cooldown and log to PHP
                now = time.time()
                cooldown_key = f"{person_id}_{device_id}"
                if cooldown_key not in last_ping_times or (now - last_ping_times[cooldown_key]) > COOLDOWN_SECONDS:
                    last_ping_times[cooldown_key] = now
                    try:
                        resp = requests.get(f"{PHP_LOG_URL}?uid={person_id}&device_id={device_id}")
                        print(f"Logged {person_id} to PHP via {device_id}: {resp.text.strip()}")
                    except Exception as e:
                        print(f"Failed to log {person_id}: {e}")

        save_encodings(known_face_encodings)
        
    except Exception as e:
        print(f"Error processing frame: {e}")

known_face_encodings = load_encodings()
person_detections = load_detections()

backup_daemon = threading.Thread(target=backup_thread, daemon=True)
backup_daemon.start()

stream_active = True
mjpeg_thread_in = threading.Thread(target=mjpeg_consumer_thread, args=(ESP32_IN_URL, "IN"), daemon=True)
mjpeg_thread_in.start()

mjpeg_thread_out = threading.Thread(target=mjpeg_consumer_thread, args=(ESP32_OUT_URL, "OUT"), daemon=True)
mjpeg_thread_out.start()

@app.route('/video_feed/<device_id>')
def video_feed(device_id):
    def generate():
        while True:
            frame = latest_frame_in if device_id == "IN" else latest_frame_out
            if not frame:
                time.sleep(0.1)
                continue
            yield (b'--frame\r\n'
                   b'Content-Type: image/jpeg\r\n\r\n' + frame + b'\r\n')
            time.sleep(0.05)
    return Response(generate(), mimetype='multipart/x-mixed-replace; boundary=frame')

@app.route('/api/register_face', methods=['POST'])
def register_face():
    try:
        if 'image' not in request.files or 'uid' not in request.form:
            return jsonify({'error': 'Missing image or uid'}), 400
        
        uid = request.form['uid']
        file = request.files['image']
        
        # Read image
        nparr = np.frombuffer(file.read(), np.uint8)
        image = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        if image is None:
            return jsonify({'error': 'Invalid image'}), 400
            
        # Resize large images to prevent Face API from hanging/crashing
        height, width = image.shape[:2]
        if max(height, width) > 800:
            scale = 800.0 / max(height, width)
            image = cv2.resize(image, (0, 0), fx=scale, fy=scale)
            
        face_locations, face_encodings = detect_and_encode_faces(image)
        
        if len(face_encodings) == 0:
            return jsonify({'error': 'No valid face detected in image'}), 400
            
        # Only take the largest face
        if len(face_encodings) > 1:
            areas = [(loc[2]-loc[0])*(loc[1]-loc[3]) for loc in face_locations]
            largest_idx = np.argmax(areas)
            face_encoding = face_encodings[largest_idx]
            face_location = face_locations[largest_idx]
        else:
            face_encoding = face_encodings[0]
            face_location = face_locations[0]
            
        person_id = str(uid)
        
        if person_id not in known_face_encodings:
            known_face_encodings[person_id] = []
            
        known_face_encodings[person_id].append(face_encoding)
        save_encodings(known_face_encodings)
        
        person_folder = os.path.join(FACES_FOLDER, person_id)
        os.makedirs(person_folder, exist_ok=True)
        top, right, bottom, left = face_location
        padding = 20
        top = max(0, top - padding)
        left = max(0, left - padding)
        bottom = min(image.shape[0], bottom + padding)
        right = min(image.shape[1], right + padding)
        face_image = image[top:bottom, left:right]
        face_filename = f'registered_{datetime.now().strftime("%Y%m%d_%H%M%S")}.jpg'
        cv2.imwrite(os.path.join(person_folder, face_filename), face_image)
        
        return jsonify({'success': True, 'message': f'Face registered for UID {person_id}'}), 200
        
    except Exception as e:
        import traceback
        traceback.print_exc()
        return jsonify({'error': str(e)}), 500

if __name__ == '__main__':
    print("=" * 60)
    print("Face Recognition System")
    print("=" * 60)
    app.run(host='0.0.0.0', port=8000, debug=False, threaded=True)
