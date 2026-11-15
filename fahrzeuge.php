<?php
/**
 * Fahrzeuge, nur für den Fuhrparkleiter.
 *
 * Tabelle aller Fahrzeuge mit Bild, Stand und HU/AU, darüber eine Filterleiste
 * (Fahrzeugart, Stand, fällige HU/AU), sortierbar nach Kennzeichen, Baujahr,
 * km-Stand und HU/AU. Fahrzeuge in Wartung setzen und wieder freigeben. Die
 * gemeldeten Schäden stehen in schaeden.php, hier nur ein Hinweis am
 * Fahrzeug. Mitarbeiter finden die Fahrzeuge in buchen.php und im Steckbrief.
 *
 * Statuspflege, keine Stammdatenpflege: Fahrzeuge werden hier nicht angelegt
 * und ihre Merkmale nicht geändert. „Ausmustern“ (CFahrzeug::Ausmustern(),
 * mit Rückfrage als Popup) nimmt ein Fahrzeug aus dem Bestand; die
 * ausgemusterten stehen getrennt in einer eigenen Tabelle unter der Liste.
 *
 * Filter und Sortierung kommen per GET, ohne JavaScript, z. B.
 * fahrzeuge.php?art=1&sortierung=hu_au&richtung=auf. Übernommen werden nur
 * erlaubte Werte, alles andere fällt auf den Standard zurück.
 *
 * „In Wartung setzen“ und „Ende ändern“ sind Links mit ?formular=ID: Das
 * Formular steht dann als eigene Zeile in voller Breite unter dem Fahrzeug.
 * Filter und Sortierung bleiben dabei im Link.
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
 * Auch „Freigeben“ in schaeden.php schickt hierher (mit zurueck=schaeden).
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

// Stand eines Fahrzeugs (CFahrzeug::GetStand()) => [Wort, Farbe] für die
// Marke. Die Schlüssel sind zugleich die Werte im Filter.
$standMarke = [
    'verfuegbar' => ['Frei', 'gruen'],
    'unterwegs'  => ['Unterwegs', 'grau'],
    'wartung'    => ['In Wartung', 'gelb'],
];

// --- Filter und Sortierung (GET) --------------------------------------------

$artIDs = array_map(fn (CSchluessel $Art): string => (string) $Art->GetID(), $Fahrzeugarten->GetAlle());

$filter = [
    'art'    => erlaubter_wert('art', $artIDs),
    'status' => erlaubter_wert('status', array_keys($standMarke)),
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

// Geöffnetes Wartungsformular: Fahrzeug-ID aus ?formular=ID, sonst null
$formular = (int) (is_string($_GET['formular'] ?? null) ? $_GET['formular'] : 0);
$formular = $formular > 0 ? $formular : null;

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
// dieses Fahrzeugs offen, mit der bisherigen Eingabe.

$fehler = [];
$eingabe = ['grund' => '', 'wartung_bis' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Formularwerte einlesen. Fehlt ein Wert oder ist er kein Text, wird er
    // leer (bzw. null beim Datum); die Prüfung unten nennt dann den Grund.
    $id = (int) (is_string($_POST['fahrzeug'] ?? null) ? $_POST['fahrzeug'] : 0);
    $aktion = is_string($_POST['aktion'] ?? null) ? $_POST['aktion'] : '';
    $grund = is_string($_POST['grund'] ?? null) ? $_POST['grund'] : '';
    $bis = datum_aus_formular('wartung_bis');
    $zurueck = ($_POST['zurueck'] ?? '') === 'schaeden' ? 'schaeden.php' : $seite;

    // Fahrzeug und seine laufende Wartung, falls es eine gibt
    $Fahrzeug = CFahrzeug::LoadByID($id);
    $Wartung = CWartung::LoadLaufende($id);

    try {
        if ($Fahrzeug === null || $Fahrzeug->IstAusgemustert()) {
            throw new CFachlicherFehler('Zu dieser Angabe gibt es kein Fahrzeug.');
        }

        // Freigeben braucht weder Grund noch Datum. Danach steht die
        // abgeschlossene Wartung in der Historie.
        if ($aktion === 'freigeben') {
            if ($Wartung === null) {
                throw new CFachlicherFehler('Das Fahrzeug ist nicht in Wartung, es ist bereits freigegeben.');
            }

            $Wartung->Freigeben($ich);

            merke_meldung(
                $Fahrzeug->GetName() . ' ist wieder freigegeben und kann gebucht werden.'
                    . ($Wartung->GetSchadenID() !== null ? ' Der gemeldete Schaden gilt damit als behoben.' : ''),
                'erfolg',
                ['Zur Historie', 'historie.php?auswahl=id:' . $id]
            );
            redirect($zurueck);
        }

        // Ausmustern: Buchungen werden storniert, die Bestätigung zählt sie auf
        if ($aktion === 'ausmustern') {
            $Stornierte = $Fahrzeug->Ausmustern($ich);

            merke_meldung(
                $Fahrzeug->GetName() . ' ist ausgemustert und kann nicht mehr gebucht werden.'
                    . stornierte_text($Stornierte),
                'erfolg',
                ['Zum Steckbrief', 'fahrzeug.php?id=' . $id]
            );
            redirect($seite);
        }

        if ($aktion !== 'wartung' && $aktion !== 'verlaengern') {
            throw new CFachlicherFehler('Diese Aktion gibt es nicht.');
        }

        // Ab hier geht es um das Wartungsformular: Bei einem Fehler bleibt es
        // offen und behält die Eingabe, damit man sie korrigieren kann.
        $formular = $id;
        $eingabe = [
            'grund'       => $grund,
            'wartung_bis' => is_string($_POST['wartung_bis'] ?? null) ? $_POST['wartung_bis'] : '',
        ];

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
        // Die Sperre ist danach im Kalender zu sehen.
        merke_meldung(
            wartung_meldung($Fahrzeug, $Wartung, $buchungenJeFahrzeug[$id] ?? []),
            'hinweis',
            ['Im Kalender ansehen', 'kalender.php']
        );
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

// Ausgemusterte, getrennt unter der Liste
$ListeAusgemustert = new CListeFahrzeuge();
$ListeAusgemustert->LadenAusgemusterte();

// Spalten der Tabelle: Überschrift, Sortierung (null = nicht sortierbar),
// CSS-Klasse. Der Stand steht hinter den Kennwerten, damit er auf dem Handy
// rechts neben dem Kennzeichen sitzt (.table--stapel).
$spalten = [
    ['Fahrzeug',  'kennzeichen', ''],
    ['Art',       null,          ''],
    ['Baujahr',   'baujahr',     ''],
    ['km-Stand',  'kmstand',     'table__num'],
    ['HU/AU',     'hu_au',       ''],
    ['Stand',     null,          ''],
    ['Aktionen',  null,          ''],
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
        . $Wartung->GetVoraussichtlichBis()->format('d.m.Y') . '. Bis dahin kann es nicht gebucht werden.'
        . stornierte_text($Wartung->GetStornierte());

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
 * Satz zu den automatisch stornierten Buchungen mit Zeitraum und Fahrer, leer
 * ohne Stornierung.
 */
