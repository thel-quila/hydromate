"""
HydroMate — Flask Prediction API
=================================
Run:  python api.py
Port: http://localhost:5000

Endpoint: POST /predict
Body (JSON):
    {
        "age": 22,
        "gender": 1,
        "weight": 60.0,
        "water_intake": 1.5,
        "activity": 0,
        "weather": 1
    }

Response (JSON):
    {
        "prediction": 0,
        "label": "Hydrated",
        "confidence": 87.0,
        "proba_good": 87.0,
        "proba_poor": 13.0
    }

Encodings (must match training):
    Gender:   Female=0, Male=1
    Activity: Low=0, Moderate=1, High/Active=2
    Weather:  Cold=0, Normal=1, Hot=2
    Label:    Good=0 (Hydrated), Poor=1 (Not Hydrated)

IMPORTANT — StandardScaler:
    Your model was trained with StandardScaler but the scaler
    was NOT saved with the model. Two options:
    Option A (recommended): Re-save from Colab:
        pickle.dump({'model': rf_classifier, 'scaler': sc}, open('rf_model.pkl','wb'))
    Option B: The API will attempt prediction without scaling
              (may be inaccurate — always predicts Hydrated)
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
import pickle
import os
import numpy as np

app = Flask(__name__)
CORS(app)  # Allow PHP to call this API

# ── Load model on startup ──
MODEL_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "model.pkl")

model  = None
scaler = None

def load_model():
    global model, scaler
    try:
        with open(MODEL_PATH, "rb") as f:
            saved = pickle.load(f)

        if isinstance(saved, dict) and 'model' in saved and 'scaler' in saved:
            model  = saved['model']
            scaler = saved['scaler']
            print("✅ Model + Scaler loaded successfully!")
        else:
            # Old format — model only, no scaler
            model  = saved
            scaler = None
            print("⚠️  Model loaded WITHOUT scaler — predictions may be inaccurate!")
            print("    Fix: In Colab, run: pickle.dump({'model': rf_classifier, 'scaler': sc}, open('model.pkl','wb'))")

    except FileNotFoundError:
        print(f"❌ model.pkl not found at: {MODEL_PATH}")
    except Exception as e:
        print(f"❌ Failed to load model: {e}")

load_model()


# ══════════════════════════════
#  ROUTES
# ══════════════════════════════

@app.route('/', methods=['GET'])
def index():
    return jsonify({
        "status": "running",
        "model_loaded": model is not None,
        "scaler_loaded": scaler is not None,
        "endpoint": "POST /predict",
        "fields": ["age", "gender", "weight", "water_intake", "activity", "weather"]
    })


@app.route('/predict', methods=['POST'])
def predict():
    if model is None:
        return jsonify({"error": "Model not loaded. Check rf_model.pkl exists."}), 500

    data = request.get_json()
    if not data:
        return jsonify({"error": "No JSON body received."}), 400

    # ── Validate required fields ──
    required = ["age", "gender", "weight", "water_intake", "activity", "weather"]
    missing  = [f for f in required if f not in data]
    if missing:
        return jsonify({"error": f"Missing fields: {missing}"}), 400

    try:
        # ── Feature array — EXACT training order ──
        # Age, Gender, Weight_kg, Daily_Water_Intake_liters, Physical_Activity_Level, Weather
        features = np.array([[
            float(data['age']),
            float(data['gender']),       # Female=0, Male=1
            float(data['weight']),
            float(data['water_intake']), # Daily_Water_Intake_liters
            float(data['activity']),     # Low=0, Moderate=1, High=2
            float(data['weather']),      # Cold=0, Normal=1, Hot=2
        ]])

        # ── Apply scaler if available ──
        if scaler is not None:
            features = scaler.transform(features)

        # ── Predict ──
        prediction = int(model.predict(features)[0])
        proba      = model.predict_proba(features)[0].tolist()

        # 0 = Good (Hydrated), 1 = Poor (Not Hydrated)
        label = "Hydrated" if prediction == 0 else "Not Hydrated"

        return jsonify({
            "prediction":  prediction,
            "label":       label,
            "confidence":  round(max(proba) * 100, 1),
            "proba_good":  round(proba[0] * 100, 1),
            "proba_poor":  round(proba[1] * 100, 1),
            "scaled":      scaler is not None,
            "input": {
                "age":         data['age'],
                "gender":      data['gender'],
                "weight":      data['weight'],
                "water_intake":data['water_intake'],
                "activity":    data['activity'],
                "weather":     data['weather'],
            }
        })

    except ValueError as e:
        return jsonify({"error": f"Invalid input value: {str(e)}"}), 400
    except Exception as e:
        return jsonify({"error": f"Prediction failed: {str(e)}"}), 500


@app.route('/health', methods=['GET'])
def health():
    return jsonify({
        "status":       "ok",
        "model":        "loaded" if model else "missing",
        "scaler":       "loaded" if scaler else "missing — re-save pkl from Colab!",
        "model_path":   MODEL_PATH,
    })


if __name__ == '__main__':
    print("🚀 HydroMate ML API starting on http://localhost:5000")
    print(f"📁 Looking for model at: {MODEL_PATH}")
    app.run(host='0.0.0.0', port=5000, debug=True)