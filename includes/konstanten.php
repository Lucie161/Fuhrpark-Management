<?php
/**
 * Schlüssel der festen Wertelisten: je Schlüsseltabelle der Datenbank eine
 * Konstante pro Zeile.
 *
 * In der Datenbank stehen nur Zahlen (z. B. buchungen.status_id = 3). Im Code
 * wird nie mit der Zahl oder einem Wort verglichen, sondern immer mit der
 * Konstante:
 *   if ($buchung['status_id'] === STATUS_UNTERWEGS) { … }
 * Ein Tippfehler im Namen fällt dann sofort auf (PHP: „Undefined constant“),
 * ein Tippfehler in einem Wort wie 'untewegs' dagegen nie (Feedback vom
 * 15.10.2026, siehe docs/besprechungen/transkript-feedback-datenmodell-2026-10-15.md).
 *
 * Die IDs müssen mit den INSERTs in datenbank/schema.sql übereinstimmen.
 *
 * Die Bezeichnungen kommen aus der Datenbank über CListeSchluessel. Die Listen
 * ID => Bezeichnung hier (ZWECKE usw.) gelten nur noch, bis die letzte Seite
 * umgestellt ist; neuer Code nutzt sie nicht mehr. Die Konstanten bleiben.
 */

declare(strict_types=1);

// --- rollen -----------------------------------------------------------------

const ROLLE_MITARBEITER    = 1;
const ROLLE_FUHRPARKLEITER = 2;

// --- fahrzeugarten ----------------------------------------------------------
// Die Reihenfolge der Listen ist die Anzeigereihenfolge (Kalender, Filter),
// nicht die der IDs: Luxusautos stehen nach den Autos.
//
// Luxusautos (entschieden am 19.10.2026) sind Autos mit Sonderregeln: buchen
// dürfen sie nur Fahrer mit nutzer.darf_luxus, und schon ein Kleinschaden
// sperrt sie bis zur Reparatur (schaden_sperrt()). Wie Autos brauchen sie eine
// Genehmigung; für Montage und Materialtransport werden sie nicht angeboten.

const ART_AUTO        = 1;
const ART_TRANSPORTER = 2;
const ART_ROLLER      = 3;
const ART_FAHRRAD     = 4;
const ART_LUXUS       = 5;

const FAHRZEUGARTEN = [
    ART_AUTO        => 'Auto',
    ART_LUXUS       => 'Luxusauto',
    ART_TRANSPORTER => 'Transporter',
    ART_ROLLER      => 'Roller',
    ART_FAHRRAD     => 'Fahrrad',
];

// Mehrzahl für Filter und Gruppenüberschriften; steht nicht in der Datenbank.
const FAHRZEUGARTEN_MEHRZAHL = [
    ART_AUTO        => 'Autos',
    ART_LUXUS       => 'Luxusautos',
    ART_TRANSPORTER => 'Transporter',
    ART_ROLLER      => 'Roller',
    ART_FAHRRAD     => 'Fahrräder',
];

// --- antriebe ---------------------------------------------------------------

const ANTRIEB_BENZIN  = 1;
const ANTRIEB_DIESEL  = 2;
const ANTRIEB_ELEKTRO = 3;

const ANTRIEBE = [
    ANTRIEB_BENZIN  => 'Benzin',
    ANTRIEB_DIESEL  => 'Diesel',
    ANTRIEB_ELEKTRO => 'Elektro',
];

// --- buchungsstatus ---------------------------------------------------------
// Geordnet nach dem Ablauf: 1–3 sind laufend (die Buchung belegt das
// Fahrzeug), 4–6 erledigt. Damit gehen auch Vergleiche mit kleiner/größer:
//   $b['status_id'] <= STATUS_UNTERWEGS     – offen, genehmigt oder unterwegs
//   $b['status_id'] >= STATUS_ABGESCHLOSSEN – abgeschlossen, abgelehnt, storniert

const STATUS_OFFEN         = 1;
const STATUS_GENEHMIGT     = 2;
const STATUS_UNTERWEGS     = 3;
const STATUS_ABGESCHLOSSEN = 4;
const STATUS_ABGELEHNT     = 5;
const STATUS_STORNIERT     = 6;

// „offen“ heißt in der Oberfläche „beantragt“.
const BUCHUNGSSTATUS = [
    STATUS_OFFEN         => 'beantragt',
    STATUS_GENEHMIGT     => 'genehmigt',
    STATUS_UNTERWEGS     => 'unterwegs',
    STATUS_ABGESCHLOSSEN => 'abgeschlossen',
    STATUS_ABGELEHNT     => 'abgelehnt',
    STATUS_STORNIERT     => 'storniert',
];

// CSS-Modifier der Badges (.badge--offen usw.); steht nicht in der Datenbank.
const BUCHUNGSSTATUS_KLASSE = [
    STATUS_OFFEN         => 'offen',
    STATUS_GENEHMIGT     => 'genehmigt',
    STATUS_UNTERWEGS     => 'unterwegs',
    STATUS_ABGESCHLOSSEN => 'abgeschlossen',
    STATUS_ABGELEHNT     => 'abgelehnt',
    STATUS_STORNIERT     => 'storniert',
];

// --- zwecke -----------------------------------------------------------------
// Feste Liste der Zwecke (siehe docs/konzept/user-stories.md).

const ZWECK_MONTAGE           = 1;
const ZWECK_SERVICE           = 2;
const ZWECK_KUNDENTERMIN      = 3;
const ZWECK_AUFMASS           = 4;
const ZWECK_MATERIALTRANSPORT = 5;
const ZWECK_LIEFERANT         = 6;
const ZWECK_SCHULUNG          = 7;
const ZWECK_SONSTIGES         = 8;

const ZWECKE = [
    ZWECK_MONTAGE           => 'Montage',
    ZWECK_SERVICE           => 'Wartung/Service',
    ZWECK_KUNDENTERMIN      => 'Kundentermin',
    ZWECK_AUFMASS           => 'Aufmaß',
    ZWECK_MATERIALTRANSPORT => 'Materialtransport',
    ZWECK_LIEFERANT         => 'Lieferantenbesuch',
    ZWECK_SCHULUNG          => 'Schulung',
    ZWECK_SONSTIGES         => 'Sonstiges',
];

// --- schadensanlaesse -------------------------------------------------------
// Wann der Schaden gemeldet wurde: bei Fahrtbeginn (schon vorhanden) oder bei
// der Rückgabe. Ein Schaden gehört immer zu einer Buchung.

const ANLASS_UEBERNAHME = 1;
const ANLASS_RUECKGABE  = 2;

const SCHADENSANLAESSE = [
    ANLASS_UEBERNAHME => 'bei Übernahme',
    ANLASS_RUECKGABE  => 'bei Rückgabe',
];

// --- schweregrade -----------------------------------------------------------
// Ein Kleinschaden sperrt nichts; ein Schaden mit Wartung sperrt das Fahrzeug,
// bis der Fuhrparkleiter es freigibt.

const SCHWERE_KLEIN   = 1;
const SCHWERE_WARTUNG = 2;

const SCHWEREGRADE = [
    SCHWERE_KLEIN   => 'Kleinschaden',
    SCHWERE_WARTUNG => 'Schaden',
];
