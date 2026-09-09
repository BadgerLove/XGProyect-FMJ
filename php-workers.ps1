# OGame PHP workers watchdog — keeps extra php-cgi FastCGI listeners alive on 127.0.0.1:9001-9003.
#
# Why: php-cgi on Windows handles ONE request at a time per process. The NSSM service "PHPCGI"
# (LocalSystem) owns 127.0.0.1:9000; on its own it meant every slow page blocked every visitor
# (2026-09-09). nginx now round-robins the "ogame_php" upstream across 9000-9003; this script makes
# sure 9001-9003 are always listening. It runs as Badger from the scheduled task "OGame PHP Workers"
# (every 5 minutes) and from ogame-startup.ps1 at boot.
#
# Manual: powershell -ExecutionPolicy Bypass -File C:\Users\Badger\.openclaw\workspace\xgproyect\php-workers.ps1
# Stop all extra workers: Get-Process php-cgi | Where-Object { $_.Id -ne (service PID) } | Stop-Process

$phpDir  = "C:\Users\Badger\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe"
$ports   = 9001, 9002, 9003
$logFile = "C:\Users\Badger\.openclaw\workspace\xgproyect\php-workers.log"

function Log($msg) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "$ts - $msg" | Add-Content $logFile
}

function Listening($port) {
    try {
        return [bool](Get-NetTCPConnection -LocalAddress 127.0.0.1 -LocalPort $port -State Listen -ErrorAction Stop)
    } catch {
        return [bool](netstat -ano | Select-String ("127.0.0.1:{0} .*LISTENING" -f $port))
    }
}

# php-cgi exits after PHP_FCGI_MAX_REQUESTS requests (default 500) and would have to be relaunched;
# 0 = serve forever. A crash is still caught by the next 5-minute run.
$env:PHP_FCGI_MAX_REQUESTS = "0"

foreach ($port in $ports) {
    if (Listening $port) { continue }

    Start-Process "$phpDir\php-cgi.exe" -ArgumentList "-b 127.0.0.1:$port -c $phpDir\php.ini" -WorkingDirectory $phpDir -WindowStyle Hidden
    Start-Sleep -Seconds 2

    if (Listening $port) {
        Log "started php-cgi on 127.0.0.1:$port"
    } else {
        Log "WARNING: php-cgi on 127.0.0.1:$port did not come up"
    }
}
