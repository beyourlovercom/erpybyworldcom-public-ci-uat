param([string]$Repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path)
$ErrorActionPreference = 'Stop'
$sha = (& git -C $Repo rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or $sha -notmatch '^[a-f0-9]{40}$') { throw 'P4_FAIL_INVALID_GIT_HEAD' }
$dirty = @(& git -C $Repo status --porcelain --untracked-files=normal)
if ($LASTEXITCODE -ne 0) { throw 'P4_FAIL_GIT_STATUS' }
if ($dirty.Count -gt 0) { throw 'P4_FAIL_DIRTY_WORKTREE' }
$required = @('artisan','composer.lock','public/index.php','config/backup.php','routes/web.php')
foreach ($file in $required) { if (-not (Test-Path (Join-Path $Repo $file))) { throw "P4_FAIL_MISSING_FILE:$file" } }
$tracked = @(& git -C $Repo ls-files)
if ($LASTEXITCODE -ne 0) { throw 'P4_FAIL_TRACKED_LIST' }
foreach ($file in $tracked) {
  $normalized = $file.Replace('\','/')
  if ($normalized -eq '.env.example' -or $normalized -match '^storage/app/(private|backups)/\.gitignore$') { continue }
  if ($normalized -match '(^|/)(\.env($|\.)|\.git/|vendor/|node_modules/)' -or $normalized -match '(^|/)(storage/app/private|storage/app/backups)/') { throw 'P4_FAIL_FORBIDDEN_TRACKED_PATH' }
}
Write-Output 'P4_LOCAL_PREFLIGHT=PASS'
Write-Output "EXACT_SHA=$sha"
Write-Output 'PRODUCTION_PROVISIONED=NO'
Write-Output 'CUSTOMER_DATA_ACCESS=NONE'
