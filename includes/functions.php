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
 * ID des angemeldeten Nutzers (nutzer.id).
 *
 * Prototyp: gesetzt über die Auswahl unter der Navigation (rolle.php), ohne
 * Angabe Lucie Schneider (1) bzw. als Fuhrparkleiter Jonas Weber (6). Später
 * setzt login.php $_SESSION['user_id'].
 */
function aktueller_nutzer(): int
{
    return (int) ($_SESSION['user_id'] ?? (aktuelle_rolle() === 'fuhrparkleiter' ? 6 : 1));
}

/**
 * Rolle des Nutzers: 'mitarbeiter' oder 'fuhrparkleiter'.
 *
 * Prototyp: gesetzt über die Auswahl unter der Navigation (rolle.php).
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
 * Ampel: Muss eine Buchung dieser Fahrzeugart genehmigt werden? Autos und
 * Transporter ja („gelb“, Status „offen“), Roller und Fahrräder nein
 * („grün“, sofort „genehmigt“). Wie Transporter eingeordnet werden, wird
 * noch besprochen (docs/aenderungen-todo2.md); bis dahin wie Autos.
 */
function braucht_genehmigung(string $art): bool
{
    return !in_array($art, ['roller', 'fahrrad'], true);
}

/**
 * Warum der Nutzer nicht buchen darf, sonst null: Wer eine überfällige
 * Rückgabe hat, bucht nichts Neues und wird auch von Kollegen nicht als
 * Fahrer eingetragen (docs/aenderungen-todo2.md, Punkt 12).
 */
function sperre_buchen(int $nutzerId): ?string
{
    return hat_ueberfaellige_rueckgabe($nutzerId)
        ? 'Die Rückgabe von ' . nutzer_name($nutzerId) . ' ist überfällig.'
        : null;
}

/**
 * Ist das Ende der Wartung offen? Entweder ist es noch nicht festgelegt
 * (wartung_bis NULL, nach einer Rückgabe mit Schaden) oder das voraussichtliche
 * Ende ist vorbei, das Fahrzeug aber nicht freigegeben. In beiden Fällen weiß
 * niemand, wann es fertig ist; der Fuhrparkleiter muss das Ende festlegen
 * oder das Fahrzeug freigeben.
 */
function wartung_ende_offen(array $fahrzeug): bool
{
    return $fahrzeug['status'] === 'wartung'
        && ($fahrzeug['wartung_bis'] === null || $fahrzeug['wartung_bis'] < new DateTimeImmutable('today'));
}

/**
 * Stand der Wartung als Text: „voraussichtlich bis 14.10.2026“, „Ende noch
 * offen“ oder „war bis 14.10.2026 geplant“.
 */
function wartung_text(array $fahrzeug): string
{
    return match (true) {
        $fahrzeug['wartung_bis'] === null => 'Ende noch offen',
        wartung_ende_offen($fahrzeug)     => 'war bis ' . $fahrzeug['wartung_bis']->format('d.m.Y') . ' geplant',
        default                           => 'voraussichtlich bis ' . $fahrzeug['wartung_bis']->format('d.m.Y'),
    };
}

/**
 * Sperrt die Wartung das Fahrzeug im Zeitraum? Gesperrt ist es bis
 * einschließlich zum voraussichtlichen Ende; danach ist es buchbar. Ist das
 * Ende offen (wartung_ende_offen()), ist es für jeden Zeitraum gesperrt.
 */
function wartung_sperrt(array $fahrzeug, DateTimeImmutable $von, DateTimeImmutable $bis): bool
{
    if ($fahrzeug['status'] !== 'wartung') {
        return false;
    }

    return wartung_ende_offen($fahrzeug) || $von <= $fahrzeug['wartung_bis'];
}

/**
 * Warum die Fahrt nicht beginnen kann, sonst null: bei eigener überfälliger
 * Rückgabe, wenn das Fahrzeug selbst noch nicht zurückgegeben ist oder wenn
 * es noch in Wartung steht (etwa weil die Reparatur länger dauert).
 */
function sperre_fahrtbeginn(int $fahrerId, int $fahrzeugId): ?string
{
    return match (true) {
        hat_ueberfaellige_rueckgabe($fahrerId) => 'Bitte geben Sie zuerst Ihr überfälliges Fahrzeug zurück.',
        fahrzeug_ueberfaellig($fahrzeugId)     => 'Das Fahrzeug ist vom vorigen Fahrer noch nicht zurückgegeben.',
        beispiel_fahrzeuge()[$fahrzeugId]['status'] === 'wartung'
                                               => 'Das Fahrzeug ist noch in Wartung.',
        default                                => null,
    };
}

/**
 * Warum ein Antrag nicht genehmigt werden kann, sonst null (Regel 9): Das
 * Fahrzeug ist im Zeitraum in Wartung, seine Rückgabe ist überfällig, oder es
 * ist im Zeitraum schon vergeben (genehmigt oder unterwegs).
 */
function genehmigung_hindernis(array $antrag): ?string
{
    if (wartung_sperrt(beispiel_fahrzeuge()[$antrag['fahrzeug_id']], $antrag['start'], $antrag['ende'])) {
        return 'Das Fahrzeug ist im Zeitraum in Wartung.';
    }

    if (fahrzeug_ueberfaellig($antrag['fahrzeug_id'])) {
        return 'Die Rückgabe des Fahrzeugs ist überfällig.';
    }

    foreach (beispiel_buchungen() as $b) {
        if ($b['fahrzeug_id'] === $antrag['fahrzeug_id'] && $b['id'] !== $antrag['id']
            && in_array($b['status'], ['genehmigt', 'unterwegs'], true)
            && $b['start'] <= $antrag['ende'] && $b['ende'] >= $antrag['start']) {
            return 'Das Fahrzeug ist im Zeitraum bereits vergeben.';
        }
    }

    return null;
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
