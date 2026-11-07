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
 * Ampel: Muss eine Buchung dieser Fahrzeugart genehmigt werden? Autos und
 * Transporter ja („gelb“, Status „offen“), Roller und Fahrräder nein
 * („grün“, sofort „genehmigt“).
 */
function braucht_genehmigung(int $artId): bool
{
    return !in_array($artId, [ART_ROLLER, ART_FAHRRAD], true);
}

/**
 * Fehlt dem Fahrer die Freigabe für dieses Fahrzeug? Luxusautos dürfen nur
 * Nutzer mit darf_luxus fahren; wie ein fehlender
 * Führerschein ist das „rot“ in der Ampel.
 */
function fehlt_luxusfreigabe(int $fahrerId, array $fahrzeug): bool
{
    return $fahrzeug['art_id'] === ART_LUXUS && !(beispiel_nutzer()[$fahrerId]['darf_luxus'] ?? false);
}

/**
 * Sperrt ein Schaden das Fahrzeug? Ein Schaden mit Wartung immer, bei einem
 * Luxusauto auch ein Kleinschaden. Dann entsteht
 * bei der Rückgabe eine Wartung mit offenem Ende, bis der Fuhrparkleiter das
 * Fahrzeug freigibt. Ein bei Fahrtbeginn notierter Schaden hält die Fahrt
 * nicht auf; das Luxusauto wird erst bei der Rückgabe dieser Fahrt gesperrt.
 */
function schaden_sperrt(int $schwereId, int $artId): bool
{
    return $schwereId === SCHWERE_WARTUNG || $artId === ART_LUXUS;
}

/**
 * Belegt die Buchung ihr Fahrzeug? Ja, solange sie beantragt, genehmigt oder
 * unterwegs ist. Die Status-IDs sind nach dem Ablauf geordnet, daher genügt
 * ein Vergleich (siehe includes/konstanten.php).
 */
function belegt_fahrzeug(array $buchung): bool
{
    return $buchung['status_id'] <= STATUS_UNTERWEGS;
}

/**
 * Warum der Nutzer nicht buchen darf, sonst null: Wer eine überfällige
 * Rückgabe hat, bucht nichts Neues und wird auch von Kollegen nicht als
 * Fahrer eingetragen.
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
    return $fahrzeug['in_wartung']
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
    if (!$fahrzeug['in_wartung']) {
        return false;
    }

    return wartung_ende_offen($fahrzeug) || $von <= $fahrzeug['wartung_bis'];
}

/**
 * Warum ein Antrag nicht genehmigt werden kann, sonst null: Das
 * Fahrzeug ist ein Luxusauto und der Fahrer nicht freigegeben, es ist im
 * Zeitraum in Wartung, seine Rückgabe ist überfällig, oder es ist im Zeitraum
 * schon vergeben (genehmigt oder unterwegs).
 */
function genehmigung_hindernis(array $antrag): ?string
{
    if (fehlt_luxusfreigabe($antrag['fahrer_id'], beispiel_fahrzeuge()[$antrag['fahrzeug_id']])) {
        return 'Der Fahrer ist für Luxusautos nicht freigegeben.';
    }

    if (wartung_sperrt(beispiel_fahrzeuge()[$antrag['fahrzeug_id']], $antrag['start'], $antrag['ende'])) {
        return 'Das Fahrzeug ist im Zeitraum in Wartung.';
    }

    if (fahrzeug_ueberfaellig($antrag['fahrzeug_id'])) {
        return 'Die Rückgabe des Fahrzeugs ist überfällig.';
    }

    foreach (beispiel_buchungen() as $b) {
        if ($b['fahrzeug_id'] === $antrag['fahrzeug_id'] && $b['id'] !== $antrag['id']
            && in_array($b['status_id'], [STATUS_GENEHMIGT, STATUS_UNTERWEGS], true)
            && $b['start'] <= $antrag['ende'] && $b['ende'] >= $antrag['start']) {
            return 'Das Fahrzeug ist im Zeitraum bereits vergeben.';
        }
    }

    return null;
}

// Schadensfotos (Rückgabe): höchstens 3 Stück zu je 5 MB, nur JPEG, PNG oder
// WebP. Die Grenzen liegen unter post_max_size (XAMPP: 40 MB).
const FOTOS_MAX       = 3;
const FOTO_MAX_BYTES  = 5 * 1024 * 1024;
const FOTO_TYPEN      = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * Prüft die hochgeladenen Schadensfotos aus <input type="file" name="fotos[]" multiple>.
 *
 * Der Dateityp wird am Inhalt erkannt, nicht an Endung oder Browserangabe,
 * beides lässt sich fälschen. Liefert [gültige Fotos, Fehlermeldungen].
 */
function pruefe_fotos(array $dateien): array
{
    $fotos  = [];
    $fehler = [];

    foreach ($dateien['error'] ?? [] as $i => $code) {
        $name = (string) $dateien['name'][$i];

        if ($code === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
            || $dateien['size'][$i] > FOTO_MAX_BYTES) {
            $fehler[] = '„' . $name . '“ ist größer als ' . intdiv(FOTO_MAX_BYTES, 1024 * 1024) . ' MB.';
            continue;
        }

        if ($code !== UPLOAD_ERR_OK || !is_uploaded_file($dateien['tmp_name'][$i])) {
            $fehler[] = '„' . $name . '“ konnte nicht hochgeladen werden.';
            continue;
        }

        $typ = (new finfo(FILEINFO_MIME_TYPE))->file($dateien['tmp_name'][$i]);

        if (!in_array($typ, FOTO_TYPEN, true)) {
            $fehler[] = '„' . $name . '“ ist kein Foto im Format JPEG, PNG oder WebP.';
            continue;
        }

        $fotos[] = ['tmp_name' => $dateien['tmp_name'][$i], 'typ' => $typ];
    }

    if (count($fotos) > FOTOS_MAX) {
        $fehler[] = 'Bitte höchstens ' . FOTOS_MAX . ' Fotos anhängen.';
    }

    return [$fotos, $fehler];
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
