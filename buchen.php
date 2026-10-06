<?php
/**
 * Fahrzeug buchen (Anwendungsfall 1).
 *
 * Prototyp ohne Funktion: Suche und Fahrzeugliste zeigen feste Beispieldaten.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Fahrzeug buchen';

// Zeitraum der Suche: aus dem Buchungskalender übergeben
// (kalender.php → buchen.php?beginn=2026-10-07&ende=2026-10-07), sonst
// morgen, ganztägig. Übernommen wird nur ein gültiges Datum ab heute.
$heute = new DateTimeImmutable('today');
$zeitraum = [];

foreach (['beginn', 'ende'] as $feld) {
    $wert = $_GET[$feld] ?? '';
    $datum = is_string($wert) ? DateTimeImmutable::createFromFormat('!Y-m-d', $wert) : false;

    $zeitraum[$feld] = $datum !== false && $datum->format('Y-m-d') === $wert && $datum >= $heute
        ? $datum
        : $heute->modify('+1 day');
}

if ($zeitraum['ende'] < $zeitraum['beginn']) {
    $zeitraum['ende'] = $zeitraum['beginn'];
}

// Aus dem Steckbrief vorgewähltes Fahrzeug (fahrzeug.php → buchen.php?fahrzeug=1).
$vorauswahl = (int) ($_GET['fahrzeug'] ?? 0);

// Freie Fahrzeuge für die Beispielsuche.
$fahrzeuge = [
    ['id' => 1, 'kennzeichen' => 'M-HS 101',  'art' => 'Auto',    'typ' => 'Kombi',       'hersteller' => 'Volkswagen',     'modell' => 'Passat Variant', 'sitze' => 5],
    ['id' => 3, 'kennzeichen' => 'M-HS 201',  'art' => 'Auto',    'typ' => 'Transporter', 'hersteller' => 'Ford',           'modell' => 'Transit',        'sitze' => 3],
    ['id' => 5, 'kennzeichen' => 'M-HS 301E', 'art' => 'Auto',    'typ' => 'E-Auto',      'hersteller' => 'Volkswagen',     'modell' => 'ID.3',           'sitze' => 5],
    ['id' => 7, 'kennzeichen' => 'M-HS 401',  'art' => 'Roller',  'typ' => 'Roller',      'hersteller' => 'Vespa',          'modell' => 'Primavera 125',  'sitze' => 2],
    ['id' => 8, 'kennzeichen' => 'Rad 1',     'art' => 'Fahrrad', 'typ' => 'E-Fahrrad',   'hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'sitze' => 1],
];

require_once __DIR__ . '/includes/header.php';
?>

<p class="note">
    Prototyp &ndash; Beispieldaten, noch ohne Funktion.
</p>

<!-- Schritt 1: Suche -->
<form class="form form--breit">
    <h2 class="form__titel">1. Zeitraum und Mitfahrer</h2>

    <div class="form__raster">
        <div class="form__row">
            <label class="form__label" for="beginn">Von</label>
            <input class="form__input" type="date" id="beginn" name="beginn"
                   value="<?= e($zeitraum['beginn']->format('Y-m-d')) ?>">
        </div>

        <div class="form__row">
            <label class="form__label" for="ende">Bis</label>
            <input class="form__input" type="date" id="ende" name="ende"
                   value="<?= e($zeitraum['ende']->format('Y-m-d')) ?>">
        </div>

        <div class="form__row">
            <label class="form__label" for="personen">Personen</label>
            <input class="form__input" type="number" id="personen" name="personen"
                   min="1" max="5" value="1">
        </div>

        <div class="form__row">
            <label class="form__label" for="art">Fahrzeugart</label>
            <select class="form__input" id="art" name="art">
                <option value="">alle</option>
                <option value="auto">Auto</option>
                <option value="roller">Roller</option>
                <option value="fahrrad">Fahrrad</option>
            </select>
        </div>
    </div>

    <button class="button" type="button">Freie Fahrzeuge anzeigen</button>
</form>

<!-- Schritt 2: freies Fahrzeug wählen und Antrag stellen -->
<form class="form form--breit section">
    <h2 class="form__titel">2. Freies Fahrzeug auswählen</h2>
    <p class="lead">
        <?= count($fahrzeuge) ?> Fahrzeuge frei am <?= e($zeitraum['beginn']->format('d.m.Y')) ?>
        für 1 Person.
    </p>

    <div class="fahrzeugwahl">
        <?php foreach ($fahrzeuge as $fahrzeug): ?>
            <label class="fahrzeugkarte">
                <input class="fahrzeugkarte__auswahl" type="radio" name="fahrzeug"
                       value="<?= e($fahrzeug['kennzeichen']) ?>"
                       <?= $fahrzeug['id'] === $vorauswahl ? 'checked' : '' ?>>

                <span class="fahrzeugkarte__bild"><?= e($fahrzeug['typ']) ?></span>

                <span class="fahrzeugkarte__kopf">
                    <strong><?= e($fahrzeug['hersteller'] . ' ' . $fahrzeug['modell']) ?></strong>
                    <span class="badge badge--verfuegbar">frei</span>
                </span>

                <span class="fahrzeugkarte__daten">
                    <?= e($fahrzeug['kennzeichen']) ?> &middot;
                    <?= e($fahrzeug['art']) ?> &middot;
                    <?= e((string) $fahrzeug['sitze']) ?> <?= $fahrzeug['sitze'] === 1 ? 'Sitz' : 'Sitze' ?>
                </span>
            </label>
        <?php endforeach; ?>
    </div>

    <h2 class="form__titel section">3. Antrag</h2>

    <div class="form__row">
        <label class="form__label" for="zweck">Zweck der Fahrt</label>
        <textarea class="form__input" id="zweck" name="zweck" rows="3"
                  placeholder="z. B. Montage PV-Anlage, Baustelle Musterstraße 12"></textarea>
    </div>

    <button class="button" type="button">Antrag absenden</button>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
