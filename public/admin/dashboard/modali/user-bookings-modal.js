function modifyBooking(id) {
    // TODO: Implement modification functionality
    alert('Funzionalità di modifica in arrivo!');
}

function cancelBooking(id) {
    if (confirm('Sei sicuro di voler annullare questa prenotazione?')) {
        fetch('modali/user-bookings-modal.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=cancel&id_prenotazione=${id}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Prenotazione annullata con successo');
                location.reload();
            } else {
                alert('Errore durante l\'annullamento');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Errore durante la comunicazione con il server');
        });
    }
}
