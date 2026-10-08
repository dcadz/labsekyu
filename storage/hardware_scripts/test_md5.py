import requests
import hashlib

url = "http://localhost:8000/video_feed/IN"
print("Connecting...")
try:
    r = requests.get(url, stream=True, timeout=5)
    frames = 0
    current_frame_bytes = b''
    
    for chunk in r.iter_content(chunk_size=1024):
        current_frame_bytes += chunk
        if b'--frame\r\n' in current_frame_bytes:
            parts = current_frame_bytes.split(b'--frame\r\n')
            if len(parts) > 1:
                # We have a full frame boundary
                frame_data = parts[0]
                # Extract jpeg data (starts with \xff\xd8)
                a = frame_data.find(b'\xff\xd8')
                if a != -1:
                    jpg_data = frame_data[a:]
                    frames += 1
                    md5 = hashlib.md5(jpg_data).hexdigest()
                    print(f"Frame {frames} saved, size: {len(jpg_data)}, MD5: {md5}")
                    with open(f"test_frame_{frames}.jpg", "wb") as f:
                        f.write(jpg_data)
                
                current_frame_bytes = b'--frame\r\n' + parts[1]
                
            if frames >= 5:
                break
except Exception as e:
    print(f"Error: {e}")
print("Done")
