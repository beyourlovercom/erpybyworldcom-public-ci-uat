param([string]$Repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path)
$ErrorActionPreference='Stop'
$sha=(& git -C $Repo rev-parse HEAD).Trim()
if($LASTEXITCODE -ne 0 -or $sha -notmatch '^[0-9a-f]{40}$'){throw 'P7_FAIL_SHA'}
$dirty=@(& git -C $Repo status --porcelain --untracked-files=normal)
if($LASTEXITCODE -ne 0 -or $dirty.Count -ne 0){throw 'P7_FAIL_DIRTY'}
foreach($script in @('p6-dry-run.ps1','p5-backup-isolation.ps1','preflight.ps1')){
 $f=Join-Path $Repo ('ops/yby-release/'+$script)
 if(-not(Test-Path $f)){throw 'P7_FAIL_MISSING_GATE'}
}
$p6=@(& powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Repo 'ops/yby-release/p6-dry-run.ps1') -Repo $Repo)
if($LASTEXITCODE -ne 0 -or -not ($p6 -contains 'P6_LOCAL_DRY_RUN=PASS') -or -not ($p6 -contains 'P6_PRODUCTION_RELEASE=BLOCKED')){throw 'P7_FAIL_P6_GATE'}
Write-Output 'P7_LOCAL_SECURITY_GATE=PASS'
Write-Output "EXACT_SHA=$sha"
Write-Output 'P7_PRODUCTION_DEPLOY=BLOCKED'
Write-Output 'P7_ISSUE_100_RELEASE_BLOCKER=OPEN'
