<?php
/**
 * Fahrzeug buchen, nur für Mitarbeiter.
 *
 * Eine Seite: oben die Suche (Buchen für, Zeitraum, Personen, Zweck,
 * Fahrzeugart) als GET-Formular, darunter alle passenden Fahrzeuge mit Bild,
 * darunter das Absenden. Angezeigt werden nur Fahrzeuge, die im Zeitraum
 * gebucht werden können; belegte, in Wartung, mit überfälliger Rückgabe und
 * solche, für die dem Fahrer der Führerschein oder die Freigabe für
 * Luxusautos (nutzer.darf_luxus) fehlt (Ampel „rot“), sind
 * ausgeblendet. Jedes Fahrzeug verlinkt auf seinen Steckbrief; eine eigene
 * Fahrzeugliste haben Mitarbeiter nicht.
 *
 * Ampel: Autos und Transporter werden beantragt (Status „offen“), Roller und
 * Fahrräder sind sofort bestätigt („genehmigt“), siehe CFahrzeug::BrauchtGenehmigung().
 *
 * Überfällige Rückgabe: Wer selbst eine hat, kann nichts buchen; wer eine hat, kann nicht als Fahrer eingetragen
 * werden; ein Fahrzeug mit überfälliger Rückgabe ist nicht wählbar.
 *
 * Aufruf mit Vorauswahl aus dem Steckbrief: buchen.php?fahrzeug=1. Die Suche
 * steht in der Adresse, z. B. buchen.php?beginn=2026-10-07&ende=2026-10-08&zweck=1
 * (Zweck und Fahrzeugart als ID aus den Schlüsseltabellen).
 *
 * Nutzer, Fahrzeuge, Belegung und Speichern laufen über CListeNutzer,
 * CListeFahrzeuge::LadenFuerBuchung(), CListeBuchungen und CBuchung::Create().
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle(ROLLE_MITARBEITER);

$pageTitle = 'Fahrzeug buchen';

$ich = aktueller_nutzer();

// Aktive Mitarbeiter; nur sie können Fahrer sein. Ausgeschiedene fehlen.
$ListeNutzer = new CListeNutzer();
$ListeNutzer->LadenAktive();

$nutzer = [];

foreach ($ListeNutzer->GetAlle() as $Eintrag) {
    if ($Eintrag->GetRolleID() === ROLLE_MITARBEITER) {
        $nutzer[$Eintrag->GetID()] = $Eintrag;
    }
}

$Zwecke = new CListeSchluessel('zwecke');
$Fahrzeugarten = new CListeSchluessel('fahrzeugarten');

$heute = new DateTimeImmutable('today');

// Überfällige Rückgaben, ohne Namen. Wer selbst eine hat, bucht nichts.
$ListeUeberfaellige = new CListeBuchungen();
$ListeUeberfaellige->LadenUeberfaellige();

$meineSperre = $nutzer[$ich]->GetSperreBuchen();
$meineUeberfaellige = array_filter(
    $ListeUeberfaellige->GetAlle(),
    fn (CBuchung $Buchung): bool => $Buchung->GetFahrerID() === $ich,
);

// --- Suche (GET) ------------------------------------------------------------
// Fehlt ein Wert, gilt der Standard: ich selbst, morgen, 1 Person, alle
// Arten. Ein angegebener, aber ungültiger Wert ist ein Fehler, damit nicht
// still ein anderer Zeitraum gebucht wird.

$fehler = [];

$fahrer = (int) (is_string($_GET['fahrer'] ?? null) ? $_GET['fahrer'] : $ich);
if (!isset($nutzer[$fahrer])) {
    $fehler[] = 'Diesen Fahrer gibt es nicht.';
    $fahrer = $ich;
} elseif ($fahrer !== $ich && $nutzer[$fahrer]->GetSperreBuchen() !== null) {
    $fehler[] = 'Für ' . $nutzer[$fahrer]->GetName() . ' kann derzeit nicht gebucht werden: '
        . $nutzer[$fahrer]->GetSperreBuchen() . ' Bitte zuerst zurückgeben.';
}

$zeitraum = [];

foreach (['beginn' => 'Von', 'ende' => 'Bis'] as $feld => $beschriftung) {
    $wert = $_GET[$feld] ?? '';
    $wert = is_string($wert) ? trim($wert) : '-';
    $datum = DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

    if ($wert === '') {
        $zeitraum[$feld] = $feld === 'beginn' ? $heute->modify('+1 day') : null;
    } elseif ($datum === false || $datum->format('Y-m-d') !== $wert || $datum < $heute) {
        $fehler[] = 'Bitte geben Sie für „' . $beschriftung . '“ ein gültiges Datum ab heute ein.';
        $zeitraum[$feld] = null;
    } else {
        $zeitraum[$feld] = $datum;
    }
}

// Ohne „Bis“ eintägig. Fehlt ein gültiges „Von“, gibt es keine Liste.
if ($zeitraum['beginn'] !== null) {
    $zeitraum['ende'] ??= $zeitraum['beginn'];

    if ($zeitraum['ende'] < $zeitraum['beginn']) {
        $fehler[] = '„Bis“ darf nicht vor „Von“ liegen.';
    }
}

$personen = filter_var($_GET['personen'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9]]);
if ($personen === false) {
    $fehler[] = 'Bitte geben Sie zwischen 1 und 9 Personen an.';
    $personen = 1;
}

// Zweck und Fahrzeugart als ID, 0 = nicht gewählt.
$zweck = (int) (is_string($_GET['zweck'] ?? null) ? $_GET['zweck'] : 0);
$zweck = $Zwecke->GetByID($zweck) !== null ? $zweck : 0;
$art   = (int) (is_string($_GET['art'] ?? null) ? $_GET['art'] : 0);
$art   = $Fahrzeugarten->GetByID($art) !== null ? $art : 0;

// Aus dem Steckbrief vorgewähltes Fahrzeug (fahrzeug.php → buchen.php?fahrzeug=1).
$auswahl = (int) (is_string($_GET['fahrzeug'] ?? null) ? $_GET['fahrzeug'] : 0);

// Die Suche als Parameter, für das Buchungsformular.
$suche = $fehler === [] && $meineSperre === null ? array_filter([
    'fahrer'   => $fahrer !== $ich ? $fahrer : null,
    'beginn'   => $zeitraum['beginn']->format('Y-m-d'),
    'ende'     => $zeitraum['ende']->format('Y-m-d'),
    'personen' => $personen !== 1 ? $personen : null,
    'zweck'    => $zweck,
    'art'      => $art,
], fn ($wert): bool => $wert !== null && $wert !== 0) : [];

// --- Fahrzeugliste ----------------------------------------------------------
// Personen, Fahrzeugart und Zweck schränken die passenden Fahrzeuge ein
// (LadenFuerBuchung). Je Fahrzeug ein Stand ($stand): frei, vergeben,
// wartung, ueberfaellig, fuehrerschein oder luxus. Angezeigt werden nur die
// freien ($liste); der Stand der übrigen dient der Prüfung beim Absenden und
// dem Hinweis zur Vorauswahl.

// Stand => Grund, warum das Fahrzeug nicht gebucht werden kann.
$grundText = [
    'vergeben'      => 'ist im gewählten Zeitraum bereits vergeben',
    'wartung'       => 'ist im gewählten Zeitraum in Wartung',
    'ueberfaellig'  => 'ist noch nicht zurückgegeben',
    'fuehrerschein' => 'braucht einen Führerschein, den der Fahrer nicht hat',
    'luxus'         => 'ist ein Luxusauto, für das der Fahrer nicht freigegeben ist',
];

$ListeFahrzeuge = new CListeFahrzeuge();
$stand = [];   // Fahrzeug-ID => Stand, für alle passenden
$liste = [];   // Fahrzeug-ID => CFahrzeug, nur die freien

if ($suche !== []) {
    $ListeFahrzeuge->LadenFuerBuchung($personen, $art, $zweck);

    // Belegende Buchungen im Zeitraum (offen, genehmigt, unterwegs). Namen
    // braucht die Seite nicht.
    $ListeBelegung = new CListeBuchungen();
    $ListeBelegung->LadenBelegung($zeitraum['beginn'], $zeitraum['ende']);

    $belegteFahrzeuge = $ListeBelegung->GetFahrzeugIDs();
    $ueberfaelligeFahrzeuge = $ListeUeberfaellige->GetFahrzeugIDs();
    $Fahrer = $nutzer[$fahrer];

    foreach ($ListeFahrzeuge->GetAlle() as $Fahrzeug) {
        $id = $Fahrzeug->GetID();

        // Zuerst, was am Fahrer liegt: Das gilt für jeden Zeitraum.
        $stand[$id] = match (true) {
            !$Fahrer->HatFuehrerschein($Fahrzeug->GetFuehrerschein())         => 'fuehrerschein',
            $Fahrzeug->FehltFreigabe($Fahrer)                                 => 'luxus',
            $Fahrzeug->SperrtWartung($zeitraum['beginn'], $zeitraum['ende']) => 'wartung',
            in_array($id, $ueberfaelligeFahrzeuge, true)                      => 'ueberfaellig',
            in_array($id, $belegteFahrzeuge, true)                            => 'vergeben',
            default                                                           => 'frei',
        };

        if ($stand[$id] === 'frei') {
            $liste[$id] = $Fahrzeug;
        }
    }
}

// Aus dem Steckbrief vorgewählt, aber im Zeitraum nicht buchbar: Hinweis
// statt stillem Fehlen.
$auswahlHinweis = isset($stand[$auswahl]) && $stand[$auswahl] !== 'frei'
    ? $ListeFahrzeuge->GetByID($auswahl)->GetName() . ' ' . $grundText[$stand[$auswahl]] . '.'
    : null;

// --- Buchung absenden (POST) ------------------------------------------------
// Läuft vor header.php, damit die Weiterleitung möglich ist. Die Suche kommt
// über die Adresse des Formulars mit. Die Prüfungen hier nennen dem Nutzer den
// genauen Grund; verbindlich prüft CBuchung::Create() noch einmal beim Speichern.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auswahl = (int) (is_string($_POST['fahrzeug'] ?? null) ? $_POST['fahrzeug'] : 0);

    if ($meineSperre !== null) {
        $fehler[] = 'Sie können derzeit nicht buchen. ' . $meineSperre;
    } elseif ($fehler === []) {
        if ($zweck === 0) {
            $fehler[] = 'Bitte wählen Sie oben den Zweck der Fahrt.';
        }

        if (isset($stand[$auswahl]) && $stand[$auswahl] !== 'frei') {
            $fehler[] = 'Das Fahrzeug ' . $grundText[$stand[$auswahl]]
                . ' und kann nicht gebucht werden.';
        } elseif (!isset($liste[$auswahl])) {
            $fehler[] = 'Bitte wählen Sie ein Fahrzeug aus der Liste.';
        }
    }

    if ($fehler === []) {
        try {
            $Buchung = CBuchung::Create(
                $auswahl, $ich, $fahrer, $zeitraum['beginn'], $zeitraum['ende'], $zweck, $personen
            );
            $bestaetigt = $Buchung->GetStatusID() === STATUS_GENEHMIGT;

            merke_meldung(
                ($bestaetigt ? 'Buchung bestätigt. ' : 'Antrag gestellt. Er liegt dem Fuhrparkleiter zur Genehmigung vor. ')
                    . $Buchung->GetFahrzeugName() . ' ' . zeitraum_satz($zeitraum['beginn'], $zeitraum['ende'])
                    . ', ' . $Zwecke->GetBezeichnung($zweck) . ', Fahrer: ' . $Fahrer->GetName() . '.',
                $bestaetigt ? 'erfolg' : 'hinweis',
            );
            redirect('buchen.php?gebucht=1');
        } catch (CFachlicherFehler $Fehler) {
            $fehler[] = $Fehler->getMessage();
        }
    }
}

/**
 * Zeitraum als Satzteil, eintägig ohne „bis“: „am 03.10.2026“ oder
 * „vom 03.10.2026 bis 05.10.2026“.
 */
