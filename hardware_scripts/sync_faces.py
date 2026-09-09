import os
import pickle
import mysql.connector

DATA_FOLDER = 'entrance_monitoring'
ENCODINGS_FILE = os.path.join(DATA_FOLDER, 'face_encodings.pkl')

def get_db_users():
    try:
        conn = mysql.connector.connect(
            host="localhost",
            user="root",
            password="",
            database="labsekyu_db"
        )
        cursor = conn.cursor()
        cursor.execute("SELECT role_id FROM users WHERE role_id IS NOT NULL AND role_id != ''")
        users = set(str(row[0]) for row in cursor.fetchall())
        conn.close()
        return users
    except Exception as e:
        print(f"DB Error: {e}")
        return set()

if os.path.exists(ENCODINGS_FILE):
    with open(ENCODINGS_FILE, 'rb') as f:
        known_face_encodings = pickle.load(f)
        
    db_users = get_db_users()
    
    faces_to_delete = []
    for uid in known_face_encodings.keys():
        if uid not in db_users:
            faces_to_delete.append(uid)
            
    for uid in faces_to_delete:
        print(f"Deleting orphaned face for UID {uid}")
        del known_face_encodings[uid]
        
    if faces_to_delete:
        with open(ENCODINGS_FILE, 'wb') as f:
            pickle.dump(known_face_encodings, f)
        print("Updated face_encodings.pkl")
    else:
        print("No orphaned faces found.")
else:
    print("No encodings file found.")
