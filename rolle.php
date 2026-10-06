<?php
/**
 * Rolle wechseln — nur für den Prototyp.
 *
 * Solange die Anmeldung keine Zugangsdaten prüft, wird die Rolle über die
 * Auswahl in der Navigation gewählt (includes/header.php). Später setzt
 * login.php $_SESSION['rolle'] aus nutzer.rolle; dann entfallen diese Datei
 * und die Auswahl.
 *
 * Eigene Datei statt Verarbeitung auf jeder Seite: Ein POST an die aktuelle
 * Seite würde deren eigene Formularverarbeitung auslösen.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$rollen = ['mitarbeiter', 'fuhrparkleiter'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['rolle'] ?? '', $rollen, true)) {
    $_SESSION['rolle'] = $_POST['rolle'];
}

// Zurück zur aufrufenden Seite, z. B. 'verlauf.php?von=2026-09-01'. Nur
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
