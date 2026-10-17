param([string]$Repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path)
$ErrorActionPreference = 'Stop'
$backup = Get-Content -LiteralPath (Join-Path $Repo 'config/backup.php') -Raw
$retention = Get-Content -LiteralPath (Join-Path $Repo 'app/Modules/Backup/Application/BackupRetentionService.php') -Raw
if ($backup -match 'beyourlovercom/erp-backups' -or $retention -match 'byl-erp-backup-' -or $backup -notmatch 'BACKUP_GITHUB_REPOSITORY' -or $retention -notmatch 'yby-erp-backup-') {
    Write-Output 'P5_BACKUP_PROJECT_ISOLATION=BLOCKED'
    Write-Output 'P5_AUTOMATIC_OFFSITE_BACKUP=NOT_AUTHORIZED'
    exit 2
}
if ($backup -notmatch 'storage/app/private' -or $backup -notmatch 'storage/app/public') { throw 'P5_FAIL_STORAGE_SCOPE_DRIFT' }
Write-Output 'P5_BACKUP_PROJECT_ISOLATION=PASS'
Write-Output 'P5_AUTOMATIC_OFFSITE_BACKUP=STILL_NOT_AUTHORIZED'
