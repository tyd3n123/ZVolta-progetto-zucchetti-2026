<?php

$DB_HOST = "127.0.0.1";
$DB_USER = "root";    
$DB_PASS = "";         
$DB_NAME = "northstar"; 


// 2) Crea connessione
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

// 3) Gestione errore
if ($conn->connect_error) {
  die("Connessione fallita: " . $conn->connect_error);
}
// 4) Charset corretto
$conn->set_charset("utf8mb4");

echo "Connessione al database avvenuta con successo!";