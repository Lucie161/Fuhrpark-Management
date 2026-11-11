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
 * Zeitraum kurz, für die Schilder der Übersicht: „03.10.2026“,
 * „03.–05.10.2026“ im selben Monat, sonst „30.10.2026 bis 02.11.2026“.
 * Der heutige Tag heißt „heute“, z. B. „heute bis 11.11.2026“.
 */
function zeitraum_kurz(DateTimeImmutable $von, DateTimeImmutable $bis): string
{
    $heute = new DateTimeImmutable('today');
    $vonText = $von == $heute ? 'heute' : $von->format('d.m.Y');
    $bisText = $bis == $heute ? 'heute' : $bis->format('d.m.Y');

    if ($von == $bis) {
        return $vonText;
    }

    if ($von != $heute && $bis != $heute && $von->format('m.Y') === $bis->format('m.Y')) {
        return $von->format('d.') . '–' . $bisText;
    }

    return $vonText . ' bis ' . $bisText;
}

/**
 * Datum ausgeschrieben, z. B. „Sonntag, 8. November 2026“.
 */
function datum_lang(DateTimeImmutable $datum): string
{
    $tage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    $monate = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August',
               'September', 'Oktober', 'November', 'Dezember'];

    return $tage[(int) $datum->format('w')] . ', ' . $datum->format('j') . '. '
        . $monate[(int) $datum->format('n') - 1] . ' ' . $datum->format('Y');
}

/**
 * Merkt eine Meldung für die nächste Seite, z. B. vor einer Weiterleitung.
 * header.php gibt sie einmal aus und vergisst sie dann.
 *   merke_meldung('Antrag genehmigt.');
 *   redirect('index.php');
 *
 * $art ist 'erfolg' oder 'hinweis' (CSS-Modifier von .alert). $link ist
 * optional der nächste Schritt als ['Beschriftung', 'datei.php'], z. B.
 *   merke_meldung('Antrag gestellt.', 'erfolg', ['Zu meinen Buchungen', 'meine-buchungen.php']);
 */
function merke_meldung(string $text, string $art = 'erfolg', ?array $link = null): void
{
    $_SESSION['meldung'] = ['text' => $text, 'art' => $art, 'link' => $link];
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
 * Stand einer Buchung aus Sicht des Nutzers $ich, als [Wort, Farbe] für das
 * Feld „Stand“ (includes/schild-kopf.php) oder die Marke in Tabellen.
 * Farbe ist 'gruen', 'gelb', 'rot' oder 'grau'.
 */
function buchung_stand(CBuchung $Buchung, int $ich): array
{
    $heute = new DateTimeImmutable('today');
    $status = $Buchung->GetStatusID();

    // Unterwegs: überfällig, heute fällig oder noch Zeit
    if ($status === STATUS_UNTERWEGS) {
        if ($Buchung->IstUeberfaellig()) {
            return ['Überfällig', 'rot'];
        }

        return $Buchung->GetEnde() == $heute ? ['Fällig', 'gelb'] : ['Unterwegs', 'grau'];
    }

    // Heute aktiv und ich bin Fahrer: Fahrt kann beginnen, sofern nichts sperrt
    if ($Buchung->KannBeginnen($ich)) {
        return $Buchung->GetSperreFahrtbeginn() === null ? ['Bereit', 'gruen'] : ['Gesperrt', 'rot'];
    }

    return match ($status) {
        STATUS_OFFEN         => ['Offen', 'gelb'],
        STATUS_GENEHMIGT     => ['Genehmigt', 'gruen'],
        STATUS_ABGELEHNT     => ['Abgelehnt', 'rot'],
        STATUS_ABGESCHLOSSEN => ['Abgeschlossen', 'grau'],
        default              => ['Storniert', 'grau'],
    };
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

/**
 * Kilometer mit Tausenderpunkt, Strich ohne km-Stand: „48.250“ oder „–“.
 */
function km_text(?int $km): string
{
    return $km === null ? '–' : number_format($km, 0, ',', '.');
}

/**
 * Datum aus einem Formularfeld (JJJJ-MM-TT), null bei ungültiger Eingabe.
 *
 * Der Rückvergleich fängt Daten ab, die PHP sonst still umrechnet,
 * z. B. 2026-02-31 zum 03.03.
 */
function datum_aus_eingabe(string $wert): ?DateTimeImmutable
{
    $datum = DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

    return $datum !== false && $datum->format('Y-m-d') === $wert ? $datum : null;
}

/**
 * Link zur Suche nach einem Ersatzfahrzeug für eine Buchung, die abgelehnt,
 * storniert oder gesperrt ist: gleicher Zeitraum (ab heute), Zweck, Personen
 * und Fahrer. null, wenn der Zeitraum schon vorbei ist.
 */
function ersatz_link(CBuchung $Buchung, int $ich): ?string
{
    $heute = new DateTimeImmutable('today');

    if ($Buchung->GetEnde() < $heute) {
        return null;
    }

    $suche = [
        'beginn'   => max($Buchung->GetStart(), $heute)->format('Y-m-d'),
        'ende'     => $Buchung->GetEnde()->format('Y-m-d'),
        'personen' => $Buchung->GetPersonenanzahl(),
        'zweck'    => $Buchung->GetZweckID(),
    ];

    // Für jemand anderen gebucht: wieder für diese Person suchen
    if ($Buchung->GetFahrerID() !== $ich) {
        $suche['fahrer'] = $Buchung->GetFahrerID();
    }

    return 'buchen.php?' . http_build_query($suche);
}

/**
 * Wie lange etwas schon wartet, in Kalendertagen, passend zu „Wartet seit“:
 * „heute“, „1 Tag“, „4 Tagen“.
 */
function wartet_seit(DateTimeImmutable $seit): string
{
    $tage = $seit->setTime(0, 0)->diff(new DateTimeImmutable('today'))->days;

    return match ($tage) {
        0       => 'heute',
        1       => '1 Tag',
        default => $tage . ' Tagen',
    };
}
