import json
import subprocess
import requests
import pickle
import os

# Get users from PHP
result = subprocess.run(['C:\\xampp\\php\\php.exe', 'sync.php'], capture_output=True, text=True)
db_users = json.loads(result.stdout)

DATA_FOLDER = 'entrance_monitoring'
ENCODINGS_FILE = os.path.join(DATA_FOLDER, 'face_encodings.pkl')

if os.path.exists(ENCODINGS_FILE):
    with open(ENCODINGS_FILE, 'rb') as f:
        known = pickle.load(f)
        
    for uid in known.keys():
        if uid not in db_users:
            print(f"Deleting orphaned face: {uid}")
            requests.post('http://localhost:8000/api/delete_face', data={'uid': uid})
    print("Done checking.")
else:
    print("No encodings file.")