function zeitraum_satz(DateTimeImmutable $von, DateTimeImmutable $bis): string
{
    return $von == $bis
        ? 'am ' . $von->format('d.m.Y')
        : 'vom ' . $von->format('d.m.Y') . ' bis ' . $bis->format('d.m.Y');
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if (isset($_GET['gebucht'])): ?>

    <p class="section">
        <a class="button" href="<?= url('meine-buchungen.php') ?>">Zu meinen Buchungen</a>
        <a class="button button--zweitrangig" href="<?= url('buchen.php') ?>">Weiteres Fahrzeug buchen</a>
    </p>

<?php elseif ($meineSperre !== null): ?>

    <?php foreach ($meineUeberfaellige as $Buchung): ?>
        <div class="banner">
            <p class="banner__text">
                <strong>Sie können derzeit nichts buchen.</strong>
                Ihre Rückgabe von <?= e($Buchung->GetFahrzeugName()) ?> war am
                <?= e($Buchung->GetEnde()->format('d.m.Y')) ?> fällig. Nach der Rückgabe ist Buchen
                wieder möglich.
            </p>
            <a class="button button--klein banner__knopf" href="<?= url('rueckgabe.php?buchung=' . $Buchung->GetID()) ?>">Jetzt zurückgeben</a>
        </div>
    <?php endforeach; ?>

<?php else: ?>

    <!-- Suche: als GET, damit sie in der Adresse steht und erhalten bleibt -->
    <form class="form form--breit" method="get" action="<?= url('buchen.php') ?>">
        <h2 class="form__titel">1. Wer, wann, wozu?</h2>

        <?php if ($auswahl > 0): ?>
            <input type="hidden" name="fahrzeug" value="<?= e((string) $auswahl) ?>">
        <?php endif; ?>

        <div class="form__raster">
            <div class="form__row">
                <label class="form__label" for="fahrer">Buchen für</label>
                <select class="form__input" id="fahrer" name="fahrer">
                    <?php foreach ($nutzer as $id => $Eintrag): ?>
                        <option value="<?= e((string) $id) ?>"<?= $id === $fahrer ? ' selected' : '' ?>>
                            <?= e($id === $ich ? 'mich selbst (' . $Eintrag->GetName() . ')' : $Eintrag->GetName()) ?>
                            <?= $Eintrag->GetSperreBuchen() !== null ? '(Rückgabe überfällig)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form__row">
                <label class="form__label" for="beginn">Von</label>
                <input class="form__input" type="date" id="beginn" name="beginn"
                       min="<?= e($heute->format('Y-m-d')) ?>"
                       value="<?= e(($zeitraum['beginn'] ?? $heute->modify('+1 day'))->format('Y-m-d')) ?>">
            </div>

            <div class="form__row">
                <label class="form__label" for="ende">Bis</label>
                <input class="form__input" type="date" id="ende" name="ende"
                       min="<?= e($heute->format('Y-m-d')) ?>"
                       value="<?= e(($zeitraum['ende'] ?? $zeitraum['beginn'] ?? $heute->modify('+1 day'))->format('Y-m-d')) ?>">
            </div>

            <div class="form__row">
                <label class="form__label" for="personen">Personen</label>
                <input class="form__input" type="number" id="personen" name="personen"
                       min="1" max="9" value="<?= e((string) $personen) ?>">
                <span class="form__hinweis">einschließlich Fahrer</span>
            </div>

            <div class="form__row">
                <label class="form__label" for="zweck">Zweck</label>
                <select class="form__input" id="zweck" name="zweck">
                    <option value="">bitte wählen</option>
                    <?php foreach ($Zwecke->GetAlle() as $Zweck): ?>
                        <option value="<?= e((string) $Zweck->GetID()) ?>"<?= $Zweck->GetID() === $zweck ? ' selected' : '' ?>><?= e($Zweck->GetBezeichnung()) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="form__hinweis">Montage und Materialtransport nur mit Auto oder Transporter</span>
            </div>

            <div class="form__row">
                <label class="form__label" for="art">Fahrzeugart</label>
                <select class="form__input" id="art" name="art">
                    <option value="">alle</option>
                    <?php foreach ($Fahrzeugarten->GetAlle() as $Art): ?>
                        <option value="<?= e((string) $Art->GetID()) ?>"<?= $Art->GetID() === $art ? ' selected' : '' ?>><?= e($Art->GetBezeichnung()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button class="button" type="submit">Fahrzeuge anzeigen</button>
    </form>

    <?php if ($fehler !== []): ?>
        <div class="alert section">
            <?php foreach ($fehler as $meldung): ?>
                <p class="alert__zeile"><?= e($meldung) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($suche !== []): ?>
        <!-- Fahrzeug wählen und absenden -->
        <form class="form form--breit section" method="post"
              action="<?= e(url('buchen.php?' . http_build_query($suche))) ?>">
            <h2 class="form__titel">2. Fahrzeug auswählen</h2>

            <p class="lead">
                <?= e(zeitraum_satz($zeitraum['beginn'], $zeitraum['ende'])) ?>
                für <?= e((string) $personen) ?> <?= $personen === 1 ? 'Person' : 'Personen' ?>,
                Fahrer: <?= e($Fahrer->GetName()) ?>.
                <?= e((string) count($liste)) ?> <?= count($liste) === 1 ? 'Fahrzeug ist' : 'Fahrzeuge sind' ?> frei.
                Autos und Transporter werden beim Fuhrparkleiter beantragt, Roller und Fahrräder sind
                sofort bestätigt.
            </p>

            <?php if ($auswahlHinweis !== null): ?>
                <p class="alert alert--hinweis"><?= e($auswahlHinweis) ?> Bitte wählen Sie ein anderes Fahrzeug oder einen anderen Zeitraum.</p>
            <?php endif; ?>

            <div class="fahrzeugwahl">
                <?php foreach ($liste as $id => $Fahrzeug): ?>
                    <label class="fahrzeugkarte">
                        <input class="fahrzeugkarte__auswahl" type="radio" name="fahrzeug" required
                               value="<?= e((string) $id) ?>"
                               <?= $id === $auswahl ? 'checked' : '' ?>>

                        <?php if ($Fahrzeug->GetBild() !== null && is_file(__DIR__ . '/assets/img/' . $Fahrzeug->GetBild())): ?>
                            <img class="fahrzeugkarte__bild" src="<?= url('assets/img/' . $Fahrzeug->GetBild()) ?>"
                                 alt="" loading="lazy">
                        <?php else: ?>
                            <span class="fahrzeugkarte__bild fahrzeugkarte__bild--platzhalter"><?= e($Fahrzeug->GetTyp()) ?></span>
                        <?php endif; ?>

                        <span class="fahrzeugkarte__kopf">
                            <strong><?= e($Fahrzeug->GetHersteller() . ' ' . $Fahrzeug->GetModell()) ?></strong>
                        </span>

                        <span class="fahrzeugkarte__daten">
                            <?= e($Fahrzeug->GetKennzeichen()) ?> &middot;
                            <?= e($Fahrzeugarten->GetBezeichnung($Fahrzeug->GetArtID())) ?> &middot;
                            <?= e((string) $Fahrzeug->GetSitzplaetze()) ?> <?= $Fahrzeug->GetSitzplaetze() === 1 ? 'Sitz' : 'Sitze' ?>
                            <?php if ($Fahrzeug->GetFuehrerschein() !== null): ?>
                                &middot; Klasse <?= e($Fahrzeug->GetFuehrerschein()) ?>
                            <?php endif; ?>
                        </span>

                        <span class="fahrzeugkarte__fuss">
                            <span><?= $Fahrzeug->BrauchtGenehmigung() ? 'Antrag nötig' : 'sofort bestätigt' ?></span>
                            <a href="<?= url('fahrzeug.php?id=' . $id) ?>">Steckbrief</a>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <?php if ($liste === []): ?>
                <p class="note">
                    Im gewählten Zeitraum ist kein passendes Fahrzeug frei. Ein anderer Zeitraum,
                    weniger Personen oder eine andere Fahrzeugart?
                </p>
            <?php else: ?>
                <h2 class="form__titel section">3. Absenden</h2>

                <?php if ($zweck === 0): ?>
                    <p class="note">Bitte wählen Sie oben noch den Zweck der Fahrt.</p>
                <?php endif; ?>

                <button class="button" type="submit">Buchung absenden</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
