<?php
/**
 * Fahrzeug buchen (Anwendungsfall 1), nur für Mitarbeiter.
 *
 * Eine Seite: oben die Suche (Buchen für, Zeitraum, Personen, Zweck,
 * Fahrzeugart) als GET-Formular, darunter alle passenden Fahrzeuge mit Bild,
 * darunter das Absenden. Belegte Fahrzeuge, Fahrzeuge in Wartung und solche,
 * für die dem Fahrer der Führerschein fehlt (Ampel „rot“), bleiben sichtbar,
 * sind aber nicht wählbar. Jedes Fahrzeug verlinkt auf seinen Steckbrief;
 * eine eigene Fahrzeugliste haben Mitarbeiter nicht (siehe
 * docs/technisches-konzept.md, Regel 1).
 *
 * Ampel: Autos werden beantragt (Status „offen“), Roller und Fahrräder sind
 * sofort bestätigt („genehmigt“).
 *
 * Aufruf mit Vorauswahl aus dem Steckbrief: buchen.php?fahrzeug=1. Die Suche
 * steht in der Adresse, z. B. buchen.php?beginn=2026-10-07&ende=2026-10-08&zweck=montage.
 *
 * Prototyp: Nutzer, Fahrzeuge und Buchungen sind feste Beispieldaten. Eine
 * Buchung wird geprüft und bestätigt, aber noch nicht gespeichert. Mit
 * Datenbank:
 *
 *   SELECT id, name, fuehrerscheine FROM nutzer ORDER BY name
 *   SELECT * FROM fahrzeuge
 *    WHERE sitzplaetze >= :personen [AND art = :art] [AND art = 'auto']
 *    ORDER BY art, kennzeichen
 *   SELECT fahrzeug_id, start, ende, status FROM buchungen
 *    WHERE status IN ('offen', 'genehmigt', 'unterwegs')
 *      AND (ende >= :beginn OR status = 'unterwegs')
 *
 * Beim Absenden dieselben Prüfungen noch einmal, dann:
 *   INSERT INTO buchungen (fahrzeug_id, antragsteller_id, fahrer_id, start,
 *     ende, zweck, personen, status, beantragt_am)
 *   VALUES (:fahrzeug, :ich, :fahrer, :beginn, :ende, :zweck, :personen,
 *     :status, NOW())
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle('mitarbeiter');

$pageTitle = 'Fahrzeug buchen';

// Angemeldeter Nutzer. Kommt später aus der Session.
$ich = 1;

// Nutzer mit ihren Führerscheinklassen (nutzer.fuehrerscheine).
$nutzer = [
    1 => ['name' => 'Lucie Schneider', 'fuehrerscheine' => ['B', 'A1']],
    2 => ['name' => 'Ella Luppold',    'fuehrerscheine' => ['B']],
    3 => ['name' => 'Finn Clausen',    'fuehrerscheine' => ['B', 'A1']],
    4 => ['name' => 'Kenneth Sander',  'fuehrerscheine' => ['B', 'C1']],
    5 => ['name' => 'Larissa Wagner',  'fuehrerscheine' => ['B', 'C1']],
];

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

// Zwecke, für die nur ein Auto in Frage kommt (Regel 1).
$nurAuto = ['montage', 'materialtransport'];

// Fahrzeugart => Beschriftung.
$artText = [
    'auto'    => 'Auto',
    'roller'  => 'Roller',
    'fahrrad' => 'Fahrrad',
];

// Beispiel-Fuhrpark, dieselben Fahrzeuge wie in fahrzeug.php. Fahrräder
// brauchen keinen Führerschein.
$fahrzeuge = [
    1 => ['kennzeichen' => 'M-HS 101',  'hersteller' => 'Volkswagen',     'modell' => 'Passat Variant', 'art' => 'auto',    'typ' => 'Kombi',       'sitzplaetze' => 5, 'fuehrerschein' => 'B',  'status' => 'verfuegbar'],
    2 => ['kennzeichen' => 'M-HS 102',  'hersteller' => 'Škoda',          'modell' => 'Octavia Combi',  'art' => 'auto',    'typ' => 'Kombi',       'sitzplaetze' => 5, 'fuehrerschein' => 'B',  'status' => 'verfuegbar'],
    3 => ['kennzeichen' => 'M-HS 201',  'hersteller' => 'Ford',           'modell' => 'Transit',        'art' => 'auto',    'typ' => 'Transporter', 'sitzplaetze' => 3, 'fuehrerschein' => 'B',  'status' => 'verfuegbar'],
    4 => ['kennzeichen' => 'M-HS 202',  'hersteller' => 'Mercedes-Benz',  'modell' => 'Sprinter',       'art' => 'auto',    'typ' => 'Transporter', 'sitzplaetze' => 3, 'fuehrerschein' => 'C1', 'status' => 'wartung'],
    5 => ['kennzeichen' => 'M-HS 301E', 'hersteller' => 'Volkswagen',     'modell' => 'ID.3',           'art' => 'auto',    'typ' => 'E-Auto',      'sitzplaetze' => 5, 'fuehrerschein' => 'B',  'status' => 'verfuegbar'],
    6 => ['kennzeichen' => 'M-HS 302E', 'hersteller' => 'Tesla',          'modell' => 'Model 3',        'art' => 'auto',    'typ' => 'E-Auto',      'sitzplaetze' => 5, 'fuehrerschein' => 'B',  'status' => 'verfuegbar'],
    7 => ['kennzeichen' => 'M-HS 401',  'hersteller' => 'Vespa',          'modell' => 'Primavera 125',  'art' => 'roller',  'typ' => 'Roller',      'sitzplaetze' => 2, 'fuehrerschein' => 'A1', 'status' => 'verfuegbar'],
    8 => ['kennzeichen' => 'Rad 1',     'hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'art' => 'fahrrad', 'typ' => 'E-Fahrrad',   'sitzplaetze' => 1, 'fuehrerschein' => null, 'status' => 'verfuegbar'],
    9 => ['kennzeichen' => 'Rad 2',     'hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'art' => 'fahrrad', 'typ' => 'E-Fahrrad',   'sitzplaetze' => 1, 'fuehrerschein' => null, 'status' => 'verfuegbar'],
];

// Belegende Buchungen (offen, genehmigt, unterwegs), wie in kalender.php.
// Tage relativ zu heute. Namen braucht die Seite nicht.
$buchungen = [
    ['fahrzeug_id' => 1, 'von' => 1,  'bis' => 1,  'status' => 'genehmigt'],
    ['fahrzeug_id' => 1, 'von' => 4,  'bis' => 6,  'status' => 'genehmigt'],
    ['fahrzeug_id' => 1, 'von' => 10, 'bis' => 11, 'status' => 'offen'],
    ['fahrzeug_id' => 2, 'von' => 5,  'bis' => 6,  'status' => 'offen'],
    ['fahrzeug_id' => 3, 'von' => 0,  'bis' => 2,  'status' => 'unterwegs'],
    ['fahrzeug_id' => 3, 'von' => 7,  'bis' => 8,  'status' => 'genehmigt'],
    ['fahrzeug_id' => 4, 'von' => 3,  'bis' => 4,  'status' => 'offen'],
    ['fahrzeug_id' => 5, 'von' => 2,  'bis' => 2,  'status' => 'genehmigt'],
    ['fahrzeug_id' => 6, 'von' => 8,  'bis' => 9,  'status' => 'offen'],
    ['fahrzeug_id' => 7, 'von' => -2, 'bis' => -1, 'status' => 'unterwegs'],
    ['fahrzeug_id' => 8, 'von' => 0,  'bis' => 0,  'status' => 'genehmigt'],
    ['fahrzeug_id' => 9, 'von' => 3,  'bis' => 4,  'status' => 'genehmigt'],
];

$heute = new DateTimeImmutable('today');

// --- Suche (GET) ------------------------------------------------------------
// Fehlt ein Wert, gilt der Standard: ich selbst, morgen, 1 Person, alle
// Arten. Ein angegebener, aber ungültiger Wert ist ein Fehler, damit nicht
// still ein anderer Zeitraum gebucht wird.

$fehler = [];

$fahrer = (int) (is_string($_GET['fahrer'] ?? null) ? $_GET['fahrer'] : $ich);
if (!isset($nutzer[$fahrer])) {
    $fehler[] = 'Diesen Fahrer gibt es nicht.';
    $fahrer = $ich;
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
$suche = $fehler === [] ? array_filter([
    'fahrer'   => $fahrer !== $ich ? $fahrer : null,
    'beginn'   => $zeitraum['beginn']->format('Y-m-d'),
    'ende'     => $zeitraum['ende']->format('Y-m-d'),
    'personen' => $personen !== 1 ? $personen : null,
    'zweck'    => $zweck,
    'art'      => $art,
], fn ($wert): bool => $wert !== null && $wert !== '') : [];

// --- Fahrzeugliste ----------------------------------------------------------
// Personen, Fahrzeugart und Zweck schränken die Liste ein (WHERE). Wartung,
// fehlender Führerschein und Belegung bleiben sichtbar, aber nicht wählbar.
// Je Fahrzeug 'stand': frei, vergeben, wartung oder fuehrerschein.

$liste = [];

if ($fehler === []) {
    // Tage relativ zu heute, wie in den Buchungen.
    $tagVon = (int) $heute->diff($zeitraum['beginn'])->days;
    $tagBis = (int) $heute->diff($zeitraum['ende'])->days;

    foreach ($fahrzeuge as $id => $fahrzeug) {
        if ($fahrzeug['sitzplaetze'] < $personen
            || ($art !== '' && $fahrzeug['art'] !== $art)
            || (in_array($zweck, $nurAuto, true) && $fahrzeug['art'] !== 'auto')) {
            continue;
        }

        $belegungen = array_filter($buchungen, fn (array $b): bool => $b['fahrzeug_id'] === $id);
        $freiAb = frei_ab($belegungen, $tagVon, $tagBis);
        $fehlt = $fahrzeug['fuehrerschein'] !== null
            && !in_array($fahrzeug['fuehrerschein'], $nutzer[$fahrer]['fuehrerscheine'], true);

        $fahrzeug['stand'] = match (true) {
            $fahrzeug['status'] === 'wartung' => 'wartung',
            $fehlt                            => 'fuehrerschein',
            $freiAb !== $tagVon               => 'vergeben',
            default                           => 'frei',
        };

        // „frei ab“ nur bei belegten Fahrzeugen; null, wenn eine Rückgabe
        // überfällig ist und niemand weiß, wann das Fahrzeug zurückkommt.
        $fahrzeug['frei_ab'] = $fahrzeug['stand'] === 'vergeben' && $freiAb !== null
            ? $heute->modify('+' . $freiAb . ' day')
            : null;

        $liste[$id] = $fahrzeug;
    }
}

$anzahlFrei = count(array_filter($liste, fn (array $f): bool => $f['stand'] === 'frei'));

// --- Buchung absenden (POST) ------------------------------------------------
// Läuft vor header.php, damit später eine Weiterleitung möglich ist. Die
// Suche kommt über die Adresse des Formulars mit; geprüft wird hier alles
// noch einmal, denn die Anzeige ist nur Komfort.

$bestaetigung = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auswahl = (int) (is_string($_POST['fahrzeug'] ?? null) ? $_POST['fahrzeug'] : 0);

    if ($fehler === []) {
        if ($zweck === '') {
            $fehler[] = 'Bitte wählen Sie oben den Zweck der Fahrt.';
        }

        if (!isset($liste[$auswahl])) {
            $fehler[] = 'Bitte wählen Sie ein Fahrzeug aus der Liste.';
        } elseif ($liste[$auswahl]['stand'] === 'wartung') {
            $fehler[] = 'Das Fahrzeug ist in Wartung und kann nicht gebucht werden.';
        } elseif ($liste[$auswahl]['stand'] === 'fuehrerschein') {
            $fehler[] = $nutzer[$fahrer]['name'] . ' fehlt der Führerschein '
                . $liste[$auswahl]['fuehrerschein'] . ' für dieses Fahrzeug.';
        } elseif ($liste[$auswahl]['stand'] === 'vergeben') {
            $fehler[] = 'Das Fahrzeug ist im gewählten Zeitraum nicht mehr frei.';
        }
    }

    if ($fehler === []) {
        // Nur für diese Anzeige; gespeichert wird noch nicht.
        $bestaetigung = [
            'fahrzeug' => $liste[$auswahl],
            'status'   => $liste[$auswahl]['art'] === 'auto' ? 'offen' : 'genehmigt',
        ];
    }
}

/**
 * Erster Tag ab $von, an dem das Fahrzeug für die ganze Dauer bis $bis frei
 * ist (Tage relativ zu heute). Liegt eine Buchung im Weg, geht es am Tag nach
 * ihrem Ende weiter. null, wenn eine Rückgabe überfällig ist: Dann ist
 * unbekannt, wann das Fahrzeug zurückkommt.
 */
