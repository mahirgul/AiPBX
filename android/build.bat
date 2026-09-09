@echo off
set "JAVA_HOME=C:\Program Files\Microsoft\jdk-17.0.20.101-hotspot"
set "ANDROID_HOME=%LOCALAPPDATA%\Android\Sdk"
set "PATH=C:\Gradle\gradle-8.7\bin;%JAVA_HOME%\bin;%PATH%"

echo ==========================================================
echo AI PBX Android Telefon - APK Derleyici
echo ==========================================================
call "C:\Gradle\gradle-8.7\bin\gradle.bat" --no-daemon assembleDebug

if %ERRORLEVEL% equ 0 (
    copy /y "app\build\outputs\apk\debug\app-debug.apk" "ai-pbx-phone.apk" >nul
    echo.
    echo ==========================================================
    echo DERLEME BASARILI!
    echo APK Dosyasi: %~dp0ai-pbx-phone.apk
    echo ==========================================================
) else (
    echo.
    echo DERLEME HATASI OLUSTU!
)
pause
