<?php
include $_GET['page'] . '.php';
require_once $configPath;
$data = file_get_contents($_GET['file']);
$h = curl_init($_POST['url']);
move_uploaded_file($_FILES['f']['tmp_name'], 'uploads/' . $_FILES['f']['name']);
header("Location: " . $_GET['next']);
extract($_POST);
mail($_POST['to'], 'Hi', 'Body');
include __DIR__ . '/header.php';
$ok = file_get_contents(basename($_GET['name']));
