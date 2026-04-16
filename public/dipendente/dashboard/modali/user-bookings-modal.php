<?php
session_start();
require_once __DIR__ . "/../../../../config/config.php";

// Aggiunge la colonna se non esiste ancora
$conn->query("ALTER TABLE prenotazioni ADD COLUMN IF NOT EXISTS num_modifiche TINYINT UNSIGNED NOT NULL DEFAULT 0");

// ── Auth + Dipendente check ────────────────────────────
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

if (strtolower($role) !== 'dipendente') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accesso negato.']);
    exit();
}

// ── POST: modifica prenotazione ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    header('Content-Type: application/json');

    $id_prenotazione = (int)($_POST['id_prenotazione'] ?? 0);
    $data_inizio     = trim($_POST['data_inizio'] ?? '');
    $data_fine       = trim($_POST['data_fine']   ?? '');

    if (!$id_prenotazione || !$data_inizio || !$data_fine) {
        echo json_encode(['success' => false, 'error' => 'Dati mancanti.']);
        exit();
    }

    // Verifica proprietà + contatore modifiche
    $stmt = $conn->prepare(
        "SELECT id_asset, num_modifiche FROM prenotazioni
         WHERE id_prenotazione = ? AND id_utente = ?"
    );
    $stmt->bind_param("ii", $id_prenotazione, $id_utente); $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        echo json_encode(['success' => false, 'error' => 'Prenotazione non trovata o non autorizzato.']);
        exit();
    }
    if ((int)$booking['num_modifiche'] >= 3) {
        echo json_encode(['success' => false, 'error' => 'Hai già utilizzato le 3 modifiche disponibili per questa prenotazione.']);
        exit();
    }

    // Validazione date
    $dtInizio = new DateTime($data_inizio);
    $dtFine   = new DateTime($data_fine);
    $now      = new DateTime();

    if ($dtInizio < $now) {
        echo json_encode(['success' => false, 'error' => 'Non puoi impostare una data di inizio nel passato.']);
        exit();
    }
    if ($dtFine <= $dtInizio) {
        echo json_encode(['success' => false, 'error' => 'La data di fine deve essere successiva alla data di inizio.']);
        exit();
    }
    if ($dtInizio->format('Y-m-d') !== $dtFine->format('Y-m-d')) {
        echo json_encode(['success' => false, 'error' => 'La prenotazione deve iniziare e finire nella stessa giornata (09:00–19:00).']);
        exit();
    }

    $oreInizio = (int)$dtInizio->format('H') * 60 + (int)$dtInizio->format('i');
    $oreFine   = (int)$dtFine->format('H')   * 60 + (int)$dtFine->format('i');
    $oreAp     = 9 * 60;
    $oreChius  = 19 * 60;

    if ($oreInizio < $oreAp) {
        echo json_encode(['success' => false, 'error' => 'L\'orario di inizio non può essere prima delle 09:00.']);
        exit();
    }
    if ($oreInizio >= $oreChius) {
        echo json_encode(['success' => false, 'error' => 'L\'orario di inizio non può essere dalle 19:00 in poi.']);
        exit();
    }
    if ($oreFine > $oreChius) {
        echo json_encode(['success' => false, 'error' => 'L\'orario di fine non può superare le 19:00.']);
        exit();
    }

    // Controllo sovrapposizione (esclude la prenotazione stessa)
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM prenotazioni
         WHERE id_asset = ? AND id_prenotazione != ?
           AND data_inizio < ? AND data_fine > ?"
    );
    $stmt->bind_param("iiss", $booking['id_asset'], $id_prenotazione, $data_fine, $data_inizio);
    $stmt->execute();
    $overlap = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    if ($overlap > 0) {
        echo json_encode(['success' => false, 'error' => 'Il periodo selezionato si sovrappone a un\'altra prenotazione esistente.']);
        exit();
    }

    // Aggiorna prenotazione e incrementa contatore
    $stmt = $conn->prepare(
        "UPDATE prenotazioni
         SET data_inizio = ?, data_fine = ?, num_modifiche = num_modifiche + 1
         WHERE id_prenotazione = ? AND id_utente = ?"
    );
    $stmt->bind_param("ssii", $data_inizio, $data_fine, $id_prenotazione, $id_utente);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'Errore durante l\'aggiornamento.']);
        exit();
    }
    $stmt->close();

    echo json_encode(['success' => true]);
    exit();
}

// ── POST: annulla prenotazione ─────────────────────────
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
        $stmt->bind_param("ii", $id_prenotazione, $id_utente); $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$booking) throw new Exception('Prenotazione non trovata o non autorizzato.');

        $stmt = $conn->prepare("DELETE FROM prenotazioni WHERE id_prenotazione = ? AND id_utente = ?");
        $stmt->bind_param("ii", $id_prenotazione, $id_utente);
        if (!$stmt->execute()) throw new Exception('Errore durante l\'annullamento.');
        $stmt->close();

        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE id_asset = ? AND data_fine >= NOW()");
        $stmt->bind_param("i", $booking['id_asset']); $stmt->execute();
        $remaining = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        if ($remaining === 0) {
            $stmt = $conn->prepare("UPDATE asset SET stato = 'Disponibile' WHERE id_asset = ?");
            $stmt->bind_param("i", $booking['id_asset']); $stmt->execute(); $stmt->close();
        }

        $conn->commit();
        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── GET: HTML modale ───────────────────────────────────
$userBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine,
            p.num_modifiche,
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

