@echo off
REM Test script to debug the /api/v1/users endpoint from device
REM Simulates the actual HTTP request from Flutter

echo === Testing API /api/v1/users ===
echo.

REM 1. Get the server config
set SERVER=http://192.168.1.112:8000

REM 2. Create a test token (using test user Azerty)
echo 1. Authenticating as Azerty...
for /f "tokens=2 delims=:," %%a in ('curl -s -X POST "%SERVER%/api/v1/auth/login" ^
  -H "Content-Type: application/json" ^
  -d "{"email":"azerty@gmail.com","password":"password123"}" ^
  ^| findstr "token"') do (
  set TOKEN=%%~a
  set TOKEN=!TOKEN:~2,-2!
)

if "%TOKEN%"=="" (
  echo ERROR: Could not get token. Check credentials.
  pause
  exit /b 1
)

echo Token: %TOKEN%
echo.

REM 3. Test the users endpoint
echo 2. Testing GET /api/v1/users...
curl -v -X GET "%SERVER%/api/v1/users?page=1" ^
  -H "Authorization: Bearer %TOKEN%" ^
  -H "Accept: application/json"

echo.
echo Done.
pause
