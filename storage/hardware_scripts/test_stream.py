import requests

url = "http://localhost:8000/video_feed/IN"
r = requests.get(url, stream=True, timeout=5)
frames = 0
for chunk in r.iter_content(chunk_size=1024):
    if b'--frame' in chunk:
        frames += 1
        print(f"Received frame {frames}")
    if frames >= 10:
        break
print("Done")
