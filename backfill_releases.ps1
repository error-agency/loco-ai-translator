# ============================================================
# backfill_releases.ps1
# Usage:    .\backfill_releases.ps1
# Dry run:  .\backfill_releases.ps1 -DryRun
# ============================================================

param(
    [switch]$DryRun
)

$TOKEN = $env:GITHUB_TOKEN

if (-not $TOKEN) {
    Write-Host "ERROR: GITHUB_TOKEN not found in environment variables." -ForegroundColor Red
    exit 1
}

$OWNER = "KaloyanDDimitrov"
$REPO = "loco-ai-translator"
$changelogPath = Join-Path $PSScriptRoot "changelog.md"

if (-not (Test-Path $changelogPath)) {
    Write-Host "ERROR: changelog.md not found in $PSScriptRoot" -ForegroundColor Red
    exit 1
}

$HEADER = @{
    Authorization = "Bearer $TOKEN"
    Accept        = "application/vnd.github+json"
}

# ============================================================
# HELPER: Get SHA for a tag (returns $null if tag doesn't exist)
# ============================================================
function Get-TagSha {
    param([string]$TagName)
    try {
        $ref = Invoke-RestMethod `
            -Uri "https://api.github.com/repos/$OWNER/$REPO/git/ref/tags/$TagName" `
            -Headers $HEADER `
            -ErrorAction Stop
        return $ref.object.sha
    }
    catch {
        return $null
    }
}

# ============================================================
# HELPER: Get SHA of HEAD of main branch
# ============================================================
function Get-MainSha {
    try {
        $ref = Invoke-RestMethod `
            -Uri "https://api.github.com/repos/$OWNER/$REPO/git/ref/heads/main" `
            -Headers $HEADER `
            -ErrorAction Stop
        return $ref.object.sha
    }
    catch {
        # Try 'master' if 'main' doesn't exist
        try {
            $ref = Invoke-RestMethod `
                -Uri "https://api.github.com/repos/$OWNER/$REPO/git/ref/heads/master" `
                -Headers $HEADER `
                -ErrorAction Stop
            return $ref.object.sha
        }
        catch {
            return $null
        }
    }
}

# ============================================================
# PARSE CHANGELOG
# Supports: ## 3.8.8 - 2026-03-03
#           ## v3.8.8 - 2026-03-03
#           ## [3.8.8] - 2026-03-03
# ============================================================

$lines = Get-Content $changelogPath -Encoding UTF8
$releases = [System.Collections.Generic.List[hashtable]]::new()

$currentVersion = $null
$currentBody = [System.Collections.Generic.List[string]]::new()

foreach ($line in $lines) {
    if ($line -match "^##\s+v?\[?(\d+\.\d+[\d.]*)") {
        if ($currentVersion) {
            $releases.Add(@{
                    Version = $currentVersion
                    Body    = ($currentBody -join "`n").Trim()
                })
        }
        $currentVersion = $Matches[1]
        $currentBody = [System.Collections.Generic.List[string]]::new()
    }
    elseif ($currentVersion) {
        $currentBody.Add($line)
    }
}
if ($currentVersion) {
    $releases.Add(@{
            Version = $currentVersion
            Body    = ($currentBody -join "`n").Trim()
        })
}

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host " Versions found in changelog.md: $($releases.Count)" -ForegroundColor Cyan
if ($DryRun) {
    Write-Host " MODE: DRY-RUN (nothing will be published)" -ForegroundColor Yellow
}
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# ============================================================
# GET EXISTING GITHUB RELEASES (to skip already published)
# ============================================================

Write-Host "Fetching existing GitHub releases..." -ForegroundColor Yellow

$existingReleases = @{}
try {
    $page = 1
    do {
        $batch = Invoke-RestMethod `
            -Uri "https://api.github.com/repos/$OWNER/$REPO/releases?per_page=100&page=$page" `
            -Headers $HEADER
        foreach ($r in $batch) {
            $existingReleases[$r.tag_name] = $true
        }
        $page++
    } while ($batch.Count -eq 100)
    Write-Host "OK: Found $($existingReleases.Count) existing releases." -ForegroundColor Green
}
catch {
    Write-Host "WARNING: Could not fetch existing releases." -ForegroundColor Yellow
}

Write-Host ""

# ============================================================
# GET HEAD SHA (used when creating new tags)
# ============================================================

