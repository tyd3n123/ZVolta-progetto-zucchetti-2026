function showEditForm(id) {
    document.getElementById('edit-form-' + id).style.display = 'table-row';
}

function hideEditForm(id) {
    document.getElementById('edit-form-' + id).style.display = 'none';
}

function updateBooking(id) {
    const dataInizio = document.getElementById('start-' + id).value;
    const dataFine = document.getElementById('end-' + id).value;
    
    if (!dataInizio || !dataFine) {
        alert('Per favore compila tutti i campi');
        return;
    }
    
    if (new Date(dataFine) <= new Date(dataInizio)) {
        alert('La data di fine deve essere successiva alla data di inizio');
        return;
    }
    
    fetch('gestione-prenotazioni-modal.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=update&id_prenotazione=${id}&data_inizio=${dataInizio}&data_fine=${dataFine}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Prenotazione aggiornata con successo');
            location.reload();
        } else {
            alert('Errore durante l\'aggiornamento: ' + (data.error || 'Errore sconosciuto'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Errore durante la comunicazione con il server');
    });
}

function deleteBooking(id) {
    if (confirm('Sei sicuro di voler eliminare questa prenotazione?')) {
        fetch('gestione-prenotazioni-modal.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=delete&id_prenotazione=${id}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Prenotazione eliminata con successo');
                location.reload();
            } else {
                alert('Errore durante l\'eliminazione');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Errore durante la comunicazione con il server');
        });
    }
}
