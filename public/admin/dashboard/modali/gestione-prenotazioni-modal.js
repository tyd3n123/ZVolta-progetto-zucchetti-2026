// ── Modale admin: gestione prenotazioni ───────────────

function showEditForm(id) {
    document.getElementById('edit-form-' + id).style.display = 'table-row';
}

function hideEditForm(id) {
    document.getElementById('edit-form-' + id).style.display = 'none';
}

function updateBooking(id) {
    const dataInizio = document.getElementById('start-' + id).value;
    const dataFine   = document.getElementById('end-'   + id).value;

    if (!dataInizio || !dataFine) {
        alert('Per favore compila tutti i campi.');
        return;
    }
    if (new Date(dataFine) <= new Date(dataInizio)) {
        alert('La data di fine deve essere successiva alla data di inizio.');
        return;
    }

    fetch('modali/gestione-prenotazioni-modal.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=update&id_prenotazione=${id}&data_inizio=${encodeURIComponent(dataInizio)}&data_fine=${encodeURIComponent(dataFine)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'Prenotazione aggiornata con successo.');
            location.reload();
        } else {
            alert('Errore: ' + (data.error || 'Errore sconosciuto.'));
        }
    })
    .catch(() => alert('Errore durante la comunicazione con il server.'));
}

function deleteBooking(id) {
    if (!confirm('Sei sicuro di voler eliminare questa prenotazione?')) return;

    fetch('modali/elimina-prenotazioni.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete&id_prenotazione=${id}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'Prenotazione eliminata con successo.');
            location.reload();
        } else {
            alert('Errore: ' + (data.error || 'Errore sconosciuto.'));
        }
    })
    .catch(() => alert('Errore durante la comunicazione con il server.'));
}