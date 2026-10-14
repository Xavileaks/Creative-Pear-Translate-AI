@echo off
setlocal
cd /d "%~dp0"
where node >nul 2>nul
if %errorlevel% equ 0 (
  node "%~dp0cptai-companion.cjs"
) else if exist "%USERPROFILE%\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe" (
  "%USERPROFILE%\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe" "%~dp0cptai-companion.cjs"
) else (
  echo Instala Node.js 22 o posterior desde https://nodejs.org y vuelve a abrir este archivo.
)
pause
