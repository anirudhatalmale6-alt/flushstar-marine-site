<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

fsd_logout();
header('Location: login.php');
