<?php
/**
 * Fahrzeug buchen, nur für Mitarbeiter.
 *
 * Oben die Suche als Formular-Schild (Buchen für, Von, Bis, Personen, Zweck,
 * Fahrzeugart) per GET, darunter je freies Fahrzeug ein Schild mit Bild,
 * Kennwerten und eigenem Knopf zum Absenden (POST): „Buchen“, wenn die
 * Buchung sofort bestätigt ist, „Beantragen“, wenn der Fuhrparkleiter sie
 * genehmigen muss (CFahrzeug::BrauchtGenehmigung()). Vor der Suche (ohne
 * Zweck) stehen alle Fahrzeuge im Bestand da, nur zum Ansehen, ohne Knopf.
 * Den Zweck „Wartung/Service“ können Mitarbeiter nicht wählen
 * (CBuchung::IstBuchbarerZweck()).
 *
 * Angezeigt werden nur Fahrzeuge, die im Zeitraum gebucht werden können;
 * belegte, in Wartung, mit überfälliger Rückgabe und solche, für die dem
 * Fahrer der Führerschein oder die Freigabe für Luxusautos fehlt, sind
 * ausgeblendet. Zweck und Fahrzeugart müssen zusammenpassen
 * (CBuchung::PasstZweck()).
 *
 * Wer selbst eine überfällige Rückgabe hat, kann nichts buchen und sieht
 * nur die Meldung dazu.
 *
 * Aufruf aus dem Steckbrief: buchen.php?fahrzeug=1, das Fahrzeug steht dann
 * oben. Kann der Fahrer es nicht buchen, nennt ein Hinweis den Grund: was
 * nicht vom Zeitraum abhängt (Führerschein, Freigabe, Wartung, überfällig)
 * schon vor der Suche, alles andere danach. Die Suche steht in der Adresse, z. B.
 * buchen.php?beginn=2026-10-07&ende=2026-10-08&zweck=1 (Zweck und
 * Fahrzeugart als ID aus den Schlüsseltabellen).
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
$Arten = new CListeSchluessel('fahrzeugarten');
$Antriebe = new CListeSchluessel('antriebe');

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
// still ein anderer Zeitraum gebucht wird. Der Zweck hat keinen Standard.

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

// Zweck und Fahrzeugart als ID, 0 = nicht gewählt. Ohne Zweck in der
// Adresse (erster Aufruf, Steckbrief) noch kein Fehler, nur keine Liste.
$zweck = (int) (is_string($_GET['zweck'] ?? null) ? $_GET['zweck'] : 0);
$zweck = $Zwecke->GetByID($zweck) !== null && CBuchung::IstBuchbarerZweck($zweck) ? $zweck : 0;
if ($zweck === 0 && isset($_GET['zweck'])) {
    $fehler[] = 'Bitte wählen Sie den Zweck der Fahrt.';
}

$art = (int) (is_string($_GET['art'] ?? null) ? $_GET['art'] : 0);
$art = $Arten->GetByID($art) !== null ? $art : 0;

// Aus dem Steckbrief vorgewähltes Fahrzeug (fahrzeug.php → buchen.php?fahrzeug=1).
$auswahl = (int) (is_string($_GET['fahrzeug'] ?? null) ? $_GET['fahrzeug'] : 0);
$Vorwahl = $auswahl > 0 ? CFahrzeug::LoadByID($auswahl) : null;

// Die Suche als Parameter, für die Formulare der Fahrzeuge. Leer, solange
// etwas fehlt; dann gibt es keine Liste.
$suche = $fehler === [] && $zweck !== 0 && $meineSperre === null ? array_filter([
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
$Fahrer = $nutzer[$fahrer];

if ($suche !== []) {
    $ListeFahrzeuge->LadenFuerBuchung($personen, $art, $zweck);

    // Belegende Buchungen im Zeitraum (offen, genehmigt, unterwegs). Namen
    // braucht die Seite nicht.
    $ListeBelegung = new CListeBuchungen();
    $ListeBelegung->LadenBelegung($zeitraum['beginn'], $zeitraum['ende']);

    $belegteFahrzeuge = $ListeBelegung->GetFahrzeugIDs();
    $ueberfaelligeFahrzeuge = $ListeUeberfaellige->GetFahrzeugIDs();

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

    // Das vorgewählte Fahrzeug steht oben.
    if (isset($liste[$auswahl])) {
        $liste = [$auswahl => $liste[$auswahl]] + $liste;
    }
}

// Vor der Suche: alle Fahrzeuge im Bestand zum Ansehen, ohne Knopf zum
// Buchen. Das vorgewählte steht oben.
$alleFahrzeuge = [];

if ($suche === [] && $meineSperre === null) {
    $ListeAlle = new CListeFahrzeuge();
    $ListeAlle->LadenImBestand();

    foreach ($ListeAlle->GetAlle() as $Fahrzeug) {
        $alleFahrzeuge[$Fahrzeug->GetID()] = $Fahrzeug;
    }

    if (isset($alleFahrzeuge[$auswahl])) {
        $alleFahrzeuge = [$auswahl => $alleFahrzeuge[$auswahl]] + $alleFahrzeuge;
    }
}

// Aus dem Steckbrief vorgewählt, aber nicht buchbar: Hinweis mit dem Grund
// statt stillem Fehlen.
$auswahlHinweis = null;

if ($suche !== [] && $Vorwahl !== null && !isset($liste[$auswahl])) {
    $zweckGrund = CBuchung::ZweckHindernis($zweck, $Vorwahl->GetArtID());

    if (isset($stand[$auswahl])) {
        $auswahlHinweis = $Vorwahl->GetName() . ' ' . $grundText[$stand[$auswahl]] . '.';
    } elseif ($zweckGrund !== null) {
        $auswahlHinweis = $Vorwahl->GetName() . ': ' . $zweckGrund;
    } elseif ($Vorwahl->GetSitzplaetze() < $personen) {
        $auswahlHinweis = $Vorwahl->GetName() . ' hat nur ' . $Vorwahl->GetSitzplaetze() . ' Sitzplätze.';
    }

    if ($auswahlHinweis !== null) {
        $auswahlHinweis .= ' Bitte wählen Sie ein anderes Fahrzeug, einen anderen Zeitraum oder Zweck.';
    }
} elseif ($suche === [] && $Vorwahl !== null && !$Vorwahl->IstAusgemustert()) {
    // Vor der Suche: Gründe, die nicht vom Zeitraum abhängen, gleich nennen.
    // Ob das Fahrzeug im Zeitraum frei ist, zeigt erst die Suche.
    $anderer = ' Wählen Sie ein anderes Fahrzeug oder buchen Sie für einen Kollegen, der es fahren darf.';
    $spaeter = ' Wählen Sie einen späteren Zeitraum oder ein anderes Fahrzeug.';

    $auswahlHinweis = match (true) {
        !$Fahrer->HatFuehrerschein($Vorwahl->GetFuehrerschein()) => $grundText['fuehrerschein'] . '.' . $anderer,
        $Vorwahl->FehltFreigabe($Fahrer)                          => $grundText['luxus'] . '.' . $anderer,
        $Vorwahl->IstUeberfaellig()                               => 'ist vom vorigen Fahrer noch nicht zurückgegeben.' . $spaeter,
        $Vorwahl->IstInWartung()                                  => 'ist derzeit in Wartung (' . $Vorwahl->GetWartungText() . ').' . $spaeter,
        default                                                   => null,
    };

    if ($auswahlHinweis !== null) {
        $auswahlHinweis = $Vorwahl->GetName() . ' ' . $auswahlHinweis;
    }
}

// --- Buchung absenden (POST) ------------------------------------------------
// Jedes Fahrzeug-Schild hat ein eigenes Formular mit der Fahrzeug-ID; die
// Suche kommt über die Adresse mit. Läuft vor header.php, damit die
// Weiterleitung möglich ist. Die Prüfungen hier nennen den genauen Grund;
// verbindlich prüft CBuchung::Create() noch einmal beim Speichern.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gewaehlt = (int) (is_string($_POST['fahrzeug'] ?? null) ? $_POST['fahrzeug'] : 0);

    if ($meineSperre !== null) {
        $fehler[] = 'Sie können derzeit nicht buchen. ' . $meineSperre;
    } elseif ($fehler === [] && $suche === []) {
        $fehler[] = 'Bitte wählen Sie den Zweck der Fahrt.';
    } elseif ($fehler === []) {
        if (isset($stand[$gewaehlt]) && $stand[$gewaehlt] !== 'frei') {
            $fehler[] = 'Das Fahrzeug ' . $grundText[$stand[$gewaehlt]]
                . '. Bitte wählen Sie ein anderes Fahrzeug aus der Liste.';
        } elseif (!isset($liste[$gewaehlt])) {
            $fehler[] = 'Dieses Fahrzeug passt nicht zur Suche. Bitte wählen Sie eines aus der Liste.';
        }
    }

    if ($fehler === []) {
        try {
            $Buchung = CBuchung::Create(
                $gewaehlt, $ich, $fahrer, $zeitraum['beginn'], $zeitraum['ende'], $zweck, $personen
            );
            $bestaetigt = $Buchung->GetStatusID() === STATUS_GENEHMIGT;

            // Ergebnis, Fahrzeug, Zeitraum und Zweck; den Fahrer nur, wenn
            // für jemand anderen gebucht wurde
            $satz = $Buchung->GetFahrzeugName() . ' ' . zeitraum_satz($zeitraum['beginn'], $zeitraum['ende'])
                . ', ' . $Zwecke->GetBezeichnung($zweck)
                . ($fahrer !== $ich ? ', Fahrer: ' . $Fahrer->GetName() : '') . '.';

            merke_meldung(
                $bestaetigt
                    ? 'Buchung bestätigt: ' . $satz
                    : 'Antrag gestellt: ' . $satz . ' Der Fuhrparkleiter entscheidet darüber.',
                $bestaetigt ? 'erfolg' : 'hinweis',
                ['Zu meinen Buchungen', 'meine-buchungen.php'],
            );

            // Zurück zur selben Suche, z. B. für ein zweites Fahrzeug
            redirect('buchen.php?' . http_build_query($suche));
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

<?php if ($meineSperre !== null): ?>

    <?php foreach ($meineUeberfaellige as $Buchung): ?>
        <div class="banner">
            <p class="banner__text">
                <strong>Sie können derzeit nichts buchen.</strong>
                Ihre Rückgabe von <?= e($Buchung->GetFahrzeugName()) ?> war am
                <?= e($Buchung->GetEnde()->format('d.m.Y')) ?> fällig. Nach der Rückgabe ist Buchen
                wieder möglich.
            </p>
            <a class="button button--klein banner__knopf" href="<?= url('rueckgabe.php?buchung=' . $Buchung->GetID()) ?>"><span class="icon icon--zurueck" aria-hidden="true"></span>Jetzt zurückgeben</a>
        </div>
    <?php endforeach; ?>

<?php else: ?>

    <?php if ($fehler !== []): ?>
        <div class="alert">
            <?php foreach ($fehler as $meldung): ?>
                <p class="alert__zeile"><?= e($meldung) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Suche: als GET, damit sie in der Adresse steht und erhalten bleibt -->
    <form class="schild schild--formular buchungssuche" method="get" action="<?= url('buchen.php') ?>">
        <?php if ($auswahl > 0): ?>
            <input type="hidden" name="fahrzeug" value="<?= e((string) $auswahl) ?>">
        <?php endif; ?>

        <div class="schild__koerper">
            <div class="formfelder buchungssuche__felder">
                <div class="formfeld">
                    <label class="form__label" for="fahrer">Buchen für</label>
                    <select class="form__input" id="fahrer" name="fahrer">
                        <?php foreach ($nutzer as $id => $Eintrag): ?>
                            <option value="<?= e((string) $id) ?>"<?= $id === $fahrer ? ' selected' : '' ?>>
                                <?= e($id === $ich ? 'mich selbst' : $Eintrag->GetName()) ?>
                                <?= $Eintrag->GetSperreBuchen() !== null ? '(Rückgabe überfällig)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="formfeld">
                    <label class="form__label" for="beginn">Von</label>
                    <input class="form__input" type="date" id="beginn" name="beginn" required
                           min="<?= e($heute->format('Y-m-d')) ?>"
                           value="<?= e(($zeitraum['beginn'] ?? $heute->modify('+1 day'))->format('Y-m-d')) ?>">
                </div>

                <div class="formfeld">
                    <label class="form__label" for="ende">Bis</label>
                    <input class="form__input" type="date" id="ende" name="ende"
                           min="<?= e($heute->format('Y-m-d')) ?>"
                           value="<?= e(($zeitraum['ende'] ?? $zeitraum['beginn'] ?? $heute->modify('+1 day'))->format('Y-m-d')) ?>">
                </div>

                <div class="formfeld">
                    <label class="form__label" for="personen">Personen</label>
                    <input class="form__input" type="number" id="personen" name="personen"
                           min="1" max="9" value="<?= e((string) $personen) ?>">
                    <span class="form__hinweis">mit Fahrer</span>
                </div>

                <div class="formfeld">
                    <label class="form__label" for="zweck">Zweck</label>
                    <select class="form__input" id="zweck" name="zweck" required>
                        <option value="">bitte wählen</option>
                        <?php foreach ($Zwecke->GetAlle() as $Zweck): ?>
                            <?php if (!CBuchung::IstBuchbarerZweck($Zweck->GetID())) continue; ?>
                            <option value="<?= e((string) $Zweck->GetID()) ?>"<?= $Zweck->GetID() === $zweck ? ' selected' : '' ?>><?= e($Zweck->GetBezeichnung()) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="formfeld">
                    <label class="form__label" for="art">Fahrzeugart</label>
                    <select class="form__input" id="art" name="art">
                        <option value="">alle</option>
                        <?php foreach ($Arten->GetAlle() as $Art): ?>
                            <option value="<?= e((string) $Art->GetID()) ?>"<?= $Art->GetID() === $art ? ' selected' : '' ?>><?= e($Art->GetBezeichnung()) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="schild__aktionen">
                <button class="button" type="submit">Fahrzeuge anzeigen</button>
            </div>
        </div>
    </form>

    <?php if ($suche === []): ?>
        <!-- Noch keine Suche: alle Fahrzeuge zum Ansehen, gebucht wird nach der Suche -->
        <section aria-labelledby="treffer">
            <h2 class="abschnitt" id="treffer">
                Alle Fahrzeuge
                <span class="abschnitt__wert"><?= e((string) count($alleFahrzeuge)) ?> im Bestand</span>
            </h2>
            <p class="abschnitt__hinweis">
                <?php if ($Vorwahl !== null && $auswahlHinweis === null): ?>
                    Wählen Sie Zeitraum und Zweck, dann sehen Sie, ob <?= e($Vorwahl->GetName()) ?> frei ist.
                <?php else: ?>
                    Wählen Sie Zeitraum und Zweck, dann sehen Sie, welche frei sind.
                <?php endif; ?>
            </p>
    <?php else: ?>
        <?php $anzahl = count($liste); ?>
        <section aria-labelledby="treffer">
            <h2 class="abschnitt" id="treffer">
                Freie Fahrzeuge
                <span class="abschnitt__wert">
                    <?= $anzahl === 0 ? 'keines frei' : e((string) $anzahl) . ($anzahl === 1 ? ' Fahrzeug frei' : ' Fahrzeuge frei') ?>
                </span>
                <span class="abschnitt__zusatz">
                    <?= e(zeitraum_kurz($zeitraum['beginn'], $zeitraum['ende'])) ?>
                    &middot; <?= e($Zwecke->GetBezeichnung($zweck)) ?>
                    &middot; <?= e((string) $personen) ?> <?= $personen === 1 ? 'Person' : 'Personen' ?>
                    <?php if ($fahrer !== $ich): ?>
                        &middot; für <?= e($Fahrer->GetName()) ?>
                    <?php endif; ?>
                </span>
            </h2>
    <?php endif; ?>

            <?php if ($auswahlHinweis !== null): ?>
                <p class="alert alert--hinweis"><?= e($auswahlHinweis) ?></p>
            <?php endif; ?>

            <?php foreach ($suche !== [] ? $liste : $alleFahrzeuge as $id => $SchildFahrzeug): ?>
                <?php
                $antrag = $SchildFahrzeug->BrauchtGenehmigung();
                $marke = $antrag ? ['Antrag', 'gelb'] : ['Sofort bestätigt', 'gruen'];
                $bild = $SchildFahrzeug->GetBild();
                $antrieb = $SchildFahrzeug->GetAntriebID();
                ?>
                <article class="schild treffer">
                    <!-- Bild links über Kopf und Feldern, auf dem Handy als Band dazwischen -->
                    <div class="treffer__raster">
                        <?php if ($bild !== null && is_file(__DIR__ . '/assets/img/' . $bild)): ?>
                            <img class="treffer__bild" src="<?= url('assets/img/' . $bild) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="treffer__bild ohne-bild">
                                <span class="icon icon--<?= art_icon($SchildFahrzeug->GetArtID()) ?>" aria-hidden="true"></span>
                                kein Bild
                            </span>
                        <?php endif; ?>

                        <?php require __DIR__ . '/includes/schild-kopf.php'; ?>

                        <div class="schild__koerper">
                            <dl class="felder felder--treffer">
                                <div class="feld">
                                    <dt>Typ</dt>
                                    <dd><?= e($SchildFahrzeug->GetTyp()) ?></dd>
                                </div>
                                <div class="feld">
                                    <dt>Sitze</dt>
                                    <dd><?= e((string) $SchildFahrzeug->GetSitzplaetze()) ?></dd>
                                </div>
                                <div class="feld">
                                    <dt>Antrieb</dt>
                                    <dd><?= $antrieb !== null ? e($Antriebe->GetBezeichnung($antrieb)) : '–' ?></dd>
                                </div>
                                <div class="feld">
                                    <dt>Führerschein</dt>
                                    <dd><?= $SchildFahrzeug->GetFuehrerschein() !== null ? 'Klasse ' . e($SchildFahrzeug->GetFuehrerschein()) : 'keiner nötig' ?></dd>
                                </div>
                            </dl>

                            <?php if ($suche !== []): ?>
                                <!-- Eigenes Formular je Fahrzeug, die Suche steht in der Adresse -->
                                <form class="schild__aktionen" method="post"
                                      action="<?= e(url('buchen.php?' . http_build_query($suche))) ?>">
                                    <input type="hidden" name="fahrzeug" value="<?= e((string) $id) ?>">
                                    <button class="button button--klein" type="submit"><?= $antrag ? 'Beantragen' : 'Buchen' ?></button>
                                    <p class="schild__hinweis">
                                        <?= $antrag ? 'Der Fuhrparkleiter entscheidet über den Antrag.' : 'Die Buchung gilt sofort.' ?>
                                    </p>
                                </form>
                            <?php else: ?>
                                <!-- Vor der Suche: kein Knopf, nur der Weg dorthin -->
                                <div class="schild__aktionen">
                                    <p class="schild__hinweis">
                                        Zum Buchen oben Zeitraum und Zweck wählen.
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>

            <?php if ($suche !== [] && $liste === []): ?>
                <?php $artGrund = $art !== 0 ? CBuchung::ZweckHindernis($zweck, $art) : null; ?>
                <div class="schild schild--leer">
                    <p>
                        <?php if ($artGrund !== null): ?>
                            <?= e($artGrund) ?> Wählen Sie einen anderen Zweck oder eine andere Fahrzeugart.
                        <?php else: ?>
                            Im gewählten Zeitraum ist kein passendes Fahrzeug frei. Versuchen Sie einen anderen
                            Zeitraum, weniger Personen oder eine andere Fahrzeugart.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
