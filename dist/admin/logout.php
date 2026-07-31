<?php
require __DIR__ . '/_lib.php';
adm_session();
session_destroy();
header('Location: /admin/login.php');
