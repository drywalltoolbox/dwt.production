[CmdletBinding()]
param(
    [string] $CatalogPath = 'products\launch\official\dtb_official_catalog.csv',
    [string] $OutputDir = 'products\dev\catalog-enrichment',
    [string] $Python = 'python',
    [switch] $AllRows,
    [switch] $ApplySafeFixes,
    [switch] $FailOnSeoBlocking
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Get-UtcTimestamp { return (Get-Date).ToUniversalTime().ToString('o') }

function Get-RelativePath {
    param([Parameter(Mandatory)] [string] $Root, [Parameter(Mandatory)] [string] $Path)
    return [IO.Path]::GetRelativePath($Root, $Path).Replace('\', '/')
}

function Resolve-RepoPath {
    param(
        [Parameter(Mandatory)] [string] $Root,
        [Parameter(Mandatory)] [string] $Path,
        [switch] $MustExist
    )
    $candidate = if ([IO.Path]::IsPathRooted($Path)) { $Path } else { Join-Path $Root $Path }
    $full = [IO.Path]::GetFullPath($candidate)
    if ($MustExist -and -not (Test-Path -LiteralPath $full -PathType Leaf)) {
        throw "Required file does not exist: $full"
    }
    return $full
}

function Invoke-CatalogStage {
    param(
        [Parameter(Mandatory)] [string] $Name,
        [Parameter(Mandatory)] [string] $Script,
        [Parameter()] [string[]] $Arguments = @(),
        [Parameter()] [int[]] $AllowedExitCodes = @(0),
        [switch] $DiscardOutput
    )

    $started = Get-UtcTimestamp
    if ($DiscardOutput) { & $Python $Script @Arguments | Out-Null } else { & $Python $Script @Arguments }
    $exitCode = $LASTEXITCODE
    $script:stageResults += [ordered]@{
        name = $Name
        status = if ($AllowedExitCodes -contains $exitCode) { 'passed' } else { 'failed' }
        exit_code = $exitCode
        started_at = $started
        completed_at = Get-UtcTimestamp
    }
    if ($AllowedExitCodes -notcontains $exitCode) {
        throw "Catalog enrichment stage failed: $Name ($Script, exit $exitCode)."
    }
    return $exitCode
}

$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..')).Path
$catalog = Resolve-RepoPath -Root $root -Path $CatalogPath -MustExist
$output = Resolve-RepoPath -Root $root -Path $OutputDir
New-Item -ItemType Directory -Path $output -Force | Out-Null

$runStarted = Get-UtcTimestamp
$inputCatalogSha = (Get-FileHash -LiteralPath $catalog -Algorithm SHA256).Hash.ToLowerInvariant()

$validator = Join-Path $PSScriptRoot 'validate_official_catalog.py'
$seoCanonicalFixes = Join-Path $PSScriptRoot 'clear_legacy_seo_canonicals.py'
$audit = Join-Path $PSScriptRoot 'audit_official_catalog_enrichment.py'
$seo = Join-Path $PSScriptRoot 'catalog_seo_pre_generation.py'

$seoCanonicalFixPath = Join-Path $output 'seo-canonical-safe-fixes.json'
$auditPath = Join-Path $output 'catalog-enrichment-audit.json'
$remediationPath = Join-Path $output 'catalog-remediation.csv'
$seoOutput = Join-Path $output 'seo-pre-generation'
$seoSummaryPath = Join-Path $seoOutput 'pre-generation-summary.json'
$summaryPath = Join-Path $output 'run-summary.json'
$rollbackDir = Join-Path $output 'rollback'
$rollbackPath = Join-Path $rollbackDir 'dtb_official_catalog.pre-safe-fixes.csv'

# A run manifest must never consume stale stage output from an earlier run.
@($seoCanonicalFixPath, $auditPath, $remediationPath, $summaryPath) |
    Where-Object { Test-Path -LiteralPath $_ } |
    ForEach-Object { Remove-Item -LiteralPath $_ -Force }
if (Test-Path -LiteralPath $seoOutput) {
    Remove-Item -LiteralPath $seoOutput -Recurse -Force
}
if (Test-Path -LiteralPath $rollbackDir) {
    Remove-Item -LiteralPath $rollbackDir -Recurse -Force
}

$stageResults = @()
$runStatus = 'passed'
$failureMessage = $null
$rollbackRelative = $null
$rollbackRestored = $false
$mutationCommitted = $false

try {
    Write-Host 'Validate canonical catalog'
    Invoke-CatalogStage -Name 'validate' -Script $validator -Arguments @('--catalog', $catalog) -DiscardOutput | Out-Null

    if ($ApplySafeFixes) {
        New-Item -ItemType Directory -Path $rollbackDir -Force | Out-Null
        Copy-Item -LiteralPath $catalog -Destination $rollbackPath -Force
        $rollbackRelative = Get-RelativePath -Root $root -Path $rollbackPath

        try {
            Write-Host 'Apply reviewed canonical URL safe fixes'
            Invoke-CatalogStage -Name 'seo_canonical_safe_fixes' -Script $seoCanonicalFixes -Arguments @(
                '--catalog', $catalog, '--report', $seoCanonicalFixPath, '--apply', '--no-backup'
            ) -DiscardOutput | Out-Null

            Write-Host 'Revalidate canonical catalog after safe fixes'
            Invoke-CatalogStage -Name 'post_fix_validate' -Script $validator -Arguments @('--catalog', $catalog) -DiscardOutput | Out-Null
            $mutationCommitted = $true
        }
        catch {
            Copy-Item -LiteralPath $rollbackPath -Destination $catalog -Force
            $rollbackRestored = $true
            Invoke-CatalogStage -Name 'rollback_validate' -Script $validator -Arguments @('--catalog', $catalog) -DiscardOutput | Out-Null
            throw
        }
    }

    Write-Host 'Audit actionable enrichment quality'
    $auditArgs = @('--catalog', $catalog, '--report-json', $auditPath, '--remediation-csv', $remediationPath)
    if ($AllRows) { $auditArgs += '--all' }
    Invoke-CatalogStage -Name 'enrichment_audit' -Script $audit -Arguments $auditArgs -DiscardOutput | Out-Null

    Write-Host 'Prepare evidence-bounded content/SEO packets'
    $seoArgs = @('--catalog', $catalog, '--output-dir', $seoOutput)
    if ($FailOnSeoBlocking) { $seoArgs += '--fail-on-blocking' }
    $allowedSeoExitCodes = if ($FailOnSeoBlocking) { @(0, 2) } else { @(0) }
    $seoExit = Invoke-CatalogStage -Name 'seo_pre_generation' -Script $seo -Arguments $seoArgs -AllowedExitCodes $allowedSeoExitCodes -DiscardOutput
    if ($FailOnSeoBlocking -and $seoExit -eq 2) {
        $runStatus = 'blocked'
        throw "SEO pre-generation completed, but blocking findings remain. Review $seoOutput."
    }
}
catch {
    if ($runStatus -ne 'blocked') { $runStatus = 'failed' }
    $failureMessage = $_.Exception.Message
}
finally {
    $seoCanonicalFixSummary = if (Test-Path -LiteralPath $seoCanonicalFixPath) { Get-Content -LiteralPath $seoCanonicalFixPath -Raw | ConvertFrom-Json } else { $null }
    $auditSummary = if (Test-Path -LiteralPath $auditPath) { Get-Content -LiteralPath $auditPath -Raw | ConvertFrom-Json } else { $null }
    $seoSummary = if (Test-Path -LiteralPath $seoSummaryPath) { Get-Content -LiteralPath $seoSummaryPath -Raw | ConvertFrom-Json } else { $null }
    $gitCommit = (& git -C $root rev-parse HEAD 2>$null)
    if ($LASTEXITCODE -ne 0) { $gitCommit = '' }

    $summary = [ordered]@{
        schema_version = 6
        status = $runStatus
        started_at = $runStarted
        completed_at = Get-UtcTimestamp
        repository_commit = ([string]$gitCommit).Trim()
        catalog = Get-RelativePath -Root $root -Path $catalog
        catalog_sha256_before = $inputCatalogSha
        catalog_sha256_after = (Get-FileHash -LiteralPath $catalog -Algorithm SHA256).Hash.ToLowerInvariant()
        scope = if ($AllRows) { 'all' } else { 'published' }
        mutates_catalog = [bool]$ApplySafeFixes
        mutation_committed = $mutationCommitted
        rollback = if ($ApplySafeFixes) {
            [ordered]@{
                snapshot = $rollbackRelative
                restored = $rollbackRestored
            }
        } else { $null }
        stages = $stageResults
        outputs = [ordered]@{
            seo_canonical_safe_fixes = if ($ApplySafeFixes) { Get-RelativePath -Root $root -Path $seoCanonicalFixPath } else { $null }
            audit = if (Test-Path -LiteralPath $auditPath) { Get-RelativePath -Root $root -Path $auditPath } else { $null }
            remediation = if (Test-Path -LiteralPath $remediationPath) { Get-RelativePath -Root $root -Path $remediationPath } else { $null }
            seo_pre_generation = if (Test-Path -LiteralPath $seoOutput) { Get-RelativePath -Root $root -Path $seoOutput } else { $null }
        }
        safe_fixes = [ordered]@{
            seo_canonical = if ($seoCanonicalFixSummary) {
                [ordered]@{
                    applied = $seoCanonicalFixSummary.applied
                    eligible_overrides = $seoCanonicalFixSummary.eligible_overrides
                    conflicting = $seoCanonicalFixSummary.conflicting
                    redundant = $seoCanonicalFixSummary.redundant
                }
            } else { $null }
        }
        catalog_quality = if ($auditSummary) {
            [ordered]@{
                rows = $auditSummary.quality.rows
                parts = $auditSummary.quality.parts
                actionable_remediation = $auditSummary.quality.remediation.count
                remediation_by_finding = $auditSummary.quality.remediation.by_finding
                operational_coverage = $auditSummary.quality.operational_coverage
                gtin_coverage = $auditSummary.quality.coverage.gtin
                image_coverage = $auditSummary.quality.coverage.images
                structured_spec_coverage = $auditSummary.quality.coverage.structured_specs
                compatibility_research_rows = $auditSummary.quality.relationships.primary_part_research_rows
                compatible_tool_references = $auditSummary.quality.relationships.compatible_tool_reference_count
                replacement_references = $auditSummary.quality.relationships.replacement_reference_count
            }
        } else { $null }
        seo = if ($seoSummary) {
            [ordered]@{
                generation_eligible = $seoSummary.generation_eligible
                blocking_findings = $seoSummary.blocking_findings
                findings_by_workflow = $seoSummary.findings_by_workflow
            }
        } else { $null }
        failure = $failureMessage
    }
    $summary | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $summaryPath -Encoding utf8
}

if ($runStatus -ne 'passed') { throw $failureMessage }
Write-Host "Catalog enrichment run complete: $summaryPath"
