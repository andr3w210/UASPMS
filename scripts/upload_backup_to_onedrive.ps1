param(
    [Parameter(Mandatory = $true)]
    [string]$FilePath,
    [Parameter(Mandatory = $true)]
    [string]$RemotePath,
    [string]$TenantId = $env:ONEDRIVE_TENANT_ID,
    [string]$ClientId = $env:ONEDRIVE_CLIENT_ID,
    [string]$ClientSecret = $env:ONEDRIVE_CLIENT_SECRET,
    [string]$DriveId = $env:ONEDRIVE_DRIVE_ID
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $FilePath -PathType Leaf)) {
    throw "Backup file not found: $FilePath"
}
foreach ($name in @('TenantId', 'ClientId', 'ClientSecret', 'DriveId')) {
    if ([string]::IsNullOrWhiteSpace((Get-Variable -Name $name -ValueOnly))) {
        throw "Missing OneDrive configuration: $name"
    }
}

$tokenResponse = Invoke-RestMethod -Method Post -Uri "https://login.microsoftonline.com/$TenantId/oauth2/v2.0/token" -ContentType 'application/x-www-form-urlencoded' -Body @{
    client_id     = $ClientId
    client_secret = $ClientSecret
    scope         = 'https://graph.microsoft.com/.default'
    grant_type    = 'client_credentials'
}
$accessToken = [string]$tokenResponse.access_token
if ([string]::IsNullOrWhiteSpace($accessToken)) {
    throw 'Microsoft Graph did not return an access token.'
}

$remotePath = $RemotePath.TrimStart('/')
$encodedPath = (($remotePath -split '/') | ForEach-Object { [uri]::EscapeDataString($_) }) -join '/'
$headers = @{ Authorization = "Bearer $accessToken" }
$fileInfo = Get-Item -LiteralPath $FilePath
$smallUploadLimit = 4MB

if ($fileInfo.Length -le $smallUploadLimit) {
    $content = [System.IO.File]::ReadAllBytes($FilePath)
    Invoke-RestMethod -Method Put -Uri "https://graph.microsoft.com/v1.0/drives/${DriveId}/root:/${encodedPath}:/content" -Headers $headers -ContentType 'application/octet-stream' -Body $content | Out-Null
    Write-Host "Uploaded to OneDrive: $remotePath"
    exit 0
}

$session = Invoke-RestMethod -Method Post -Uri "https://graph.microsoft.com/v1.0/drives/${DriveId}/root:/${encodedPath}:/createUploadSession" -Headers $headers -ContentType 'application/json' -Body (@{
    item = @{
        '@microsoft.graph.conflictBehavior' = 'replace'
    }
} | ConvertTo-Json -Compress)
$uploadUrl = [string]$session.uploadUrl
if ([string]::IsNullOrWhiteSpace($uploadUrl)) {
    throw 'Microsoft Graph did not return an upload session URL.'
}

$chunkSize = 10MB
$stream = [System.IO.File]::OpenRead($FilePath)
try {
    $buffer = New-Object byte[] $chunkSize
    $position = 0L
    while ($position -lt $fileInfo.Length) {
        $read = $stream.Read($buffer, 0, $buffer.Length)
        if ($read -le 0) { break }
        $chunk = if ($read -eq $buffer.Length) { $buffer } else { $buffer[0..($read - 1)] }
        $end = $position + $read - 1
        $chunkHeaders = @{
            'Content-Length' = [string]$read
            'Content-Range'  = "bytes $position-$end/$($fileInfo.Length)"
        }
        Invoke-RestMethod -Method Put -Uri $uploadUrl -Headers $chunkHeaders -ContentType 'application/octet-stream' -Body $chunk | Out-Null
        $position = $end + 1
        Write-Host "Uploaded $position/$($fileInfo.Length) bytes: $remotePath"
    }
} finally {
    $stream.Dispose()
}
Write-Host "Uploaded to OneDrive: $remotePath"
