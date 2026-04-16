<?php
session_start();
require_once __DIR__ . "/../../../../config/config.php";

// ── Auth + Coordinatore check ─────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autenticato.']);
    exit();
}

$id_utente = (int)$_SESSION['id_utente'];

$stmt = $conn->prepare("SELECT r.nome_ruolo FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ?");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$role = $stmt->get_result()->fetch_assoc()['nome_ruolo'] ?? '';
$stmt->close();

if (strtolower($role) !== 'coordinatore') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accesso negato.']);
    exit();
}

// ── POST: modifica prenotazione (solo propria) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    header('Content-Type: application/json');

    $id_prenotazione = (int)($_POST['id_prenotazione'] ?? 0);
    $data_inizio     = trim($_POST['data_inizio'] ?? '');
    $data_fine       = trim($_POST['data_fine']   ?? '');

    if (!$id_prenotazione || !$data_inizio || !$data_fine) {
        echo json_encode(['success' => false, 'error' => 'Parametri mancanti.']);
        exit();
    }

    $now      = new DateTime();
    $dtInizio = new DateTime($data_inizio);
    $dtFine   = new DateTime($data_fine);

    if ($dtInizio < $now) {
        echo json_encode(['success' => false, 'error' => 'Non puoi impostare una data di inizio nel passato.']);
        exit();
    }

    if ($dtFine <= $dtInizio) {
        echo json_encode(['success' => false, 'error' => 'La data di fine deve essere successiva alla data di inizio.']);
        exit();
    }

    // Orario consentito: 09:00 – 19:00
    $oreInizio = (int)$dtInizio->format('H') * 60 + (int)$dtInizio->format('i');
    $oreFine   = (int)$dtFine->format('H')   * 60 + (int)$dtFine->format('i');
    $oreAp     = 9  * 60;   // 09:00
    $oreChius  = 19 * 60;   // 19:00

    if ($oreInizio < $oreAp || $oreInizio >= $oreChius) {
        echo json_encode(['success' => false, 'error' => 'L\'orario di inizio deve essere compreso tra le 09:00 e le 19:00.']);
        exit();
    }

    if ($oreFine > $oreChius || $oreFine <= $oreAp) {
        echo json_encode(['success' => false, 'error' => 'L\'orario di fine deve essere compreso tra le 09:00 e le 19:00.']);
        exit();
    }

    // Ownership check + recupera id_asset
    $stmt = $conn->prepare("SELECT id_asset FROM prenotazioni WHERE id_prenotazione = ? AND id_utente = ?");
    $stmt->bind_param("ii", $id_prenotazione, $id_utente); $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        echo json_encode(['success' => false, 'error' => 'Prenotazione non trovata o non autorizzato.']);
        exit();
    }

    // Controllo sovrapposizioni con altre prenotazioni dello stesso asset
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM prenotazioni
         WHERE id_asset = ? AND id_prenotazione != ? AND data_inizio < ? AND data_fine > ?"
    );
    $stmt->bind_param("iiss", $booking['id_asset'], $id_prenotazione, $data_fine, $data_inizio);
    $stmt->execute();
    $overlap = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    if ($overlap > 0) {
        echo json_encode(['success' => false, 'error' => 'L\'asset è già prenotato nel periodo selezionato.']);
        exit();
    }

    $stmt = $conn->prepare("UPDATE prenotazioni SET data_inizio = ?, data_fine = ? WHERE id_prenotazione = ? AND id_utente = ?");
    $stmt->bind_param("ssii", $data_inizio, $data_fine, $id_prenotazione, $id_utente);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Prenotazione aggiornata con successo.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Errore durante l\'aggiornamento.']);
    }
    $stmt->close();
    exit();
}

