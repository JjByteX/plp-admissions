@echo off
rem Starts llama-server (Qwen3-VL-8B) on this PC, local only (127.0.0.1).
rem Reads settings from ai\ai.env (copy ai\ai.env.example first).
setlocal
if not exist "%~dp0ai.env" (
  echo Missing ai\ai.env. Copy ai\ai.env.example to ai\ai.env and fill it in.
  pause & exit /b 1
)
for /f "usebackq eol=# tokens=1,* delims==" %%a in ("%~dp0ai.env") do set "%%a=%%b"

set "EXE=llama-server"
if defined LLAMA_DIR set "EXE=%LLAMA_DIR%\llama-server.exe"

rem --host 127.0.0.1 : never exposed directly, only the tunnel reaches it
rem --api-key        : calls without the key get 401
rem -np 1            : one request at a time (one GPU)
"%EXE%" ^
  -m "%MODEL_PATH%" ^
  --mmproj "%MMPROJ_PATH%" ^
  --host 127.0.0.1 ^
  --port %AI_PORT% ^
  -ngl %GPU_LAYERS% ^
  -c %CTX_SIZE% ^
  -np 1 ^
  --api-key "%AI_MODEL_KEY%"
pause
