<?php

// Per far funzionare il tutto dobbiamo inserire le credenziali corrette in modo tale che tutti possano accedere al database
// Per utilizzare il database in qualsiasi pagina, basta utilizzare questo comando:
// require_once __DIR__ . "/../config/connessione.php"; --> il path cambia in base a dove vi trovate, se non sapete come fare scrivete a Pop

$DB_HOST = "localhost";
$DB_USER = "root";    
$DB_PASS = "";         
$DB_NAME = "nomedb"; 


// 2) Crea connessione
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

// 3) Gestione errore
if ($conn->connect_error) {
  die("Connessione fallita: " . $conn->connect_error);
}

// 4) Charset corretto
$conn->set_charset("utf8mb4");
