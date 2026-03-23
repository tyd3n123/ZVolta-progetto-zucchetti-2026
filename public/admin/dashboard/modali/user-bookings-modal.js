// ── Modale utente: le tue prenotazioni ───────────────

function cancelBooking(id) {
    if (!confirm('Sei sicuro di voler annullare questa prenotazione?')) return;

    fetch('modali/user-bookings-modal.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=cancel&id_prenotazione=${id}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'Prenotazione annullata con successo.');
            location.reload();
        } else {
            alert('Errore: ' + (data.error || 'Errore sconosciuto.'));
        }
    })
    .catch(() => alert('Errore durante la comunicazione con il server.'));
}