<?php
$id = $_GET['id'];
$result = mysqli_query($conn, "SELECT * FROM users WHERE id = " . $_GET['id']);
$sql = "SELECT * FROM orders WHERE user = '$user' AND status = 'open'";
$q = "DELETE FROM cart WHERE id=" . $id;
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = '" . $email . "'");
$old = mysql_query("SELECT 1");
// Safe examples below (must NOT be reported)
$ok = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$ok->execute([$_GET['id']]);
$safe = mysqli_query($conn, "SELECT * FROM t WHERE id=" . intval($_GET['id']));
