<?php
session_start();
require_once __DIR__ . "/../config/config.php";

// Check if user is logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['id_utente'])) {
    // Get user information from database
    $sql = "SELECT u.nome, u.cognome, r.nome_ruolo as ruolo 
            FROM utenti u 
            LEFT JOIN ruoli r ON u.id_ruolo = r.id_ruolo 
            WHERE u.id_utente = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['id_utente']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $userRole = strtolower($user['nome_ruolo']);
            
            // Redirect based on user role
            switch ($userRole) {
                case 'Admin':
                    header("Location: ../admin/dashboard/index.php");
                    break;
                case 'Coordinatore':
                    header("Location: ../coordinatore/dashboard/index.php");
                    break;
                case "Dipendente":
                    header("Location: ../dipendente/dashboard/index.php");
                    break;
            }
            exit();
        }
        $stmt->close();
    }
} else {
    // User not logged in, redirect to login
    header("Location: ../login.php");
    exit();
}
?>