<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

// Handle signout
if (isset($_GET['action']) && $_GET['action'] === 'signout') {
    session_unset();
    session_destroy();
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

// Fetch analytics data
$analytics = [
    'dipendenti' => 0,
    'coordinatori' => 0,
    'prenotazioni_totali' => 0
];

$sql = "SELECT COUNT(*) as count FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE r.nome_ruolo = 'Dipendente'";
$result = $conn->query($sql);
if ($result) { $row = $result->fetch_assoc(); $analytics['dipendenti'] = $row['count']; }

$sql = "SELECT COUNT(*) as count FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE r.nome_ruolo = 'Coordinatore'";
$result = $conn->query($sql);
if ($result) { $row = $result->fetch_assoc(); $analytics['coordinatori'] = $row['count']; }

$sql = "SELECT COUNT(*) as count FROM prenotazioni";
$result = $conn->query($sql);
if ($result) { $row = $result->fetch_assoc(); $analytics['prenotazioni_totali'] = $row['count']; }

// Fetch user's active bookings
$userBookings = [];
if (isset($_SESSION['id_utente'])) {
    $sql = "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset 
            FROM prenotazioni p 
            JOIN asset a ON p.id_asset = a.id_asset 
            WHERE p.id_utente = ? 
            ORDER BY p.data_inizio DESC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['id_utente']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $userBookings[] = $row; }
        $stmt->close();
    }
}

// Fetch all bookings for admin view
$allBookings = [];
$sql = "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset, u.nome, u.cognome, r.nome_ruolo
        FROM prenotazioni p 
        JOIN asset a ON p.id_asset = a.id_asset 
        JOIN utenti u ON p.id_utente = u.id_utente
        JOIN ruoli r ON u.id_ruolo = r.id_ruolo
        ORDER BY p.data_inizio DESC";
$result = $conn->query($sql);
if ($result) { while ($row = $result->fetch_assoc()) { $allBookings[] = $row; } }

// Fetch employees list
$employees = [];
$sql = "SELECT u.id_utente, u.nome, u.cognome, r.nome_ruolo as ruolo 
        FROM utenti u 
        JOIN ruoli r ON u.id_ruolo = r.id_ruolo 
        WHERE r.nome_ruolo = 'Dipendente'
        ORDER BY u.cognome, u.nome";
