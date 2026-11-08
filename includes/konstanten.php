<?php
/**
 * Schlüssel der festen Wertelisten: je Schlüsseltabelle der Datenbank eine
 * Konstante pro Zeile.
 *
 * In der Datenbank stehen nur Zahlen (z. B. buchungen.status_id = 3). Im Code
 * wird nie mit der Zahl oder einem Wort verglichen, sondern immer mit der
 * Konstante:
 *   if ($Buchung->GetStatusID() === STATUS_UNTERWEGS) { … }
 * Ein Tippfehler im Namen fällt dann sofort auf (PHP: „Undefined constant“),
 * ein Tippfehler in einem Wort wie 'untewegs' dagegen nie.
 *
 * Die IDs müssen mit den INSERTs in datenbank/schema.sql übereinstimmen.
 *
 * Die Bezeichnungen (z. B. „Kundentermin“) kommen aus der Datenbank über
 * CListeSchluessel, hier stehen nur die IDs.
 */

declare(strict_types=1);

// --- rollen -----------------------------------------------------------------

const ROLLE_MITARBEITER    = 1;
const ROLLE_FUHRPARKLEITER = 2;

// --- fahrzeugarten ----------------------------------------------------------
// Die IDs sind zugleich die Anzeigereihenfolge (Kalender, Filter):
// Luxusautos stehen direkt nach den Autos.
//
// Luxusautos sind Autos mit Sonderregeln: buchen
// dürfen sie nur Fahrer mit nutzer.darf_luxus, und schon ein Kleinschaden
// sperrt sie bis zur Reparatur (CSchaden::Sperrt()). Wie Autos brauchen sie eine
// Genehmigung; für Montage und Materialtransport werden sie nicht angeboten.

const ART_AUTO        = 1;
const ART_LUXUS       = 2;
const ART_TRANSPORTER = 3;
const ART_ROLLER      = 4;
const ART_FAHRRAD     = 5;

// --- antriebe ---------------------------------------------------------------

const ANTRIEB_BENZIN  = 1;
const ANTRIEB_DIESEL  = 2;
const ANTRIEB_ELEKTRO = 3;

// --- buchungsstatus ---------------------------------------------------------
// Geordnet nach dem Ablauf: 1–3 sind laufend (die Buchung belegt das
// Fahrzeug), 4–6 erledigt. Damit gehen auch Vergleiche mit kleiner/größer:
//   $Buchung->GetStatusID() <= STATUS_UNTERWEGS     – offen, genehmigt oder unterwegs
//   $Buchung->GetStatusID() >= STATUS_ABGESCHLOSSEN – abgeschlossen, abgelehnt, storniert

const STATUS_OFFEN         = 1;
const STATUS_GENEHMIGT     = 2;
const STATUS_UNTERWEGS     = 3;
const STATUS_ABGESCHLOSSEN = 4;
const STATUS_ABGELEHNT     = 5;
const STATUS_STORNIERT     = 6;

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
// Feste Liste der Zwecke.

const ZWECK_MONTAGE           = 1;
const ZWECK_SERVICE           = 2;
const ZWECK_KUNDENTERMIN      = 3;
const ZWECK_AUFMASS           = 4;
const ZWECK_MATERIALTRANSPORT = 5;
const ZWECK_LIEFERANT         = 6;
const ZWECK_SCHULUNG          = 7;
const ZWECK_SONSTIGES         = 8;

// --- schadensanlaesse -------------------------------------------------------
// Wann der Schaden gemeldet wurde: bei Fahrtbeginn (schon vorhanden) oder bei
// der Rückgabe. Ein Schaden gehört immer zu einer Buchung.

const ANLASS_UEBERNAHME = 1;
const ANLASS_RUECKGABE  = 2;

// --- schweregrade -----------------------------------------------------------
// Ein Kleinschaden sperrt nichts; ein Schaden mit Wartung sperrt das Fahrzeug,
// bis der Fuhrparkleiter es freigibt.

const SCHWERE_KLEIN   = 1;
const SCHWERE_WARTUNG = 2;
