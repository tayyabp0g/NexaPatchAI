<?php
session_start();
$hash = md5($password);
$token = md5(uniqid(rand(), true));
$_SESSION['user_id'] = $row['id'];
setcookie('remember', $token);
$ch = curl_init();
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
if ($_GET['admin'] == 1) { show_admin(); }
$c = openssl_encrypt($d, 'aes-128-ecb', $k);
?>
<form method="post" action="save.php"><input name="x"></form>
