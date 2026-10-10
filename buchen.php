<?php
/**
 * Fahrzeug buchen (Anwendungsfall 1), nur für Mitarbeiter.
 *
 * Eine Seite: oben die Suche (Buchen für, Zeitraum, Personen, Zweck,
 * Fahrzeugart) als GET-Formular, darunter alle passenden Fahrzeuge mit Bild,
 * darunter das Absenden. Angezeigt werden nur Fahrzeuge, die im Zeitraum
 * gebucht werden können; belegte, in Wartung, mit überfälliger Rückgabe und
 * solche, für die dem Fahrer der Führerschein fehlt (Ampel „rot“), sind
 * ausgeblendet (entschieden am 10.10.2026). Jedes Fahrzeug verlinkt auf seinen
 * Steckbrief; eine eigene Fahrzeugliste haben Mitarbeiter nicht (siehe
 * docs/technisches-konzept.md, Regel 1).
 *
 * Ampel: Autos und Transporter werden beantragt (Status „offen“), Roller und
 * Fahrräder sind sofort bestätigt („genehmigt“), siehe braucht_genehmigung().
 *
 * Überfällige Rückgabe (docs/aenderungen-todo2.md, Punkt 12): Wer selbst eine
 * hat, kann nichts buchen; wer eine hat, kann nicht als Fahrer eingetragen
 * werden; ein Fahrzeug mit überfälliger Rückgabe ist nicht wählbar.
 *
 * Aufruf mit Vorauswahl aus dem Steckbrief: buchen.php?fahrzeug=1. Die Suche
 * steht in der Adresse, z. B. buchen.php?beginn=2026-10-07&ende=2026-10-08&zweck=montage.
 *
 * Prototyp: Die Daten kommen aus includes/beispieldaten.php. Eine Buchung
 * wird geprüft und bestätigt, aber noch nicht gespeichert. Mit Datenbank:
 *
 *   SELECT id, vorname, nachname FROM nutzer WHERE rolle = 'mitarbeiter'
 *   SELECT nutzer_id, klasse FROM nutzer_fuehrerscheine
 *   SELECT * FROM fahrzeuge
 *    WHERE sitzplaetze >= :personen [AND art = :art] [AND art IN ('auto', 'transporter')]
 *    ORDER BY art, kennzeichen
 *   SELECT fahrzeug_id, start, ende, status FROM buchungen
 *    WHERE status IN ('offen', 'genehmigt', 'unterwegs')
 *      AND (ende >= :beginn OR status = 'unterwegs')
 *
 * Beim Absenden dieselben Prüfungen noch einmal, dann:
 *   INSERT INTO buchungen (fahrzeug_id, antragsteller_id, fahrer_id, start,
 *     ende, zweck, personenanzahl, status, beantragt_am)
 *   VALUES (:fahrzeug, :ich, :fahrer, :beginn, :ende, :zweck, :personen,
 *     :status, NOW())
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle('mitarbeiter');

$pageTitle = 'Fahrzeug buchen';

$ich = aktueller_nutzer();

// Mitarbeiter mit ihren Führerscheinklassen; nur sie können Fahrer sein.
$nutzer = [];

foreach (beispiel_nutzer() as $id => $person) {
    if ($person['rolle'] === 'mitarbeiter') {
        $nutzer[$id] = ['name' => nutzer_name($id), 'fuehrerscheine' => $person['fuehrerscheine']];
    }
}

// Feste Liste der Zwecke (siehe docs/user-stories.md).
$zweckText = [
    'montage'           => 'Montage',
    'service'           => 'Wartung/Service',
    'kundentermin'      => 'Kundentermin',
    'aufmass'           => 'Aufmaß',
    'materialtransport' => 'Materialtransport',
    'lieferant'         => 'Lieferantenbesuch',
    'schulung'          => 'Schulung',
    'sonstiges'         => 'Sonstiges',
];

// Zwecke, für die nur Autos und Transporter in Frage kommen (Regel 1). Wie
// Zwecke und Fahrzeugarten zusammenhängen, wird noch besprochen.
$nurAuto = ['montage', 'materialtransport'];
$autoArten = ['auto', 'transporter'];

// Fahrzeugart => Beschriftung.
$artText = [
    'auto'        => 'Auto',
    'transporter' => 'Transporter',
    'roller'      => 'Roller',
    'fahrrad'     => 'Fahrrad',
];

$fahrzeuge = beispiel_fahrzeuge();

$heute = new DateTimeImmutable('today');

// Belegende Buchungen (offen, genehmigt, unterwegs) mit Tagen relativ zu
// heute. Namen braucht die Seite nicht.
$buchungen = [];

foreach (beispiel_buchungen() as $b) {
    if (in_array($b['status'], ['offen', 'genehmigt', 'unterwegs'], true)) {
        $buchungen[] = [
            'fahrzeug_id' => $b['fahrzeug_id'],
            'von'         => (int) $heute->diff($b['start'])->format('%r%a'),
            'bis'         => (int) $heute->diff($b['ende'])->format('%r%a'),
        ];
    }
}

// Wer selbst eine überfällige Rückgabe hat, bucht nichts.
$meineSperre = sperre_buchen($ich);
$meineUeberfaellige = array_filter(ueberfaellige_rueckgaben(), fn (array $b): bool => $b['fahrer_id'] === $ich);

// --- Suche (GET) ------------------------------------------------------------
// Fehlt ein Wert, gilt der Standard: ich selbst, morgen, 1 Person, alle
// Arten. Ein angegebener, aber ungültiger Wert ist ein Fehler, damit nicht
// still ein anderer Zeitraum gebucht wird.

$fehler = [];

$fahrer = (int) (is_string($_GET['fahrer'] ?? null) ? $_GET['fahrer'] : $ich);
if (!isset($nutzer[$fahrer])) {
    $fehler[] = 'Diesen Fahrer gibt es nicht.';
    $fahrer = $ich;
} elseif ($fahrer !== $ich && sperre_buchen($fahrer) !== null) {
    $fehler[] = 'Für ' . $nutzer[$fahrer]['name'] . ' kann derzeit nicht gebucht werden: '
        . sperre_buchen($fahrer) . ' Bitte zuerst zurückgeben.';
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

$zweck = is_string($_GET['zweck'] ?? null) && isset($zweckText[$_GET['zweck']]) ? $_GET['zweck'] : '';
$art   = is_string($_GET['art'] ?? null) && isset($artText[$_GET['art']]) ? $_GET['art'] : '';

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
], fn ($wert): bool => $wert !== null && $wert !== '') : [];

// --- Fahrzeugliste ----------------------------------------------------------
// Personen, Fahrzeugart und Zweck schränken die passenden Fahrzeuge ein
// (WHERE). Je Fahrzeug 'stand': frei, vergeben, wartung, ueberfaellig oder
// fuehrerschein. Angezeigt werden nur die freien ($liste); der Stand der
// übrigen dient der Prüfung beim Absenden und dem Hinweis zur Vorauswahl.

// Stand => Grund, warum das Fahrzeug nicht gebucht werden kann.
$grundText = [
    'vergeben'      => 'ist im gewählten Zeitraum bereits vergeben',
    'wartung'       => 'ist im gewählten Zeitraum in Wartung',
    'ueberfaellig'  => 'ist noch nicht zurückgegeben',
    'fuehrerschein' => 'braucht einen Führerschein, den der Fahrer nicht hat',
];

$passende = [];

if ($suche !== []) {
    // Tage relativ zu heute, wie in den Buchungen.
    $tagVon = (int) $heute->diff($zeitraum['beginn'])->days;
    $tagBis = (int) $heute->diff($zeitraum['ende'])->days;

    foreach ($fahrzeuge as $id => $fahrzeug) {
        if ($fahrzeug['sitzplaetze'] < $personen
            || ($art !== '' && $fahrzeug['art'] !== $art)
            || (in_array($zweck, $nurAuto, true) && !in_array($fahrzeug['art'], $autoArten, true))) {
            continue;
        }

        $belegungen = array_filter($buchungen, fn (array $b): bool => $b['fahrzeug_id'] === $id);
        $belegt = array_filter($belegungen, fn (array $b): bool => $b['von'] <= $tagBis && $b['bis'] >= $tagVon) !== [];
        $fehlt = $fahrzeug['fuehrerschein'] !== null
            && !in_array($fahrzeug['fuehrerschein'], $nutzer[$fahrer]['fuehrerscheine'], true);

        $fahrzeug['stand'] = match (true) {
            wartung_sperrt($fahrzeug, $zeitraum['beginn'], $zeitraum['ende']) => 'wartung',
            fahrzeug_ueberfaellig($id) => 'ueberfaellig',
            $fehlt                     => 'fuehrerschein',
            $belegt                    => 'vergeben',
            default                    => 'frei',
        };

        $passende[$id] = $fahrzeug;
    }
}

$liste = array_filter($passende, fn (array $f): bool => $f['stand'] === 'frei');

// Aus dem Steckbrief vorgewählt, aber im Zeitraum nicht buchbar: Hinweis
// statt stillem Fehlen.
$auswahlHinweis = isset($passende[$auswahl]) && $passende[$auswahl]['stand'] !== 'frei'
    ? fahrzeug_name($auswahl) . ' ' . $grundText[$passende[$auswahl]['stand']] . '.'
    : null;

// --- Buchung absenden (POST) ------------------------------------------------
// Läuft vor header.php, damit später eine Weiterleitung möglich ist. Die
// Suche kommt über die Adresse des Formulars mit; geprüft wird hier alles
// noch einmal, denn die Anzeige ist nur Komfort.

$bestaetigung = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auswahl = (int) (is_string($_POST['fahrzeug'] ?? null) ? $_POST['fahrzeug'] : 0);

    if ($meineSperre !== null) {
        $fehler[] = 'Sie können derzeit nicht buchen. ' . $meineSperre;
    } elseif ($fehler === []) {
        if ($zweck === '') {
            $fehler[] = 'Bitte wählen Sie oben den Zweck der Fahrt.';
        }

        if (isset($passende[$auswahl]) && $passende[$auswahl]['stand'] !== 'frei') {
            $fehler[] = 'Das Fahrzeug ' . $grundText[$passende[$auswahl]['stand']]
                . ' und kann nicht gebucht werden.';
        } elseif (!isset($liste[$auswahl])) {
            $fehler[] = 'Bitte wählen Sie ein Fahrzeug aus der Liste.';
        }
    }

    if ($fehler === []) {
        // Nur für diese Anzeige; gespeichert wird noch nicht.
        $bestaetigung = [
            'fahrzeug' => $liste[$auswahl],
            'status'   => braucht_genehmigung($liste[$auswahl]['art']) ? 'offen' : 'genehmigt',
        ];
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

<?php if ($bestaetigung !== null): ?>
    <?php
    $fahrzeug = $bestaetigung['fahrzeug'];
    $bestaetigt = $bestaetigung['status'] === 'genehmigt';
    ?>

    <div class="alert <?= $bestaetigt ? 'alert--erfolg' : 'alert--hinweis' ?>">
        <p class="alert__zeile">
            <?php if ($bestaetigt): ?>
                <strong>Buchung bestätigt.</strong>
            <?php else: ?>
                <strong>Antrag gestellt.</strong> Er liegt dem Fuhrparkleiter zur Genehmigung vor.
            <?php endif; ?>
        </p>
        <p class="alert__zeile">
            <?= e($fahrzeug['hersteller'] . ' ' . $fahrzeug['modell'] . ' (' . $fahrzeug['kennzeichen'] . ')') ?>
            <?= e(zeitraum_satz($zeitraum['beginn'], $zeitraum['ende'])) ?>,
            <?= e($zweckText[$zweck]) ?>, Fahrer: <?= e($nutzer[$fahrer]['name']) ?>.
        </p>
    </div>

    <p class="section">
        <a class="button" href="<?= url('meine-buchungen.php') ?>">Zu meinen Buchungen</a>
        <a class="button button--zweitrangig" href="<?= url('buchen.php') ?>">Weiteres Fahrzeug buchen</a>
    </p>

<?php elseif ($meineSperre !== null): ?>

    <?php foreach ($meineUeberfaellige as $buchung): ?>
        <div class="banner">
            <p class="banner__text">
                <strong>Sie können derzeit nichts buchen.</strong>
                Ihre Rückgabe von <?= e(fahrzeug_name($buchung['fahrzeug_id'])) ?> war am
                <?= e($buchung['ende']->format('d.m.Y')) ?> fällig. Nach der Rückgabe ist Buchen
                wieder möglich.
            </p>
            <a class="button button--klein banner__knopf" href="<?= url('rueckgabe.php?buchung=' . $buchung['id']) ?>">Jetzt zurückgeben</a>
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
                    <?php foreach ($nutzer as $id => $person): ?>
                        <option value="<?= e((string) $id) ?>"<?= $id === $fahrer ? ' selected' : '' ?>>
                            <?= e($id === $ich ? 'mich selbst (' . $person['name'] . ')' : $person['name']) ?>
                            <?= sperre_buchen($id) !== null ? '(Rückgabe überfällig)' : '' ?>
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
                    <?php foreach ($zweckText as $wert => $text): ?>
                        <option value="<?= e($wert) ?>"<?= $wert === $zweck ? ' selected' : '' ?>><?= e($text) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="form__hinweis">Montage und Materialtransport nur mit Auto oder Transporter</span>
            </div>

            <div class="form__row">
                <label class="form__label" for="art">Fahrzeugart</label>
                <select class="form__input" id="art" name="art">
                    <option value="">alle</option>
                    <?php foreach ($artText as $wert => $text): ?>
                        <option value="<?= e($wert) ?>"<?= $wert === $art ? ' selected' : '' ?>><?= e($text) ?></option>
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
                Fahrer: <?= e($nutzer[$fahrer]['name']) ?>.
                <?= e((string) count($liste)) ?> <?= count($liste) === 1 ? 'Fahrzeug ist' : 'Fahrzeuge sind' ?> frei.
                Autos und Transporter werden beim Fuhrparkleiter beantragt, Roller und Fahrräder sind
                sofort bestätigt.
            </p>

            <?php if ($auswahlHinweis !== null): ?>
                <p class="alert alert--hinweis"><?= e($auswahlHinweis) ?> Bitte wählen Sie ein anderes Fahrzeug oder einen anderen Zeitraum.</p>
            <?php endif; ?>

            <div class="fahrzeugwahl">
                <?php foreach ($liste as $id => $fahrzeug): ?>
                    <label class="fahrzeugkarte">
                        <input class="fahrzeugkarte__auswahl" type="radio" name="fahrzeug" required
                               value="<?= e((string) $id) ?>"
                               <?= $id === $auswahl ? 'checked' : '' ?>>

                        <?php if ($fahrzeug['bild'] !== null && is_file(__DIR__ . '/assets/img/' . $fahrzeug['bild'])): ?>
                            <img class="fahrzeugkarte__bild" src="<?= url('assets/img/' . $fahrzeug['bild']) ?>"
                                 alt="" loading="lazy">
                        <?php else: ?>
                            <span class="fahrzeugkarte__bild fahrzeugkarte__bild--platzhalter"><?= e($fahrzeug['typ']) ?></span>
                        <?php endif; ?>

                        <span class="fahrzeugkarte__kopf">
                            <strong><?= e($fahrzeug['hersteller'] . ' ' . $fahrzeug['modell']) ?></strong>
                        </span>

                        <span class="fahrzeugkarte__daten">
                            <?= e($fahrzeug['kennzeichen']) ?> &middot;
                            <?= e($artText[$fahrzeug['art']]) ?> &middot;
                            <?= e((string) $fahrzeug['sitzplaetze']) ?> <?= $fahrzeug['sitzplaetze'] === 1 ? 'Sitz' : 'Sitze' ?>
                            <?php if ($fahrzeug['fuehrerschein'] !== null): ?>
                                &middot; Klasse <?= e($fahrzeug['fuehrerschein']) ?>
                            <?php endif; ?>
                        </span>

                        <span class="fahrzeugkarte__fuss">
                            <span><?= braucht_genehmigung($fahrzeug['art']) ? 'Antrag nötig' : 'sofort bestätigt' ?></span>
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

                <?php if ($zweck === ''): ?>
                    <p class="note">Bitte wählen Sie oben noch den Zweck der Fahrt.</p>
                <?php endif; ?>

                <button class="button" type="submit">Buchung absenden</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
