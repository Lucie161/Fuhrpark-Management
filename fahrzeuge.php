<?php
/**
 * Fahrzeuge, nur für den Fuhrparkleiter.
 *
 * Liste aller Fahrzeuge mit Status und HU/AU, filterbar nach Fahrzeugart,
 * Status und fälliger HU/AU, sortierbar nach Kennzeichen, km-Stand, HU/AU und
 * Baujahr. Fahrzeuge in Wartung setzen und wieder freigeben. Die gemeldeten
 * Schäden stehen in schaeden.php, hier nur ein Hinweis am Fahrzeug.
 * Mitarbeiter finden die Fahrzeuge in buchen.php und im Steckbrief.
 *
 * Statuspflege, keine Stammdatenpflege: Fahrzeuge werden hier weder angelegt
 * noch gelöscht. Ausgemusterte
 * Fahrzeuge (geloescht_am gesetzt) stehen nicht in der Liste.
 *
 * Filter und Sortierung kommen per GET, ohne JavaScript, z. B.
 * fahrzeuge.php?art=1&sortierung=hu_au&richtung=auf. Übernommen werden nur
 * erlaubte Werte, alles andere fällt auf den Standard zurück.
 *
 * Daten: CListeFahrzeuge::LadenMitFilter() (Sortierspalte nur aus
 * CListeFahrzeuge::SORTIERUNGEN), die betroffenen Buchungen aus
 * CListeBuchungen::LadenAbHeute().
 *
 * Wartung: „In Wartung setzen“ (CWartung::Create()) verlangt Grund und
 * voraussichtliches Ende, „Ende ändern“ (CWartung::AendereEnde()) das neue
 * Ende. Beantragte und genehmigte Buchungen, die vor dem Ende beginnen,
 * storniert CWartung dabei automatisch; laufende Fahrten bleiben, der
 * Fuhrparkleiter sieht einen Hinweis. „Freigeben“ (CWartung::Freigeben())
 * markiert zugleich den Schaden als behoben, der die Wartung ausgelöst hat.
 * Auch „Freigeben“ in schaeden.php schickt hierher.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle(ROLLE_FUHRPARKLEITER);

$pageTitle = 'Fahrzeuge';

$ich = aktueller_nutzer();
$heute = new DateTimeImmutable('today');

$Fahrzeugarten = new CListeSchluessel('fahrzeugarten');
$Zwecke = new CListeSchluessel('zwecke');
$Buchungsstatus = new CListeSchluessel('buchungsstatus');

// Angezeigter Status => Beschriftung, zugleich Wert im Filter und CSS-Klasse
// (.badge--wartung usw.). Gespeichert wird keiner davon, siehe CFahrzeug::GetStand().
$statusText = [
    'verfuegbar' => 'verfügbar',
    'unterwegs'  => 'unterwegs',
    'wartung'    => 'in Wartung',
];

// --- Filter und Sortierung (GET) --------------------------------------------

$artIDs = array_map(fn (CSchluessel $Art): string => (string) $Art->GetID(), $Fahrzeugarten->GetAlle());

$filter = [
    'art'    => erlaubter_wert('art', $artIDs),
    'status' => erlaubter_wert('status', array_keys($statusText)),
    'hu_au'  => erlaubter_wert('hu_au', ['faellig']),
];

$sortierung = erlaubter_wert('sortierung', CListeFahrzeuge::SORTIERUNGEN, 'kennzeichen');
$richtung   = erlaubter_wert('richtung', ['auf', 'ab'], 'auf');

// Parameter dieser Ansicht ohne Standardwerte. Formulare und Links der Seite
// hängen sie an, damit Filter und Sortierung nach einer Aktion erhalten
// bleiben.
$ansicht = array_filter($filter, fn (string $wert): bool => $wert !== '');

if ($sortierung !== 'kennzeichen' || $richtung !== 'auf') {
    $ansicht += ['sortierung' => $sortierung, 'richtung' => $richtung];
}

$seite = 'fahrzeuge.php' . ($ansicht !== [] ? '?' . http_build_query($ansicht) : '');

// --- Buchungen je Fahrzeug --------------------------------------------------
// Bestehende Buchungen ab heute (beantragt, genehmigt, unterwegs), mit Namen.

$ListeBuchungen = new CListeBuchungen();
$ListeBuchungen->LadenAbHeute(true);

$buchungenJeFahrzeug = [];

foreach ($ListeBuchungen->GetAlle() as $Buchung) {
    $buchungenJeFahrzeug[$Buchung->GetFahrzeugID()][] = $Buchung;
}

// --- Formular verarbeiten ---------------------------------------------------
// Läuft vor header.php, damit nach dem Speichern die Weiterleitung möglich
// ist. Bei einem Fehler bleibt die Seite stehen und das Wartungsformular
// dieses Fahrzeugs offen.

$fehler = [];
$offenesFormular = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Formularwerte einlesen. Fehlt ein Wert oder ist er kein Text, wird er
    // leer (bzw. null beim Datum); die Prüfung unten nennt dann den Grund.
    $id = (int) (is_string($_POST['fahrzeug'] ?? null) ? $_POST['fahrzeug'] : 0);
    $aktion = is_string($_POST['aktion'] ?? null) ? $_POST['aktion'] : '';
    $grund = is_string($_POST['grund'] ?? null) ? $_POST['grund'] : '';
    $bis = datum_aus_formular('wartung_bis');

    // Fahrzeug und seine laufende Wartung, falls es eine gibt
    $Fahrzeug = CFahrzeug::LoadByID($id);
    $Wartung = CWartung::LoadLaufende($id);

    try {
        if ($Fahrzeug === null || $Fahrzeug->IstAusgemustert()) {
            throw new CFachlicherFehler('Zu dieser Angabe gibt es kein Fahrzeug.');
        }

        // Freigeben braucht weder Grund noch Datum
        if ($aktion === 'freigeben') {
            if ($Wartung === null) {
                throw new CFachlicherFehler('Das Fahrzeug ist nicht in Wartung, es ist bereits freigegeben.');
            }

            $Wartung->Freigeben($ich);

            merke_meldung($Fahrzeug->GetName() . ' ist wieder freigegeben und kann gebucht werden.'
                . ($Wartung->GetSchadenID() !== null ? ' Der gemeldete Schaden gilt damit als behoben.' : ''));
            redirect($seite);
        }

        if ($aktion !== 'wartung' && $aktion !== 'verlaengern') {
            throw new CFachlicherFehler('Diese Aktion gibt es nicht.');
        }

        // Ab hier geht es um das Wartungsformular: Bei einem Fehler bleibt es
        // aufgeklappt, damit man die Eingabe korrigieren kann.
        $offenesFormular = $id;

        if ($bis === null) {
            throw new CFachlicherFehler('Bitte geben Sie das voraussichtliche Ende der Wartung an.');
        }

        // Neue Wartung anlegen oder das Ende der laufenden ändern. Die Regeln
        // (Rolle, Grund, Datum, schon in Wartung) prüft CWartung.
        if ($aktion === 'wartung') {
            $Wartung = CWartung::Create($id, $ich, $grund, $bis);
        } elseif ($Wartung === null) {
            throw new CFachlicherFehler('Das Fahrzeug ist nicht mehr in Wartung. Das Ende lässt sich nicht ändern.');
        } else {
            $Wartung->AendereEnde($ich, $bis);
        }

        // Gespeichert: Bestätigung merken und zurück zur Liste, mit denselben
        // Filtern. So schickt ein Neuladen das Formular nicht noch einmal.
        merke_meldung(wartung_meldung($Fahrzeug, $Wartung, $buchungenJeFahrzeug[$id] ?? []), 'hinweis');
        redirect($seite);
    } catch (CFachlicherFehler $Fehler) {
        // Regel verletzt: Die Seite bleibt stehen und zeigt den Grund an.
        $fehler[] = $Fehler->getMessage();
    }
}

// --- Fahrzeuge laden --------------------------------------------------------

$ListeFahrzeuge = new CListeFahrzeuge();
$ListeFahrzeuge->LadenMitFilter(
    (int) $filter['art'],
    $filter['status'],
    $filter['hu_au'] !== '',
    $sortierung,
    $richtung === 'ab'
);

// Für „3 von 9 Fahrzeugen“
$ListeBestand = new CListeFahrzeuge();
$ListeBestand->LadenImBestand();
$anzahlBestand = $ListeBestand->Count();

// Spalten der Tabelle: Überschrift, Sortierung (null = nicht sortierbar),
// CSS-Klasse.
$spalten = [
    ['Fahrzeug',       'kennzeichen', ''],
    ['Baujahr',        'baujahr',     ''],
    ['km-Stand',       'kmstand',     'table__num'],
    ['Status',         null,          ''],
    ['Nächste HU/AU',  'hu_au',       ''],
    ['Aktionen',       null,          ''],
];

/**
 * GET-Parameter, sofern er in der Liste erlaubter Werte steht, sonst der
 * Standard.
 */
