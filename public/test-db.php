<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST = "127.0.0.1";   // meglio di localhost
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "northstar";
$PORT    = 3306;          // se non va prova 3307

try {
  $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $PORT);
  $conn->set_charset("utf8mb4");
  echo "✅ OK: Connessione al DB riuscita!";
} catch (mysqli_sql_exception $e) {
  echo "ERRORE DB: " . $e->getMessage();
}