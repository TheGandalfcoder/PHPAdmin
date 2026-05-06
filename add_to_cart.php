<?php
session_start();
$id = $_POST['id'] ?? '';
if (!ctype_digit($id)) die("Invalid product");

$_SESSION['cart'] = $_SESSION['cart'] ?? [];
$_SESSION['cart'][] = (int)$id;

header("Location: cart.php");
exit;