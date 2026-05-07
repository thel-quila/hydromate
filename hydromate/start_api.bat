@echo off
echo ========================================
echo    HydroMate ML API
echo    http://localhost:5000
echo ========================================
echo.

cd /d "%~dp0"

echo Installing dependencies...
pip install flask flask-cors scikit-learn==1.6.1 numpy

echo.
echo Starting Flask API...
python api.py

pause