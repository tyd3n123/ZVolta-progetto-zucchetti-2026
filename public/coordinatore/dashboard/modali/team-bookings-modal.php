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

// ── GET: prenotazioni dei propri dipendenti ───────────
$teamBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine,
            a.codice_asset,
            COALESCE(t.descrizione, 'Parcheggio') AS nome_tipologia,
            u.nome, u.cognome
     FROM prenotazioni p
     JOIN asset           a ON p.id_asset  = a.id_asset
     LEFT JOIN tipologie_asset t ON a.id_tipologia = t.id_tipologia
     JOIN utenti          u ON p.id_utente = u.id_utente
     WHERE u.id_coordinatore = ?
     ORDER BY p.data_inizio DESC"
);
$stmt->bind_param("i", $id_utente); $stmt->execute();
$r = $stmt->get_result(); while ($row = $r->fetch_assoc()) $teamBookings[] = $row; $stmt->close();
?>
<div class="modal-content">
    <div class="modal-header">
        <h3>Prenotazioni del Team</h3>
        <button class="modal-close" onclick="closeTeamBookingsModal()">&times;</button>
    </div>

    <div class="modal-body">
        <?php if (empty($teamBookings)): ?>
            <div class="empty-state">
                <h4>Nessuna prenotazione del team</h4>
                <p>I tuoi dipendenti non hanno ancora effettuato prenotazioni.</p>
            </div>
        <?php else: ?>
            <div class="bookings-table-container">
                <table class="bookings-table">
                    <thead>
                        <tr>
                            <th>Asset</th>
                            <th>Tipologia</th>
                            <th>Dipendente</th>
                            <th>Data Inizio</th>
                            <th>Data Fine</th>
                            <th>Stato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teamBookings as $b):
                            $now    = new DateTime();
                            $start  = new DateTime($b['data_inizio']);
                            $end    = new DateTime($b['data_fine']);
                            if ($end < $now) {
                                $stato = 'Conclusa'; $stBg = 'var(--clr-surface-3)'; $stClr = 'var(--clr-text-3)';
                            } elseif ($start <= $now) {
                                $stato = 'In corso'; $stBg = 'var(--clr-success-bg)'; $stClr = 'var(--clr-success)';
                            } else {
                                $stato = 'Programmata'; $stBg = 'var(--clr-primary-light)'; $stClr = 'var(--clr-primary)';
                            }
                        ?>
                        <tr>
                            <td style="font-family:var(--font-mono);font-weight:600">
                                <?= htmlspecialchars($b['codice_asset']) ?>
                            </td>
                            <td>
                                <span class="user-badge"><?= htmlspecialchars($b['nome_tipologia']) ?></span>
                            </td>
                            <td><?= htmlspecialchars($b['nome'] . ' ' . $b['cognome']) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($b['data_inizio'])) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($b['data_fine'])) ?></td>
                            <td>
                                <span style="font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;
                                             background:<?= $stBg ?>;color:<?= $stClr ?>">
                                    <?= $stato ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