$mainSha = Get-MainSha
if ($mainSha) {
    Write-Host "OK: HEAD SHA = $mainSha" -ForegroundColor Green
}
else {
    Write-Host "WARNING: Could not get HEAD SHA. Tag creation may fail." -ForegroundColor Yellow
}

Write-Host ""

# ============================================================
# PROCESS EACH VERSION
# ============================================================

$published = 0
$skipped = 0
$failed = 0

foreach ($release in $releases) {
    $ver = $release.Version
    $tag = "v$ver"
    $body = $release.Body
    if (-not $body) { $body = "Release $tag" }

    # Skip if GitHub Release already exists
    if ($existingReleases.ContainsKey($tag)) {
        Write-Host "SKIP: $tag - release already exists." -ForegroundColor DarkGray
        $skipped++
        continue
    }

    Write-Host "-> $tag ..." -ForegroundColor Yellow

    if ($DryRun) {
        $preview = $body.Substring(0, [Math]::Min(120, $body.Length))
        Write-Host "  [DRY-RUN] Would create release: $tag" -ForegroundColor Cyan
        Write-Host "  Preview: $preview ..." -ForegroundColor DarkCyan
        $published++
        continue
    }

    # --------------------------------------------------------
    # STEP A: Ensure the git tag exists
    # Check first — if it already exists, reuse its SHA
    # --------------------------------------------------------
    $tagSha = Get-TagSha -TagName $tag

    if ($tagSha) {
        Write-Host "  INFO: Git tag $tag already exists (SHA: $($tagSha.Substring(0,7)))." -ForegroundColor DarkGray
    }
    elseif ($mainSha) {
        # Create the tag pointing to HEAD
        try {
            $tagPayload = @{ ref = "refs/tags/$tag"; sha = $mainSha } | ConvertTo-Json
            Invoke-RestMethod `
                -Uri "https://api.github.com/repos/$OWNER/$REPO/git/refs" `
                -Method Post `
                -Headers $HEADER `
                -Body $tagPayload `
                -ContentType "application/json" `
                -ErrorAction Stop | Out-Null
            Write-Host "  OK: Git tag $tag created." -ForegroundColor Green
            $tagSha = $mainSha
        }
        catch {
            # 422 here almost always means the tag already exists despite our earlier check
            $tagSha = Get-TagSha -TagName $tag
            if ($tagSha) {
                Write-Host "  INFO: Git tag $tag already existed." -ForegroundColor DarkGray
            }
            else {
                Write-Host "  ERROR: Could not create git tag $tag - $($_.Exception.Message)" -ForegroundColor Red
                $failed++
                continue
            }
        }
    }
    else {
        Write-Host "  ERROR: No HEAD SHA available, cannot create tag $tag. Skipping." -ForegroundColor Red
        $failed++
        continue
    }

    # --------------------------------------------------------
    # STEP B: Create the GitHub Release
    # --------------------------------------------------------
    $releasePayload = @{
        tag_name   = $tag
        name       = $tag
        body       = $body
        draft      = $false
        prerelease = $false
    } | ConvertTo-Json -Depth 5

    try {
        Invoke-RestMethod `
            -Uri "https://api.github.com/repos/$OWNER/$REPO/releases" `
            -Method Post `
            -Headers $HEADER `
            -Body $releasePayload `
            -ContentType "application/json" `
            -ErrorAction Stop | Out-Null

        Write-Host "  OK: Release $tag published." -ForegroundColor Green
        $published++
        Start-Sleep -Milliseconds 400
    }
    catch {
        # Try to extract GitHub's actual error message from the response
        $errMsg = $_.Exception.Message
        try {
            $errBody = $_.ErrorDetails.Message | ConvertFrom-Json
            $errMsg = $errBody.message
            if ($errBody.errors) {
                $errMsg += " | " + ($errBody.errors | ForEach-Object { "$($_.field): $($_.code)" } | Join-String -Separator ", ")
            }
        }
        catch {}
        Write-Host "  ERROR: $tag - $errMsg" -ForegroundColor Red
        $failed++
    }
}

# ============================================================
# SUMMARY
# ============================================================

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host " Done!" -ForegroundColor Cyan
Write-Host " Published : $published" -ForegroundColor Green
Write-Host " Skipped   : $skipped (already exist)" -ForegroundColor DarkGray
if ($failed -gt 0) {
    Write-Host " Errors    : $failed" -ForegroundColor Red
}
else {
    Write-Host " Errors    : 0" -ForegroundColor Green
}
Write-Host " https://github.com/$OWNER/$REPO/releases" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""