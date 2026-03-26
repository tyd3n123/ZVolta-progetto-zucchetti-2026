<?php
session_start();
require_once __DIR__ . "/../../../../config/config.php";

// ── Auth: login + admin check ─────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autenticato.']);
    exit();
}

$stmt = $conn->prepare("SELECT r.nome_ruolo FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ?");
$stmt->bind_param("i", $_SESSION['id_utente']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || strtolower($user['nome_ruolo']) !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accesso negato.']);
    exit();
}

// ── POST: update ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    header('Content-Type: application/json');

    $id_prenotazione = (int)($_POST['id_prenotazione'] ?? 0);
    $data_inizio     = $_POST['data_inizio'] ?? '';
    $data_fine       = $_POST['data_fine']   ?? '';

    if (!$id_prenotazione || !$data_inizio || !$data_fine) {
        echo json_encode(['success' => false, 'error' => 'Parametri mancanti.']);
        exit();
    }

    if (strtotime($data_fine) <= strtotime($data_inizio)) {
        echo json_encode(['success' => false, 'error' => 'La data di fine deve essere successiva alla data di inizio.']);
        exit();
    }

    $stmt = $conn->prepare("UPDATE prenotazioni SET data_inizio = ?, data_fine = ? WHERE id_prenotazione = ?");
    $stmt->bind_param("ssi", $data_inizio, $data_fine, $id_prenotazione);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Prenotazione aggiornata con successo.']);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
    $stmt->close();
    exit();
}

// ── GET: restituisce HTML del modale ──────────────────
$allBookings = [];
$result = $conn->query(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine,
            a.codice_asset, a.id_asset,
            u.nome, u.cognome, r.nome_ruolo
     FROM prenotazioni p
     JOIN asset   a ON p.id_asset   = a.id_asset
     JOIN utenti  u ON p.id_utente  = u.id_utente
     JOIN ruoli   r ON u.id_ruolo   = r.id_ruolo
     ORDER BY p.data_inizio DESC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) $allBookings[] = $row;
}
?>
<div class="modal-content">
    <div class="modal-header">
        <h3>Gestione Prenotazioni</h3>
        <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>

    <div class="modal-body">
        <?php if (empty($allBookings)): ?>
            <div class="empty-state">
                <h4>Nessuna prenotazione presente</h4>
                <p>Non ci sono prenotazioni attive nel sistema.</p>
            </div>
        <?php else: ?>
            <div class="bookings-table-container">
                <table class="bookings-table">
                    <thead>
                        <tr>
                            <th>Asset</th>
                            <th>Utente</th>
                            <th>Data Inizio</th>
                            <th>Data Fine</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allBookings as $b): ?>
                            <tr>
                                <td><?= htmlspecialchars($b['codice_asset']) ?></td>
                                <td>
                                    <?= htmlspecialchars($b['nome'] . ' ' . $b['cognome']) ?>
                                    <span class="user-badge"><?= htmlspecialchars($b['nome_ruolo']) ?></span>
                                </td>
                                <td><?= date('d/m/Y H:i', strtotime($b['data_inizio'])) ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($b['data_fine'])) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-edit"   onclick="showEditForm(<?= $b['id_prenotazione'] ?>)">Modifica</button>
                                        <button class="btn-delete" onclick="deleteBooking(<?= $b['id_prenotazione'] ?>)">Elimina</button>
                                    </div>
                                </td>
                            </tr>
                            <tr id="edit-form-<?= $b['id_prenotazione'] ?>" class="edit-row" style="display:none;">
                                <td colspan="5">
                                    <div class="edit-form">
                                        <h4>Modifica Prenotazione #<?= $b['id_prenotazione'] ?></h4>
                                        <div class="form-group">
                                            <label for="start-<?= $b['id_prenotazione'] ?>">Data Inizio:</label>
                                            <input type="datetime-local"
                                                   id="start-<?= $b['id_prenotazione'] ?>"
                                                   value="<?= date('Y-m-d\TH:i', strtotime($b['data_inizio'])) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="end-<?= $b['id_prenotazione'] ?>">Data Fine:</label>
                                            <input type="datetime-local"
                                                   id="end-<?= $b['id_prenotazione'] ?>"
                                                   value="<?= date('Y-m-d\TH:i', strtotime($b['data_fine'])) ?>">
                                        </div>
                                        <div class="form-buttons">
                                            <button class="btn-save"   onclick="updateBooking(<?= $b['id_prenotazione'] ?>)">Salva</button>
                                            <button class="btn-cancel" onclick="hideEditForm(<?= $b['id_prenotazione'] ?>)">Annulla</button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Il JS viene caricato una sola volta dalla pagina principale tramite index.php -->