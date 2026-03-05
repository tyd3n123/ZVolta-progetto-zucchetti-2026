<?php
require_once __DIR__ . "/../../config/config.php";
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Uffici | Z Volta</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="./uffici.css">
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
      <h2>Prenotazione Ufficio</h2>
      
      <div class="form-group">
        <label for="select-office">Seleziona Ufficio:</label>
        <select id="select-office">
          <option>-- Scegli un ufficio --</option>
          <option>Ufficio A101</option>
          <option>Ufficio A102</option>
          <option>Ufficio B201</option>
          <option>Ufficio B202</option>
          <option>Ufficio C301</option>
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
        <label for="work-type">Tipologia Lavoro:</label>
        <select id="work-type">
          <option>-- Seleziona tipologia --</option>
          <option>Lavoro in presenza</option>
          <option>Smart working</option>
          <option>Ibrido</option>
        </select>
      </div>

      <div class="form-group">
        <label for="people-count">Numero Persone:</label>
        <input type="number" id="people-count" min="1" max="10" placeholder="1-10">
      </div>

      <button class="book-btn">Effettua Prenotazione</button>
    </div>

    <div class="column">
      <h2>Dettagli Ufficio</h2>
      
      <div class="details-card">
        <h4>Ufficio selezionato: Nessuno</h4>
        <p>Postazioni: -</p>
        <p>Piano: -</p>
        <p>Attività: -</p>
        <p>Servizi: -</p>
      </div>

      <h2>Le tue prenotazioni attive</h2>
      <div class="booking-list">
        <p>Nessuna prenotazione attiva.</p>
      </div>

      <h2>Elenco Uffici</h2>
      <table class="parking-table">
        <thead>
          <tr>
            <th>Codice</th>
            <th>Descrizione</th>
            <th>Postazioni</th>
            <th>Stato</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>UF001</td>
            <td>Ufficio A101</td>
            <td>4</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
          <tr>
            <td>UF002</td>
            <td>Ufficio A102</td>
            <td>6</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
          <tr>
            <td>UF003</td>
            <td>Ufficio B201</td>
            <td>8</td>
            <td><span class="status-badge occupato">Occupato</span></td>
          </tr>
          <tr>
            <td>UF004</td>
            <td>Ufficio B202</td>
            <td>4</td>
            <td><span class="status-badge disponibile">Disponibile</span></td>
          </tr>
          <tr>
            <td>UF005</td>
            <td>Ufficio C301</td>
            <td>10</td>
            <td><span class="status-badge occupato">Occupato</span></td>
          </tr>
        </tbody>
      </table>

      <button class="show-more-btn" onclick="location.href='dashboard.php'">Torna alla dashboard</button>
    </div>
  </div>

  <script>
    document.getElementById('select-office').addEventListener('change', function() {
      const selectedOffice = this.value;
      const detailsCard = document.querySelector('.details-card');
      
      if (selectedOffice !== '-- Scegli un ufficio --') {
        const officeData = {
          'Ufficio A101': { postazioni: '4 postazioni', piano: 'Piano 1', attività: 'Sviluppo Software', servizi: 'Monitor doppio, Aria condizionata, Caffetteria' },
          'Ufficio A102': { postazioni: '6 postazioni', piano: 'Piano 1', attività: 'Marketing', servizi: 'Spazio riunioni, Videochiamate, Stampante' },
          'Ufficio B201': { postazioni: '8 postazioni', piano: 'Piano 2', attività: 'Amministrazione', servizi: 'Archivio, Telefono, Accesso disabili' },
          'Ufficio B202': { postazioni: '4 postazioni', piano: 'Piano 2', attività: 'Ricerca e Sviluppo', servizi: 'Laboratorio, Attrezzature speciali, Sicurezza' },
          'Ufficio C301': { postazioni: '10 postazioni', piano: 'Piano 3', attività: 'Customer Service', servizi: 'Open space, Call center, Relax area' }
        };
        
        const data = officeData[selectedOffice];
        detailsCard.innerHTML = `
          <h4>Ufficio selezionato: ${selectedOffice}</h4>
          <p>Postazioni: ${data.postazioni}</p>
          <p>Piano: ${data.piano}</p>
          <p>Attività: ${data.attività}</p>
          <p>Servizi: ${data.servizi}</p>
        `;
      } else {
        detailsCard.innerHTML = `
          <h4>Ufficio selezionato: Nessuno</h4>
          <p>Postazioni: -</p>
          <p>Piano: -</p>
          <p>Attività: -</p>
          <p>Servizi: -</p>
        `;
      }
    });

    document.querySelector('.book-btn').addEventListener('click', function() {
      const office = document.getElementById('select-office').value;
      const start = document.getElementById('start-date').value;
      const end = document.getElementById('end-date').value;
      const workType = document.getElementById('work-type').value;
      const peopleCount = document.getElementById('people-count').value;
      
      if (office === '-- Scegli un ufficio --' || !start || !end || workType === '-- Seleziona tipologia --' || !peopleCount) {
        alert('Per favore compila tutti i campi');
        return;
      }
      
      const bookingList = document.querySelector('.booking-list');
      const newBooking = `
        <div style="background: white; padding: 15px; margin-bottom: 10px; border-radius: 8px; border-left: 4px solid #667eea;">
          <h4 style="margin-bottom: 8px;">${office}</h4>
          <p style="margin: 4px 0; font-size: 14px;">Tipologia: ${workType}</p>
          <p style="margin: 4px 0; font-size: 14px;">Persone: ${peopleCount}</p>
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
      document.getElementById('select-office').value = '-- Scegli un ufficio --';
      document.getElementById('start-date').value = '';
      document.getElementById('end-date').value = '';
      document.getElementById('work-type').value = '-- Seleziona tipologia --';
      document.getElementById('people-count').value = '';
      document.getElementById('select-office').dispatchEvent(new Event('change'));
    });
  </script>
</body>
</html>