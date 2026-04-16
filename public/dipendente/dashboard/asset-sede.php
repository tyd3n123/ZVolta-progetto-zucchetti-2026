<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

// Verifica autenticazione
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    header("Location: ../login.php"); 
    exit();
}

$id_utente = (int)$_SESSION['id_utente'];

// Ottieni informazioni utente
$stmt = $conn->prepare("SELECT u.nome, u.cognome, r.nome_ruolo AS ruolo FROM utenti u LEFT JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ? LIMIT 1");
$stmt->bind_param("i", $id_utente); 
$stmt->execute();
$userInfo = $stmt->get_result()->fetch_assoc() ?? ['nome'=>'','cognome'=>'','ruolo'=>''];
$stmt->close();

// Conta asset disponibili per ogni categoria
$officeStats = ['total' => 0, 'available' => 0];
$meetingStats = ['total' => 0, 'available' => 0];

// Conta uffici
$result = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN stato = 'Disponibile' THEN 1 ELSE 0 END) as available FROM asset WHERE mappa = 'Sede' AND codice_asset LIKE 'Ufficio%'");
if ($result) {
    $row = $result->fetch_assoc();
    $officeStats['total'] = (int)$row['total'];
    $officeStats['available'] = (int)$row['available'];
}

// Conta sale riunioni
$result = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN stato = 'Disponibile' THEN 1 ELSE 0 END) as available FROM asset WHERE mappa = 'Sede' AND codice_asset LIKE 'Sala%'");
if ($result) {
    $row = $result->fetch_assoc();
    $meetingStats['total'] = (int)$row['total'];
    $meetingStats['available'] = (int)$row['available'];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Asset Sede | Northstar</title>
    <link rel="stylesheet" href="../dashboard/dashboard.css">
    <link rel="stylesheet" href="asset-sede.css">
</head>
<body>

<header class="header">
    <div class="header-left">
        <h1>Northstar</h1>
        <nav class="header-breadcrumb">
            <a href="index.php" style="color: black;">Dashboard</a>
            <span class="bc-sep">/</span>
            <a href="asset-disponibili.php" style="color: black;">Asset Disponibili</a>
            <span class="bc-sep">/</span>
            <span class="bc-current" style="color: var(--clr-text-1);">Asset Sede</span>
        </nav>
    </div>
    <div class="uf-user-pill">
        <?= htmlspecialchars($userInfo['nome'].' '.$userInfo['cognome']) .  ' - ' ?>
        <span class="uf-role"><?= htmlspecialchars($userInfo['ruolo']) ?></span>
    </div>
</header>

<div class="asset-sede-page">

    <div class="page-header">
        <h1 class="page-title">Scegli l'Asset della Sede</h1>
        <p class="page-subtitle">Seleziona il tipo di asset che desideri visualizzare e prenotare all'interno della sede aziendale</p>
    </div>

    <div class="selection-container">
        
        <!-- Card Scrivania con Cassettiera e Armadietto -->
        <div class="asset-card" onclick="location.href='../uffici-tipologia-a/index.php'" tabindex="0" role="button" aria-label="Vai alle scrivanie con cassettiera e armadietto">
            <div class="card-icon office-icon">
                🪑
            </div>
            <div class="card-content">
                <h2 class="card-title">Scrivania con Cassettiera e Armadietto</h2>
                <p class="card-description">
                    Postazioni lavoro complete con scrivania spaziosa, cassettiera per documenti e armadietto personale. 
                    Ideali per chi necessita di spazio di archiviazione privato e organizzazione.
                </p>
                <div class="card-features">
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Cassettiera inclusa</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Armadietto personale</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Spazio archiviazione</span>
                    </div>
                </div>
            </div>
            <button class="card-button">
                Accedi alle postazioni
                <span class="button-arrow">→</span>
            </button>
        </div>

        <!-- Card Scrivania con Monitor Esterno -->
        <div class="asset-card" onclick="location.href='../uffici-tipologia-a2/index.php'" tabindex="0" role="button" aria-label="Vai alle scrivanie con monitor esterno">
            <div class="card-icon meeting-icon">
                🖥️
            </div>
            <div class="card-content">
                <h2 class="card-title">Scrivania con Monitor Esterno</h2>
                <p class="card-description">
                    Postazioni lavoro con scrivania e monitor esterno per videoconferenze. 
                    Perfette per riunioni remote e lavoro ibrido con tecnologia avanzata.
                </p>
                <div class="card-features">
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Monitor grande</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Videoconferenza</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Postazione ibrida</span>
                    </div>
                </div>
            </div>
            <button class="card-button">
                Accedi alle postazioni
                <span class="button-arrow">→</span>
            </button>
        </div>

        <!-- Card Sale Riunioni -->
        <div class="asset-card" onclick="location.href='../sale-riunioni/index.php'" tabindex="0" role="button" aria-label="Vai alle sale riunioni">
            <div class="card-icon meeting-icon">
                🏢
            </div>
            <div class="card-content">
                <h2 class="card-title">Sale Riunioni</h2>
                <p class="card-description">
                    Prenota sale attrezzate per meeting, presentazioni e videoconferenze. 
                    Spazi professionali con tutte le tecnologie necessarie per le tue riunioni.
                </p>
                <div class="card-features">
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Proiettori e schermi</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Videoconferenza</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Postazioni lavoro</span>
                    </div>
                </div>
            </div>
            <button class="card-button">
                Accedi alle sale
                <span class="button-arrow">→</span>
            </button>
        </div>

    </div>

</div>

<script>
// Aggiungi navigazione con tastiera
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.asset-card');
    
    cards.forEach(card => {
        card.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                card.click();
            }
        });
    });
});

// Animazione di ingresso
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.asset-card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 200);
    });
});
</script>

</body>
</html>
