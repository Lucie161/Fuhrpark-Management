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
 * ID des angemeldeten Nutzers (nutzer.id), gesetzt von login.php. Die Seite
 * stellt mit nur_angemeldet() oder nur_fuer_rolle() sicher, dass es ihn gibt.
 */
function aktueller_nutzer(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

/**
 * Rolle des Nutzers: ROLLE_MITARBEITER oder ROLLE_FUHRPARKLEITER. login.php
 * setzt $_SESSION['rolle_id'] aus nutzer.rolle_id. Ohne Anmeldung gilt
 * ROLLE_MITARBEITER, also keine besonderen Rechte.
 */
function aktuelle_rolle(): int
{
    return ($_SESSION['rolle_id'] ?? null) === ROLLE_FUHRPARKLEITER ? ROLLE_FUHRPARKLEITER : ROLLE_MITARBEITER;
}

/**
 * Ist der Nutzer Fuhrparkleiter? Er bucht nicht selbst, sondern genehmigt,
 * verwaltet die Fahrzeuge und wertet aus.
 */
function ist_fuhrparkleiter(): bool
{
    return aktuelle_rolle() === ROLLE_FUHRPARKLEITER;
}

/**
 * Sperrt eine Seite für alle, die nicht angemeldet sind: Sie landen auf der
 * Anmeldung. Steht direkt nach config.php auf den Seiten für beide Rollen.
 *   nur_angemeldet();
 */
function nur_angemeldet(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

/**
 * Sperrt eine Seite für die andere Rolle: Wer nicht angemeldet ist, landet auf
 * der Anmeldung, wer nicht die angegebene Rolle hat, auf der Übersicht. Steht
 * direkt nach config.php, vor jeder Formularverarbeitung der Seite.
 *   nur_fuer_rolle(ROLLE_FUHRPARKLEITER);
 */
function nur_fuer_rolle(int $rolle): void
{
    nur_angemeldet();

    if (aktuelle_rolle() !== $rolle) {
        redirect('index.php');
    }
}

/**
 * Zeitraum als Text, eintägig ohne „bis“: „03.10.2026“ oder
 * „03.10.2026 bis 05.10.2026“.
 */
function zeitraum_text(DateTimeImmutable $von, DateTimeImmutable $bis): string
{
    $von = $von->format('d.m.Y');
    $bis = $bis->format('d.m.Y');

    return $von === $bis ? $von : $von . ' bis ' . $bis;
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
