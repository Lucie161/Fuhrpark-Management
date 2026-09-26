<?php
/**
 * Zentrale Konfiguration.
 *
 * Wird von jeder Seite als allererstes eingebunden und zieht ihrerseits
 * die Datenbank- und Hilfsfunktionen nach. Eine Seite braucht dadurch
 * nur eine einzige require-Zeile.
 */

declare(strict_types=1);

// --- Anwendung --------------------------------------------------------------

const APP_NAME = 'Fuhrpark-Management';

// 'dev' zeigt PHP-Fehler im Browser an, 'prod' blendet sie aus.
// Vor dem Live-Gang auf 'prod' stellen.
const APP_ENV = 'dev';

// Basis-Pfad der Anwendung relativ zum Webroot, z. B. '/Fuhrpark-Management'
// wenn das Projekt unter htdocs/Fuhrpark-Management liegt, sonst ''.
// Wird automatisch ermittelt, damit die Links in jeder Ablage funktionieren.
define('BASE_URL', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/'));

// --- Datenbank --------------------------------------------------------------
// XAMPP-Standardwerte: Benutzer 'root' ohne Passwort.

const DB_HOST    = 'localhost';
const DB_NAME    = 'fuhrpark';
const DB_USER    = 'root';
const DB_PASS    = '';
const DB_CHARSET = 'utf8mb4';

// --- Fehleranzeige ----------------------------------------------------------

if (APP_ENV === 'dev') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// --- Session ----------------------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Bausteine --------------------------------------------------------------

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
