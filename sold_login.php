<?php

require 'auth.php';

session_start();

$lang = $_GET['lang'] ?? 'ar';

$SOLD_PASSWORD = "Mrkhaled1963";

$error = '';

$t = [

'ar' => [

'title' => 'السيارات المباعة',
'password' => 'كلمة المرور',
'unlock' => 'فتح',
'wrong_password' => 'كلمة المرور غير صحيحة',
'back' => 'العودة للمخزون'

],

'en' => [

'title' => 'Sold Inventory',
'password' => 'Password',
'unlock' => 'Unlock',
'wrong_password' => 'Wrong Password',
'back' => 'Back To Inventory'

]

];

if($_SERVER['REQUEST_METHOD'] == 'POST')
{

$password =
trim($_POST['password']);

if($password === $SOLD_PASSWORD)
{

$_SESSION['sold_access'] = true;

header(
"Location: sold_inventory.php?lang=".$lang
);

exit;

}

$error =
$t[$lang]['wrong_password'];

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

background:
linear-gradient(
135deg,
#020617,
#0f172a
);

min-height:100vh;

display:flex;

justify-content:center;

align-items:center;

padding:20px;

color:white;

}

.card{

width:100%;

max-width:450px;

background:
rgba(15,23,42,.9);

border:
1px solid rgba(255,255,255,.08);

border-radius:30px;

padding:35px;

backdrop-filter:
blur(20px);

box-shadow:
0 20px 50px rgba(0,0,0,.35);

}

.title{

font-size:28px;

font-weight:800;

text-align:center;

margin-bottom:25px;

color:#9333ea;

}

.lock{

font-size:60px;

text-align:center;

margin-bottom:15px;

}

.error{

background:
rgba(239,68,68,.15);

border:
1px solid rgba(239,68,68,.3);

padding:12px;

border-radius:14px;

text-align:center;

margin-bottom:15px;

color:#fca5a5;

font-weight:700;

}

label{

display:block;

margin-bottom:8px;

font-weight:600;

}

input{

width:100%;

height:60px;

border:none;

outline:none;

background:#111827;

color:white;

padding:15px;

border-radius:16px;

font-size:16px;

margin-bottom:18px;

}

.btn{

width:100%;

height:60px;

border:none;

cursor:pointer;

border-radius:16px;

font-size:18px;

font-weight:700;

color:white;

background:
linear-gradient(
90deg,
#22c55e,
#9333ea
);

}

.back{

display:block;

margin-top:20px;

text-align:center;

text-decoration:none;

color:#94a3b8;

font-weight:600;

}

</style>

</head>

<body>

<div class="card">

<div class="lock">

🔒

</div>

<div class="title">

<?= $t[$lang]['title'] ?>

</div>

<?php if($error): ?>

<div class="error">

<?= $error ?>

</div>

<?php endif; ?>

<form method="POST">

<label>

<?= $t[$lang]['password'] ?>

</label>

<input
type="password"
name="password"
required>

<button
type="submit"
class="btn">

<?= $t[$lang]['unlock'] ?>

</button>

</form>

<a
href="dashboard.php?lang=<?= $lang ?>"
class="back">

← <?= $t[$lang]['back'] ?>

</a>

</div>

</body>

</html>