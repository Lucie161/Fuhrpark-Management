<?php
/**
 * Angemeldete Person wechseln — nur für den Prototyp.
 *
 * Solange die Anmeldung keine Zugangsdaten prüft, wird die Person über die
 * Auswahl unter der Navigation gewählt (includes/header.php). Gesetzt werden
 * dieselben Werte wie später in login.php: $_SESSION['user_id'] und
 * $_SESSION['rolle'] aus nutzer.rolle. Mit der Anmeldung entfallen diese
 * Datei und die Auswahl.
 *
 * Eigene Datei statt Verarbeitung auf jeder Seite: Ein POST an die aktuelle
 * Seite würde deren eigene Formularverarbeitung auslösen.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$nutzerId = (int) (is_string($_POST['nutzer'] ?? null) ? $_POST['nutzer'] : 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset(beispiel_nutzer()[$nutzerId])) {
    $_SESSION['user_id'] = $nutzerId;
    $_SESSION['rolle']   = beispiel_nutzer()[$nutzerId]['rolle'];
}

// Zurück zur aufrufenden Seite, z. B. 'historie.php?von=2026-09-01'. Nur
// eine vorhandene Seite der Anwendung ist erlaubt, sonst ließe sich hierüber
// auf fremde Adressen weiterleiten.
$zurueck = $_POST['zurueck'] ?? '';
[$datei, $abfrage] = array_pad(explode('?', is_string($zurueck) ? $zurueck : '', 2), 2, '');

if (preg_match('/^[a-z0-9-]+\.php$/', $datei) !== 1 || !is_file(__DIR__ . '/' . $datei)) {
    $datei   = 'index.php';
    $abfrage = '';
}

parse_str($abfrage, $parameter);

redirect($datei . ($parameter !== [] ? '?' . http_build_query($parameter) : ''));
