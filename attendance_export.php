<?php
/* ============================================================
   attendance_export.php — First 1 Car professional attendance workbook (.xlsx)
   Admin picks from/to → downloads a fully-formatted, colour-coded Excel.

   Sheet 1 "الملخص / Dashboard"  — KPI cards + summary table (with in-cell data
        bars on hours and a red→green colour scale on attendance %) + TWO native
        charts (hours per employee, present-vs-absent per employee).
   Sheet 2 "التفصيلي / Daily"     — quick summary on top, then one table PER
        EMPLOYEE (full day-by-day calendar). Each day shows first/last punch; days
        with several sessions show a ×N badge and every punch nested beneath;
        days with no punch show غياب (admin/export only). Green subtotal per person.
   Sheet 3 "الشبكة / Matrix"      — employees (rows) × dates (cols) grid.
   Sheet 4 "البصمات / Sessions"   — every raw punch (audit) with locations.

   No leaves table exists, so absences show as غياب and appear only in this admin
   export. No fixed weekend. Built by hand as OOXML (ZipArchive) — no libraries.
   ============================================================ */

require 'auth.php';
require 'config.php';
ini_set('display_errors', 0);

$role = $_SESSION['role'] ?? '';
perm_require('page.attendance_export');

$lang = $_GET['lang'] ?? 'ar';
$isAr = $lang === 'ar';
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
if ($from > $to) { $t=$from; $from=$to; $to=$t; }

$fBranch = $_GET['branch']  ?? '';
$fUser   = $_GET['user_id'] ?? '';
$L = fn($ar,$en) => $isAr ? $ar : $en;

/* ---------------- Queries ---------------- */
$where  = ["a.status='completed'", "a.clock_out IS NOT NULL", "DATE(a.clock_in) BETWEEN ? AND ?"];
$params = [$from, $to];
if ($fBranch !== '') { $where[] = "a.branch_name = ?"; $params[] = $fBranch; }
if ($fUser   !== '') { $where[] = "a.user_id = ?";     $params[] = (int)$fUser; }
$whereSql = implode(' AND ', $where);

