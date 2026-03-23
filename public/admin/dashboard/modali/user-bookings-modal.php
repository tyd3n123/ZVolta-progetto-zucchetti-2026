<?php
session_start();
require_once __DIR__ . "/../../../../config/config.php";

// ── Auth check ────────────────────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autenticato.']);
    exit();
}

$id_utente = (int)$_SESSION['id_utente'];

// ── POST: cancel ──────────────────────────────────────
// Gestito prima dell'ob_start per poter restituire JSON pulito
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    header('Content-Type: application/json');

    $id_prenotazione = (int)($_POST['id_prenotazione'] ?? 0);
    if (!$id_prenotazione) {
        echo json_encode(['success' => false, 'error' => 'ID prenotazione non valido.']);
        exit();
    }

    try {
        $conn->begin_transaction();

        // Verifica che la prenotazione appartenga all'utente e recupera id_asset
        $stmt = $conn->prepare("SELECT id_asset FROM prenotazioni WHERE id_prenotazione = ? AND id_utente = ?");
        $stmt->bind_param("ii", $id_prenotazione, $id_utente);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$booking) {
            throw new Exception('Prenotazione non trovata o non autorizzato.');
        }

        // Elimina prenotazione
        $stmt = $conn->prepare("DELETE FROM prenotazioni WHERE id_prenotazione = ? AND id_utente = ?");
        $stmt->bind_param("ii", $id_prenotazione, $id_utente);
        if (!$stmt->execute()) throw new Exception('Errore durante l\'annullamento della prenotazione.');
        $stmt->close();

        // Rende l'asset disponibile
        $stmt = $conn->prepare("UPDATE asset SET stato = 'Disponibile' WHERE id_asset = ?");
        $stmt->bind_param("i", $booking['id_asset']);
        if (!$stmt->execute()) throw new Exception('Errore durante l\'aggiornamento dello stato dell\'asset.');
        $stmt->close();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Prenotazione annullata e asset reso disponibile.']);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── GET: restituisce HTML del modale ──────────────────
$userBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset
     FROM prenotazioni p
     JOIN asset a ON p.id_asset = a.id_asset
     WHERE p.id_utente = ?
     ORDER BY p.data_inizio DESC"
);
$stmt->bind_param("i", $id_utente);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $userBookings[] = $row;
$stmt->close();
?>
<div class="modal-content">
    <div class="modal-header">
        <h3>Le Tue Prenotazioni</h3>
        <button class="modal-close" onclick="closeUserBookingsModal()">&times;</button>
    </div>

    <div class="modal-body">
        <?php if (empty($userBookings)): ?>
            <div class="empty-state">
                <h4>Nessuna prenotazione disponibile</h4>
                <p>Non hai ancora effettuato nessuna prenotazione.</p>
            </div>
        <?php else: ?>
            <div class="bookings-list-container">
                <?php foreach ($userBookings as $b): ?>
                    <?php
                        $start    = new DateTime($b['data_inizio']);
                        $end      = new DateTime($b['data_fine']);
                        $duration = $start->diff($end);
                    ?>
                    <div class="booking-item-detail">
                        <div class="booking-header">
                            <h4><?= htmlspecialchars($b['codice_asset']) ?></h4>
                            <span class="booking-id">#<?= $b['id_prenotazione'] ?></span>
                        </div>
                        <div class="booking-details">
                            <div class="detail-item">
                                <i class="icon">📅</i>
                                <div>
                                    <strong>Data Inizio:</strong><br>
                                    <?= date('d/m/Y H:i', strtotime($b['data_inizio'])) ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <i class="icon">🕐</i>
                                <div>
                                    <strong>Data Fine:</strong><br>
                                    <?= date('d/m/Y H:i', strtotime($b['data_fine'])) ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <i class="icon">⏱️</i>
                                <div>
                                    <strong>Durata:</strong><br>
                                    <?= $duration->format('%h ore %i minuti') ?>
                                </div>
                            </div>
                        </div>
                        <div class="booking-actions">
                            <button class="btn-cancel" onclick="cancelBooking(<?= $b['id_prenotazione'] ?>)">Annulla</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="modali/user-bookings-modal.js"></script>