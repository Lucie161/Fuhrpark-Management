<?php
/**
 * Kleine Hilfsfunktionen, die auf allen Seiten gebraucht werden.
 */

declare(strict_types=1);

/**
 * Gibt einen Wert HTML-sicher aus.
 *
 * Kurzform für htmlspecialchars(). Jede Ausgabe von Daten, die aus der
 * Datenbank oder aus einem Formular stammen, läuft durch diese Funktion:
 *   <td><?= e($fahrzeug['kennzeichen']) ?></td>
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Baut eine URL relativ zum Basis-Pfad der Anwendung.
 *   url('fahrzeuge.php')       -> /Fuhrpark-Management/fahrzeuge.php
 *   url('assets/css/style.css') -> /Fuhrpark-Management/assets/css/style.css
 */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Prüft, ob gerade die übergebene Seite aufgerufen wird.
 * Wird in der Navigation genutzt, um den aktiven Punkt zu markieren.
 */
function is_current(string $file): bool
{
    return basename($_SERVER['SCRIPT_NAME']) === $file;
}

/**
 * Ist ein Benutzer angemeldet?
 */
function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * Rolle des Nutzers: 'mitarbeiter' oder 'fuhrparkleiter'.
 *
 * Prototyp: gesetzt über die Rollenauswahl unter der Navigation (rolle.php).
 * Später setzt login.php $_SESSION['rolle'] aus nutzer.rolle. Ohne Angabe
 * gilt 'mitarbeiter'.
 */
function aktuelle_rolle(): string
{
    return ($_SESSION['rolle'] ?? '') === 'fuhrparkleiter' ? 'fuhrparkleiter' : 'mitarbeiter';
}

/**
 * Ist der Nutzer Fuhrparkleiter? Er bucht nicht selbst, sondern genehmigt,
 * verwaltet die Fahrzeuge und wertet aus (siehe docs/user-stories.md, „Rollen“).
 */
function ist_fuhrparkleiter(): bool
{
    return aktuelle_rolle() === 'fuhrparkleiter';
}

/**
 * Sperrt eine Seite für die andere Rolle: Wer nicht die angegebene Rolle hat,
 * landet auf der Übersicht. Steht direkt nach config.php, vor jeder
 * Formularverarbeitung der Seite (siehe docs/technisches-konzept.md, „Rollen
 * je Seite“).
 *   nur_fuer_rolle('fuhrparkleiter');
 *
 * Weiterleitung statt Fehlerseite: Wechselt man im Prototyp die Rolle, kehrt
 * rolle.php auf die aktuelle Seite zurück und landet so auf der Übersicht.
 */
function nur_fuer_rolle(string $rolle): void
{
    if (aktuelle_rolle() !== $rolle) {
        redirect('index.php');
    }
}

/**
 * Merkt eine Meldung für die nächste Seite, z. B. vor einer Weiterleitung.
 * header.php gibt sie einmal aus und vergisst sie dann.
 *   merke_meldung('Antrag genehmigt.');
 *   redirect('index.php');
 *
 * $art ist 'erfolg' oder 'hinweis' (CSS-Modifier von .alert).
 */
function merke_meldung(string $text, string $art = 'erfolg'): void
{
    $_SESSION['meldung'] = ['text' => $text, 'art' => $art];
}

/**
 * Gemerkte Meldung holen und löschen; null, wenn keine da ist.
 */
function hole_meldung(): ?array
{
    $meldung = $_SESSION['meldung'] ?? null;
    unset($_SESSION['meldung']);

    return $meldung;
}

/**
 * Leitet auf eine Seite der Anwendung um und beendet das Skript.
 *   redirect('login.php');
 */
function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}
