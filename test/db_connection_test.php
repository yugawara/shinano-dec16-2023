<?php

// $dsn = "{$sqlclient}:host={$dbhost};dbname={$dbname};charset=...";

$dsn = "mysql:host=localhost;dbname=shinano_dev";

$dbconn_ro = new PDO($dsn, "sdev_ro", "Kis0Shinan0DevR0"); // todo: charset
$stmt_ro = $dbconn_ro->query("SELECT 1");
$row = $stmt_ro->fetch(PDO::FETCH_ASSOC);
print_r($row);

$dbconn_rw = new PDO($dsn, "sdev_rw", "Kis0Shinan0DevRW"); // todo: charset
$stmt_rw = $dbconn_rw->query("SELECT 1");
$row = $stmt_rw->fetch(PDO::FETCH_ASSOC);
print_r($row);

 ?>