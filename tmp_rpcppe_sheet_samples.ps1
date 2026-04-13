$tmp = "c:\xampp\htdocs\UASPMS\tmp_rpcppe_xlsx"
[xml]$sst = Get-Content "$tmp\xl\sharedStrings.xml"
$nsS = New-Object System.Xml.XmlNamespaceManager($sst.NameTable)
$nsS.AddNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main')
$shared=@()
foreach($si in $sst.SelectNodes('//x:si',$nsS)){
  $txt=''
  foreach($t in $si.SelectNodes('.//x:t',$nsS)){ $txt += $t.InnerText }
  $shared += $txt
}
[xml]$wb = Get-Content "$tmp\xl\workbook.xml"
[xml]$rels = Get-Content "$tmp\xl\_rels\workbook.xml.rels"
$nsWb = New-Object System.Xml.XmlNamespaceManager($wb.NameTable)
$nsWb.AddNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main')
$ridNs='http://schemas.openxmlformats.org/officeDocument/2006/relationships'
$relMap=@{}
foreach($r in $rels.Relationships.Relationship){ $relMap[$r.Id]=$r.Target }
function CellVal($c,$ns,$shared){
  $vN=$c.SelectSingleNode('./x:v',$ns)
  if(-not $vN){ return '' }
  $v=$vN.InnerText
  if($c.GetAttribute('t') -eq 's'){ return $shared[[int]$v] }
  return $v
}
$sheetNames=@('OFFICE EQUIPMENT','INFORMATION & COMMUNICATION','FURNITURE & FIXTURE')
foreach($targetName in $sheetNames){
  $sheet=$wb.SelectNodes('//x:sheets/x:sheet',$nsWb) | Where-Object { $_.name -eq $targetName } | Select-Object -First 1
  $rid=$sheet.GetAttribute('id',$ridNs)
  [xml]$sx=Get-Content "$tmp\xl\$($relMap[$rid])"
  $ns=New-Object System.Xml.XmlNamespaceManager($sx.NameTable)
  $ns.AddNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main')
  Write-Output "=== $targetName ==="
  $count=0
  foreach($r in $sx.SelectNodes('//x:sheetData/x:row',$ns)){
    if([int]$r.r -le 11){ continue }
    $hiddenAttr = '' + $r.hidden
    if($hiddenAttr -eq '1'){ continue }
    $cells=@{}
    foreach($c in $r.SelectNodes('./x:c',$ns)){
      $ref=[string]$c.r
      $col=($ref -replace '\d','')
      $cells[$col]=CellVal $c $ns $shared
    }
    if(([string]$cells['A']).Trim() -eq '' -and ([string]$cells['B']).Trim() -eq ''){ continue }
    if(([string]$cells['D']).Trim() -eq '' -and ([string]$cells['E']).Trim() -eq ''){ continue }
    Write-Output (([string]$cells['A']) + ' || ' + ([string]$cells['B']) + ' || PN=' + ([string]$cells['C']) + ' || REM=' + ([string]$cells['J']) + ' || DT=' + ([string]$cells['K']) + ' || FUND=' + ([string]$cells['L']) + ' || ACC=' + ([string]$cells['M']) + ' || STATUS=' + ([string]$cells['N']))
    $count++
    if($count -ge 8){ break }
  }
}
