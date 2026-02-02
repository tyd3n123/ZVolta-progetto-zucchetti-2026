<?php
// ================================
// CONFIGURAZIONE DATABASE
// ================================
$host = "localhost";       // di solito localhost
$user = "root";            // utente MySQL
$password = "";            // password MySQL
$database = "z_volta";     // nome del database

// Connessione
$conn = new mysqli($host, $user, $password, $database);

// Controllo connessione
if ($conn->connect_error) {
    die("Connessione fallita: " . $conn->connect_error);
}

// ================================
// FUNZIONE PER STAMPARE TABELLE HTML
// ================================
function printTable($result, $title) {
    echo "<h2>$title</h2>";
    if ($result->num_rows > 0) {
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        // Header
        echo "<tr>";
        while ($field = $result->fetch_field()) {
            echo "<th>" . $field->name . "</th>";
        }
        echo "</tr>";
        // Dati
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table><br>";
    } else {
        echo "<p>Nessun dato trovato.</p>";
    }
}

// ================================
// QUERY TABELLE
// ================================

// 1️⃣ Utenti con coordinatore
$sql_utenti = "
SELECT 
    u.id,
    u.username,
    u.nome,
    u.cognome,
    u.ruolo,
    c.nome AS coordinatore_nome,
    c.cognome AS coordinatore_cognome
FROM utenti u
LEFT JOIN utenti c ON u.coordinatore_id = c.id
";
$result_utenti = $conn->query($sql_utenti);
printTable($result_utenti, "Utenti");

// 2️⃣ Uffici / Postazioni
$sql_uffici = "
SELECT 
    uf.id,
    uf.codice,
    uf.tipo,
    uf.stato,
    p.utente_id,
    p.data_inizio,
    p.data_fine
FROM uffici uf
LEFT JOIN prenotazioni p ON p.asset_id = uf.id AND p.tipo_asset='ufficio'
";
$result_uffici = $conn->query($sql_uffici);
printTable($result_uffici, "Uffici / Postazioni");

// 3️⃣ Sale riunioni
$sql_sale = "
SELECT 
    s.id,
    s.codice,
    s.capienza,
    s.stato,
    p.utente_id,
    p.data_inizio,
    p.data_fine
FROM sale_riunioni s
LEFT JOIN prenotazioni p ON p.asset_id = s.id AND p.tipo_asset='sala'
";
$result_sale = $conn->query($sql_sale);
printTable($result_sale, "Sale Riunioni");

// 4️⃣ Parcheggi
$sql_parcheggi = "
SELECT 
    pa.id,
    pa.codice,
    pa.stato,
    p.utente_id,
    p.data_inizio,
    p.data_fine
FROM parcheggi pa
LEFT JOIN prenotazioni p ON p.asset_id = pa.id AND p.tipo_asset='parcheggio'
";
$result_parcheggi = $conn->query($sql_parcheggi);
printTable($result_parcheggi, "Parcheggi");

// 5️⃣ Prenotazioni complete
$sql_prenotazioni = "
SELECT 
    p.id AS prenotazione_id,
    u.nome AS utente_nome,
    u.cognome AS utente_cognome,
    u.ruolo AS utente_ruolo,
    p.tipo_asset,
    p.asset_id,
    p.data_inizio,
    p.data_fine,
    p.modifiche,
    CASE
        WHEN p.tipo_asset='ufficio' THEN uf.codice
        WHEN p.tipo_asset='sala' THEN s.codice
        WHEN p.tipo_asset='parcheggio' THEN pa.codice
        ELSE NULL
    END AS codice_asset
FROM prenotazioni p
JOIN utenti u ON p.utente_id = u.id
LEFT JOIN uffici uf ON p.asset_id = uf.id AND p.tipo_asset='ufficio'
LEFT JOIN sale_riunioni s ON p.asset_id = s.id AND p.tipo_asset='sala'
LEFT JOIN parcheggi pa ON p.asset_id = pa.id AND p.tipo_asset='parcheggio'
";
$result_prenotazioni = $conn->query($sql_prenotazioni);
printTable($result_prenotazioni, "Prenotazioni Complete");

// Chiudo connessione
$conn->close();
?>
