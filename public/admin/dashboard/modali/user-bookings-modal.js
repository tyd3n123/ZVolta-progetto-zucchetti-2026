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
            // Ricarica il contenuto del modale senza ricaricare l'intera pagina
            reloadUserBookingsModal();
        } else {
            alert('Errore: ' + (data.error || 'Errore sconosciuto.'));
        }
    })
    .catch(() => alert('Errore durante la comunicazione con il server.'));
}

function reloadUserBookingsModal() {
    const modal     = document.getElementById('user-bookings-modal');
    const container = modal.querySelector('.modal-container');
    fetch('modali/user-bookings-modal.php')
        .then(r => r.text())
        .then(html => { container.innerHTML = html; })
        .catch(() => { container.innerHTML = '<div class="error-message">Errore nel ricaricamento del contenuto.</div>'; });
}