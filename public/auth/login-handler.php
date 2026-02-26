<?php

session_start();
$_SESSION["logged_in"] = false;
require_once __DIR__ . "/../../config/config.php";


$username = trim($_POST["username"]);
$password = $_POST["password"];

/* Qui verifico se username e password sono vuoti */
if ( $username === "" || $password === "" ) {
    $_SESSION["login_error"] = "Inserisci username e password";
    header("Location: login.php");
    exit();
}

$sql = "SELECT id_utente, username, password FROM utenti WHERE username = ? LIMIT 1";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Errore nella preparazione della query: " . $conn->error);
}

$stmt-> bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION["login_error"] = "Username o password errati";
    header("Location: ../login.php");
    exit();
}

$user = $result->fetch_assoc();
echo "Utente trovato: " . $user["username"] . $user["id_utente"]. "<br>";
if($user["username"] === $username && $user["password"]===$password) {
    $_SESSION["username"] = $user["username"];
    $_SESSION["logged_in"] = true;
    header("Location: ../dashboard.php");
    exit();
} else {
    $_SESSION["login_error"] = "Username o password errati";
    header("Location: ../login.php");
    exit();
}
