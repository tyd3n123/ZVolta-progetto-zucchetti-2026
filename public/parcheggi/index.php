<?php
require_once __DIR__ . "/../../config/config.php";
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Parcheggi | Z Volta</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="./parcheggi.css">
  <style>
    body {
      background-color: #7A288A;
    }
  </style>
</head>
<body>
  <header class="header">
    <div style="display: flex; align-items: center; gap: 15px;">
      <a href="hp.php" style="text-decoration: none; color: inherit;">
        <h1>Northstar</h1>
      </a>
      <a href="../dashboard/index.php" style="color: #7A288A; text-decoration: none; font-size: 14px; font-weight: bold;">
        | Dashboard
      </a>
    </div>
    <div class="user-info">
      <p>User: Mattia Carta</p>
      <p>Ruolo: Admin</p>
    </div>
  </header>

  <div class="dashboard-container">
    <div class="column">
      <h2>Prenotazione Parcheggio</h2>
      
      <div class="form-group">
        <label for="select-parking">Seleziona Parcheggio:</label>
        <select id="select-parking">
          <option>-- Scegli un parcheggio --</option>
          <option>Parcheggio 1</option>
          <option>Parcheggio 2</option>
          <option>Parcheggio 3</option>
          <option>Parcheggio 4</option>
          <option>Parcheggio 5</option>
          <option>Parcheggio 6</option>
          <option>Parcheggio 7</option>
          <option>Parcheggio 8</option>
          <option>Parcheggio 9</option>
          <option>Parcheggio 10</option>
        </select>
      </div>

      <div class="form-group">
        <label for="start-date">Data Inizio:</label>
        <input type="date" id="start-date">
      </div>

      <div class="form-group">
        <label for="end-date">Data Fine:</label>
        <input type="date" id="end-date">
      </div>

      <div class="form-group">
        <label for="vehicle-type">Tipo Veicolo:</label>
        <select id="vehicle-type">
          <option>-- Seleziona tipo --</option>
          <option>Auto</option>
          <option>Moto</option>
        </select>
      </div>

      <button class="book-btn">Effettua Prenotazione</button>
    </div>

    <div class="column">
      <h2>Dettagli Parcheggio</h2>
      
      <div class="details-card">
        <h4>Parcheggio selezionato: Nessuno</h4>
        <p>Posti disponibili: -</p>
        <p>Caratteristiche: -</p>
      </div>

      <h2>Le tue prenotazioni attive</h2>
      <div class="booking-list">
        <p>Nessuna prenotazione attiva.</p>
      </div>

      <h2>Elenco Parcheggi</h2>
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
            <td>PR001</td>
            <td>Parcheggio 1</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
          <tr>
            <td>PR002</td>
            <td>Parcheggio 2</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
          <tr>
            <td>PR003</td>
            <td>Parcheggio 3</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
          <tr>
            <td>PR004</td>
            <td>Parcheggio 4</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
          <tr>
            <td>PR005</td>
            <td>Parcheggio 5</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
          <tr>
            <td>PR006</td>
            <td>Parcheggio 6</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
          <tr>
            <td>PR007</td>
            <td>Parcheggio 7</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
          <tr>
            <td>PR008</td>
            <td>Parcheggio 8</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
          <tr>
            <td>PR009</td>
            <td>Parcheggio 9</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
          <tr>
            <td>PR010</td>
            <td>Parcheggio 10</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
        </tbody>
      </table>

      <button class="show-more-btn" onclick="location.href='../dashboard/index.php'">Torna alla dashboard</button>
    </div>
  </div>

  <script>
    document.getElementById('select-parking').addEventListener('change', function() {
      const selectedParking = this.value;
      const detailsCard = document.querySelector('.details-card');
      
      if (selectedParking !== '-- Scegli un parcheggio --') {
        const parkingData = {
          'Parcheggio 1': {caratteristiche: 'Coperto, Videosorveglianza, Accesso disabili' },
          'Parcheggio 2': {caratteristiche: 'Scoperto, Illuminato' },
          'Parcheggio 3': {caratteristiche: 'Scoperto, Vicino ingresso' },
          'Parcheggio 4': {caratteristiche: 'Coperto, Ricarica elettrica, Videosorveglianza' },
          'Parcheggio 5': {caratteristiche: 'Scoperto, Area riservata dipendenti' },
          'Parcheggio 6': {caratteristiche: 'Coperto, Piani superiori, Ascensore' },
          'Parcheggio 7': {caratteristiche: 'Scoperto, Zona ospiti, Custodia 24h' },
          'Parcheggio 8': {caratteristiche: 'Coperto, Moto solo, Riscaldato' },
          'Parcheggio 9': {caratteristiche: 'Scoperto, Area eventi, Grande dimensione' },
          'Parcheggio 10': {caratteristiche: 'Coperto, Area ricarica' }
        };
        
        const data = parkingData[selectedParking];
        detailsCard.innerHTML = `
          <h4>Parcheggio selezionato: ${selectedParking}</h4>
          <p>Caratteristiche: ${data.caratteristiche}</p>
        `;
      } else {
        detailsCard.innerHTML = `
          <h4>Parcheggio selezionato: Nessuno</h4>
          <p>Caratteristiche: -</p>
        `;
      }
    });

    document.querySelector('.book-btn').addEventListener('click', function() {
      const parking = document.getElementById('select-parking').value;
      const start = document.getElementById('start-date').value;
      const end = document.getElementById('end-date').value;
      const vehicle = document.getElementById('vehicle-type').value;
      
      if (parking === '-- Scegli un parcheggio --' || !start || !end || vehicle === '-- Seleziona tipo --') {
        alert('Per favore compila tutti i campi');
        return;
      }
      
      const bookingList = document.querySelector('.booking-list');
      const newBooking = `
        <div style="background: white; padding: 15px; margin-bottom: 10px; border-radius: 8px; border-left: 4px solid #667eea;">
          <h4 style="margin-bottom: 8px;">${parking}</h4>
          <p style="margin: 4px 0; font-size: 14px;">Veicolo: ${vehicle}</p>
          <p style="margin: 4px 0; font-size: 14px;">Inizio: ${new Date(start).toLocaleDateString('it-IT')}</p>
          <p style="margin: 4px 0; font-size: 14px;">Fine: ${new Date(end).toLocaleDateString('it-IT')}</p>
        </div>
      `;
      
      if (bookingList.innerHTML.includes('Nessuna prenotazione attiva.')) {
        bookingList.innerHTML = newBooking;
      } else {
        bookingList.innerHTML += newBooking;
      }
      
      // Reset form
      document.getElementById('select-parking').value = '-- Scegli un parcheggio --';
      document.getElementById('start-date').value = '';
      document.getElementById('end-date').value = '';
      document.getElementById('vehicle-type').value = '-- Seleziona tipo --';
      document.getElementById('select-parking').dispatchEvent(new Event('change'));
    });
  </script>
</body>
</html>