function erlaubter_wert(string $name, array $erlaubt, string $standard = ''): string
{
    $wert = $_GET[$name] ?? '';

    return is_string($wert) && in_array($wert, $erlaubt, true) ? $wert : $standard;
}

/**
 * Datum aus einem Formularfeld (JJJJ-MM-TT), null wenn leer oder ungültig.
 */
function datum_aus_formular(string $name): ?DateTimeImmutable
{
    $wert = is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
    $datum = DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

    // createFromFormat() macht aus dem 31.02. still den 03.03. Nur wenn das
    // Datum zurückformatiert gleich der Eingabe ist, gab es den Tag wirklich.
    return $datum !== false && $datum->format('Y-m-d') === $wert ? $datum : null;
}

/**
 * Bestätigung nach „In Wartung setzen“ oder „Ende ändern“: bis wann, welche
 * Buchungen storniert wurden und wer noch unterwegs ist.
 */
function wartung_meldung(CFahrzeug $Fahrzeug, CWartung $Wartung, array $buchungen): string
{
    // Bis wann das Fahrzeug gesperrt ist
    $text = $Fahrzeug->GetName() . ' ist in Wartung, voraussichtlich bis '
        . $Wartung->GetVoraussichtlichBis()->format('d.m.Y') . '. Bis dahin kann es nicht gebucht werden.';

    // Die stornierten Buchungen mit Zeitraum und Fahrer aufzählen
    $storniert = array_map(
        fn (CBuchung $Buchung): string => zeitraum_text($Buchung->GetStart(), $Buchung->GetEnde())
            . ' (' . $Buchung->GetFahrerName() . ')',
        $Wartung->GetStornierte()
    );

    if ($storniert !== []) {
        $text .= ' Automatisch storniert, die Fahrer sehen den Grund unter „Frühere Buchungen“: '
            . implode('; ', $storniert) . '.';
    }

    // Laufende Fahrten storniert niemand; der Fuhrparkleiter muss die
    // Rückgabe selbst klären.
    foreach ($buchungen as $Buchung) {
        if ($Buchung->GetStatusID() === STATUS_UNTERWEGS) {
            $text .= ' ' . $Buchung->GetFahrerName() . ' ist mit dem Fahrzeug noch unterwegs.'
                . ' Bitte klären Sie die Rückgabe mit dem Fahrer.';
        }
    }

    return $text;
}

