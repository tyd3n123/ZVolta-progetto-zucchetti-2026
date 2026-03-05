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
      
      <div class="form-group">
        <label for="select-room">Seleziona Sala Riunioni:</label>
        <select id="select-room">
          <option>-- Scegli una sala --</option>
          <option>Sala Riunioni A</option>
          <option>Sala Riunioni B</option>
          <option>Sala Riunioni C</option>
          <option>Sala Riunioni D</option>
          <option>Sala Riunioni E</option>
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

      <button class="book-btn">Effettua Prenotazione</button>
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
        <p>Nessuna prenotazione attiva.</p>
      </div>

      <h2>Elenco Sale Riunioni</h2>
      <table class="parking-table">
        <thead>
          <tr>
            <th>Codice</th>
            <th>Descrizione</th>
            <th>Stato</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>SR001</td>
            <td>Sala Riunioni A</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
          <tr>
            <td>SR002</td>
            <td>Sala Riunioni B</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
          <tr>
            <td>SR003</td>
            <td>Sala Riunioni C</td>
            <td><span class="status-badge occupato">Occupato</span></td>
          </tr>
          <tr>
            <td>SR004</td>
            <td>Sala Riunioni D</td>
            <td><span class="status-badge occupato">Occupato</span></td>
          </tr>
          <tr>
            <td>SR005</td>
            <td>Sala Riunioni E</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
        </tbody>
      </table>

      <button class="show-more-btn" onclick="location.href='../dashboard/index.php'">Torna alla dashboard</button>
    </div>
  </div>

  <script>
    document.getElementById('select-room').addEventListener('change', function() {
      const selectedRoom = this.value;
      const detailsCard = document.querySelector('.details-card');
      
      if (selectedRoom !== '-- Scegli una sala --') {
        const roomData = {
          'Sala Riunioni A': { capacità: '10 persone', attrezzatura: 'Proiettore, Lavagna, Videochiamata', disponibilità: '09:00 - 18:00' },
          'Sala Riunioni B': { capacità: '8 persone', attrezzatura: 'Schermo, Whiteboard', disponibilità: '09:00 - 18:00' },
          'Sala Riunioni C': { capacità: '15 persone', attrezzatura: 'Proiettore, Sistema audio', disponibilità: '09:00 - 18:00' },
          'Sala Riunioni D': { capacità: '6 persone', attrezzatura: 'Monitor, Videochiamata', disponibilità: '09:00 - 18:00' },
          'Sala Riunioni E': { capacità: '20 persone', attrezzatura: 'Proiettore, Lavagna, Sistema audio', disponibilità: '09:00 - 18:00' }
        };
        
        const data = roomData[selectedRoom];
        detailsCard.innerHTML = `
          <h4>Sala selezionata: ${selectedRoom}</h4>
          <p>Capacità: ${data.capacità}</p>
          <p>Attrezzatura: ${data.attrezzatura}</p>
          <p>Disponibilità: ${data.disponibilità}</p>
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

    document.querySelector('.book-btn').addEventListener('click', function() {
      const room = document.getElementById('select-room').value;
      const start = document.getElementById('start-datetime').value;
      const end = document.getElementById('end-datetime').value;
      
      if (room === '-- Scegli una sala --' || !start || !end) {
        alert('Per favore compila tutti i campi');
        return;
      }
      
      const bookingList = document.querySelector('.booking-list');
      const newBooking = `
        <div style="background: white; padding: 15px; margin-bottom: 10px; border-radius: 8px; border-left: 4px solid #667eea;">
          <h4 style="margin-bottom: 8px;">${room}</h4>
          <p style="margin: 4px 0; font-size: 14px;">Inizio: ${new Date(start).toLocaleString('it-IT')}</p>
          <p style="margin: 4px 0; font-size: 14px;">Fine: ${new Date(end).toLocaleString('it-IT')}</p>
        </div>
      `;
      
      if (bookingList.innerHTML.includes('Nessuna prenotazione attiva.')) {
        bookingList.innerHTML = newBooking;
      } else {
        bookingList.innerHTML += newBooking;
      }
      
      // Reset form
      document.getElementById('select-room').value = '-- Scegli una sala --';
      document.getElementById('start-datetime').value = '';
      document.getElementById('end-datetime').value = '';
      document.getElementById('select-room').dispatchEvent(new Event('change'));
    });
  </script>
</body>
</html>