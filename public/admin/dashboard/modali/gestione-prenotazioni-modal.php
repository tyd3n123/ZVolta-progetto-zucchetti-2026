<?php
session_start();
require_once __DIR__ . "/../../../../config/config.php";

// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get user info to verify admin role
$sql = "SELECT r.nome_ruolo FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $_SESSION['id_utente']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (strtolower($user['nome_ruolo']) !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id_prenotazione = $_POST['id_prenotazione'];
    
    // Get asset info before deleting
    $sql = "SELECT id_asset FROM prenotazioni WHERE id_prenotazione = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id_prenotazione);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();

    if ($booking) {
        // Update asset status to 'Disponibile'
        $update_sql = "UPDATE asset SET stato = 'Disponibile' WHERE id_asset = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $booking['id_asset']);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Delete booking
        $delete_sql = "DELETE FROM prenotazioni WHERE id_prenotazione = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $id_prenotazione);
        $delete_stmt->execute();
        $delete_stmt->close();
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit();
}

// Handle update action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $id_prenotazione = $_POST['id_prenotazione'];
    $data_inizio = $_POST['data_inizio'];
    $data_fine = $_POST['data_fine'];
    
    $sql = "UPDATE prenotazioni SET data_inizio = ?, data_fine = ? WHERE id_prenotazione = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $data_inizio, $data_fine, $id_prenotazione);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
    $stmt->close();
    exit();
}

// Fetch all bookings for display
$allBookings = [];
$sql = "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset, a.id_asset, u.nome, u.cognome, r.nome_ruolo
        FROM prenotazioni p 
        JOIN asset a ON p.id_asset = a.id_asset 
        JOIN utenti u ON p.id_utente = u.id_utente
        JOIN ruoli r ON u.id_ruolo = r.id_ruolo
        ORDER BY p.data_inizio DESC";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $allBookings[] = $row;
    }
}

// Return HTML content for modal
ob_start();
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
                        <?php foreach ($allBookings as $booking): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($booking['codice_asset']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($booking['nome'] . ' ' . $booking['cognome']); ?>
                                    <span class="user-badge"><?php echo htmlspecialchars($booking['nome_ruolo']); ?></span>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($booking['data_inizio'])); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($booking['data_fine'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-edit" onclick="showEditForm(<?php echo $booking['id_prenotazione']; ?>)">Modifica</button>
                                        <button class="btn-delete" onclick="deleteBooking(<?php echo $booking['id_prenotazione']; ?>)">Elimina</button>
                                    </div>
                                </td>
                            </tr>
                            <tr id="edit-form-<?php echo $booking['id_prenotazione']; ?>" class="edit-row" style="display: none;">
                                <td colspan="5">
                                    <div class="edit-form">
                                        <h4>Modifica Prenotazione #<?php echo $booking['id_prenotazione']; ?></h4>
                                        <div class="form-group">
                                            <label for="start-<?php echo $booking['id_prenotazione']; ?>">Data Inizio:</label>
                                            <input type="datetime-local" id="start-<?php echo $booking['id_prenotazione']; ?>" value="<?php echo date('Y-m-d\TH:i', strtotime($booking['data_inizio'])); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="end-<?php echo $booking['id_prenotazione']; ?>">Data Fine:</label>
                                            <input type="datetime-local" id="end-<?php echo $booking['id_prenotazione']; ?>" value="<?php echo date('Y-m-d\TH:i', strtotime($booking['data_fine'])); ?>">
                                        </div>
                                        <div class="form-buttons">
                                            <button class="btn-save" onclick="updateBooking(<?php echo $booking['id_prenotazione']; ?>)">Salva</button>
                                            <button class="btn-cancel" onclick="hideEditForm(<?php echo $booking['id_prenotazione']; ?>)">Annulla</button>
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

<script src="gestione-prenotazioni-modal.js"></script>
<?php
$html = ob_get_clean();
echo $html;
?>
