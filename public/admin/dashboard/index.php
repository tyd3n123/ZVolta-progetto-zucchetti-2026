<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

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

// Fetch analytics data
$analytics = [
    'dipendenti' => 0,
    'coordinatori' => 0,
    'prenotazioni_totali' => 0
];

// Count employees (role = Dipendente)
$sql = "SELECT COUNT(*) as count FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE r.nome_ruolo = 'Dipendente'";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $analytics['dipendenti'] = $row['count'];
}

// Count coordinators (role = Coordinatore)
$sql = "SELECT COUNT(*) as count FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE r.nome_ruolo = 'Coordinatore'";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $analytics['coordinatori'] = $row['count'];
}

// Count total bookings
$sql = "SELECT COUNT(*) as count FROM prenotazioni";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $analytics['prenotazioni_totali'] = $row['count'];
}

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
        
        while ($row = $result->fetch_assoc()) {
            $userBookings[] = $row;
        }
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

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $allBookings[] = $row;
    }
}

// Fetch employees list for user management
$employees = [];
$sql = "SELECT u.nome, u.cognome, r.nome_ruolo as ruolo 
        FROM utenti u 
        JOIN ruoli r ON u.id_ruolo = r.id_ruolo 
        WHERE r.nome_ruolo = 'Dipendente'
        ORDER BY u.cognome, u.nome";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
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
    </div>
    <a href="?action=signout" class="signout-btn">Signout</a>
  </header>

  <!-- Welcome Section -->
  <div class="welcome-section">
    <h2>Benvenuto <?php echo htmlspecialchars($userInfo['nome']); ?></h2>
    <p class="role-badge"><?php echo htmlspecialchars($userInfo['ruolo']); ?></p>
  </div>

  <!-- Analytics Section -->
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

    <div class="bookings-section">
      <div class="bookings-row">
        <div class="bookings-column">
          <h2>Le tue prenotazioni</h2>
          
          <div class="booking-list">
            <?php if (empty($userBookings)): ?>
              <p>Nessuna prenotazione disponibile</p>
            <?php else: ?>
              <?php foreach ($userBookings as $booking): ?>
                <div class="booking-item">
                  <h4><?php echo htmlspecialchars($booking['codice_asset']); ?></h4>
                  <p><strong>Inizio:</strong> <?php echo date('d/m/Y H:i', strtotime($booking['data_inizio'])); ?></p>
                  <p><strong>Fine:</strong> <?php echo date('d/m/Y H:i', strtotime($booking['data_fine'])); ?></p>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <button class="show-more-btn" onclick="openUserBookingsModal()">Mostra di più</button>
        </div>

        <div class="bookings-column">
          <h2>Tutte le prenotazioni</h2>
          
          <div class="booking-list">
            <?php if (empty($allBookings)): ?>
              <p>Nessuna prenotazione presente</p>
            <?php else: ?>
              <?php foreach ($allBookings as $booking): ?>
                <div class="booking-item">
                  <h4><?php echo htmlspecialchars($booking['codice_asset']); ?></h4>
                  <p><strong>Utente:</strong> <?php echo htmlspecialchars($booking['nome'] . ' ' . $booking['cognome']); ?> (<?php echo htmlspecialchars($booking['nome_ruolo']); ?>)</p>
                  <p><strong>Inizio:</strong> <?php echo date('d/m/Y H:i', strtotime($booking['data_inizio'])); ?></p>
                  <p><strong>Fine:</strong> <?php echo date('d/m/Y H:i', strtotime($booking['data_fine'])); ?></p>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <button class="show-more-btn" onclick="openGestionePrenotazioni()">Gestisci prenotazioni</button>
        </div>

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
                  <div class="employee-actions">
                    <button class="employee-btn" onclick="manageEmployee('<?php echo htmlspecialchars($employee['nome'] . ' ' . $employee['cognome']); ?>')">Gestisci</button>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Container for User Bookings -->
  <div id="user-bookings-modal" class="modal-overlay" style="display: none;">
    <div class="modal-container">
      <!-- Content will be loaded here -->
    </div>
  </div>

  <!-- Modal Container for All Bookings -->
  <div id="gestione-prenotazioni-modal" class="modal-overlay" style="display: none;">
    <div class="modal-container">
      <!-- Content will be loaded here -->
    </div>
  </div>

  <script>
    function openUserBookingsModal() {
      const modal = document.getElementById('user-bookings-modal');
      const container = modal.querySelector('.modal-container');
      
      // Show modal
      modal.style.display = 'flex';
      
      // Load content
      fetch('modali/user-bookings-modal.php')
        .then(response => response.text())
        .then(html => {
          container.innerHTML = html;
        })
        .catch(error => {
          console.error('Error loading modal content:', error);
          container.innerHTML = '<div class="error-message">Errore nel caricamento del contenuto</div>';
        });
    }
    
    function closeUserBookingsModal() {
      const modal = document.getElementById('user-bookings-modal');
      modal.style.display = 'none';
    }
    
    function openGestionePrenotazioni() {
      const modal = document.getElementById('gestione-prenotazioni-modal');
      const container = modal.querySelector('.modal-container');
      
      // Show modal
      modal.style.display = 'flex';
      
      // Load content
      fetch('modali/gestione-prenotazioni-modal.php')
        .then(response => response.text())
        .then(html => {
          container.innerHTML = html;
        })
        .catch(error => {
          console.error('Error loading modal content:', error);
          container.innerHTML = '<div class="error-message">Errore nel caricamento del contenuto</div>';
        });
    }
    
    function manageEmployee(employeeName) {
      // TODO: Implement employee management modal
      alert(`Gestione dipendente: ${employeeName}`);
    }
    
    function closeModal() {
      const modals = document.querySelectorAll('.modal-overlay');
      modals.forEach(modal => {
        modal.style.display = 'none';
      });
    }
    
    // Close modal when clicking outside
    document.addEventListener('click', function(event) {
      if (event.target.classList.contains('modal-overlay')) {
        closeModal();
      }
    });
    
    // Close modal with ESC key
    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') {
        closeModal();
      }
    });
  </script>
</body>
</html>