$daily = $pdo->prepare("
    SELECT a.user_id, u.username, u.role AS user_role,
           DATE(a.clock_in) AS d,
           MIN(a.clock_in)  AS first_in,
           MAX(a.clock_out) AS last_out,
           COUNT(*)         AS punches,
           MAX(a.branch_name) AS branch_name,
           MAX(a.auto_closed) AS any_auto,
           TIMESTAMPDIFF(MINUTE, MIN(a.clock_in), MAX(a.clock_out)) AS day_mins,
           SUM(TIMESTAMPDIFF(MINUTE, a.clock_in, a.clock_out)) AS worked_mins
    FROM attendance_logs a
    LEFT JOIN users u ON u.id = a.user_id
    WHERE $whereSql
    GROUP BY a.user_id, DATE(a.clock_in)
    ORDER BY u.username ASC, d ASC
");
$daily->execute($params);
$dailyRows = $daily->fetchAll(PDO::FETCH_ASSOC);

$raw = $pdo->prepare("
    SELECT a.*, u.username, b.name_ar AS branch_ar, b.name_en AS branch_en,
           DATE(a.clock_in) AS d,
           TIMESTAMPDIFF(MINUTE, a.clock_in, a.clock_out) AS mins
    FROM attendance_logs a
    LEFT JOIN users u ON u.id = a.user_id
    LEFT JOIN branches b ON b.name = a.branch_name
    WHERE $whereSql
    ORDER BY u.username ASC, a.clock_in ASC
");
$raw->execute($params);
$rawRows = $raw->fetchAll(PDO::FETCH_ASSOC);

$punchesByUserDay = [];
foreach ($rawRows as $r) { $punchesByUserDay[(int)$r['user_id']][$r['d']][] = $r; }

$rosterSql = "SELECT id, username, role FROM users WHERE active = 1";
$rosterParams = [];
if ($fUser !== '') { $rosterSql .= " AND id = ?"; $rosterParams[] = (int)$fUser; }
$rosterSql .= " ORDER BY username ASC";
$rst = $pdo->prepare($rosterSql); $rst->execute($rosterParams);
$roster = $rst->fetchAll(PDO::FETCH_ASSOC);

$branchMap = [];
foreach ($pdo->query("SELECT name,name_ar,name_en FROM branches")->fetchAll(PDO::FETCH_ASSOC) as $b) {
    $branchMap[$b['name']] = $isAr ? ($b['name_ar'] ?: $b['name']) : ($b['name_en'] ?: $b['name']);
}
$bDisp = fn($n) => ($n && isset($branchMap[$n])) ? $branchMap[$n] : ($n ?: '—');

/* ---------------- Calendar spine ---------------- */
$fromTs = strtotime($from.' 00:00:00'); $toTs = strtotime($to.' 00:00:00');
$allDays = [];
for ($t=$fromTs; $t<=$toTs; $t=strtotime('+1 day',$t)) $allDays[] = date('Y-m-d',$t);
$rangeDayCount = count($allDays);

$workedByUser=[]; $namesByUser=[]; $rolesByUser=[];
foreach ($dailyRows as $r) {
    $uid=(int)$r['user_id'];
    $workedByUser[$uid][$r['d']]=$r;
    $namesByUser[$uid]=$r['username'] ?? ('#'.$uid);
    $rolesByUser[$uid]=$r['user_role'] ?? '';
}
foreach ($roster as $u) {
    $uid=(int)$u['id'];
    if (!isset($namesByUser[$uid])) { $namesByUser[$uid]=$u['username']; $rolesByUser[$uid]=$u['role']; }
    if (!isset($workedByUser[$uid])) $workedByUser[$uid]=[];
}
$emp=[];
foreach ($namesByUser as $uid=>$uname) {
    $days=$workedByUser[$uid]; $mins=0;$present=0;$fa=null;$la=null;$sc=0;
    foreach ($days as $d=>$row) {
        $mins+=max(0,(int)$row['worked_mins']); $present++; $sc+=(int)$row['punches'];
        $inTs=strtotime($row['first_in']);$outTs=strtotime($row['last_out']);
        if($fa===null||$inTs<$fa)$fa=$inTs; if($la===null||$outTs>$la)$la=$outTs;
    }
    $emp[$uid]=['name'=>$uname,'role'=>$rolesByUser[$uid]??'','days'=>$days,'mins'=>$mins,
        'present'=>$present,'absent'=>$rangeDayCount-$present,'firstAct'=>$fa,'lastAct'=>$la,'sessions'=>$sc];
}
uasort($emp, function($a,$b){ if($b['mins']!==$a['mins']) return $b['mins']<=>$a['mins']; return $a['absent']<=>$b['absent']; });
$N=count($emp);

$dayAr=['Sun'=>'الأحد','Mon'=>'الإثنين','Tue'=>'الثلاثاء','Wed'=>'الأربعاء','Thu'=>'الخميس','Fri'=>'الجمعة','Sat'=>'السبت'];
$dayName=fn($ts)=>$isAr?($dayAr[date('D',$ts)]??date('D',$ts)):date('D',$ts);
function dec($m){ return round(max(0,(int)$m)/60,2); }

function xlDate($ts){ return xlSerial((int)date('Y',$ts),(int)date('n',$ts),(int)date('j',$ts),0,0,0); }
function xlTime($ts){ return ((int)date('G',$ts)*3600+(int)date('i',$ts)*60+(int)date('s',$ts))/86400; }
function xlSerial($y,$m,$d,$H,$Mi,$S){
    $a=intdiv(14-$m,12);$yy=$y+4800-$a;$mm=$m+12*$a-3;
    $jdn=$d+intdiv(153*$mm+2,5)+365*$yy+intdiv($yy,4)-intdiv($yy,100)+intdiv($yy,400)-32045;
    return ($jdn-2415019)+($H*3600+$Mi*60+$S)/86400;
}

/* ============================================================ OOXML builder ============================================================ */
function xesc($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_XML1, 'UTF-8'); }
function colL($n){ $r=''; while($n>0){ $n--; $r=chr(65+($n%26)).$r; $n=intdiv($n,26);} return $r; }
function cellXml($ref,$c){
    $v=$c['v']??''; $t=$c['t']??'s'; $s=$c['s']??0;
    if($t==='n') return '<c r="'.$ref.'" s="'.$s.'"><v>'.$v.'</v></c>';
    return '<c r="'.$ref.'" s="'.$s.'" t="inlineStr"><is><t xml:space="preserve">'.xesc($v).'</t></is></c>';
}
function sheetXml($sh){
    $cols='';
    if(!empty($sh['cols'])){ $cols='<cols>'; foreach($sh['cols'] as $i=>$w) $cols.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$w.'" customWidth="1"/>'; $cols.='</cols>'; }
    $rows='';
    foreach($sh['rows'] as $ri=>$row){
        $rn=$ri+1; $cells=''; foreach($row as $ci=>$c){ if($c===null) continue; $cells.=cellXml(colL($ci+1).$rn,$c); }
        $ra=isset($sh['rowInfo'][$rn])?$sh['rowInfo'][$rn]:'';
        $rows.='<row r="'.$rn.'"'.$ra.'>'.$cells.'</row>';
    }
    $merges='';
    if(!empty($sh['merges'])){ $merges='<mergeCells count="'.count($sh['merges']).'">'; foreach($sh['merges'] as $m) $merges.='<mergeCell ref="'.$m.'"/>'; $merges.='</mergeCells>'; }
    $freeze='';
    if(!empty($sh['freeze'])){ $f=$sh['freeze']; $freeze='<pane xSplit="'.($f['x']??0).'" ySplit="'.($f['y']??0).'" topLeftCell="'.$f['cell'].'" activePane="bottomRight" state="frozen"/>'; }
    $rtl=!empty($sh['rtl'])?' rightToLeft="1"':'';
    $views='<sheetViews><sheetView workbookViewId="0"'.$rtl.'>'.$freeze.'</sheetView></sheetViews>';
    $autof=!empty($sh['autofilter'])?'<autoFilter ref="'.$sh['autofilter'].'"/>':'';
    $cf='';
    if(!empty($sh['cf'])) foreach($sh['cf'] as $c) $cf.='<conditionalFormatting sqref="'.$c[0].'">'.$c[1].'</conditionalFormatting>';
    $drawing=!empty($sh['drawing'])?'<drawing r:id="'.$sh['drawing'].'"/>':'';
    $sheetPr=!empty($sh['outline'])?'<sheetPr><outlinePr summaryBelow="0"/></sheetPr>':'';
    $fmt=!empty($sh['outline'])?'<sheetFormatPr defaultRowHeight="15" outlineLevelRow="1"/>':'';
    $ns='xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet '.$ns.'>'
        .$sheetPr.$views.$fmt.$cols.'<sheetData>'.$rows.'</sheetData>'.$autof.$merges.$cf.$drawing.'</worksheet>';
}

/* styles.xml — full colour palette + KPI card styles + number formats
   Style index map:
   0 body 1 navyHdr 2 title 3 bold 4 green 5 greenTotal 6 plain 7 purpleBand 8 amberSub
   9 date 10 time 11 elapsedGreen 12 decimal 13 absent 14 absentDate 15 absentDay
   16 elapsedTotal 17 decTotal 18 matrixPresent 19 matrixAbsent 20 muted 21 vertHdr
   22 nameMatrix 23 intBold 24 subLabel 25 subTime 26 subElapsed 27 subDec 28 subPlain
   29 badge 30/31 KPI blue 32/33 teal 34/35 orange 36/37 green 38/39 rose
   40 pctBold 41 pctTotal 42 decShaded 43 pct */
$STYLES='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="5"><numFmt numFmtId="164" formatCode="h:mm\ AM/PM"/><numFmt numFmtId="165" formatCode="yyyy\-mm\-dd"/><numFmt numFmtId="166" formatCode="[h]:mm"/><numFmt numFmtId="167" formatCode="0.00"/><numFmt numFmtId="168" formatCode="0%"/></numFmts><fonts count="12"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="10"/><name val="Arial"/></font><font><b/><sz val="10"/><color rgb="FF15803D"/><name val="Arial"/></font><font><b/><sz val="10"/><color rgb="FFB45309"/><name val="Arial"/></font><font><b/><sz val="10"/><color rgb="FFB91C1C"/><name val="Arial"/></font><font><sz val="9"/><color rgb="FF64748B"/><name val="Arial"/></font><font><i/><sz val="9"/><color rgb="FF475569"/><name val="Arial"/></font><font><b/><sz val="22"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="9"/><color rgb="FFF1F5F9"/><name val="Arial"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font></fonts><fills count="15"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF7C3AED"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF16A34A"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0D9488"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEA580C"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE11D48"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/></patternFill></fill></fills><borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border><border><left style="medium"><color rgb="FFFFFFFF"/></left><right style="medium"><color rgb="FFFFFFFF"/></right><top style="medium"><color rgb="FFFFFFFF"/></top><bottom style="medium"><color rgb="FFFFFFFF"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="44"><xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="3" borderId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="4" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="4" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="3" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="5" fillId="5" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="165" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="166" fontId="4" fillId="0" borderId="1" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="167" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="6" fillId="6" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="165" fontId="6" fillId="6" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="6" fillId="6" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="166" fontId="1" fillId="4" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="167" fontId="1" fillId="4" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="167" fontId="0" fillId="8" borderId="1" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="6" fillId="6" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="7" fillId="0" borderId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" textRotation="90"/></xf><xf numFmtId="0" fontId="3" fillId="5" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="8" fillId="9" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="2"/></xf><xf numFmtId="164" fontId="8" fillId="9" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="166" fontId="8" fillId="9" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="167" fontId="8" fillId="9" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="8" fillId="9" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="5" fillId="7" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="9" fillId="10" borderId="2" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="10" fillId="10" borderId="2" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="9" fillId="11" borderId="2" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="10" fillId="11" borderId="2" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="9" fillId="12" borderId="2" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="10" fillId="12" borderId="2" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="9" fillId="4" borderId="2" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="10" fillId="4" borderId="2" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="9" fillId="13" borderId="2" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="10" fillId="13" borderId="2" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="168" fontId="3" fillId="0" borderId="1" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="168" fontId="1" fillId="4" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="167" fontId="3" fillId="14" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="168" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf></cellXfs></styleSheet>';

/* ============================================================
   SHEET 1 — Dashboard + Summary
   ============================================================ */
$SUM=$L('الملخص','Summary');
$s1=[]; $m1=[]; $ri1=[];
$s1[]=[['v'=>$L('فرست 1 كار — لوحة الحضور','First 1 Car — Attendance Dashboard'),'s'=>2]]; $m1[]='A1:L1'; $ri1[1]=' ht="26" customHeight="1"';
$s1[]=[['v'=>$L('الفترة','Period').": $from  →  $to   •   $rangeDayCount ".$L('أيام','days')."   •   $N ".$L('موظفين','staff'),'s'=>8]]; $m1[]='A2:L2';
$s1[]=[];
$gMins=0;$gPres=0;$gAbs=0;$gSess=0;
foreach($emp as $i){ $gMins+=$i['mins'];$gPres+=$i['present'];$gAbs+=$i['absent'];$gSess+=$i['sessions']; }
$avgPct = $N>0 ? round($gPres*100/($rangeDayCount*$N)) : 0;
$cards=[[$N,$L('موظفين','Staff'),30,31],[dec($gMins),$L('إجمالي الساعات','Total Hours'),32,33],[$avgPct.'%',$L('متوسط الحضور','Avg Attend'),36,37],[$gAbs,$L('أيام الغياب','Absences'),38,39],[$gSess,$L('عدد الجلسات','Sessions'),34,35]];
$numrow=[];$lblrow=[];
foreach($cards as $idx=>$card){ list($val,$lbl,$ns,$ls)=$card; $c0=$idx*2;
    $isnum = !is_string($val);
    $numrow[]= $isnum?['v'=>$val,'t'=>'n','s'=>$ns]:['v'=>$val,'s'=>$ns]; $numrow[]=['v'=>'','s'=>$ns];
    $lblrow[]=['v'=>$lbl,'s'=>$ls]; $lblrow[]=['v'=>'','s'=>$ls];
    $m1[]=colL($c0+1).'4:'.colL($c0+2).'4'; $m1[]=colL($c0+1).'5:'.colL($c0+2).'5';
}
$numrow[]=['v'=>'','s'=>6];$numrow[]=['v'=>'','s'=>6]; $lblrow[]=['v'=>'','s'=>6];$lblrow[]=['v'=>'','s'=>6];
$s1[]=$numrow; $ri1[4]=' ht="40" customHeight="1"';
$s1[]=$lblrow; $ri1[5]=' ht="18" customHeight="1"';
$s1[]=[];
$HDR=7;
$s1[]=[['v'=>'#','s'=>1],['v'=>$L('الموظف','Employee'),'s'=>1],['v'=>$L('الوظيفة','Role'),'s'=>1],['v'=>$L('حضور','Present'),'s'=>1],['v'=>$L('غياب','Absent'),'s'=>1],['v'=>$L('الحضور %','Attend %'),'s'=>1],['v'=>$L('جلسات','Sess'),'s'=>1],['v'=>$L('المدة','Duration'),'s'=>1],['v'=>$L('ساعات','Hours'),'s'=>1],['v'=>$L('متوسط/يوم','Avg/Day'),'s'=>1],['v'=>$L('أول نشاط','First Seen'),'s'=>1],['v'=>$L('آخر نشاط','Last Seen'),'s'=>1]];
$idx=1;
foreach($emp as $uid=>$info){
    $pct=$rangeDayCount>0?$info['present']/$rangeDayCount:0;
    $avg=$info['present']>0?$info['mins']/$info['present']:0;
    $s1[]=[
        ['v'=>$idx,'t'=>'n','s'=>23],['v'=>$info['name'],'s'=>3],['v'=>$info['role'],'s'=>0],
        ['v'=>$info['present'],'t'=>'n','s'=>23],['v'=>$info['absent'],'t'=>'n','s'=>($info['absent']>0?13:0)],
        ['v'=>round($pct,4),'t'=>'n','s'=>40],['v'=>$info['sessions'],'t'=>'n','s'=>0],
        ['v'=>dec($info['mins'])/24,'t'=>'n','s'=>11],['v'=>dec($info['mins']),'t'=>'n','s'=>42],
        ['v'=>dec($avg)/24,'t'=>'n','s'=>11],
        ['v'=>$info['firstAct']?date('Y-m-d',$info['firstAct']):'—','s'=>0],
        ['v'=>$info['lastAct']?date('Y-m-d',$info['lastAct']):'—','s'=>0],
    ]; $idx++;
}
$LAST=$HDR+$N;
$s1[]=[['v'=>'','s'=>5],['v'=>$L('الإجمالي العام','GRAND TOTAL'),'s'=>5],['v'=>'','s'=>5],['v'=>$gPres,'t'=>'n','s'=>5],['v'=>$gAbs,'t'=>'n','s'=>5],['v'=>($rangeDayCount*$N>0?round($gPres/($rangeDayCount*$N),4):0),'t'=>'n','s'=>41],['v'=>$gSess,'t'=>'n','s'=>5],['v'=>dec($gMins)/24,'t'=>'n','s'=>16],['v'=>dec($gMins),'t'=>'n','s'=>17],['v'=>'','s'=>5],['v'=>'','s'=>5],['v'=>'','s'=>5]];
$GT=$LAST+1; $chartTop=$GT+2;
$databar='<cfRule type="dataBar" priority="1"><dataBar><cfvo type="min"/><cfvo type="max"/><color rgb="FF16A34A"/></dataBar></cfRule>';
$colorscale='<cfRule type="colorScale" priority="2"><colorScale><cfvo type="num" val="0"/><cfvo type="num" val="0.6"/><cfvo type="num" val="1"/><color rgb="FFF87171"/><color rgb="FFFBBF24"/><color rgb="FF4ADE80"/></colorScale></cfRule>';
$cf1=[['I'.($HDR+1).':I'.$LAST,$databar],['F'.($HDR+1).':F'.$LAST,$colorscale]];
$sheet1=['name'=>$SUM,'cols'=>[4,20,12,8,8,10,8,10,9,11,12,12],'rtl'=>$isAr,'merges'=>$m1,'rows'=>$s1,'rowInfo'=>$ri1,'freeze'=>['x'=>0,'y'=>$HDR,'cell'=>'A'.($HDR+1)],'cf'=>($N>0?$cf1:[]),'drawing'=>($N>0?'rId1':'')];

/* ============================================================
   SHEET 2 — Daily
   ============================================================ */
$s2=[]; $m2=[]; $rc=0; $ri2=[];
$push=function($row,$attr='') use (&$s2,&$rc,&$ri2){ $s2[]=$row; $rc++; if($attr!=='') $ri2[$rc]=$attr; };
$push([['v'=>$L('فرست 1 كار — تقرير الحضور اليومي','First 1 Car — Daily Attendance'),'s'=>2]]); $m2[]='A1:I1'; $ri2[1]=' ht="24" customHeight="1"';
$push([['v'=>$L('الفترة','Period').": $from → $to  •  ".$L('كل يوم يعرض أول وآخر بصمة؛ الأيام متعددة الجلسات مفصّلة تحتها؛ بدون بصمة = غياب','Each day shows first/last punch; multi-session days itemised beneath; no punch = Absent'),'s'=>8]]); $m2[]='A2:I2';
$push([]);
$push([['v'=>$L('▸ ملخص سريع لكل الموظفين','▸ Quick Summary — All Staff'),'s'=>7]]); $mb=$rc; $m2[]='A'.$mb.':I'.$mb;
$push([['v'=>'#','s'=>1],['v'=>$L('الموظف','Employee'),'s'=>1],['v'=>$L('الوظيفة','Role'),'s'=>1],['v'=>$L('حضور','Present'),'s'=>1],['v'=>$L('غياب','Absent'),'s'=>1],['v'=>$L('جلسات','Sess'),'s'=>1],['v'=>$L('المدة','Duration'),'s'=>1],['v'=>$L('ساعات','Hours'),'s'=>1],['v'=>$L('الحضور %','Attend %'),'s'=>1]]);
$mi=1;
foreach($emp as $uid=>$info){
    $pct=$rangeDayCount>0?$info['present']/$rangeDayCount:0;
    $push([['v'=>$mi,'t'=>'n','s'=>23],['v'=>$info['name'],'s'=>3],['v'=>$info['role'],'s'=>0],['v'=>$info['present'],'t'=>'n','s'=>23],['v'=>$info['absent'],'t'=>'n','s'=>($info['absent']>0?13:0)],['v'=>$info['sessions'],'t'=>'n','s'=>0],['v'=>dec($info['mins'])/24,'t'=>'n','s'=>11],['v'=>dec($info['mins']),'t'=>'n','s'=>12],['v'=>round($pct,4),'t'=>'n','s'=>40]]); $mi++;
}
$push([]); $push([]);
$hdr=[['v'=>$L('التاريخ','Date'),'s'=>1],['v'=>$L('اليوم','Day'),'s'=>1],['v'=>$L('أول بصمة','First In'),'s'=>1],['v'=>$L('آخر بصمة','Last Out'),'s'=>1],['v'=>$L('جلسات','Sess'),'s'=>1],['v'=>$L('المدة','Duration'),'s'=>1],['v'=>$L('ساعات','Hours'),'s'=>1],['v'=>$L('الحالة','Status'),'s'=>1],['v'=>$L('الفرع','Branch'),'s'=>1]];
if($N===0){
    $push([['v'=>$L('لا توجد بيانات في هذه الفترة','No data in this range'),'s'=>0]]);
} else {
    foreach($emp as $uid=>$info){
        $band=$rc+1;
        $push([['v'=>'👤  '.$info['name'].'   ('.$info['role'].')   •   '.$info['present'].' '.$L('حضور','present').'   •   '.$info['absent'].' '.$L('غياب','absent').'   •   '.$info['sessions'].' '.$L('جلسة','sessions').'   •   '.dec($info['mins']).' '.$L('ساعة','h'),'s'=>7]]); $m2[]='A'.$band.':I'.$band; $ri2[$band]=' ht="20" customHeight="1"';
        $push($hdr);
        foreach($allDays as $d){
            $dTs=strtotime($d.' 00:00:00');
            if(isset($info['days'][$d])){
                $day=$info['days'][$d]; $it=strtotime($day['first_in']); $ot=strtotime($day['last_out']); $n=(int)$day['punches'];
                $shown = $n>1 ? max(0,(int)$day['worked_mins']) : max(0,(int)$day['day_mins']);
                $st=$day['any_auto']?$L('إغلاق تلقائي ⏰','Auto-closed ⏰'):$L('حضور','Present');
                $sess = $n>1 ? ['v'=>'×'.$n,'s'=>29] : ['v'=>$n,'t'=>'n','s'=>0];
                $push([['v'=>xlDate($dTs),'t'=>'n','s'=>9],['v'=>$dayName($dTs),'s'=>0],['v'=>xlTime($it),'t'=>'n','s'=>10],['v'=>xlTime($ot),'t'=>'n','s'=>10],$sess,['v'=>dec($shown)/24,'t'=>'n','s'=>11],['v'=>dec($shown),'t'=>'n','s'=>12],['v'=>$st,'s'=>($day['any_auto']?8:4)],['v'=>$bDisp($day['branch_name']),'s'=>0]]);
                if($n>1 && isset($punchesByUserDay[$uid][$d])){
                    $k=1;
                    foreach($punchesByUserDay[$uid][$d] as $p){
                        $pin=strtotime($p['clock_in']); $pout=strtotime($p['clock_out']); $pm=max(0,(int)$p['mins']);
                        $pbr=$isAr?($p['branch_ar']??$p['branch_name']):($p['branch_en']??$p['branch_name']);
                        $pn=$p['auto_closed']?' ⏰':'';
                        $push([['v'=>$L('جلسة','Session').' '.$k.$pn,'s'=>24],['v'=>'','s'=>28],['v'=>xlTime($pin),'t'=>'n','s'=>25],['v'=>xlTime($pout),'t'=>'n','s'=>25],['v'=>'','s'=>28],['v'=>dec($pm)/24,'t'=>'n','s'=>26],['v'=>dec($pm),'t'=>'n','s'=>27],['v'=>'','s'=>28],['v'=>$bDisp($pbr),'s'=>28]],' outlineLevel="1"');
                        $k++;
                    }
                }
            } else {
                $push([['v'=>xlDate($dTs),'t'=>'n','s'=>14],['v'=>$dayName($dTs),'s'=>15],['v'=>$L('غياب','Absent'),'s'=>13],['v'=>'—','s'=>13],['v'=>'—','s'=>13],['v'=>'—','s'=>13],['v'=>'—','s'=>13],['v'=>$L('غياب','Absent'),'s'=>13],['v'=>'—','s'=>13]]);
            }
        }
        $sub=$rc+1; $tm=$info['mins'];
        $push([['v'=>$L('إجمالي','TOTAL').'  '.$info['name'],'s'=>5],['v'=>'','s'=>5],['v'=>'','s'=>5],['v'=>$info['present'].' '.$L('حضور','present'),'s'=>5],['v'=>$info['sessions'],'t'=>'n','s'=>5],['v'=>dec($tm)/24,'t'=>'n','s'=>16],['v'=>dec($tm),'t'=>'n','s'=>17],['v'=>$info['absent'].' '.$L('غياب','abs'),'s'=>5],['v'=>'','s'=>5]]); $m2[]='A'.$sub.':C'.$sub;
        $push([]);
    }
}
$sheet2=['name'=>$L('التفصيلي','Daily'),'cols'=>[14,10,11,11,7,11,9,15,16],'rtl'=>$isAr,'merges'=>$m2,'rows'=>$s2,'rowInfo'=>$ri2,'outline'=>true];

/* ============================================================
   SHEET 3 — Matrix
   ============================================================ */
$s3=[]; $m3=[];
$s3[]=[['v'=>$L('فرست 1 كار — شبكة الحضور','First 1 Car — Attendance Matrix'),'s'=>2]]; $cc=1+$rangeDayCount+3; $m3[]='A1:'.colL($cc).'1';
$s3[]=[['v'=>$L('الأرقام = ساعات ذلك اليوم  •  الأحمر = غياب','Numbers = hours that day  •  Red = Absent'),'s'=>8]]; $m3[]='A2:'.colL($cc).'2';
$s3[]=[];
$hdr3=[['v'=>$L('الموظف','Employee'),'s'=>1]];
foreach($allDays as $d) $hdr3[]=['v'=>date('d/m',strtotime($d)),'s'=>21];
$hdr3[]=['v'=>$L('حضور','Present'),'s'=>1]; $hdr3[]=['v'=>$L('غياب','Absent'),'s'=>1]; $hdr3[]=['v'=>$L('ساعات','Hours'),'s'=>1];
$hr3=count($s3)+1; $s3[]=$hdr3;
if($N===0){ $s3[]=[['v'=>$L('لا توجد بيانات','No data'),'s'=>0]]; }
else foreach($emp as $uid=>$info){
    $row=[['v'=>$info['name'],'s'=>22]];
    foreach($allDays as $d){ $row[]= isset($info['days'][$d]) ? ['v'=>dec($info['days'][$d]['day_mins']),'t'=>'n','s'=>18] : ['v'=>'✗','s'=>19]; }
    $row[]=['v'=>$info['present'],'t'=>'n','s'=>23]; $row[]=['v'=>$info['absent'],'t'=>'n','s'=>($info['absent']>0?13:23)]; $row[]=['v'=>dec($info['mins']),'t'=>'n','s'=>4];
    $s3[]=$row;
}
$cols3=[20]; for($k=0;$k<$rangeDayCount;$k++) $cols3[]=4.5; $cols3[]=8;$cols3[]=8;$cols3[]=9;
$sheet3=['name'=>$L('الشبكة','Matrix'),'cols'=>$cols3,'rtl'=>$isAr,'merges'=>$m3,'rows'=>$s3,'freeze'=>['x'=>1,'y'=>$hr3,'cell'=>'B'.($hr3+1)]];

/* ============================================================
   SHEET 4 — Sessions (raw audit)
   ============================================================ */
$s4=[];
$s4[]=[['v'=>$L('الموظف','Employee'),'s'=>1],['v'=>$L('التاريخ','Date'),'s'=>1],['v'=>$L('اليوم','Day'),'s'=>1],['v'=>$L('حضور','Clock In'),'s'=>1],['v'=>$L('انصراف','Clock Out'),'s'=>1],['v'=>$L('المدة','Duration'),'s'=>1],['v'=>$L('ساعات','Hours'),'s'=>1],['v'=>$L('الفرع','Branch'),'s'=>1],['v'=>$L('موقع الدخول','In Location'),'s'=>1],['v'=>$L('موقع الخروج','Out Location'),'s'=>1],['v'=>$L('ملاحظة','Note'),'s'=>1]];
foreach($rawRows as $r){
    $it=strtotime($r['clock_in']);$ot=strtotime($r['clock_out']);$m=max(0,(int)$r['mins']);
    $inLoc=$r['clock_in_lat']!==null?($r['clock_in_lat'].','.$r['clock_in_lng']):($r['in_loc_denied']?$L('مرفوض','Denied'):'—');
    $outLoc=$r['clock_out_lat']!==null?($r['clock_out_lat'].','.$r['clock_out_lng']):($r['out_loc_denied']?$L('مرفوض','Denied'):'—');
    $bd=$isAr?($r['branch_ar']??$r['branch_name']):($r['branch_en']??$r['branch_name']);
    $note=$r['auto_closed']?$L('إغلاق تلقائي','Auto-closed'):'';
    $s4[]=[['v'=>$r['username']??('#'.$r['user_id']),'s'=>0],['v'=>xlDate($it),'t'=>'n','s'=>9],['v'=>$dayName($it),'s'=>0],['v'=>xlTime($it),'t'=>'n','s'=>10],['v'=>xlTime($ot),'t'=>'n','s'=>10],['v'=>dec($m)/24,'t'=>'n','s'=>11],['v'=>dec($m),'t'=>'n','s'=>12],['v'=>$bd?:'—','s'=>0],['v'=>$inLoc,'s'=>0],['v'=>$outLoc,'s'=>0],['v'=>$note,'s'=>($r['auto_closed']?8:0)]];
}
if(count($s4)===1) $s4[]=[['v'=>$L('لا توجد بيانات','No data'),'s'=>0]];
$sheet4=['name'=>$L('البصمات','Sessions'),'cols'=>[20,12,10,11,11,11,9,16,18,18,14],'rtl'=>$isAr,'rows'=>$s4,'freeze'=>['x'=>0,'y'=>1,'cell'=>'A2'],'autofilter'=>'A1:K1'];

$sheets=[$sheet1,$sheet2,$sheet3,$sheet4];

/* ============================================================
   Charts (native) — reference the Summary table
   ============================================================ */
$haveCharts = $N>0;
$q = "'".str_replace("'","''",$SUM)."'"; // quoted sheet name for formulas
$names=[];$hours=[];$present=[];$absent=[];
foreach($emp as $i){ $names[]=$i['name']; $hours[]=dec($i['mins']); $present[]=$i['present']; $absent[]=$i['absent']; }
$numcache=function($vals){ $p=''; foreach($vals as $i=>$v){ $p.='<c:pt idx="'.$i.'"><c:v>'.$v.'</c:v></c:pt>'; } return '<c:numCache><c:formatCode>General</c:formatCode><c:ptCount val="'.count($vals).'"/>'.$p.'</c:numCache>'; };
$strcache=function($vals){ $p=''; foreach($vals as $i=>$v){ $p.='<c:pt idx="'.$i.'"><c:v>'.xesc($v).'</c:v></c:pt>'; } return '<c:strCache><c:ptCount val="'.count($vals).'"/>'.$p.'</c:strCache>'; };
$catf=$q.'!$B$'.($HDR+1).':$B$'.$LAST;
$strref=fn($f,$v)=>'<c:strRef><c:f>'.$f.'</c:f>'.$strcache($v).'</c:strRef>';
$numref=fn($f,$v)=>'<c:numRef><c:f>'.$f.'</c:f>'.$numcache($v).'</c:numRef>';

$chart1='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
.'<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
.'<c:chart><c:title><c:tx><c:rich><a:bodyPr/><a:p><a:pPr><a:defRPr sz="1100" b="1"/></a:pPr><a:r><a:rPr lang="ar-SA" sz="1100" b="1"/><a:t>'.xesc($L('إجمالي ساعات العمل لكل موظف','Total Hours by Employee')).'</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title><c:autoTitleDeleted val="0"/>'
.'<c:plotArea><c:layout/><c:barChart><c:barDir val="col"/><c:grouping val="clustered"/><c:varyColors val="0"/>'
.'<c:ser><c:idx val="0"/><c:order val="0"/><c:tx><c:v>'.xesc($L('ساعات','Hours')).'</c:v></c:tx><c:spPr><a:solidFill><a:srgbClr val="7C3AED"/></a:solidFill></c:spPr>'
.'<c:cat>'.$strref($catf,$names).'</c:cat><c:val>'.$numref($q.'!$I$'.($HDR+1).':$I$'.$LAST,$hours).'</c:val></c:ser>'
.'<c:axId val="111"/><c:axId val="222"/></c:barChart>'
.'<c:catAx><c:axId val="111"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="b"/><c:crossAx val="222"/></c:catAx>'
.'<c:valAx><c:axId val="222"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="l"/><c:majorGridlines/><c:crossAx val="111"/></c:valAx>'
.'</c:plotArea><c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend><c:plotVisOnly val="1"/></c:chart></c:chartSpace>';

$chart2='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
.'<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
.'<c:chart><c:title><c:tx><c:rich><a:bodyPr/><a:p><a:pPr><a:defRPr sz="1100" b="1"/></a:pPr><a:r><a:rPr lang="ar-SA" sz="1100" b="1"/><a:t>'.xesc($L('أيام الحضور مقابل الغياب','Present vs Absent Days')).'</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title><c:autoTitleDeleted val="0"/>'
.'<c:plotArea><c:layout/><c:barChart><c:barDir val="bar"/><c:grouping val="stacked"/><c:varyColors val="0"/>'
.'<c:ser><c:idx val="0"/><c:order val="0"/><c:tx><c:v>'.xesc($L('حضور','Present')).'</c:v></c:tx><c:spPr><a:solidFill><a:srgbClr val="16A34A"/></a:solidFill></c:spPr><c:cat>'.$strref($catf,$names).'</c:cat><c:val>'.$numref($q.'!$D$'.($HDR+1).':$D$'.$LAST,$present).'</c:val></c:ser>'
.'<c:ser><c:idx val="1"/><c:order val="1"/><c:tx><c:v>'.xesc($L('غياب','Absent')).'</c:v></c:tx><c:spPr><a:solidFill><a:srgbClr val="E11D48"/></a:solidFill></c:spPr><c:cat>'.$strref($catf,$names).'</c:cat><c:val>'.$numref($q.'!$E$'.($HDR+1).':$E$'.$LAST,$absent).'</c:val></c:ser>'
.'<c:overlap val="100"/><c:axId val="333"/><c:axId val="444"/></c:barChart>'
.'<c:catAx><c:axId val="333"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="l"/><c:crossAx val="444"/></c:catAx>'
.'<c:valAx><c:axId val="444"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="b"/><c:majorGridlines/><c:crossAx val="333"/></c:valAx>'
.'</c:plotArea><c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend><c:plotVisOnly val="1"/></c:chart></c:chartSpace>';

$anchor=function($fc,$fr,$tc,$tr,$rid,$name){
    return '<xdr:twoCellAnchor><xdr:from><xdr:col>'.$fc.'</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.$fr.'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
      .'<xdr:to><xdr:col>'.$tc.'</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.$tr.'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
      .'<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="'.($rid+1).'" name="'.$name.'"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>'
      .'<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>'
      .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId'.$rid.'"/></a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:twoCellAnchor>';
};
$t0=$chartTop-1;
$drawing='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
 .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
 .$anchor(0,$t0,6,$t0+16,1,'Chart1').$anchor(6,$t0,12,$t0+16,2,'Chart2').'</xdr:wsDr>';
$drawingRels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
 .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart1.xml"/>'
 .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart2.xml"/></Relationships>';
$sheet1Rels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
 .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>';

/* ============================================================ Package ============================================================ */
if(!class_exists('ZipArchive')){
    header('Content-Type: text/plain; charset=utf-8');
    echo $isAr ? 'خاصية ZipArchive غير مفعّلة. تواصل مع دعم الاستضافة لتفعيل php-zip.' : 'ZipArchive not enabled. Ask hosting support to enable php-zip.';
    exit;
}
$ct='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
.'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
.'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
.'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
if($haveCharts){
    $ct.='<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>'
       .'<Override PartName="/xl/charts/chart1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>'
       .'<Override PartName="/xl/charts/chart2.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>';
}
foreach($sheets as $i=>$s) $ct.='<Override PartName="/xl/worksheets/sheet'.($i+1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
$ct.='</Types>';

$rels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
$wb='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
foreach($sheets as $i=>$s) $wb.='<sheet name="'.xesc($s['name']).'" sheetId="'.($i+1).'" r:id="rId'.($i+1).'"/>';
$wb.='</sheets></workbook>';
$wbr='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
foreach($sheets as $i=>$s) $wbr.='<Relationship Id="rId'.($i+1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i+1).'.xml"/>';
$wbr.='<Relationship Id="rId'.(count($sheets)+1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

$tmp=tempnam(sys_get_temp_dir(),'att').'.xlsx';
$zip=new ZipArchive();
$zip->open($tmp, ZipArchive::CREATE|ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml',$ct);
$zip->addFromString('_rels/.rels',$rels);
$zip->addFromString('xl/workbook.xml',$wb);
$zip->addFromString('xl/_rels/workbook.xml.rels',$wbr);
$zip->addFromString('xl/styles.xml',$STYLES);
foreach($sheets as $i=>$s) $zip->addFromString('xl/worksheets/sheet'.($i+1).'.xml', sheetXml($s));
if($haveCharts){
    $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels',$sheet1Rels);
    $zip->addFromString('xl/drawings/drawing1.xml',$drawing);
    $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels',$drawingRels);
    $zip->addFromString('xl/charts/chart1.xml',$chart1);
    $zip->addFromString('xl/charts/chart2.xml',$chart2);
}
$zip->close();

$fname="attendance_{$from}_to_{$to}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$fname.'"');
header('Content-Length: '.filesize($tmp));
header('Cache-Control: no-cache, no-store, must-revalidate');
readfile($tmp);
@unlink($tmp);
exit;
