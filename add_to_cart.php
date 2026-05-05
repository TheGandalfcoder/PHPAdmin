<?php
// add_to_cart.php
session_start();// start session
$id = $_POST['id'] ?? '';
if (!ctype_digit($id)) die("Invalid product");

$_SESSION['cart'] = $_SESSION['cart'] ?? [];
$_SESSION['cart'][] = (int)$id;

header("Location: cart.php");// redirect to cart
exit;