// ── POST: annulla prenotazione (solo propria) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    header('Content-Type: application/json');

    $id_prenotazione = (int)($_POST['id_prenotazione'] ?? 0);
    if (!$id_prenotazione) {
        echo json_encode(['success' => false, 'error' => 'ID prenotazione non valido.']);
        exit();
    }

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare("SELECT id_asset FROM prenotazioni WHERE id_prenotazione = ? AND id_utente = ?");
        $stmt->bind_param("ii", $id_prenotazione, $id_utente);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$booking) {
            throw new Exception('Prenotazione non trovata o non autorizzato.');
        }

        $stmt = $conn->prepare("DELETE FROM prenotazioni WHERE id_prenotazione = ? AND id_utente = ?");
        $stmt->bind_param("ii", $id_prenotazione, $id_utente);
        if (!$stmt->execute()) throw new Exception('Errore durante l\'annullamento della prenotazione.');
        $stmt->close();

        // Aggiorna stato asset solo se non ha altre prenotazioni future
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE id_asset = ? AND data_fine >= NOW()");
        $stmt->bind_param("i", $booking['id_asset']); $stmt->execute();
        $remaining = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        if ($remaining === 0) {
            $stmt = $conn->prepare("UPDATE asset SET stato = 'Disponibile' WHERE id_asset = ?");
            $stmt->bind_param("i", $booking['id_asset']);
            if (!$stmt->execute()) throw new Exception('Errore durante l\'aggiornamento dello stato.');
            $stmt->close();
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Prenotazione annullata con successo.']);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── GET: restituisce HTML del modale ──────────────────
$userBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine,
            a.codice_asset,
            COALESCE(t.descrizione, 'Parcheggio') AS nome_tipologia
     FROM prenotazioni p
     JOIN asset a ON p.id_asset = a.id_asset
     LEFT JOIN tipologie_asset t ON a.id_tipologia = t.id_tipologia
     WHERE p.id_utente = ?
     ORDER BY p.data_inizio DESC"
);
$stmt->bind_param("i", $id_utente); $stmt->execute();
$r = $stmt->get_result(); while ($row = $r->fetch_assoc()) $userBookings[] = $row; $stmt->close();
?>
<div class="modal-content">
    <div class="modal-header">
        <h3>Le Tue Prenotazioni</h3>
        <button class="modal-close" onclick="closeUserBookingsModal()">&times;</button>
    </div>

    <div class="modal-body">
        <?php if (empty($userBookings)): ?>
            <div class="empty-state">
                <h4>Nessuna prenotazione presente</h4>
                <p>Non hai ancora effettuato nessuna prenotazione.</p>
            </div>
        <?php else: ?>
            <div class="bookings-list-container">
                <?php foreach ($userBookings as $b):
                    $start    = new DateTime($b['data_inizio']);
                    $end      = new DateTime($b['data_fine']);
                    $duration = $start->diff($end);
                    $now      = new DateTime();
                    $isPast   = $end < $now;
                ?>
                <div class="booking-item-detail">
                    <div class="booking-header">
                        <div style="display:flex;align-items:center;gap:10px">
                            <h4><?= htmlspecialchars($b['codice_asset']) ?></h4>
                            <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;
                                         background:var(--clr-surface-3);color:var(--clr-text-2)">
                                <?= htmlspecialchars($b['nome_tipologia']) ?>
                            </span>
                        </div>
                        <span class="booking-id">#<?= $b['id_prenotazione'] ?></span>
                    </div>
                    <div class="booking-details">
                        <div class="detail-item">
                            <i class="icon">📅</i>
                            <div>
                                <strong>Data Inizio</strong>
                                <?= date('d/m/Y H:i', strtotime($b['data_inizio'])) ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <i class="icon">🕐</i>
                            <div>
                                <strong>Data Fine</strong>
                                <?= date('d/m/Y H:i', strtotime($b['data_fine'])) ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <i class="icon">⏱️</i>
                            <div>
                                <strong>Durata</strong>
                                <?= $duration->format('%h ore %i minuti') ?>
                            </div>
                        </div>
                    </div>
                    <?php if (!$isPast): ?>
                    <div class="booking-actions">
                        <button class="btn-modify" onclick="showUserEditForm(<?= $b['id_prenotazione'] ?>)">Modifica</button>
                        <button class="btn-cancel" onclick="cancelBooking(<?= $b['id_prenotazione'] ?>)">Annulla prenotazione</button>
                    </div>
                    <div id="user-edit-form-<?= $b['id_prenotazione'] ?>" class="booking-edit-panel" style="display:none">
                        <p class="booking-edit-title">Modifica prenotazione</p>
                        <div id="user-edit-error-<?= $b['id_prenotazione'] ?>" class="booking-edit-error"></div>
                        <div class="booking-edit-grid">
                            <div class="form-group">
                                <label for="user-edit-start-<?= $b['id_prenotazione'] ?>">Data Inizio</label>
                                <input type="datetime-local"
                                       id="user-edit-start-<?= $b['id_prenotazione'] ?>"
                                       value="<?= date('Y-m-d\TH:i', strtotime($b['data_inizio'])) ?>">
                            </div>
                            <div class="form-group">
                                <label for="user-edit-end-<?= $b['id_prenotazione'] ?>">Data Fine</label>
                                <input type="datetime-local"
                                       id="user-edit-end-<?= $b['id_prenotazione'] ?>"
                                       value="<?= date('Y-m-d\TH:i', strtotime($b['data_fine'])) ?>">
                            </div>
                        </div>
                        <div class="form-buttons">
                            <button class="btn-save" onclick="updateUserBooking(<?= $b['id_prenotazione'] ?>)">Salva modifiche</button>
                            <button class="btn-ghost" onclick="hideUserEditForm(<?= $b['id_prenotazione'] ?>)">Annulla</button>
                        </div>
                    </div>
                    <?php else: ?>
                    <div style="padding-top:12px;border-top:1px solid var(--clr-border)">
                        <span style="font-size:11px;color:var(--clr-text-3);font-weight:500">Prenotazione conclusa</span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
