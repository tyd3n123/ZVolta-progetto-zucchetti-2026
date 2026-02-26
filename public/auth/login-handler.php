<?php
session_start();

require_once __DIR__ . '/../../config/config.php';


$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    $_SESSION['login_error'] = "Inserisci username e password.";
    header("Location: ../login.php");
    exit;
}


$sql = "SELECT id, username, password FROM users WHERE username = ? LIMIT 1";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Errore prepare: " . $conn->error);
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    $_SESSION['login_error'] = "Credenziali non valide.";
    header("Location: ../login.php");
    exit;
}

$row = $result->fetch_assoc();

if (!password_verify($password, $row['password'])) {
    $_SESSION['login_error'] = "Credenziali non valide.";
    header("Location: ../login.php");
    exit;
}

// 5) Login OK -> sessione
session_regenerate_id(true);
$_SESSION['logged'] = true;
$_SESSION['user_id'] = $row['id'];
$_SESSION['username'] = $row['username'];

unset($_SESSION['login_error']);
header("Location: ../area-privata.php");
exit;