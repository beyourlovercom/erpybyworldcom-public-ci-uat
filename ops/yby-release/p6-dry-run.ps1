param([string]$Repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path)
$ErrorActionPreference = 'Stop'
$sha = (& git -C $Repo rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or $sha -notmatch '^[a-f0-9]{40}$') { throw 'P6_FAIL_INVALID_EXACT_SHA' }
$dirty = @(& git -C $Repo status --porcelain --untracked-files=normal)
if ($LASTEXITCODE -ne 0 -or $dirty.Count -ne 0) { throw 'P6_FAIL_DIRTY_RELEASE_SOURCE' }
$required = @('ops/yby-release/preflight.ps1','ops/yby-release/p5-backup-isolation.ps1','ops/yby-release/P6-RELEASE-GATES.md')
foreach ($file in $required) { if (-not (Test-Path (Join-Path $Repo $file))) { throw "P6_FAIL_MISSING_GATE:$file" } }
$p4 = & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Repo 'ops/yby-release/preflight.ps1') -Repo $Repo
if ($LASTEXITCODE -ne 0 -or -not ($p4 -contains 'P4_LOCAL_PREFLIGHT=PASS')) { throw 'P6_FAIL_P4_PREFLIGHT' }
$p5 = & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Repo 'ops/yby-release/p5-backup-isolation.ps1') -Repo $Repo
if ($LASTEXITCODE -ne 0 -or -not ($p5 -contains 'P5_BACKUP_PROJECT_ISOLATION=PASS')) { throw 'P6_FAIL_P5_BACKUP_ISOLATION' }
Write-Output 'P6_LOCAL_DRY_RUN=PASS'
Write-Output "EXACT_SHA=$sha"
Write-Output 'P6_PRODUCTION_RELEASE=BLOCKED'
Write-Output 'P6_REMOTE_PROVISIONING=NOT_AUTHORIZED'
Write-Output 'P6_REAL_CUSTOMER_DATA=NOT_AUTHORIZED'
