$body = @{
    email = 'test@example.com'
    password = 'password123'
} | ConvertTo-Json

Write-Host "Sending login request..."
$response = Invoke-WebRequest -Uri 'http://192.168.8.101:8000/api/v1/auth/login' -Method POST -ContentType 'application/json' -Body $body

Write-Host "Response received:"
$response.Content | ConvertFrom-Json | ConvertTo-Json -Depth 10
