# ============================================================
# create_releases.ps1
# Употреба: .\create_releases.ps1 -Version "3.8.8" -Message "fix cart notices"
# ============================================================

param(
    [Parameter(Mandatory = $true)]
    [string]$Version,

    [Parameter(Mandatory = $true)]
    [string]$Message
)

# ============================================================
# ТОКЕНЪТ СЕ ЧЕТЕ ОТ ENVIRONMENT VARIABLE - не се подава в скрипта
# Задай го веднъж в Windows:
# [System.Environment]::SetEnvironmentVariable("GITHUB_TOKEN", "ghp_xxxx", "User")
# След това рестартирай VSCode/терминала
# ============================================================

$TOKEN = $env:GITHUB_TOKEN

if (-not $TOKEN) {
    Write-Host "ГРЕШКА: Няма намерен GITHUB_TOKEN в environment variables." -ForegroundColor Red
    Write-Host "Изпълни веднъж:" -ForegroundColor Yellow
    Write-Host '[System.Environment]::SetEnvironmentVariable("GITHUB_TOKEN", "ghp_ТВОЯ_ТОКЕН", "User")' -ForegroundColor Yellow
    Write-Host "После рестартирай терминала и опитай отново." -ForegroundColor Yellow
    exit 1
}

$OWNER = "KaloyanDDimitrov"
$REPO = "loco-ai-translator"   # БЕЗ .git — GitHub API не го иска
$TAG = "v$Version"

$HEADER = @{
    Authorization = "Bearer $TOKEN"
    Accept        = "application/vnd.github+json"
}

Write-Host ""
Write-Host "======================================" -ForegroundColor Cyan
Write-Host " WC Scheduled Discount Manager - New Release: $TAG" -ForegroundColor Cyan
Write-Host "======================================" -ForegroundColor Cyan
Write-Host ""

# ============================================================
# СТЪПКА 1 - Git add, commit, push
# ============================================================

Write-Host "[ 1/4 ] Git commit & push..." -ForegroundColor Yellow

git add .
git commit -m "$TAG - $Message"
git push origin main

if ($LASTEXITCODE -ne 0) {
    Write-Host "ГРЕШКА при git push. Спиране." -ForegroundColor Red
    exit 1
}

Write-Host "OK: Commit pushed." -ForegroundColor Green

# ============================================================
# СТЪПКА 2 - Създай и push-ни тага
# ============================================================

Write-Host ""
Write-Host "[ 2/4 ] Създаване на таг $TAG..." -ForegroundColor Yellow

git tag $TAG
git push origin $TAG

if ($LASTEXITCODE -ne 0) {
    Write-Host "ГРЕШКА при git tag push. Може би тагът вече съществува." -ForegroundColor Red
    exit 1
}

Write-Host "OK: Tag $TAG pushed." -ForegroundColor Green

# ============================================================
# СТЪПКА 3 - Прочети changelog за тази версия от changelog.md
# Поддържа формати:
#   ## 3.8.8 - 2026-03-03
#   ## v3.8.8 - 2026-03-03
#   ## [3.8.8] - 2026-03-03
# ============================================================

Write-Host ""
Write-Host "[ 3/4 ] Четене на changelog.md..." -ForegroundColor Yellow

$changelogPath = Join-Path $PSScriptRoot "changelog.md"
$changelogBody = ""

if (Test-Path $changelogPath) {
    $lines = Get-Content $changelogPath
    $capture = $false
    $bodyLines = @()

    # Нормализираме версията — махаме водещо 'v' ако има
    $cleanVersion = $Version -replace '^v', ''

    foreach ($line in $lines) {
        # Засичаме началото на секцията за тази версия
        if ($line -match "^##\s+v?$([regex]::Escape($cleanVersion))(\s|$|-|\])") {
            $capture = $true
            continue
        }
        # Спираме при следващата ## секция
        if ($capture -and $line -match "^##\s") {
            break
        }
        if ($capture) {
            $bodyLines += $line
        }
    }

    $changelogBody = ($bodyLines -join "`n").Trim()

    if ($changelogBody) {
        Write-Host "OK: Changelog намерен за версия $Version." -ForegroundColor Green
    }
    else {
        Write-Host "ВНИМАНИЕ: Няма запис за $Version в changelog.md. Release ще е без описание." -ForegroundColor Yellow
        $changelogBody = "Release $TAG"
    }
}
else {
    Write-Host "ВНИМАНИЕ: changelog.md не е намерен. Release ще е без описание." -ForegroundColor Yellow
    $changelogBody = "Release $TAG"
}

# ============================================================
# СТЪПКА 4 - Създай GitHub Release
# ============================================================

Write-Host ""
Write-Host "[ 4/4 ] Създаване на GitHub Release..." -ForegroundColor Yellow

$payload = @{
    tag_name    = $TAG
    name        = "$TAG"
    body        = $changelogBody
    draft       = $false
    prerelease  = $false
    make_latest = "true"
} | ConvertTo-Json -Depth 5

try {
    Invoke-RestMethod `
        -Uri "https://api.github.com/repos/$OWNER/$REPO/releases" `
        -Method Post `
        -Headers $HEADER `
        -Body $payload `
        -ContentType "application/json" | Out-Null

    Write-Host "OK: GitHub Release $TAG създаден успешно." -ForegroundColor Green
}
catch {
    Write-Host "ГРЕШКА при създаване на Release: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}

# ============================================================
# ГОТОВО
# ============================================================

Write-Host ""
Write-Host "======================================" -ForegroundColor Cyan
Write-Host " Версия $TAG е публикувана успешно!" -ForegroundColor Cyan
Write-Host " https://github.com/$OWNER/$REPO/releases/tag/$TAG" -ForegroundColor Cyan
Write-Host "======================================" -ForegroundColor Cyan
Write-Host ""