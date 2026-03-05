<?php

session_start();
$_SESSION["logged_in"] = false;
require_once __DIR__ . "/../../config/config.php";

// Get form data
$username = trim($_POST["User"] ?? "");
$password = $_POST["password"] ?? "";
$captchaInserito = trim($_POST["captcha"] ?? "");
$mouseDataJson = $_POST["mouse_data"] ?? '[]';

// 1. Check if username and password are empty
if ($username === "" || $password === "") {
    $_SESSION["login_error"] = "Inserisci username e password";
    header("Location: ../login.php");
    exit();
}

// 2. CAPTCHA Verification
$isBot = false;
$motivoErrore = "";

// A. Controllo del testo copiato (case-insensitive)
if (strtoupper($captchaInserito) !== $_SESSION['text_captcha']) {
    $isBot = true;
    $motivoErrore = "Il codice di testo non corrisponde.";
} else {
    // B. IL VERO CAPTCHA: Analisi dei movimenti del mouse
    $mouseData = json_decode($mouseDataJson, true);
    
    // 1. Controllo quantità movimenti: un umano genera decine di eventi mousemove
    if (!is_array($mouseData) || count($mouseData) < 5) {
        $isBot = true;
        $motivoErrore = "Movimento assente (rilevato possibile bot).";
    } else {
        // 2. Controllo precisione robotica (se è una linea retta perfetta)
        $isLinear = true;
        $dx0 = $mouseData[1]['x'] - $mouseData[0]['x'];
        $dy0 = $mouseData[1]['y'] - $mouseData[0]['y'];
        
        for ($i = 2; $i < count($mouseData); $i++) {
            $dx = $mouseData[$i]['x'] - $mouseData[$i-1]['x'];
            $dy = $mouseData[$i]['y'] - $mouseData[$i-1]['y'];
            
            // Se il prodotto incrociato non è 0, c'è una curva/tremolio (comportamento umano)
            if (($dx0 * $dy) - ($dy0 * $dx) != 0) {
                $isLinear = false;
                break;
            }
        }
        
        if ($isLinear) {
            $isBot = true;
            $motivoErrore = "Movimento del mouse troppo preciso e innaturale.";
        }
    }
}

// If CAPTCHA fails, redirect with error
if ($isBot) {
    $_SESSION["login_error"] = "Accesso negato: $motivoErrore";
    // Rigenera il codice dopo un tentativo fallito
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $_SESSION['text_captcha'] = substr(str_shuffle($chars), 0, 5);
    header("Location: ../login.php");
    exit();
}

// 3. Credential Verification (only if CAPTCHA passes)
$sql = "SELECT id_utente, username, password FROM utenti WHERE username = ? LIMIT 1";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Errore nella preparazione della query: " . $conn->error);
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION["login_error"] = "Username o password errati";
    // Rigenera il codice dopo un tentativo fallito
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $_SESSION['text_captcha'] = substr(str_shuffle($chars), 0, 5);
    header("Location: ../dashboard/index.php");
    exit();
}

$user = $result->fetch_assoc();

if($user["username"] === $username && $user["password"] === $password) {
    $_SESSION["username"] = $user["username"];
    $_SESSION["id_utente"] = $user["id_utente"];
    $_SESSION["logged_in"] = true;
    
    // Clear CAPTCHA after successful login
    unset($_SESSION['text_captcha']);
    
    header("Location: ../dashboard/index.php");
    exit();
} else {
    $_SESSION["login_error"] = "Username o password errati";
    // Rigenera il codice dopo un tentativo fallito
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $_SESSION['text_captcha'] = substr(str_shuffle($chars), 0, 5);
    header("Location: ../dashboard/index.php");
    exit();
}
