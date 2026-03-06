<?php
session_start();
require_once __DIR__ . "/../../config/config.php";

// Handle signout
if (isset($_GET['action']) && $_GET['action'] === 'signout') {
    // Destroy all session variables
    session_unset();
    session_destroy();
    
    // Redirect to login page
    header("Location: ../login.php");
    exit();
}

// Initialize empty user info
$userInfo = [
    'nome' => '',
    'cognome' => '', 
    'ruolo' => ''
];

// Get user information from database only if logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['id_utente'])) {
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
            $userInfo = [
                'nome' => $user['nome'],
                'cognome' => $user['cognome'],
                'ruolo' => $user['ruolo']
            ];
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Dashboard | Z Volta</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="./dashboard.css">
</head>
<body>
  <header class="header">
    <div class="header-left">
      <h1>Northstar</h1>
      <div class="user-info">
        <p>User: <?php echo htmlspecialchars($userInfo['nome'] . ' ' . $userInfo['cognome']); ?></p>
        <p>Ruolo: <?php echo htmlspecialchars($userInfo['ruolo']); ?></p>
      </div>
    </div>
    <a href="?action=signout" class="signout-btn">Signout</a>
  </header>

  <div class="dashboard-container">
    <div class="column">
      <h2>Prenota</h2>
      
      <div class="booking-card">
        <div class="card-content">
          <h3>Sale riunioni</h3>
          <p>Prenota sale riunioni attrezzate</p>
          <button class="book-btn" onclick="location.href='../sale-riunioni/index.php'">Prenota→</button>
        </div>
      </div>

      <div class="booking-card">
        <div class="card-content">
          <h3>Uffici</h3>
          <p>Prenota uffici e postazioni lavoro</p>
          <button class="book-btn">Prenota→</button>
        </div>
      </div>

      <div class="booking-card">
        <div class="card-content">
          <h3>Parcheggi</h3>
          <p>Prenota posti auto e moto</p>
          <button class="book-btn">Prenota→</button>
        </div>
      </div>
    </div>

    <div class="column">
      <h2>Le tue prenotazioni</h2>
      
      <div class="booking-list">
        <p>Nessuna prenotazione disponibile</p>
      </div>

      <button class="show-more-btn">Mostra di più</button>
    </div>
  </div>
</body>
</html>