function frei_ab(array $belegungen, int $von, int $bis): ?int
{
    foreach ($belegungen as $b) {
        if ($b['status'] === 'unterwegs' && $b['bis'] < 0) {
            return null;
        }
    }

    $dauer = $bis - $von;
    $tag = $von;

    do {
        $verschoben = false;

        foreach ($belegungen as $b) {
            if ($b['von'] <= $tag + $dauer && $b['bis'] >= $tag) {
                $tag = $b['bis'] + 1;
                $verschoben = true;
            }
        }
    } while ($verschoben);

    return $tag;
}

/**
 * Zeitraum als Text, eintägig ohne „bis“.
 */
function zeitraum_text(DateTimeImmutable $von, DateTimeImmutable $bis): string
{
    return $von == $bis
        ? 'am ' . $von->format('d.m.Y')
        : 'vom ' . $von->format('d.m.Y') . ' bis ' . $bis->format('d.m.Y');
}

require_once __DIR__ . '/includes/header.php';
?>

<p class="note">
    Prototyp &ndash; Beispieldaten. Die Buchung wird geprüft und bestätigt, aber noch nicht
    gespeichert.
</p>

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
            <?= e(zeitraum_text($zeitraum['beginn'], $zeitraum['ende'])) ?>,
            <?= e($zweckText[$zweck]) ?>, Fahrer: <?= e($nutzer[$fahrer]['name']) ?>.
        </p>
    </div>

    <p class="section">
        <a class="button" href="<?= url('meine-buchungen.php') ?>">Zu meinen Buchungen</a>
        <a class="button button--zweitrangig" href="<?= url('buchen.php') ?>">Weiteres Fahrzeug buchen</a>
    </p>

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
                <span class="form__hinweis">Montage und Materialtransport nur mit Auto</span>
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
                <?= e(zeitraum_text($zeitraum['beginn'], $zeitraum['ende'])) ?>
                für <?= e((string) $personen) ?> <?= $personen === 1 ? 'Person' : 'Personen' ?>,
                Fahrer: <?= e($nutzer[$fahrer]['name']) ?>.
                <?= e((string) $anzahlFrei) ?> von <?= e((string) count($liste)) ?>
                passenden Fahrzeugen <?= $anzahlFrei === 1 ? 'ist' : 'sind' ?> frei.
                Autos werden beim Fuhrparkleiter beantragt, Roller und Fahrräder sind sofort bestätigt.
            </p>

            <div class="fahrzeugwahl">
                <?php foreach ($liste as $id => $fahrzeug): ?>
                    <?php $waehlbar = $fahrzeug['stand'] === 'frei'; ?>
                    <label class="fahrzeugkarte<?= $waehlbar ? '' : ' fahrzeugkarte--gesperrt' ?>">
                        <input class="fahrzeugkarte__auswahl" type="radio" name="fahrzeug" required
                               value="<?= e((string) $id) ?>"
                               <?= $waehlbar ? '' : 'disabled' ?>
                               <?= $waehlbar && $id === $auswahl ? 'checked' : '' ?>>

                        <span class="fahrzeugkarte__bild"><?= e($fahrzeug['typ']) ?></span>

                        <span class="fahrzeugkarte__kopf">
                            <strong><?= e($fahrzeug['hersteller'] . ' ' . $fahrzeug['modell']) ?></strong>
                            <?php if ($fahrzeug['stand'] === 'frei'): ?>
                                <span class="badge badge--verfuegbar">frei</span>
                            <?php elseif ($fahrzeug['stand'] === 'vergeben'): ?>
                                <span class="badge badge--vergeben">vergeben</span>
                            <?php elseif ($fahrzeug['stand'] === 'wartung'): ?>
                                <span class="badge badge--wartung">in Wartung</span>
                            <?php else: ?>
                                <span class="badge badge--abgelehnt">Führerschein <?= e($fahrzeug['fuehrerschein']) ?> fehlt</span>
                            <?php endif; ?>
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
                            <span>
                                <?php if ($fahrzeug['stand'] === 'vergeben'): ?>
                                    <?= $fahrzeug['frei_ab'] !== null
                                        ? 'frei ab ' . e($fahrzeug['frei_ab']->format('d.m.'))
                                        : 'Rückgabe noch offen' ?>
                                <?php elseif ($fahrzeug['stand'] === 'frei'): ?>
                                    <?= $fahrzeug['art'] === 'auto' ? 'Antrag nötig' : 'sofort bestätigt' ?>
                                <?php endif; ?>
                            </span>
                            <a href="<?= url('fahrzeug.php?id=' . $id) ?>">Steckbrief</a>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <?php if ($liste === []): ?>
                <p class="note">Kein Fahrzeug passt zu dieser Suche. Weniger Personen oder eine andere Fahrzeugart?</p>
            <?php elseif ($anzahlFrei > 0): ?>
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
