<?php
require_once 'auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    logout_destroy();
}

header('Location: home.php');
exit;
