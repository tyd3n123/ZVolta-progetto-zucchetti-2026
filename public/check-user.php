<?php
session_start();
require_once __DIR__ . "/../config/config.php";

// Controlla se l'utente è loggato
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['id_utente'])) {
    
    $sql = "SELECT u.nome, u.cognome, r.nome_ruolo AS ruolo
            FROM utenti u
            LEFT JOIN ruoli r ON u.id_ruolo = r.id_ruolo
            WHERE u.id_utente = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Errore nella prepare: " . $conn->error);
    }

    $idUtente = (int) $_SESSION['id_utente'];
    $stmt->bind_param("i", $idUtente);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();

        $userRole = strtolower(trim($user['ruolo']));

        switch ($userRole) {
            case 'admin':
                header("Location: ../admin/dashboard/index.php");
                exit();

            case 'coordinatore':
                header("Location: ../coordinatore/dashboard/index.php");
                exit();

            case 'dipendente':
                header("Location: ../dipendente/dashboard/index.php");
                exit();

            default:
                // Ruolo non riconosciuto
                header("Location: ../login.php");
                exit();
        }
    } else {
        // Utente non trovato nel database
        header("Location: ../login.php");
        exit();
    }

    $stmt->close();
} else {
    // Utente non loggato
    header("Location: ../login.php");
    exit();
}
?>