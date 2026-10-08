# hardware_scripts/run_waitress.py
from waitress import serve
from server import app

if __name__ == '__main__':
    print("Starting Flask app via Waitress on 127.0.0.1:8000...")
    serve(app, host='127.0.0.1', port=8000)
