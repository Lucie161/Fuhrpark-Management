<?php
/**
 * Zeitraum und Filter für historie.php und analyse.php, aus der Adresse
 * (GET): von, bis, auswahl ('art:1' = Kategorie, 'id:3' = ein Fahrzeug) und
 * fahrer (Nutzer-ID). Ungültige Angaben landen als Satz in $fehler.
 *
 * Erwartet von der einbindenden Seite:
 *   $filterStandardVon  Beginn des Zeitraums ohne Angabe, z. B. der Erste des
 *                       Vormonats; das Ende ist dann heute
 *
 * Setzt für die Seite:
 *   $Fahrzeuge          alle Fahrzeuge, auch ausgemusterte (CListeFahrzeuge)
 *   $mitarbeiter        Nutzer-ID => „Nachname, Vorname“, auch ausgeschiedene
 *   $auswahlKategorien, $auswahlFahrzeuge  Werte der Fahrzeugauswahl
 *   $eingabe            die Eingaben als Text, für die Formularfelder
 *   $von, $bis          der Zeitraum, null bei ungültiger Eingabe
 *   $auswahlText        Name der gewählten Auswahl, sonst null
 *   $fahrerFilter       Nutzer-ID oder null
 *   $listeGefiltert     true, wenn Fahrzeug oder Mitarbeiter gewählt ist
 *   $fehler             Liste der Fehler
 */

declare(strict_types=1);

// Alle Fahrzeuge, auch ausgemusterte: Ihre Fahrten gehören zur Auswertung.
$Fahrzeuge = new CListeFahrzeuge();
$Fahrzeuge->LadenAlle();

// Mitarbeiter für den Filter, nach Nachname, auch ausgeschiedene
$ListeNutzer = new CListeNutzer();
$ListeNutzer->LadenAlle();

$mitarbeiter = [];

foreach ($ListeNutzer->GetAlle() as $Nutzer) {
    if ($Nutzer->GetRolleID() === ROLLE_MITARBEITER) {
        $mitarbeiter[$Nutzer->GetID()] = $Nutzer->GetNachname() . ', ' . $Nutzer->GetVorname()
            . ($Nutzer->IstAusgeschieden() ? ' (ausgeschieden)' : '');
    }
}

$heute = new DateTimeImmutable('today');
$fehler = [];
$eingabe = [];

// --- Zeitraum ---------------------------------------------------------------

foreach (['von', 'bis'] as $feld) {
    // Ein Array (?von[]=…) gilt als ungültiges Datum statt einer PHP-Warnung.
    $wert = $_GET[$feld] ?? '';
    $eingabe[$feld] = is_string($wert) ? trim($wert) : '-';
}

$von = $eingabe['von'] === '' ? $filterStandardVon : datum_aus_eingabe($eingabe['von']);
$bis = $eingabe['bis'] === '' ? $heute : datum_aus_eingabe($eingabe['bis']);

if ($von === null) {
    $fehler[] = 'Bitte geben Sie für „Von“ ein gültiges Datum ein.';
}

if ($bis === null) {
    $fehler[] = 'Bitte geben Sie für „Bis“ ein gültiges Datum ein.';
}

if ($von !== null && $bis !== null && $von > $bis) {
    $fehler[] = '„Von“ darf nicht nach „Bis“ liegen.';
}

// Leere Felder zeigen den verwendeten Standardzeitraum an.
if ($eingabe['von'] === '' && $von !== null) {
    $eingabe['von'] = $von->format('Y-m-d');
}

if ($eingabe['bis'] === '' && $bis !== null) {
    $eingabe['bis'] = $bis->format('Y-m-d');
}

// --- Fahrzeug oder Kategorie, Mitarbeiter -----------------------------------

$auswahlKategorien = [];

foreach ((new CListeSchluessel('fahrzeugarten'))->GetAlle() as $Art) {
    $auswahlKategorien['art:' . $Art->GetID()] = $Art->GetPlural();
}

$auswahlFahrzeuge = [];

foreach ($Fahrzeuge->GetAlle() as $Fahrzeug) {
    $auswahlFahrzeuge['id:' . $Fahrzeug->GetID()] = $Fahrzeug->GetName() . ($Fahrzeug->IstAusgemustert() ? ', ausgemustert' : '');
}

$wert = $_GET['auswahl'] ?? '';
$eingabe['auswahl'] = is_string($wert) ? $wert : '-';

$auswahlText = $auswahlKategorien[$eingabe['auswahl']] ?? $auswahlFahrzeuge[$eingabe['auswahl']] ?? null;

if ($eingabe['auswahl'] !== '' && $auswahlText === null) {
    $fehler[] = 'Diese Fahrzeugauswahl gibt es nicht.';
}

$wert = $_GET['fahrer'] ?? '';
$eingabe['fahrer'] = is_string($wert) ? $wert : '-';

$fahrerFilter = $eingabe['fahrer'] === '' ? null : (int) $eingabe['fahrer'];

if ($fahrerFilter !== null && (!isset($mitarbeiter[$fahrerFilter]) || (string) $fahrerFilter !== $eingabe['fahrer'])) {
    $fehler[] = 'Diesen Mitarbeiter gibt es nicht.';
}

$listeGefiltert = $eingabe['auswahl'] !== '' || $fahrerFilter !== null;

/**
 * Ob ein Fahrzeug zur Auswahl im Filter passt: '' (alle), 'art:…' oder 'id:…'.
 */
function passt_zur_auswahl(int $fahrzeugId, string $auswahl, CListeFahrzeuge $Fahrzeuge): bool
{
    if ($auswahl === '') {
        return true;
    }

    [$feld, $wert] = explode(':', $auswahl, 2);

    return $feld === 'art'
        ? (string) $Fahrzeuge->GetByID($fahrzeugId)->GetArtID() === $wert
        : (string) $fahrzeugId === $wert;
}

/**
 * Nur der Tag ohne Uhrzeit, damit eine Fahrt an einem Tag als ein Datum
 * erscheint (zeitraum_kurz() vergleicht ganze Zeitpunkte).
 */
function nur_tag(DateTimeImmutable $zeitpunkt): DateTimeImmutable
{
    return $zeitpunkt->setTime(0, 0);
}
