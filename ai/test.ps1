# Checks the model server. Usage (from the repo root):
#   powershell -File ai\test.ps1 -Image C:\samples\id.jpg                       # local
#   powershell -File ai\test.ps1 -Image C:\samples\id.jpg -Url https://ai.example.com   # through the tunnel
# The key is read from ai\ai.env (AI_MODEL_KEY). Never printed.
param(
  [Parameter(Mandatory = $true)][string]$Image,
  [string]$Url = ""
)
$ErrorActionPreference = "Stop"
$envFile = Join-Path $PSScriptRoot "ai.env"
$cfg = @{}
Get-Content $envFile | Where-Object { $_ -match '^\s*[^#\s].*=' } | ForEach-Object {
  $k, $v = $_ -split '=', 2; $cfg[$k.Trim()] = $v.Trim()
}
if (-not $Url) { $Url = "http://127.0.0.1:$($cfg['AI_PORT'])" }
$Url = $Url.TrimEnd('/')

$ext = [IO.Path]::GetExtension($Image).TrimStart('.').ToLower()
if ($ext -eq 'jpg') { $ext = 'jpeg' }
$b64 = [Convert]::ToBase64String([IO.File]::ReadAllBytes($Image))

# Single number answer so the choice is one token (Phase 3.8 reads its logprob).
$body = @{
  messages = @(@{
    role = "user"
    content = @(
      @{ type = "image_url"; image_url = @{ url = "data:image/$ext;base64,$b64" } },
      @{ type = "text"; text = "Which document is this? 1 = birth certificate, 2 = valid ID, 3 = report card, 0 = other. Answer with the single number only." }
    )
  })
  max_tokens = 1
  temperature = 0
  logprobs = $true
  top_logprobs = 5
} | ConvertTo-Json -Depth 8
$tmp = New-TemporaryFile
Set-Content -Path $tmp -Value $body -Encoding utf8

# 1. Without the key: must fail (401)
$code = & curl.exe -s -o NUL -w "%{http_code}" -X POST "$Url/v1/chat/completions" -H "Content-Type: application/json" -d "@$tmp"
Write-Host "No key  -> HTTP $code (expected 401)"

# 2. With the key: must return a reply with logprobs
$sw = [Diagnostics.Stopwatch]::StartNew()
$resp = & curl.exe -s -X POST "$Url/v1/chat/completions" -H "Content-Type: application/json" -H "Authorization: Bearer $($cfg['AI_MODEL_KEY'])" -d "@$tmp"
$sw.Stop()
Remove-Item $tmp
$j = $resp | ConvertFrom-Json
$choice = $j.choices[0]
$hasLp = [bool]$choice.logprobs.content
Write-Host ("With key -> reply '{0}', logprobs: {1}, time: {2:N1}s (limit 20s)" -f $choice.message.content, $hasLp, $sw.Elapsed.TotalSeconds)
if ($hasLp) {
  $choice.logprobs.content[0].top_logprobs | ForEach-Object {
    Write-Host ("  token '{0}'  prob {1:P1}" -f $_.token, [math]::Exp($_.logprob))
  }
}