$result = $conn->query($sql);
if ($result) { while ($row = $result->fetch_assoc()) { $employees[] = $row; } }
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Dashboard | Northstar</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="./dashboard.css">
</head>
<body>

  <!-- ── Header ─────────────────────────────────────── -->
  <header class="header">
    <div class="header-left">
      <h1>Northstar</h1>
    </div>
    <a href="?action=signout" class="signout-btn">Esci</a>
  </header>

  <!-- ── Welcome ────────────────────────────────────── -->
  <div class="welcome-section">
    <h2>Benvenuto, <?php echo htmlspecialchars($userInfo['nome']); ?></h2>
    <span class="role-badge"><?php echo htmlspecialchars($userInfo['ruolo']); ?></span>
  </div>

  <!-- ── Analytics Strip ────────────────────────────── -->
  <div class="analytics-container">
    <div class="analytics-card">
      <div class="analytics-icon">👥</div>
      <div class="analytics-content">
        <h3>Dipendenti</h3>
        <p class="analytics-number"><?php echo $analytics['dipendenti']; ?></p>
      </div>
    </div>
    <div class="analytics-card">
      <div class="analytics-icon">👔</div>
      <div class="analytics-content">
        <h3>Coordinatori</h3>
        <p class="analytics-number"><?php echo $analytics['coordinatori']; ?></p>
      </div>
    </div>
    <div class="analytics-card">
      <div class="analytics-icon">📅</div>
      <div class="analytics-content">
        <h3>Prenotazioni Totali</h3>
        <p class="analytics-number"><?php echo $analytics['prenotazioni_totali']; ?></p>
      </div>
    </div>
  </div>

  <!-- ── Main Layout ────────────────────────────────── -->
  <div class="dashboard-container">

    <!-- Left sidebar: quick booking -->
    <div class="column">
      <h2>Prenota</h2>

      <div class="booking-card" onclick="location.href='../sale-riunioni/index.php'">
        <div class="card-content">
          <h3>🪑 Sale riunioni</h3>
          <p>Prenota sale attrezzate per meeting e presentazioni</p>
          <button class="book-btn" onclick="location.href='../sale-riunioni/index.php'">Prenota →</button>
        </div>
      </div>

      <div class="booking-card">
        <div class="card-content">
          <h3>🖥️ Uffici</h3>
          <p>Prenota uffici e postazioni lavoro</p>
          <button class="book-btn" disabled style="opacity:.5;cursor:not-allowed;">Prossimamente</button>
        </div>
      </div>

      <div class="booking-card">
        <div class="card-content">
          <h3>🚗 Parcheggi</h3>
          <p>Prenota posti auto e moto</p>
          <button class="book-btn" onclick="location.href='../parcheggi/index.php'">Prenota →</button>
        </div>
      </div>
    </div>

    <!-- Right: bookings + users -->
    <div class="bookings-section">
      <div class="bookings-row">

        <!-- Le tue prenotazioni -->
        <div class="bookings-column">
          <h2>Le tue prenotazioni</h2>
          <div class="booking-list">
            <?php if (empty($userBookings)): ?>
              <p>Nessuna prenotazione attiva</p>
            <?php else: ?>
              <?php foreach (array_slice($userBookings, 0, 3) as $booking): ?>
                <div class="booking-item">
                  <h4><?php echo htmlspecialchars($booking['codice_asset']); ?></h4>
                  <p><strong>Inizio:</strong> <?php echo date('d/m/Y H:i', strtotime($booking['data_inizio'])); ?></p>
                  <p><strong>Fine:</strong> <?php echo date('d/m/Y H:i', strtotime($booking['data_fine'])); ?></p>
                </div>
              <?php endforeach; ?>
              <?php if (count($userBookings) > 3): ?>
                <p class="more-bookings">+<?php echo count($userBookings) - 3; ?> altre prenotazioni</p>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <button class="show-more-btn" onclick="openUserBookingsModal()">Mostra tutte →</button>
        </div>

        <!-- Tutte le prenotazioni -->
        <div class="bookings-column">
          <h2>Tutte le prenotazioni</h2>
          <div class="booking-list">
            <?php if (empty($allBookings)): ?>
              <p>Nessuna prenotazione presente</p>
            <?php else: ?>
              <?php foreach (array_slice($allBookings, 0, 4) as $booking): ?>
                <div class="booking-item">
                  <h4><?php echo htmlspecialchars($booking['codice_asset']); ?></h4>
                  <p><strong>Utente:</strong> <?php echo htmlspecialchars($booking['nome'] . ' ' . $booking['cognome']); ?></p>
                  <p><strong>Inizio:</strong> <?php echo date('d/m/Y H:i', strtotime($booking['data_inizio'])); ?></p>
                  <p><strong>Fine:</strong> <?php echo date('d/m/Y H:i', strtotime($booking['data_fine'])); ?></p>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <button class="show-more-btn" onclick="openGestionePrenotazioni()">Gestisci prenotazioni →</button>
        </div>

        <!-- Gestisci Utenti -->
        <div class="bookings-column small">
          <h2>Gestisci Utenti</h2>
          <div class="employees-list">
            <?php if (empty($employees)): ?>
              <p class="no-employees">Nessun dipendente presente</p>
            <?php else: ?>
              <?php foreach ($employees as $employee): ?>
                <div class="employee-item">
                  <div class="employee-info">
                    <h4><?php echo htmlspecialchars($employee['nome'] . ' ' . $employee['cognome']); ?></h4>
                    <p class="employee-role"><?php echo htmlspecialchars($employee['ruolo']); ?></p>
                  </div>
                  <button class="employee-btn" onclick="window.location.href='../utenti/index.php'">Gestisci</button>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <button class="show-more-btn">Aggiungi utente +</button>
        </div>

      </div>
    </div>
  </div>

  <!-- ── Modals ──────────────────────────────────────── -->
  <div id="user-bookings-modal" class="modal-overlay" style="display:none;">
    <div class="modal-container"></div>
  </div>

  <div id="gestione-prenotazioni-modal" class="modal-overlay" style="display:none;">
    <div class="modal-container"></div>
  </div>

  <script>
    function openUserBookingsModal() {
      const modal = document.getElementById('user-bookings-modal');
      const container = modal.querySelector('.modal-container');
      modal.style.display = 'flex';
      fetch('modali/user-bookings-modal.php')
        .then(r => r.text())
        .then(html => { container.innerHTML = html; })
        .catch(() => { container.innerHTML = '<div class="error-message">Errore nel caricamento del contenuto</div>'; });
    }

    function closeUserBookingsModal() {
      document.getElementById('user-bookings-modal').style.display = 'none';
    }

    function openGestionePrenotazioni() {
      const modal = document.getElementById('gestione-prenotazioni-modal');
      const container = modal.querySelector('.modal-container');
      modal.style.display = 'flex';
      fetch('modali/gestione-prenotazioni-modal.php')
        .then(r => r.text())
        .then(html => { container.innerHTML = html; })
        .catch(() => { container.innerHTML = '<div class="error-message">Errore nel caricamento del contenuto</div>'; });
    }

    function manageEmployee(employeeName) {
      alert(`Gestione dipendente: ${employeeName}`);
    }

    function closeModal() {
      document.querySelectorAll('.modal-overlay').forEach(m => m.style.display = 'none');
    }

    document.addEventListener('click', e => {
      if (e.target.classList.contains('modal-overlay')) closeModal();
    });

    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') closeModal();
    });
  </script>
</body>
</html>
