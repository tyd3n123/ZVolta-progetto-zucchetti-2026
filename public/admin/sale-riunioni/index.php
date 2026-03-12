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

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book') {
    $id_asset = $_POST['id_asset'];
    $data_inizio = $_POST['data_inizio'];
    $data_fine = $_POST['data_fine'];
    
    if (isset($_SESSION['id_utente']) && $id_asset && $data_inizio && $data_fine) {
        // Insert booking into prenotazioni table
        $sql = "INSERT INTO prenotazioni (id_utente, id_asset, data_inizio, data_fine) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("iiss", $_SESSION['id_utente'], $id_asset, $data_inizio, $data_fine);
            
            if ($stmt->execute()) {
                // Update asset status to 'Occupato'
                $update_sql = "UPDATE asset SET stato = 'Occupato' WHERE id_asset = ?";
                $update_stmt = $conn->prepare($update_sql);
                
                if ($update_stmt) {
                    $update_stmt->bind_param("i", $id_asset);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
                
                // Redirect to avoid form resubmission
                header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
                exit();
            }
            $stmt->close();
        }
    }
}

// Fetch room details from database
$roomDetails = [];
$sql = "SELECT id_asset, capacita, attrezzatura, orario_apertura, orario_chiusura FROM sala_dettagli";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $roomDetails[$row['id_asset']] = [
            'capacita' => $row['capacita'],
            'attrezzatura' => $row['attrezzatura'],
            'disponibilita' => $row['orario_apertura'] . ' - ' . $row['orario_chiusura']
        ];
    }
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

// Fetch room names and status from asset table
$roomNames = [];
$sql = "SELECT id_asset, codice_asset, stato FROM asset";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $roomNames[$row['id_asset']] = [
            'name' => $row['codice_asset'],
            'status' => $row['stato']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Sale Riunioni | Z Volta</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="./sale-riunioni.css">
  <link rel="stylesheet" href="../dashboard/dashboard.css">
  <style>
    body {
      background-color: #7A288A;
    }
  </style>
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
      <h2>Prenotazione Sala Riunioni</h2>
      
      <form method="POST" id="booking-form">
        <input type="hidden" name="action" value="book">
        <input type="hidden" name="id_asset" id="hidden-id-asset">
        <input type="hidden" name="data_inizio" id="hidden-data-inizio">
        <input type="hidden" name="data_fine" id="hidden-data-fine">
        
        <div class="form-group">
          <label for="select-room">Seleziona Sala Riunioni:</label>
          <select id="select-room">
            <option>-- Scegli una sala --</option>
            <?php foreach ($roomNames as $id => $room): ?>
              <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($room['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="start-datetime">Data e Ora Inizio:</label>
          <input type="datetime-local" id="start-datetime">
        </div>

        <div class="form-group">
          <label for="end-datetime">Data e Ora Fine:</label>
          <input type="datetime-local" id="end-datetime">
        </div>

        <button type="submit" class="book-btn">Effettua Prenotazione</button>
      </form>
    </div>

    <div class="column">
      <h2>Dettagli Sala</h2>
      
      <div class="details-card">
        <h4>Sala selezionata: Nessuna</h4>
        <p>Capacità: -</p>
        <p>Attrezzatura: -</p>
        <p>Disponibilità: -</p>
      </div>

      <h2>Le tue prenotazioni attive</h2>
      <div class="booking-list">
        <?php if (empty($userBookings)): ?>
          <p>Nessuna prenotazione attiva.</p>
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

      <h2>Elenco Sale Riunioni</h2>
      <table class="parking-table">
        <thead>
          <tr>
            <th>ID Asset</th>
            <th>Nome Sala</th>
            <th>Stato</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($roomNames as $id => $room): ?>
            <tr>
              <td><?php echo $id; ?></td>
              <td><?php echo htmlspecialchars($room['name']); ?></td>
              <td><span class="status-badge <?php echo strtolower($room['status']); ?>"><?php echo htmlspecialchars($room['status']); ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <button class="show-more-btn" onclick="location.href='../dashboard/index.php'">Torna alla dashboard</button>
    </div>
  </div>

  <script>
    // Pass PHP data to JavaScript
    const roomDetails = <?php echo json_encode($roomDetails); ?>;
    const roomNames = <?php echo json_encode($roomNames); ?>;
    
    document.getElementById('select-room').addEventListener('change', function() {
      const selectedRoom = this.value;
      const detailsCard = document.querySelector('.details-card');
      
      if (selectedRoom !== '-- Scegli una sala --' && roomDetails[selectedRoom]) {
        const data = roomDetails[selectedRoom];
        const roomName = roomNames[selectedRoom]?.name || 'Sala ' + selectedRoom;
        detailsCard.innerHTML = `
          <h4>Sala selezionata: ${roomName}</h4>
          <p>Capacità: ${data.capacita}</p>
          <p>Attrezzatura: ${data.attrezzatura}</p>
          <p>Disponibilità: ${data.disponibilita}</p>
        `;
      } else {
        detailsCard.innerHTML = `
          <h4>Sala selezionata: Nessuna</h4>
          <p>Capacità: -</p>
          <p>Attrezzatura: -</p>
          <p>Disponibilità: -</p>
        `;
      }
    });

    // Handle form submission
    document.getElementById('booking-form').addEventListener('submit', function(e) {
      e.preventDefault();
      
      const room = document.getElementById('select-room').value;
      const start = document.getElementById('start-datetime').value;
      const end = document.getElementById('end-datetime').value;
      
      if (room === '-- Scegli una sala --' || !start || !end) {
        alert('Per favore compila tutti i campi');
        return;
      }
      
      // Set hidden fields
      document.getElementById('hidden-id-asset').value = room;
      document.getElementById('hidden-data-inizio').value = start;
      document.getElementById('hidden-data-fine').value = end;
      
      // Submit form
      this.submit();
    });
  </script>
</body>
</html>