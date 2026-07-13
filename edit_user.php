<?php

require 'auth.php';
require 'config.php';

perm_require('page.edit_user');

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("
SELECT *
FROM users
WHERE id = ?
LIMIT 1
");

$stmt->execute([$id]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$user)
{
    die("User Not Found");
}

$lang = $_GET['lang'] ?? 'ar';

$t = [

'ar' => [

'title' => 'تعديل المستخدم',

'username' => 'اسم المستخدم',

'role' => 'الصلاحية',

'status' => 'الحالة',

'active' => 'نشط',

'inactive' => 'غير نشط',

'save' => 'حفظ التعديلات',

'success' => 'تم حفظ التعديلات'

],

'en' => [

'title' => 'Edit User',

'username' => 'Username',

'role' => 'Role',

'status' => 'Status',

'active' => 'Active',

'inactive' => 'Inactive',

'save' => 'Save Changes',

'success' => 'Changes Saved'

]

];

$success = '';

if($_SERVER['REQUEST_METHOD'] == 'POST')
{

$username =
trim($_POST['username']);

$role =
trim($_POST['role']);

$active =
(int)$_POST['active'];

$stmt = $pdo->prepare("
UPDATE users
SET
username = ?,
role = ?,
active = ?
WHERE id = ?
");

$stmt->execute([
$username,
$role,
$active,
$id
]);

$success =
$t[$lang]['success'];

$stmt = $pdo->prepare("
SELECT *
FROM users
WHERE id = ?
");

$stmt->execute([$id]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

}

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
content="width=device-width, initial-scale=1.0">

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

min-height:100vh;

display:flex;

justify-content:center;

align-items:center;

padding:20px;

background:
linear-gradient(
135deg,
#020617,
#0f172a
);

overflow-x:hidden;

}

.bg1,
.bg2{

position:fixed;

border-radius:50%;

filter:blur(140px);

opacity:.22;

z-index:0;

}

.bg1{

width:400px;
height:400px;

background:#22c55e;

top:-120px;
left:-120px;

}

.bg2{

width:450px;
height:450px;

background:#9333ea;

bottom:-150px;
right:-150px;

}

.card{

position:relative;

z-index:2;

width:650px;

max-width:100%;

background:
rgba(15,23,42,.88);

backdrop-filter:
blur(20px);

border:
1px solid
rgba(255,255,255,.08);

border-radius:30px;

padding:35px;

box-shadow:
0 25px 60px
rgba(0,0,0,.35);

}

.avatar{

width:90px;
height:90px;

margin:auto;

border-radius:50%;

display:flex;

justify-content:center;
align-items:center;

font-size:36px;

font-weight:700;

background:
linear-gradient(
135deg,
#22c55e,
#9333ea
);

margin-bottom:20px;

}

.title{

text-align:center;

font-size:30px;

font-weight:700;

color:#9333ea;

margin-bottom:8px;

}

.subtitle{

text-align:center;

color:#94a3b8;

margin-bottom:30px;

}

.success{

background:
rgba(34,197,94,.15);

border:
1px solid
rgba(34,197,94,.3);

padding:15px;

border-radius:15px;

margin-bottom:20px;

text-align:center;

color:#86efac;

font-weight:700;

}

.form-group{

margin-bottom:18px;

}

label{

display:block;

margin-bottom:8px;

font-weight:600;

color:white;

}

input,
select{

width:100%;

height:58px;

border:none;

outline:none;

padding:15px;

border-radius:16px;

background:#111827;

color:white;

font-size:15px;

}

input:focus,
select:focus{

box-shadow:
0 0 0 2px
#9333ea;

}

.btn{

width:100%;

height:60px;

border:none;

cursor:pointer;

border-radius:18px;

font-size:18px;

font-weight:700;

color:white;

background:
linear-gradient(
90deg,
#22c55e,
#9333ea
);

transition:.3s;

margin-top:10px;

}

.btn:hover{

transform:
translateY(-3px);

}

.back{

display:block;

text-align:center;

margin-top:20px;

text-decoration:none;

color:#94a3b8;

font-weight:600;

}

@media(max-width:768px){

.card{

padding:25px;

}

.title{

font-size:24px;

}

.avatar{

width:75px;
height:75px;

font-size:30px;

}

}

</style>

</head>

<body>

<div class="bg1"></div>
<div class="bg2"></div>

<div class="card">

<div class="avatar">

👤

</div>

<div class="title">

<?= $t[$lang]['title'] ?>

</div>

<div class="subtitle">

<?= htmlspecialchars($user['username']) ?>

</div>

<?php if($success): ?>

<div class="success">

<?= $success ?>

</div>

<?php endif; ?>

<form method="POST">

<div class="form-group">

<label>

<?= $t[$lang]['username'] ?>

</label>

<input
type="text"
name="username"
value="<?= htmlspecialchars($user['username']) ?>"
required>

</div>

<div class="form-group">

<label>

<?= $t[$lang]['role'] ?>

</label>

<select name="role">

<option
value="admin"
<?= $user['role']=='admin' ? 'selected' : '' ?>>

Admin

</option>

<option
value="manager"
<?= $user['role']=='manager' ? 'selected' : '' ?>>

Manager

</option>

<option
value="sales"
<?= $user['role']=='sales' ? 'selected' : '' ?>>

Sales

</option>

</select>

</div>

<div class="form-group">

<label>

<?= $t[$lang]['status'] ?>

</label>

<select name="active">

<option
value="1"
<?= $user['active']==1 ? 'selected' : '' ?>>

<?= $t[$lang]['active'] ?>

</option>

<option
value="0"
<?= $user['active']==0 ? 'selected' : '' ?>>

<?= $t[$lang]['inactive'] ?>

</option>

</select>

</div>

<button
type="submit"
class="btn">

💾 <?= $t[$lang]['save'] ?>

</button>

</form>

<a
href="users.php?lang=<?= $lang ?>"
class="back">

← Back To Users

</a>

</div>

</body>

</html>
