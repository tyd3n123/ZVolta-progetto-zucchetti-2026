
<?php
require_once __DIR__ . "/../config/config.php";
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Dashboard | Z Volta</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="dashboard.css">
</head>
<body>
  <header class="header">
    <h1>Northstar</h1>
    <div class="user-info">
      <p>User: Mattia Carta</p>
      <p>Ruolo: Admin</p>
    </div>
  </header>

  <div class="dashboard-container">
    <div class="column">
      <h2>Prenota</h2>
      
      <div class="meeting-room" >
        <div class="meeting-room-content">
          <h3>Sale riunioni</h3>
          <button class="book-btn" onclick="location.href='sale-riunioni.php'">Prenota→</button>
        </div>
      </div>

      <div class="meeting-room">
        <div class="meeting-room-content">
          <h3>Uffici</h3>
          <button class="book-btn">Prenota→</button>
        </div>
      </div>

      <div class="meeting-room">
        <div class="meeting-room-content">
          <h3>Parcheggi</h3>
          <button class="book-btn">Prenota→</button>
        </div>
      </div>

      <div class="meeting-room">
        <div class="meeting-room-content">
          <h3></h3>
          <button class="book-btn">Prenota→</button>
        </div>
      </div>
    </div>

    <div class="column">
      <h2>Le tue prenotazioni</h2>
      
      <div class="booking-item">
        <h4>Sala Riunioni A</h4>
        <div class="booking-details">
          <p>Piano: 1°</p>
          <p>Ora: 09:00 - 10:00</p>
          <p>Data: 05/03/2026</p>
        </div>
      </div>

      <div class="booking-item">
        <h4>Sala Riunioni C</h4>
        <div class="booking-details">
          <p>Piano: 2°</p>
          <p>Ora: 14:00 - 15:30</p>
          <p>Data: 05/03/2026</p>
        </div>
      </div>

      <div class="booking-item">
        <h4>Sala Riunioni B</h4>
        <div class="booking-details">
          <p>Piano: 1°</p>
          <p>Ora: 16:00 - 17:00</p>
          <p>Data: 06/03/2026</p>
        </div>
      </div>

      <div class="placeholder"></div>
      <div class="placeholder"></div>
      <div class="placeholder"></div>

      <button class="show-more-btn">Mostra di più</button>
    </div>
  </div>
</body>
</html>