/**
 * Link einer Spaltenüberschrift: sortiert nach dieser Spalte aufsteigend,
 * ist sie es schon, kehrt er die Richtung um. Der Filter bleibt erhalten.
 */
function sortier_link(string $spalte, string $sortierung, string $richtung, array $filter): string
{
    $neueRichtung = $spalte === $sortierung && $richtung === 'auf' ? 'ab' : 'auf';
    $parameter = array_filter($filter, fn (string $wert): bool => $wert !== '')
        + ['sortierung' => $spalte, 'richtung' => $neueRichtung];

    return url('fahrzeuge.php?' . http_build_query($parameter));
}

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Alle Fahrzeuge mit Status und HU/AU. Fahrzeuge für Wartung oder Reparatur sperren und wieder
    freigeben. Gemeldete Schäden stehen unter <a href="<?= url('schaeden.php') ?>">Schäden</a>.
</p>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="section">
    <form class="filter" method="get" action="<?= url('fahrzeuge.php') ?>">
        <?php if (isset($ansicht['sortierung'])): ?>
            <input type="hidden" name="sortierung" value="<?= e($sortierung) ?>">
            <input type="hidden" name="richtung" value="<?= e($richtung) ?>">
        <?php endif; ?>

        <div class="filter__feld filter__feld--schmal">
            <label class="form__label" for="art">Fahrzeugart</label>
            <select class="form__input" id="art" name="art">
                <option value="">Alle</option>
                <?php foreach ($Fahrzeugarten->GetAlle() as $Art): ?>
                    <?php $wert = (string) $Art->GetID(); ?>
                    <option value="<?= e($wert) ?>"<?= $wert === $filter['art'] ? ' selected' : '' ?>><?= e($Art->GetPlural()) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter__feld filter__feld--schmal">
            <label class="form__label" for="status">Status</label>
            <select class="form__input" id="status" name="status">
                <option value="">Alle</option>
                <?php foreach ($statusText as $wert => $text): ?>
                    <option value="<?= e($wert) ?>"<?= $wert === $filter['status'] ? ' selected' : '' ?>><?= e($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter__feld filter__feld--schmal">
            <label class="form__label" for="hu_au">HU/AU</label>
            <select class="form__input" id="hu_au" name="hu_au">
                <option value="">Alle</option>
                <option value="faellig"<?= $filter['hu_au'] === 'faellig' ? ' selected' : '' ?>>bald fällig oder überfällig</option>
            </select>
        </div>

        <button class="button" type="submit">Filtern</button>

        <?php if (array_filter($filter) !== []): ?>
            <a class="button button--zweitrangig"
               href="<?= e(url('fahrzeuge.php' . (isset($ansicht['sortierung']) ? '?' . http_build_query(['sortierung' => $sortierung, 'richtung' => $richtung]) : ''))) ?>">Filter zurücksetzen</a>
        <?php endif; ?>
    </form>

    <p class="lead">
        <?php if ($ListeFahrzeuge->Count() === $anzahlBestand): ?>
            <?= e((string) $anzahlBestand) ?> Fahrzeuge.
        <?php else: ?>
            <?= e((string) $ListeFahrzeuge->Count()) ?> von <?= e((string) $anzahlBestand) ?> Fahrzeugen.
        <?php endif; ?>
        Zum Sortieren auf eine Spaltenüberschrift klicken.
    </p>

    <table class="table">
        <thead>
            <tr>
                <?php foreach ($spalten as [$titel, $spalte, $klasse]): ?>
                    <?php $aktiv = $spalte === $sortierung; ?>
                    <th<?= $klasse !== '' ? ' class="' . e($klasse) . '"' : '' ?><?= $aktiv ? ' aria-sort="' . ($richtung === 'auf' ? 'ascending' : 'descending') . '"' : '' ?>>
                        <?php if ($spalte === null): ?>
                            <?= e($titel) ?>
                        <?php else: ?>
                            <a class="table__sortierlink" href="<?= e(sortier_link($spalte, $sortierung, $richtung, $filter)) ?>"><?= e($titel) ?><?php if ($aktiv): ?>
                                <span aria-hidden="true"><?= $richtung === 'auf' ? '&uarr;' : '&darr;' ?></span><?php endif; ?></a>
                        <?php endif; ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($ListeFahrzeuge->GetAlle() as $Fahrzeug): ?>
                <?php
                $id = $Fahrzeug->GetID();
                $stand = $Fahrzeug->GetStand();
                $huAuStand = $Fahrzeug->GetHuAuStand();
                $buchungen = $buchungenJeFahrzeug[$id] ?? [];
                ?>
                <tr>
                    <td>
                        <a href="<?= url('fahrzeug.php?id=' . $id) ?>"><?= e($Fahrzeug->GetHersteller() . ' ' . $Fahrzeug->GetModell()) ?></a>
                        <span class="table__zusatz"><?= e($Fahrzeug->GetKennzeichen()) ?></span>
                    </td>
                    <td><?= e((string) $Fahrzeug->GetBaujahr()) ?></td>
                    <td class="table__num">
                        <?= $Fahrzeug->GetKmStand() !== null ? e(number_format($Fahrzeug->GetKmStand(), 0, ',', '.')) : '&ndash;' ?>
                    </td>
                    <td>
                        <span class="badge badge--<?= e($stand) ?>">
                            <?= e($statusText[$stand] ?? $stand) ?>
                        </span>
                        <?php if ($stand === 'wartung' && $Fahrzeug->IstUnterwegs()): ?>
                            <span class="badge badge--unterwegs">unterwegs</span>
                        <?php endif; ?>
                        <?php if ($Fahrzeug->IstWartungEndeOffen()): ?>
                            <span class="badge badge--abgelehnt">Ende festlegen</span>
                        <?php endif; ?>
                        <?php if ($Fahrzeug->IstInWartung()): ?>
                            <span class="table__zusatz"><?= e($Fahrzeug->GetWartungText()) ?></span>
                        <?php endif; ?>
                        <?php if ($Fahrzeug->IstUeberfaellig()): ?>
                            <span class="badge badge--abgelehnt">Rückgabe überfällig</span>
                        <?php endif; ?>
                        <?php if ($Fahrzeug->IstWartungWegenSchaden()): ?>
                            <span class="table__zusatz">
                                <a href="<?= url('schaeden.php') ?>">Schaden gemeldet</a>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($Fahrzeug->GetHuAu() === null): ?>
                            &ndash;
                        <?php else: ?>
                            <?= e($Fahrzeug->GetHuAu()->format('m/Y')) ?>
                            <?php if ($huAuStand === 'ueberfaellig'): ?>
                                <span class="badge badge--abgelehnt">überfällig</span>
                            <?php elseif ($huAuStand === 'faellig'): ?>
                                <span class="badge badge--offen">bald fällig</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="aktionen">
                            <?php
                            // Dasselbe Formular zum Sperren und zum Ändern des Endes.
                            $wartungAktion = $Fahrzeug->IstInWartung() ? 'verlaengern' : 'wartung';
                            $wartungWert = $Fahrzeug->IstInWartung() && !$Fahrzeug->IstWartungEndeOffen()
                                ? $Fahrzeug->GetWartungBis()->format('Y-m-d')
                                : '';
                            ?>
                            <details class="klappaktion"<?= $offenesFormular === $id ? ' open' : '' ?>>
                                <summary class="button button--klein"><?= $wartungAktion === 'wartung' ? 'In Wartung setzen' : 'Ende ändern' ?></summary>

                                <form class="klappaktion__form" method="post" action="<?= e(url($seite)) ?>">
                                    <input type="hidden" name="fahrzeug" value="<?= e((string) $id) ?>">
                                    <input type="hidden" name="aktion" value="<?= e($wartungAktion) ?>">

                                    <?php if ($wartungAktion === 'wartung'): ?>
                                        <label class="form__label" for="grund-<?= e((string) $id) ?>">Grund</label>
                                        <input class="form__input" type="text" id="grund-<?= e((string) $id) ?>"
                                               name="grund" required maxlength="200" placeholder="z. B. Inspektion, HU/AU">
                                    <?php endif; ?>

                                    <label class="form__label" for="wartung-bis-<?= e((string) $id) ?>">Voraussichtlich bis</label>
                                    <input class="form__input" type="date" id="wartung-bis-<?= e((string) $id) ?>"
                                           name="wartung_bis" required min="<?= e($heute->format('Y-m-d')) ?>"
                                           value="<?= e($wartungWert) ?>">

                                    <?php if ($buchungen !== []): ?>
                                        <p class="klappaktion__frage">
                                            Beantragte und genehmigte Buchungen, die bis dahin beginnen, werden
                                            automatisch storniert:
                                        </p>
                                        <ul class="klappaktion__liste">
                                            <?php foreach ($buchungen as $Buchung): ?>
                                                <li>
                                                    <?= e(zeitraum_text($Buchung->GetStart(), $Buchung->GetEnde())) ?>,
                                                    <?= e($Buchung->GetFahrerName()) ?>,
                                                    <?= e($Zwecke->GetBezeichnung($Buchung->GetZweckID())) ?>
                                                    (<?= e($Buchungsstatus->GetBezeichnung($Buchung->GetStatusID())) ?>)
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <p class="klappaktion__frage">Es bestehen keine Buchungen.</p>
                                    <?php endif; ?>

                                    <button class="button button--klein" type="submit">
                                        <?= $wartungAktion === 'wartung' ? 'Fahrzeug sperren' : 'Ende speichern' ?>
                                    </button>
                                </form>
                            </details>

                            <?php if ($Fahrzeug->IstInWartung()): ?>
                                <form method="post" action="<?= e(url($seite)) ?>">
                                    <input type="hidden" name="fahrzeug" value="<?= e((string) $id) ?>">
                                    <input type="hidden" name="aktion" value="freigeben">
                                    <button class="button button--klein" type="submit">Freigeben</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($ListeFahrzeuge->Count() === 0): ?>
                <tr>
                    <td colspan="<?= e((string) count($spalten)) ?>" class="table__empty">
                        <?= $anzahlBestand === 0 ? 'Keine Fahrzeuge erfasst.' : 'Kein Fahrzeug passt zu diesem Filter.' ?>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