function stornierte_text(array $buchungen): string
{
    if ($buchungen === []) {
        return '';
    }

    $liste = array_map(
        fn (CBuchung $Buchung): string => zeitraum_text($Buchung->GetStart(), $Buchung->GetEnde())
            . ' (' . $Buchung->GetFahrerName() . ')',
        $buchungen
    );

    return ' Automatisch storniert, die Fahrer sehen den Grund unter „Frühere Buchungen“: '
        . implode('; ', $liste) . '.';
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
    Übersicht aller Fahrzeuge. Hier setzen Sie ein Fahrzeug in Wartung, geben es wieder frei oder
    mustern es aus.
</p>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Filterleiste: per GET, die Sortierung bleibt erhalten. Auf dem Handy
     zugeklappt, solange kein Filter gesetzt ist, am Desktop immer offen. -->
<?php $anzahlFilter = count(array_filter($filter)); ?>
<details class="filterklappe"<?= $anzahlFilter > 0 ? ' open' : '' ?>>
    <summary class="filterklappe__kopf">
        Filter
        <?php if ($anzahlFilter > 0): ?>
            <span class="filterklappe__zusatz"><?= e((string) $anzahlFilter) ?> gesetzt</span>
        <?php endif; ?>
    </summary>
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
            <label class="form__label" for="status">Stand</label>
            <select class="form__input" id="status" name="status">
                <option value="">Alle</option>
                <?php foreach ($standMarke as $wert => $marke): ?>
                    <option value="<?= e($wert) ?>"<?= $wert === $filter['status'] ? ' selected' : '' ?>><?= e($marke[0]) ?></option>
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

        <div class="aktionen">
            <button class="button" type="submit">Filtern</button>

            <?php if (array_filter($filter) !== []): ?>
                <a class="button button--zweitrangig"
                   href="<?= e(url('fahrzeuge.php' . (isset($ansicht['sortierung']) ? '?' . http_build_query(['sortierung' => $sortierung, 'richtung' => $richtung]) : ''))) ?>">Filter zurücksetzen</a>
            <?php endif; ?>
        </div>
    </form>
</details>

<section aria-labelledby="fahrzeugliste">
    <h2 class="abschnitt" id="fahrzeugliste">
        <?= array_filter($filter) !== [] ? 'Gefiltert' : 'Im Bestand' ?>
        <span class="abschnitt__wert">
            <?php if ($ListeFahrzeuge->Count() === $anzahlBestand): ?>
                <?= e((string) $anzahlBestand) ?> Fahrzeuge
            <?php else: ?>
                <?= e((string) $ListeFahrzeuge->Count()) ?> von <?= e((string) $anzahlBestand) ?> Fahrzeugen
            <?php endif; ?>
        </span>
    </h2>

    <table class="table table--stapel fahrzeugliste">
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
                $ueberfaellig = $Fahrzeug->IstUeberfaellig();
                $offen = $formular === $id;

                // Stand als Marke; eine überfällige Rückgabe ist rot
                $marke = $standMarke[$stand];
                if ($stand === 'unterwegs' && $ueberfaellig) {
                    $marke = ['Überfällig', 'rot'];
                }

                // Dasselbe Formular zum Sperren und zum Ändern des Endes
                $wartungAktion = $Fahrzeug->IstInWartung() ? 'verlaengern' : 'wartung';
                $knopf = match (true) {
                    $wartungAktion === 'wartung'         => 'In Wartung setzen',
                    $Fahrzeug->GetWartungBis() === null => 'Wartungsende eintragen',
                    default                              => 'Ende ändern',
                };
                ?>
                <tr<?= $offen ? ' class="table__zeile--gewaehlt"' : '' ?>>
                    <td class="table__fahrzeug">
                        <!-- Bild aus assets/img/, sonst das Icon der Fahrzeugart -->
                        <div class="fahrzeugliste__fahrzeug">
                            <?php $bild = $Fahrzeug->GetBild(); ?>
                            <?php if ($bild !== null && is_file(__DIR__ . '/assets/img/' . $bild)): ?>
                                <img class="fahrzeugliste__bild" src="<?= url('assets/img/' . $bild) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span class="fahrzeugliste__bild ohne-bild" title="kein Bild">
                                    <span class="icon icon--<?= art_icon($Fahrzeug->GetArtID()) ?>" aria-hidden="true"></span>
                                </span>
                            <?php endif; ?>
                            <div>
                                <a href="<?= url('fahrzeug.php?id=' . $id) ?>"><?= e($Fahrzeug->GetHersteller() . ' ' . $Fahrzeug->GetModell()) ?></a>
                                <span class="table__zusatz"><?= e($Fahrzeug->GetKennzeichen()) ?></span>
                            </div>
                        </div>
                    </td>
                    <td data-label="Art"><?= e($Fahrzeugarten->GetBezeichnung($Fahrzeug->GetArtID())) ?></td>
                    <td data-label="Baujahr"><?= $Fahrzeug->GetBaujahr() !== null ? e((string) $Fahrzeug->GetBaujahr()) : '&ndash;' ?></td>
                    <td class="table__num" data-label="km-Stand">
                        <?= $Fahrzeug->GetKmStand() !== null ? e(number_format($Fahrzeug->GetKmStand(), 0, ',', '.')) : '&ndash;' ?>
                    </td>
                    <td data-label="HU/AU">
                        <?php if ($Fahrzeug->GetHuAu() === null): ?>
                            keine
                        <?php else: ?>
                            <?= e($Fahrzeug->GetHuAu()->format('m/Y')) ?>
                            <?php if ($huAuStand === 'ueberfaellig'): ?>
                                <span class="table__zusatz"><span class="marke marke--rot">Überfällig</span></span>
                            <?php elseif ($huAuStand === 'faellig'): ?>
                                <span class="table__zusatz"><span class="marke marke--gelb">Bald fällig</span></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="table__stand" data-label="Stand">
                        <span class="marke marke--<?= e($marke[1]) ?>"><?= e($marke[0]) ?></span>
                        <?php if ($Fahrzeug->IstInWartung()): ?>
                            <span class="table__zusatz"><?= e($Fahrzeug->GetWartungText()) ?></span>
                            <?php if ($Fahrzeug->IstUnterwegs()): ?>
                                <span class="table__zusatz"><?= $ueberfaellig ? 'Rückgabe überfällig' : 'noch unterwegs' ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($Fahrzeug->IstWartungWegenSchaden()): ?>
                            <span class="table__zusatz"><a href="<?= url('schaeden.php') ?>">Schaden gemeldet</a></span>
                        <?php endif; ?>
                    </td>
                    <td class="table__breit">
                        <div class="aktionen">
                            <?php if ($Fahrzeug->IstInWartung()): ?>
                                <form method="post" action="<?= e(url($seite)) ?>">
                                    <input type="hidden" name="fahrzeug" value="<?= e((string) $id) ?>">
                                    <input type="hidden" name="aktion" value="freigeben">
                                    <button class="button button--klein" type="submit">Freigeben</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!$offen): ?>
                                <a class="button button--klein button--zweitrangig"
                                   href="<?= e(url('fahrzeuge.php?' . http_build_query($ansicht + ['formular' => $id]))) ?>#formular"><?= e($knopf) ?></a>
                            <?php endif; ?>

                            <?php if (!$Fahrzeug->IstUnterwegs()): ?>
                                <!-- Rückfrage als Popup wie beim Stornieren, geht ohne JavaScript -->
                                <?php $popup = 'ausmustern-' . $id; ?>
                                <button class="button button--klein button--gefahr" type="button" popovertarget="<?= e($popup) ?>">Ausmustern</button>

                                <div class="rueckfrage" id="<?= e($popup) ?>" popover>
                                    <form method="post" action="<?= e(url($seite)) ?>">
                                        <input type="hidden" name="fahrzeug" value="<?= e((string) $id) ?>">
                                        <input type="hidden" name="aktion" value="ausmustern">
                                        <p class="rueckfrage__titel">
                                            <span class="icon icon--warnung" aria-hidden="true"></span>
                                            Fahrzeug ausmustern?
                                        </p>
                                        <dl class="felder">
                                            <div class="feld">
                                                <dt>Fahrzeug</dt>
                                                <dd><?= e($Fahrzeug->GetHersteller() . ' ' . $Fahrzeug->GetModell()) ?></dd>
                                            </div>
                                            <div class="feld">
                                                <dt>Kennzeichen</dt>
                                                <dd><?= e($Fahrzeug->GetKennzeichen()) ?></dd>
                                            </div>
                                        </dl>
                                        <p class="rueckfrage__text">
                                            Es kann danach nicht mehr gebucht werden.
                                            <?php if ($buchungen !== []): ?>
                                                <?= e((string) count($buchungen)) ?> <?= count($buchungen) === 1 ? 'Buchung wird' : 'Buchungen werden' ?> storniert.
                                            <?php endif; ?>
                                            <?php if ($Fahrzeug->IstInWartung()): ?>
                                                Die Wartung endet, offene Schäden gelten als erledigt.
                                            <?php endif; ?>
                                            Steckbrief und Fahrtenbuch bleiben erhalten.
                                        </p>
                                        <div class="aktionen">
                                            <button class="button button--klein button--zweitrangig" type="button"
                                                    popovertarget="<?= e($popup) ?>" popovertargetaction="hide">Abbrechen</button>
                                            <button class="button button--klein button--gefahr-voll" type="submit">Ja, ausmustern</button>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <?php if ($offen): ?>
                    <!-- Wartungsformular als eigene Zeile unter dem Fahrzeug -->
                    <tr class="table__formular" id="formular">
                        <td colspan="<?= e((string) count($spalten)) ?>">
                            <form class="wartungsformular" method="post" action="<?= e(url($seite)) ?>">
                                <input type="hidden" name="fahrzeug" value="<?= e((string) $id) ?>">
                                <input type="hidden" name="aktion" value="<?= e($wartungAktion) ?>">

                                <div class="schild__kopf">
                                    <h3 class="schild__titel"><?= e($Fahrzeug->GetKennzeichen()) ?>: <?= e($knopf) ?></h3>
                                </div>

                                <div class="schild__koerper">
                                    <div class="formfelder">
                                        <?php if ($wartungAktion === 'wartung'): ?>
                                            <div class="formfeld">
                                                <label class="form__label" for="grund">Grund</label>
                                                <input class="form__input" type="text" id="grund" name="grund" required maxlength="200"
                                                       placeholder="z. B. Inspektion, HU/AU" value="<?= e($eingabe['grund']) ?>">
                                            </div>
                                        <?php else: ?>
                                            <!-- Beim Ändern des Endes bleibt der Grund; er steht nur zur Ansicht da -->
                                            <div class="formfeld">
                                                <span class="form__label">Grund</span>
                                                <p class="wartungsformular__wert"><?= e(CWartung::LoadLaufende($id)?->GetGrund()) ?></p>
                                            </div>
                                        <?php endif; ?>

                                        <div class="formfeld">
                                            <?php
                                            // Vorbelegt mit dem bisherigen Ende, nach einem Fehler mit der Eingabe
                                            $wert = $eingabe['wartung_bis'];
                                            if ($wert === '' && $Fahrzeug->IstInWartung() && !$Fahrzeug->IstWartungEndeOffen()) {
                                                $wert = $Fahrzeug->GetWartungBis()->format('Y-m-d');
                                            }
                                            ?>
                                            <label class="form__label" for="wartung-bis">Voraussichtlich bis</label>
                                            <input class="form__input" type="date" id="wartung-bis" name="wartung_bis" required
                                                   min="<?= e($heute->format('Y-m-d')) ?>" value="<?= e($wert) ?>">
                                        </div>

                                        <div class="formfeld formfeld--breit">
                                            <span class="form__label">Buchungen ab heute</span>
                                            <?php if ($buchungen !== []): ?>
                                                <ul class="wartungsformular__buchungen">
                                                    <?php foreach ($buchungen as $Buchung): ?>
                                                        <li>
                                                            <?= e(zeitraum_text($Buchung->GetStart(), $Buchung->GetEnde())) ?>,
                                                            <?= e($Buchung->GetFahrerName()) ?>,
                                                            <?= e($Zwecke->GetBezeichnung($Buchung->GetZweckID())) ?>
                                                            (<?= e($Buchungsstatus->GetBezeichnung($Buchung->GetStatusID())) ?>)
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                                <span class="form__hinweis">
                                                    Beantragte und genehmigte Buchungen, die bis zum Ende beginnen, werden
                                                    automatisch storniert. Laufende Fahrten bleiben.
                                                </span>
                                            <?php else: ?>
                                                <p class="wartungsformular__keine">Keine. Es wird nichts storniert.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="schild__aktionen">
                                        <div class="aktionen">
                                            <button class="button button--klein" type="submit">
                                                <?= $wartungAktion === 'wartung' ? 'Fahrzeug sperren' : 'Ende speichern' ?>
                                            </button>
                                            <a class="button button--klein button--zweitrangig" href="<?= e(url($seite)) ?>">Abbrechen</a>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endif; ?>
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

