@echo off
rem Starts the Cloudflare Tunnel (fixed hostname) to the local model server.
rem Needs cloudflared installed and CF_TUNNEL_TOKEN in ai\ai.env.
rem The public hostname is set in the Cloudflare dashboard to http://localhost:<AI_PORT>.
setlocal
if not exist "%~dp0ai.env" (
  echo Missing ai\ai.env. Copy ai\ai.env.example to ai\ai.env and fill it in.
  pause & exit /b 1
)
for /f "usebackq eol=# tokens=1,* delims==" %%a in ("%~dp0ai.env") do set "%%a=%%b"
if "%CF_TUNNEL_TOKEN%"=="" (
  echo CF_TUNNEL_TOKEN is empty in ai\ai.env.
  pause & exit /b 1
)
cloudflared tunnel run --token %CF_TUNNEL_TOKEN%
pause
