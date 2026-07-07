@echo off
title PLAZA PRO - Office DNS Setup
REM ===================================================================
REM  Points THIS Windows PC at the office server (192.168.1.200) for DNS.
REM  Result: https://office.plaza-pro.com opens at LAN speed and keeps
REM  working even when the internet is down. Run once per office PC.
REM  Safe & reversible - run reset-office-dns.bat to undo.
REM ===================================================================

REM --- Setting DNS needs Administrator: re-launch elevated if we're not ---
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo Requesting administrator permission...
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

echo(
echo ==========================================================
echo    PLAZA PRO  -  pointing this PC at the office server
echo ==========================================================
echo(

REM --- Set DNS on every connected physical adapter (Wi-Fi / Ethernet) ---
powershell -NoProfile -ExecutionPolicy Bypass -Command "$done=$false; Get-NetAdapter -Physical | Where-Object { $_.Status -eq 'Up' } | ForEach-Object { Set-DnsClientServerAddress -InterfaceIndex $_.ifIndex -ServerAddresses '192.168.1.200','8.8.8.8'; Write-Host ('   DNS set on:  ' + $_.Name) -ForegroundColor Green; $done=$true }; Clear-DnsClientCache; if (-not $done) { Write-Host '   ERROR: no connected network adapter found.' -ForegroundColor Red }"

echo(
echo Checking that the office name now resolves...
powershell -NoProfile -Command "try { $ip = (Resolve-DnsName office.plaza-pro.com -Type A -ErrorAction Stop | Select-Object -First 1).IPAddress; if ($ip -eq '192.168.1.200') { Write-Host ('   OK  office.plaza-pro.com -> ' + $ip) -ForegroundColor Green } else { Write-Host ('   office.plaza-pro.com -> ' + $ip + '  (expected 192.168.1.200)') -ForegroundColor Yellow } } catch { Write-Host '   Could not resolve yet - is the server switched on?' -ForegroundColor Yellow }"

echo(
echo ----------------------------------------------------------
echo  Done. Open the app at:   https://office.plaza-pro.com
echo  (bookmark it - this PC will now reach it even offline)
echo ----------------------------------------------------------
echo(
pause