<?php if ($ListeAusgemustert->Count() > 0): ?>
    <!-- Ausgemusterte Fahrzeuge: nicht mehr buchbar, Steckbrief und Fahrtenbuch bleiben -->
    <section class="fahrzeuge-ausgemustert" aria-labelledby="ausgemustert">
        <h2 class="abschnitt" id="ausgemustert">
            Ausgemustert
            <span class="abschnitt__wert"><?= e((string) $ListeAusgemustert->Count()) ?> <?= $ListeAusgemustert->Count() === 1 ? 'Fahrzeug' : 'Fahrzeuge' ?></span>
        </h2>

        <table class="table table--stapel fahrzeugliste">
            <thead>
                <tr>
                    <th>Fahrzeug</th>
                    <th>Art</th>
                    <th>Baujahr</th>
                    <th class="table__num">km-Stand</th>
                    <th>Ausgemustert am</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ListeAusgemustert->GetAlle() as $Fahrzeug): ?>
                    <tr>
                        <td class="table__fahrzeug">
                            <a href="<?= url('fahrzeug.php?id=' . $Fahrzeug->GetID()) ?>"><?= e($Fahrzeug->GetHersteller() . ' ' . $Fahrzeug->GetModell()) ?></a>
                            <span class="table__zusatz"><?= e($Fahrzeug->GetKennzeichen()) ?></span>
                        </td>
                        <td data-label="Art"><?= e($Fahrzeugarten->GetBezeichnung($Fahrzeug->GetArtID())) ?></td>
                        <td data-label="Baujahr"><?= $Fahrzeug->GetBaujahr() !== null ? e((string) $Fahrzeug->GetBaujahr()) : '&ndash;' ?></td>
                        <td class="table__num" data-label="km-Stand">
                            <?= $Fahrzeug->GetKmStand() !== null ? e(number_format($Fahrzeug->GetKmStand(), 0, ',', '.')) : '&ndash;' ?>
                        </td>
                        <td data-label="Ausgemustert am"><?= e($Fahrzeug->GetGeloeschtAm()->format('d.m.Y')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
