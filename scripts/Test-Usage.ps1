param(
    [int]$CustomerId = 1,
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [string]$Key
)

$ErrorActionPreference = 'Stop'

if (-not $Key) {
    $Key = Read-Host 'Paste merchant API key'
}

$uri = $BaseUrl.TrimEnd('/') + '/usage'
$headers = @{ 'X-Merchant-Key' = $Key }
$body = @{
    customer_id = $CustomerId
    event_id = 'usage-check-' + [guid]::NewGuid().ToString()
    occurred_at = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    units = 5
} | ConvertTo-Json

Write-Host "POST $uri"
Write-Host 'First request:'
Invoke-RestMethod -Method Post -Uri $uri -Headers $headers -ContentType 'application/json' -Body $body |
    ConvertTo-Json

Write-Host 'Identical retry:'
Invoke-RestMethod -Method Post -Uri $uri -Headers $headers -ContentType 'application/json' -Body $body |
    ConvertTo-Json
