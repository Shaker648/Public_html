<?php

require 'auth.php';
require 'config.php';

perm_require('page.user_activity');

$lang = $_GET['lang'] ?? 'ar';

$userId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
SELECT *
FROM users
WHERE id = ?
LIMIT 1
");

$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$user)
{
    die("User Not Found");
}

$t = [

'ar' => [

'title' => 'نشاط المستخدم',

'added_cars' => 'السيارات المضافة',

'transfers' => 'عمليات النقل',

'sales' => 'السيارات المباعة',

'total' => 'إجمالي النشاط',

'activity_history' => 'سجل النشاط',

'created_vehicle' => 'تم إضافة سيارة',

'transfer_vehicle' => 'تم نقل سيارة',

'sold_vehicle' => 'تم بيع سيارة',

'from' => 'من',

'to' => 'إلى',

'customer' => 'العميل',

'back' => 'العودة للمستخدمين',

'created' => 'تاريخ الإنشاء',

'role' => 'الدور',

],

'en' => [

'title' => 'User Activity',

'added_cars' => 'Vehicles Added',

'transfers' => 'Transfers',

'sales' => 'Vehicles Sold',

'total' => 'Total Activity',

'activity_history' => 'Activity History',

'created_vehicle' => 'Vehicle Added',

'transfer_vehicle' => 'Vehicle Transfer',

'sold_vehicle' => 'Vehicle Sold',

'from' => 'From',

'to' => 'To',

'customer' => 'Customer',

'back' => 'Back To Users',

'created' => 'Created',

'role' => 'Role',

]

];
$addedStmt = $pdo->prepare("
SELECT *
FROM cars
WHERE created_by = ?
");

$addedStmt->execute([
$user['username']
]);

$addedCars =
$addedStmt->fetchAll(PDO::FETCH_ASSOC);

$addedCount =
count($addedCars);

$transferStmt = $pdo->prepare("
SELECT *
FROM movements
WHERE moved_by = ?
");

$transferStmt->execute([
$user['username']
]);

$transfers =
$transferStmt->fetchAll(PDO::FETCH_ASSOC);

$transferCount =
count($transfers);

$soldStmt = $pdo->prepare("
SELECT *
FROM sold_cars
WHERE sold_by = ?
");

$soldStmt->execute([
$user['username']
]);

$soldCars =
$soldStmt->fetchAll(PDO::FETCH_ASSOC);

$soldCount =
count($soldCars);

$totalActivity =
$addedCount +
$transferCount +
$soldCount;
$activities = [];
foreach($addedCars as $car)
{

$activities[] = [

'type' => 'add',

'date' => $car['created_at'],

'brand' => $car['brand'],

'model' => $car['model'],

'chassis' => $car['chassis']

];

}

foreach($transfers as $move)
{

$activities[] = [

'type' => 'transfer',

'date' => $move['created_at'],

'from_branch' => $move['from_branch'],

'to_branch' => $move['to_branch'],

'notes' => $move['notes']

];

}

foreach($soldCars as $sale)
{

$activities[] = [

'type' => 'sale',

'date' => $sale['sold_at'],

'customer_name' =>
$sale['customer_name'],

'dealer_name' =>
$sale['dealer_name'],

'sale_type' =>
$sale['sale_type']

];

}

usort(
$activities,
function($a,$b)
{
return strtotime($b['date'])
-
strtotime($a['date']);
}
);
?>
<!DOCTYPE html>

<html
lang="<?= $lang ?>"
dir="<?= $lang == 'ar' ? 'rtl' : 'ltr' ?>"
>

<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width, initial-scale=1.0"
>

<title>

<?= $t[$lang]['title'] ?>

</title>
<style>

*{
margin:0;
padding:0;
box-sizing:border-box;
font-family:
Segoe UI,
Tahoma,
sans-serif;
}

body{

background:
linear-gradient(
135deg,
#020617,
#0f172a
);

color:white;

min-height:100vh;

padding-bottom:100px;

}

.container{

max-width:1400px;

margin:auto;

padding:20px;

}

.header{

background:
rgba(15,23,42,.9);

border:
1px solid rgba(255,255,255,.08);

border-radius:30px;

padding:25px;

margin-bottom:25px;

}

.user-top{

display:flex;

justify-content:space-between;

align-items:center;

flex-wrap:wrap;

gap:20px;

}

.username{

font-size:34px;

font-weight:800;

color:#22c55e;

}

.user-role{

margin-top:10px;

color:#94a3b8;

font-size:15px;

}

.back-btn{

text-decoration:none;

padding:14px 22px;

border-radius:14px;

background:#2563eb;

color:white;

font-weight:700;

}

.stats-grid{

display:grid;

grid-template-columns:
repeat(4,1fr);

gap:15px;

margin-bottom:25px;

}

.stat-card{

background:
rgba(15,23,42,.9);

border:
1px solid rgba(255,255,255,.08);

border-radius:25px;

padding:25px;

text-align:center;

}

.stat-title{

font-size:14px;

color:#94a3b8;

}

.stat-number{

font-size:42px;

font-weight:800;

margin-top:10px;

}

.green{
color:#22c55e;
}

.orange{
color:#f59e0b;
}

.red{
color:#ef4444;
}

.purple{
color:#9333ea;
}

.timeline-title{

font-size:26px;

font-weight:800;

margin-bottom:20px;

color:#22c55e;

}

.timeline{

position:relative;

}

.timeline::before{

content:'';

position:absolute;

top:0;
bottom:0;

left:30px;

width:3px;

background:
linear-gradient(
180deg,
#22c55e,
#9333ea
);

}

.timeline-item{

position:relative;

margin-left:70px;

margin-bottom:20px;

background:
rgba(15,23,42,.9);

border:
1px solid rgba(255,255,255,.08);

border-radius:25px;

padding:20px;

}

.timeline-icon{

position:absolute;

left:-56px;

top:20px;

width:35px;

height:35px;

border-radius:50%;

display:flex;

align-items:center;

justify-content:center;

font-size:16px;

background:#111827;

border:2px solid #22c55e;

}

.activity-date{

font-size:13px;

color:#94a3b8;

margin-bottom:12px;

}

.activity-title{

font-size:20px;

font-weight:800;

margin-bottom:12px;

}

.activity-info{

line-height:2;

color:#e2e8f0;

}

@media(max-width:768px){

.stats-grid{

grid-template-columns:
repeat(2,1fr);

}

.username{

font-size:26px;

}

.timeline-item{

margin-left:55px;

}

}

</style>

</head>

<body>
    <div class="container">

<div class="header">

<div class="user-top">

<div>

<div class="username">

👤 <?= htmlspecialchars($user['username']) ?>

</div>

<div class="user-role">

<?= $t[$lang]['role'] ?>:
<?= ucfirst($user['role']) ?>

<br>

<?= $t[$lang]['created'] ?>:
<?= date(
'd M Y',
strtotime($user['created_at'])
) ?>

</div>

</div>

<a
href="users.php?lang=<?= $lang ?>"
class="back-btn"
>

← <?= $t[$lang]['back'] ?>

</a>

</div>

</div>

<div class="stats-grid">

<div class="stat-card">

<div class="stat-title">

<?= $t[$lang]['added_cars'] ?>

</div>

<div class="stat-number green">

<?= $addedCount ?>

</div>

</div>

<div class="stat-card">

<div class="stat-title">

<?= $t[$lang]['transfers'] ?>

</div>

<div class="stat-number orange">

<?= $transferCount ?>

</div>

</div>

<div class="stat-card">

<div class="stat-title">

<?= $t[$lang]['sales'] ?>

</div>

<div class="stat-number red">

<?= $soldCount ?>

</div>

</div>

<div class="stat-card">

<div class="stat-title">

<?= $t[$lang]['total'] ?>

</div>

<div class="stat-number purple">

<?= $totalActivity ?>

</div>

</div>

</div>

<div class="timeline-title">

📊 <?= $t[$lang]['activity_history'] ?>

</div>

<div class="timeline">
<?php foreach($activities as $activity): ?>

<div class="timeline-item">

<div class="timeline-icon">

<?php

if($activity['type'] == 'add')
{
    echo '🚗';
}
elseif($activity['type'] == 'transfer')
{
    echo '🔄';
}
else
{
    echo '💰';
}

?>

</div>

<div class="activity-date">

<?= date(
'd M Y h:i A',
strtotime($activity['date'])
) ?>

</div>
<?php if($activity['type'] == 'add'): ?>

<div class="activity-title">

🚗 <?= $t[$lang]['created_vehicle'] ?>

</div>

<div class="activity-info">

<strong>

<?= htmlspecialchars($activity['brand']) ?>

<?= htmlspecialchars($activity['model']) ?>

</strong>

<br>

<?= htmlspecialchars($activity['chassis']) ?>

</div>

<?php endif; ?>
<?php if($activity['type'] == 'transfer'): ?>

<div class="activity-title">

🔄 <?= $t[$lang]['transfer_vehicle'] ?>

</div>

<div class="activity-info">

<?= $t[$lang]['from'] ?>:

<strong>

<?= htmlspecialchars($activity['from_branch']) ?>

</strong>

<br>

<?= $t[$lang]['to'] ?>:

<strong>

<?= htmlspecialchars($activity['to_branch']) ?>

</strong>

<?php if(!empty($activity['notes'])): ?>

<br><br>

📝

<?= htmlspecialchars($activity['notes']) ?>

<?php endif; ?>

</div>

<?php endif; ?>

<?php if($activity['type'] == 'sale'): ?>

<div class="activity-title">

💰 <?= $t[$lang]['sold_vehicle'] ?>

</div>

<div class="activity-info">

<?php if($activity['sale_type'] == 'customer'): ?>

<?= $t[$lang]['customer'] ?>:

<strong>

<?= htmlspecialchars(
$activity['customer_name']
?: '-'
) ?>

</strong>

<?php endif; ?>

<?php if($activity['sale_type'] == 'dealer'): ?>

🏢

<strong>

<?= htmlspecialchars(
$activity['dealer_name']
?: '-'
) ?>

</strong>

<?php endif; ?>

</div>

<?php endif; ?>
</div>

<?php endforeach; ?>
<?php if(count($activities) == 0): ?>

<div
style="
background:rgba(15,23,42,.9);
border:1px solid rgba(255,255,255,.08);
border-radius:25px;
padding:50px;
text-align:center;
"
>

<h2>

📊 No Activity Found

</h2>

</div>

<?php endif; ?>
</div>

</div>

</body>

</html>