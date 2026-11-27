@echo off
setlocal EnableExtensions
REM ==========================================================
REM  sync.bat - Sinkron project Smart Order ke GitHub
REM  Pemakaian:  sync.bat                 (pesan otomatis + tanggal)
REM              sync.bat "pesan commit"  (pesan sendiri)
REM ==========================================================
cd /d "%~dp0"
set "REMOTE=https://github.com/lordbyaku/SmartOrder.git"
set "BRANCH=main"

where git >nul 2>&1 || (echo [GAGAL] Git belum terpasang. Unduh di https://git-scm.com & goto :end)

if not exist ".git" (
  echo [INFO] Inisialisasi repository git...
  git init -b %BRANCH% || goto :fail
)

git remote get-url origin >nul 2>&1 || git remote add origin %REMOTE%

REM Pengaman: file rahasia tidak boleh ikut ter-commit
for %%F in (config\env.php config\vapid.php) do (
  git ls-files --error-unmatch "%%F" >nul 2>&1 && (
    echo [PERINGATAN] %%F sempat ter-track, dikeluarkan dari git...
    git rm --cached -q "%%F"
  )
)

set "MSG=%~1"
if "%MSG%"=="" (
  for /f "usebackq delims=" %%T in (`powershell -NoProfile -Command "Get-Date -Format 'yyyy-MM-dd HH:mm'"`) do set "MSG=Update %%T"
)

echo.
echo === Status perubahan ===
git status --short
git add -A || goto :fail

git diff --cached --quiet
if errorlevel 1 (
  git commit -m "%MSG%" || goto :fail
) else (
  echo [INFO] Tidak ada perubahan baru untuk di-commit.
)

echo.
echo === Ambil perubahan dari GitHub ===
git ls-remote --exit-code --heads origin %BRANCH% >nul 2>&1
if not errorlevel 1 (
  git pull --rebase origin %BRANCH% || (
    echo [GAGAL] Ada konflik saat pull. Selesaikan konflik, lalu jalankan: git rebase --continue
    goto :fail
  )
)

echo.
echo === Kirim ke GitHub ===
git push -u origin %BRANCH% || goto :fail

echo.
echo [SELESAI] Tersinkron ke %REMOTE% (branch %BRANCH%)
goto :end

:fail
echo.
echo [GAGAL] Sinkron tidak selesai. Periksa pesan di atas.
pause
exit /b 1

:end
pause
endlocal
