<?php
$m = new mysqli('127.0.0.1','root','','spamsdb');
$tag='RCPPEE_2025_ICT_FUND01_LIST';

$sql="SELECT COALESCE(fund_code,'(blank)') fund_code,
             COALESCE(fund_source,'(blank)') fund_source,
             COALESCE(fund_number,'(blank)') fund_number,
             COALESCE(account_code,'(blank)') account_code,
             COALESCE(account_name,'(blank)') account_name,
             COUNT(*) row_count,
             ROUND(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),2) total
      FROM rpcppe_batch_items
      WHERE batch_id=14 AND remarks LIKE CONCAT('%', ?, '%')
      GROUP BY fund_code,fund_source,fund_number,account_code,account_name
      ORDER BY fund_code, account_code";
$stmt=$m->prepare($sql);
$stmt->bind_param('s',$tag);
$stmt->execute();
$res=$stmt->get_result();

echo "Tagged ICT Fund/Account Totals ($tag)\n";
echo str_repeat('=',120) . "\n";
echo "fund_code\tfund_source\tfund_number\taccount_code\taccount_name\trows\ttotal\n";
$rows=0; $total=0.0;
while($r=$res->fetch_assoc()){
  $rows += (int)$r['row_count'];
  $total += (float)$r['total'];
  echo "{$r['fund_code']}\t{$r['fund_source']}\t{$r['fund_number']}\t{$r['account_code']}\t{$r['account_name']}\t{$r['row_count']}\t" . number_format((float)$r['total'],2,'.',',') . "\n";
}
echo str_repeat('-',120) . "\n";
echo "TOTAL\t-\t-\t-\t-\t{$rows}\t" . number_format($total,2,'.',',') . "\n";

$out = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'ict_fund01_tagged_fund_account_totals.csv';
$f = fopen($out,'w');
fputcsv($f,['fund_code','fund_source','fund_number','account_code','account_name','rows','total']);
$stmt->execute();
$res2=$stmt->get_result();
while($r=$res2->fetch_assoc()){
  fputcsv($f,[$r['fund_code'],$r['fund_source'],$r['fund_number'],$r['account_code'],$r['account_name'],$r['row_count'],number_format((float)$r['total'],2,'.','')]);
}
fputcsv($f,[]);
fputcsv($f,['TOTAL','','','','',$rows,number_format($total,2,'.','')]);
fclose($f);

echo "Exported: $out\n";

$stmt->close();
$m->close();
