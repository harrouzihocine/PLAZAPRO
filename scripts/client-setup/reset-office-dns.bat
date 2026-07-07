@echo off
title PLAZA PRO - Reset DNS to Automatic
REM ===================================================================
REM  Undo set-office-dns.bat: return this PC's DNS to Automatic (DHCP).
REM  Use if you move the PC out of the office or want the default back.
REM ===================================================================

net session >nul 2>&1
if %errorlevel% neq 0 (
    echo Requesting administrator permission...
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

echo(
echo Resetting DNS to Automatic on all connected adapters...
echo(
powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-NetAdapter -Physical | Where-Object { $_.Status -eq 'Up' } | ForEach-Object { Set-DnsClientServerAddress -InterfaceIndex $_.ifIndex -ResetServerAddresses; Write-Host ('   Reset to automatic:  ' + $_.Name) -ForegroundColor Green }; Clear-DnsClientCache"

echo(
echo Done - this PC is back to the router's default DNS.
echo(
pause
