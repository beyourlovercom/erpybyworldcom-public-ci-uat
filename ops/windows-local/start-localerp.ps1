$ErrorActionPreference = 'Stop'

$Repo = 'D:\ai\localerp.ybyworld.com'
$Domain = 'localerp.ybyworld.com'
$Port = 18180
$Runtime = Join-Path $Repo '.runtime'
$Router = Join-Path $env:APPDATA 'Local\run\router\nginx'
$Nginx = Join-Path $env:APPDATA 'Local\lightning-services\nginx-1.26.1+3\bin\win32\nginx.exe'
$PhpCommand = Get-Command php -ErrorAction SilentlyContinue
if ($PhpCommand) {
    $Php = $PhpCommand.Source
} else {
    $Php = Get-ChildItem (Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages\PHP.PHP.8.3_*') -Recurse -Filter php.exe -File -ErrorAction Stop | Select-Object -First 1 -ExpandProperty FullName
}
$PhpIni = Join-Path $Runtime 'php83\php.ini'
$LaravelRouter = Join-Path $Repo 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'

if ((Get-Service YBYERPMySQL -ErrorAction Stop).Status -ne 'Running') {
    Start-Service YBYERPMySQL
}

$listener = Get-NetTCPConnection -State Listen -LocalAddress 127.0.0.1 -LocalPort $Port -ErrorAction SilentlyContinue
if (-not $listener) {
    Start-Process -FilePath $Php -ArgumentList @('-c', $PhpIni, '-S', "127.0.0.1:$Port", $LaravelRouter) -WorkingDirectory (Join-Path $Repo 'public') -RedirectStandardOutput (Join-Path $Runtime 'web.log') -RedirectStandardError (Join-Path $Runtime 'web.err.log') -WindowStyle Hidden
}

$routeFile = Join-Path $Router "conf\route.$Domain.conf"
if (Test-Path (Join-Path $Router 'conf\nginx.conf')) {
    @"
server {
    server_name $Domain;
    include server-block.conf;
    location / { proxy_pass http://127.0.0.1:$Port; include location-block.conf; }
}
server {
    server_name $Domain;
    ssl_certificate      ../certs/$Domain.crt;
    ssl_certificate_key  ../certs/$Domain.key;
    include server-block-ssl.conf;
    location / { proxy_pass http://127.0.0.1:$Port; include location-block.conf; }
}
"@ | Set-Content $routeFile -Encoding ASCII

    if ((Test-Path $Nginx) -and (Test-Path (Join-Path $Router "certs\$Domain.crt"))) {
        Push-Location (Join-Path $Router 'conf')
        $previousErrorAction = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        & $Nginx -t -c (Join-Path $Router 'conf\nginx.conf') -p $Router 2>$null | Out-Null
        $nginxConfigOk = ($LASTEXITCODE -eq 0)
        if ($nginxConfigOk) { & $Nginx -s reload -c (Join-Path $Router 'conf\nginx.conf') -p $Router 2>$null | Out-Null }
        $ErrorActionPreference = $previousErrorAction
        Pop-Location
    }
}

Start-Sleep -Seconds 2
$health = & curl.exe -sk -o NUL -w '%{http_code}' --max-time 8 "https://$Domain/dashboard"
if ($health -ne '200') {
    throw "YBY ERP local health check failed: HTTP $health"
}

Write-Output "YBY ERP local READY: https://$Domain (HTTP $health)"
