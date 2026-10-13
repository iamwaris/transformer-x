# Regenerates tests/fixtures/golden/golden_<name>.xlsx by running the REAL Access application
# (DataTransformer v3.5) on each tests/fixtures/input/<name>.xlsx.
#
# It works on a throwaway copy of the .accdb in a work folder; the original database is never modified.
# The work folder must be outside %TEMP% (Access opens databases there in Disabled Mode, blocking queries and VBA).
#
# Usage (PowerShell, Windows with Access installed):
#   .\tests\tools\access_golden.ps1            (uses legacy\DataTransformer  - v3.5.accdb)
param(
    [string]$Accdb = (Join-Path $PSScriptRoot '..\..\legacy\DataTransformer  - v3.5.accdb'),
    [string[]]$Names = @('sample_v5', 'edge_cases', 'edge_cases_2', 'edge_cases_3')
)
$ErrorActionPreference = 'Stop'
$fixtures = Resolve-Path (Join-Path $PSScriptRoot '..\fixtures')
$work = Join-Path $fixtures 'access-work'
New-Item -ItemType Directory -Force $work | Out-Null
$db = Join-Path $work 'golden.accdb'
Copy-Item $Accdb $db -Force

$app = New-Object -ComObject Access.Application
try {
    $app.Visible = $false
    $app.OpenCurrentDatabase($db, $true)
    # BuildAttributesTable ends with a MsgBox that would block automation; comment it out in the copy.
    $cm = $app.VBE.ActiveVBProject.VBComponents.Item('modFillAtt').CodeModule
    for ($i = 1; $i -le $cm.CountOfLines; $i++) {
        if ($cm.Lines($i, 1) -match 'MsgBox "Attributes built successfully!"') { $cm.ReplaceLine($i, "    'MsgBox removed for automation") }
    }
    $app.DoCmd.SetWarnings($false)
    foreach ($name in $Names) {
        $in = Join-Path $work "$name.xlsx"
        Copy-Item (Join-Path $fixtures "input\$name.xlsx") $in -Force
        $out = Join-Path $fixtures "golden\golden_$name.xlsx"
        if (Test-Path $out) { Remove-Item $out -Confirm:$false }
        # Same steps as frmImportData: Import_Click then Command82_Click.
        $app.DoCmd.RunSQL('Delete * from [tempImportData]')
        $app.DoCmd.TransferSpreadsheet(0, 9, 'tempImportData', $in, $true)        # acImport, acSpreadsheetTypeExcel12
        $app.Run('BuildAttributesTable')
        $app.DoCmd.TransferSpreadsheet(1, 10, 'qryExportData', $out, $true)        # acExport, acSpreadsheetTypeExcel12Xml
        '{0}: imported={1} exported={2}' -f $name, $app.DCount('*', 'tempImportData'), $app.DCount('*', 'qryExportData')
    }
    $app.DoCmd.SetWarnings($true)
}
finally {
    try { $app.CloseCurrentDatabase() } catch {}
    $app.Quit(2)   # acQuitSaveNone
    [void][Runtime.InteropServices.Marshal]::ReleaseComObject($app)
    Start-Sleep -Seconds 1
    Remove-Item $work -Recurse -Force -Confirm:$false -ErrorAction SilentlyContinue
}
