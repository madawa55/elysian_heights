<?php
require __DIR__ . '/_init.php';

$_SESSION = [];
session_regenerate_id(true);
redirect('login.php');