// Conta quante prenotazioni future hanno ancora modifiche disponibili
$futureModifiable = count(array_filter($userBookings, function($b) {
    return (new DateTime($b['data_fine'])) >= new DateTime() && (int)$b['num_modifiche'] < 3;
}));
?>
<div class="modal-content">
    <div class="modal-header">
        <h3>Le Tue Prenotazioni</h3>
        <button class="modal-close" onclick="closeAllBookingsModal()">&times;</button>
    </div>

    <!-- Banner info modifiche -->
    <div class="mod-info-banner">
        <span class="mod-info-icon">✏️</span>
        <div class="mod-info-text">
            <strong>Puoi modificare ogni prenotazione al massimo 3 volte.</strong>
            <span>Cambia orario o data entro i limiti consentiti (09:00–19:00, stesso giorno).</span>
        </div>
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
                    $start        = new DateTime($b['data_inizio']);
                    $end          = new DateTime($b['data_fine']);
                    $duration     = $start->diff($end);
                    $now          = new DateTime();
                    $isPast       = $end < $now;
                    $numMod       = (int)$b['num_modifiche'];
                    $modLeft      = 3 - $numMod;
                    $canModify    = !$isPast && $modLeft > 0;

                    // Classe e testo del badge modifiche
                    if ($isPast) {
                        $modBadgeClass = 'mod-counter--past';
                        $modBadgeText  = 'Conclusa';
                    } elseif ($modLeft === 3) {
                        $modBadgeClass = 'mod-counter--full';
                        $modBadgeText  = '✓ 3 modifiche disponibili';
                    } elseif ($modLeft === 2) {
                        $modBadgeClass = 'mod-counter--one';
                        $modBadgeText  = '⚠ 2 modifiche rimanenti';
                    } elseif ($modLeft === 1) {
                        $modBadgeClass = 'mod-counter--one';
                        $modBadgeText  = '⚠ 1 modifica rimanente';
                    } else {
                        $modBadgeClass = 'mod-counter--none';
                        $modBadgeText  = '🔒 Nessuna modifica disponibile';
                    }

                    // Pre-formatta le date per l'input datetime-local
                    $inizio_input = $start->format('Y-m-d\TH:i');
                    $fine_input   = $end->format('Y-m-d\TH:i');
                ?>
                <div class="booking-item-detail">
                    <div class="booking-header">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <h4><?= htmlspecialchars($b['codice_asset']) ?></h4>
                            <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;
                                         background:var(--clr-surface-3);color:var(--clr-text-2)">
                                <?= htmlspecialchars($b['nome_tipologia']) ?>
                            </span>
                            <!-- Badge modifiche — sempre visibile -->
                            <span class="mod-counter <?= $modBadgeClass ?>">
                                <?= $modBadgeText ?>
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

                    <!-- Barra avanzamento modifiche (solo prenotazioni non passate) -->
                    <?php if (!$isPast): ?>
                    <div class="mod-progress-bar">
                        <div class="mod-progress-track">
                            <div class="mod-progress-fill mod-progress-fill--<?= $numMod === 0 ? 'zero' : ($numMod < 3 ? 'one' : 'two') ?>"
                                 style="width:<?= round($numMod / 3 * 100) ?>%"></div>
                        </div>
                        <span class="mod-progress-label">
                            <?= $numMod ?>/3 modifiche utilizzate
                        </span>
                    </div>
                    <?php endif; ?>

                    <?php if (!$isPast): ?>
                    <div class="booking-actions">
                        <?php if ($canModify): ?>
                        <button class="btn-modify" onclick="showUserEditForm(<?= $b['id_prenotazione'] ?>)">
                            ✏️ Modifica
                        </button>
                        <?php else: ?>
                        <button class="btn-modify btn-modify--locked" disabled title="Hai esaurito le modifiche disponibili">
                            🔒 Modifica non disponibile
                        </button>
                        <?php endif; ?>
                        <button class="btn-cancel" onclick="cancelBooking(<?= $b['id_prenotazione'] ?>)">Annulla prenotazione</button>
                    </div>

                    <!-- Pannello modifica inline -->
                    <?php if ($canModify): ?>
                    <div id="user-edit-form-<?= $b['id_prenotazione'] ?>" class="booking-edit-panel" style="display:none">
                        <div class="booking-edit-title">✏️ Modifica prenotazione — rimangono <strong><?= $modLeft ?></strong> modifica<?= $modLeft !== 1 ? 'he' : '' ?></div>
                        <div id="user-edit-error-<?= $b['id_prenotazione'] ?>" class="booking-edit-error"></div>
                        <div class="booking-edit-grid">
                            <div class="form-group">
                                <label>Nuova data inizio</label>
                                <input type="datetime-local" id="edit-inizio-<?= $b['id_prenotazione'] ?>"
                                       value="<?= $inizio_input ?>" min="<?= (new DateTime())->format('Y-m-d\TH:i') ?>">
                            </div>
                            <div class="form-group">
                                <label>Nuova data fine</label>
                                <input type="datetime-local" id="edit-fine-<?= $b['id_prenotazione'] ?>"
                                       value="<?= $fine_input ?>" min="<?= (new DateTime())->format('Y-m-d\TH:i') ?>">
                            </div>
                        </div>
                        <div class="form-buttons">
                            <button class="btn-save"  onclick="updateUserBooking(<?= $b['id_prenotazione'] ?>)">Salva modifiche</button>
                            <button class="btn-ghost" onclick="hideUserEditForm(<?= $b['id_prenotazione'] ?>)">Annulla</button>
                        </div>
                    </div>
                    <?php endif; ?>

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
