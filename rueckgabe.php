<?php
/**
 * Fahrzeug zurückgeben (Anwendungsfall 6), nur für Mitarbeiter.
 *
 * Kein Navigationspunkt: Aufruf über „Zurückgeben“ an der laufenden Fahrt in
 * index.php (auch aus dem roten Banner) und meine-buchungen.php, jeweils mit
 * rueckgabe.php?buchung=12. Ohne Angabe ist bei nur einer laufenden Fahrt
 * diese gewählt.
 *
 * Ein neuer Schaden ist entweder ein Kleinschaden (z. B. ein Kratzer, das
 * Fahrzeug bleibt fahrbereit) oder ein Schaden, mit dem das Fahrzeug in die
 * Wartung muss. Beides braucht eine Beschreibung; Fotos sind bei beiden
 * möglich. Die Bemerkung zum Zustand ist davon getrennt und bleibt optional.
 *
 * Prototyp: Die laufenden Fahrten kommen aus includes/beispieldaten.php. Das
 * Formular wird geprüft und bestätigt, aber noch nicht gespeichert. Mit
 * Datenbank:
 *
 *   SELECT b.*, f.kmstand, ... FROM buchungen b JOIN fahrzeuge f ON f.id = b.fahrzeug_id
 *    WHERE b.fahrer_id = :ich AND b.status = 'unterwegs'
 *
 * Beim Speichern in einer Transaktion (siehe docs/technisches-konzept.md,
 * Regel 6):
 *   UPDATE buchungen  SET status = 'abgeschlossen', zurueckgegeben_am = NOW(),
 *                         km_ende = :km, bemerkung = :bemerkung
 *   UPDATE fahrzeuge  SET kmstand = :km
 *   INSERT INTO schaeden (buchung_id, anlass, schwere, beschreibung, gemeldet_am)
 *   VALUES (:buchung, 'rueckgabe', :schwere, :beschreibung, NOW())   -- nur bei Schaden
 *   INSERT INTO wartungen (fahrzeug_id, schaden_id, grund, begonnen_am)
 *   VALUES (:fahrzeug, :schaden, :beschreibung, CURDATE())          -- nur bei Schaden mit Wartung
 *   (voraussichtlich_bis bleibt NULL: Das Ende kennt der Fahrer nicht. Bis der
 *   Fuhrparkleiter es auf der Übersicht festlegt, ist das Fahrzeug ganz gesperrt.)
 *
 * Schadensfotos werden geprüft (Anzahl, Größe, Dateityp am Inhalt), aber im
 * Prototyp noch nicht abgelegt. Später: move_uploaded_file() nach
 * uploads/schaeden/ unter einem zufälligen Namen, dazu je Foto
 *   INSERT INTO schadensfotos (schaden_id, datei) VALUES (:schaden, :datei)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle('mitarbeiter');

$pageTitle = 'Fahrzeug zurückgeben';

$ich = aktueller_nutzer();

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

// Neuer Schaden => Beschriftung. Die Kürzel außer 'nein' sind zugleich
// schaeden.schwere; 'wartung' sperrt das Fahrzeug.
$schadenText = [
    'nein'    => 'Nein',
    'klein'   => 'Ja, Kleinschaden',
    'wartung' => 'Ja, muss in die Wartung',
];

$heute = new DateTimeImmutable('today');

// Meine laufenden Fahrten (Status „unterwegs“), wie in meine-buchungen.php.
// km_start ist null bei Fahrzeugen ohne km-Stand (Fahrrad).
$laufende = array_filter(
    beispiel_buchungen(),
    fn (array $b): bool => $b['fahrer_id'] === $ich && $b['status'] === 'unterwegs',
);

// Schadensfotos: höchstens 3 Stück zu je 5 MB, nur JPEG, PNG oder WebP.
// Die Grenzen liegen unter post_max_size (XAMPP: 40 MB).
$maxFotos     = 3;
$maxFotoBytes = 5 * 1024 * 1024;
$fotoTypen    = ['image/jpeg', 'image/png', 'image/webp'];

// --- Fahrt auswählen --------------------------------------------------------
// Gibt es nur eine laufende Fahrt, ist sie gleich ausgewählt.

$buchungId = (int) ($_POST['buchung'] ?? $_GET['buchung'] ?? 0);

if ($buchungId === 0 && count($laufende) === 1) {
    $buchungId = array_key_first($laufende);
}

$fahrt = $laufende[$buchungId] ?? null;

// --- Formular verarbeiten ---------------------------------------------------
// Läuft vor header.php, damit später eine Weiterleitung möglich ist.

$fehler = [];
$bestaetigung = null;

$eingabe = [
    'km_ende'      => trim((string) ($_POST['km_ende'] ?? '')),
    'schaden'      => (string) ($_POST['schaden'] ?? 'nein'),
    'beschreibung' => trim((string) ($_POST['beschreibung'] ?? '')),
    'bemerkung'    => trim((string) ($_POST['bemerkung'] ?? '')),
];

// Überschreitet die Anfrage post_max_size, verwirft PHP alle Felder. Ohne
// diese Prüfung käme die irreführende Meldung „Bitte wählen Sie die Fahrt aus“.
$zuGross = $_SERVER['REQUEST_METHOD'] === 'POST' && $_POST === []
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;

if ($zuGross) {
    $fehler[] = 'Die Fotos sind zusammen zu groß. Bitte wählen Sie kleinere oder weniger Fotos.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($fahrt === null) {
        $fehler[] = 'Bitte wählen Sie die Fahrt aus, die Sie beendet haben.';
    } else {
        $kmEnde = null;

        if ($fahrt['km_start'] !== null) {
            $kmEnde = filter_var($eingabe['km_ende'], FILTER_VALIDATE_INT);

            if ($kmEnde === false) {
                $fehler[] = 'Bitte geben Sie den Kilometerstand als ganze Zahl ein.';
            } elseif ($kmEnde < $fahrt['km_start']) {
                $fehler[] = 'Der Kilometerstand darf nicht kleiner sein als bei Fahrtbeginn ('
                    . number_format($fahrt['km_start'], 0, ',', '.') . ' km).';
            }
        }

        $mitSchaden = $eingabe['schaden'] !== 'nein';

        if (!isset($schadenText[$eingabe['schaden']])) {
            $fehler[] = 'Bitte geben Sie an, ob ein neuer Schaden entstanden ist.';
        } elseif ($mitSchaden && $eingabe['beschreibung'] === '') {
            $fehler[] = 'Bitte beschreiben Sie den Schaden.';
        }

        // Fotos gehören zur Schadensmeldung und werden ohne Schaden ignoriert.
        $fotos = [];

        if ($mitSchaden) {
            [$fotos, $fotoFehler] = pruefe_fotos($_FILES['fotos'] ?? [], $maxFotos, $maxFotoBytes, $fotoTypen);
            $fehler = array_merge($fehler, $fotoFehler);
        }

        if ($fehler === []) {
            $bestaetigung = [
                'fahrzeug' => fahrzeug_name($fahrt['fahrzeug_id']),
                'schaden'  => $eingabe['schaden'],
                'km'       => $kmEnde !== null ? $kmEnde - $fahrt['km_start'] : null,
                'fotos'    => count($fotos),
            ];
        } elseif ($fotos !== [] || ($_FILES['fotos']['name'][0] ?? '') !== '') {
            // Der Browser kann ausgewählte Dateien nicht erneut vorbelegen.
            $fehler[] = 'Bitte wählen Sie die Fotos nach der Korrektur erneut aus.';
        }
    }
}

/**
 * Prüft die hochgeladenen Schadensfotos aus <input type="file" name="fotos[]" multiple>.
 *
 * Der Dateityp wird am Inhalt erkannt, nicht an Endung oder Browserangabe,
 * beides lässt sich fälschen. Liefert [gültige Fotos, Fehlermeldungen].
 */
function pruefe_fotos(array $dateien, int $max, int $maxBytes, array $typen): array
{
    $fotos  = [];
    $fehler = [];

    foreach ($dateien['error'] ?? [] as $i => $code) {
        $name = (string) $dateien['name'][$i];

        if ($code === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
            || $dateien['size'][$i] > $maxBytes) {
            $fehler[] = '„' . $name . '“ ist größer als ' . intdiv($maxBytes, 1024 * 1024) . ' MB.';
            continue;
        }

        if ($code !== UPLOAD_ERR_OK || !is_uploaded_file($dateien['tmp_name'][$i])) {
            $fehler[] = '„' . $name . '“ konnte nicht hochgeladen werden.';
            continue;
        }

        $typ = (new finfo(FILEINFO_MIME_TYPE))->file($dateien['tmp_name'][$i]);

        if (!in_array($typ, $typen, true)) {
            $fehler[] = '„' . $name . '“ ist kein Foto im Format JPEG, PNG oder WebP.';
            continue;
        }

        $fotos[] = ['tmp_name' => $dateien['tmp_name'][$i], 'typ' => $typ];
    }

    if (count($fotos) > $max) {
        $fehler[] = 'Bitte höchstens ' . $max . ' Fotos anhängen.';
    }

    return [$fotos, $fehler];
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($bestaetigung !== null): ?>

    <?php if ($bestaetigung['schaden'] === 'wartung'): ?>
        <p class="alert alert--hinweis">
            Rückgabe gespeichert. <?= e($bestaetigung['fahrzeug']) ?> steht wegen des gemeldeten
            Schadens jetzt &bdquo;in Wartung&ldquo;. Der Fuhrparkleiter sieht Ihre Meldung und gibt
            das Fahrzeug nach der Reparatur wieder frei.
        </p>
    <?php elseif ($bestaetigung['schaden'] === 'klein'): ?>
        <p class="alert alert--erfolg">
            Rückgabe gespeichert. <?= e($bestaetigung['fahrzeug']) ?> ist wieder für andere frei.
            Der Fuhrparkleiter sieht den Kleinschaden, der nächste Fahrer sieht ihn vor Fahrtbeginn.
        </p>
    <?php else: ?>
        <p class="alert alert--erfolg">
            Rückgabe gespeichert. <?= e($bestaetigung['fahrzeug']) ?> ist wieder für andere frei.
        </p>
    <?php endif; ?>

    <?php if ($bestaetigung['fotos'] > 0): ?>
        <p class="lead">
            <?= $bestaetigung['fotos'] === 1 ? '1 Foto' : $bestaetigung['fotos'] . ' Fotos' ?>
            zur Schadensmeldung angehängt.
        </p>
    <?php endif; ?>

    <?php if ($bestaetigung['km'] !== null): ?>
        <p class="lead">Gefahrene Strecke: <?= number_format($bestaetigung['km'], 0, ',', '.') ?> km.</p>
    <?php endif; ?>

    <p><a href="<?= url('meine-buchungen.php') ?>">Zu meinen Buchungen</a></p>

<?php elseif ($laufende === []): ?>

    <p class="lead">Sie haben derzeit keine laufende Fahrt.</p>
    <p><a href="<?= url('meine-buchungen.php') ?>">Zu meinen Buchungen</a></p>

<?php else: ?>

    <section>
        <h2>Laufende Fahrten</h2>

        <table class="table">
            <thead>
                <tr>
                    <th>Zeitraum</th>
                    <th>Fahrzeug</th>
                    <th>Zweck</th>
                    <th>Stand</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($laufende as $id => $laufend): ?>
                    <tr<?= $id === $buchungId ? ' class="table__zeile--gewaehlt"' : '' ?>>
                        <td><?= e(zeitraum_text($laufend['start'], $laufend['ende'])) ?></td>
                        <td><?= e(fahrzeug_name($laufend['fahrzeug_id'])) ?></td>
                        <td><?= e($zweckText[$laufend['zweck']] ?? $laufend['zweck']) ?></td>
                        <td>
                            <span class="badge badge--unterwegs">unterwegs</span>
                            <?php if ($laufend['ende'] < $heute): ?>
                                <span class="badge badge--abgelehnt">Rückgabe überfällig</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($id !== $buchungId): ?>
                                <a class="button button--klein" href="<?= url('rueckgabe.php?buchung=' . $id) ?>">Auswählen</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <?php if ($fahrt !== null): ?>
        <form class="form form--breit section" method="post" action="<?= url('rueckgabe.php') ?>"
              enctype="multipart/form-data">
            <h2 class="form__titel">Rückgabe: <?= e(fahrzeug_name($fahrt['fahrzeug_id'])) ?></h2>

            <input type="hidden" name="buchung" value="<?= e((string) $buchungId) ?>">

            <div class="form__raster">
                <?php if ($fahrt['km_start'] !== null): ?>
                    <div class="form__row">
                        <label class="form__label" for="km_ende">Kilometerstand bei Rückgabe</label>
                        <input class="form__input" type="number" id="km_ende" name="km_ende"
                               min="<?= e((string) $fahrt['km_start']) ?>" step="1" required
                               value="<?= e($eingabe['km_ende']) ?>">
                        <span class="form__hinweis">
                            bei Fahrtbeginn: <?= number_format($fahrt['km_start'], 0, ',', '.') ?> km
                        </span>
                    </div>
                <?php endif; ?>

                <fieldset class="form__row form__gruppe">
                    <legend class="form__label">Neuer Schaden entstanden?</legend>
                    <div class="form__optionen">
                        <?php foreach ($schadenText as $wert => $text): ?>
                            <label>
                                <input type="radio" name="schaden" value="<?= e($wert) ?>"
                                       <?= $wert === $eingabe['schaden'] || ($wert === 'nein' && !isset($schadenText[$eingabe['schaden']])) ? 'checked' : '' ?>>
                                <?= e($text) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <span class="form__hinweis">
                        Ein Kleinschaden (z. B. ein Kratzer) sperrt das Fahrzeug nicht; der nächste
                        Fahrer sieht ihn vor Fahrtbeginn. Mit Wartung ist das Fahrzeug gesperrt, bis
                        der Fuhrparkleiter es wieder freigibt.
                    </span>
                </fieldset>
            </div>

            <div class="form__row" data-nur-bei-schaden>
                <label class="form__label" for="beschreibung">Beschreibung des Schadens</label>
                <textarea class="form__input" id="beschreibung" name="beschreibung" rows="2"
                          placeholder="z. B. Delle hinten links, Tür schließt schwer"><?= e($eingabe['beschreibung']) ?></textarea>
                <span class="form__hinweis">Nur bei einem Schaden, dann Pflicht: Was ist beschädigt?</span>
            </div>

            <div class="form__row" data-nur-bei-schaden>
                <label class="form__label" for="fotos">Fotos vom Schaden (optional)</label>
                <input class="form__input" type="file" id="fotos" name="fotos[]" multiple
                       accept="image/jpeg,image/png,image/webp">
                <span class="form__hinweis">
                    Nur bei einem Schaden. Höchstens <?= e((string) $maxFotos) ?> Fotos
                    (JPEG, PNG oder WebP) zu je <?= e((string) intdiv($maxFotoBytes, 1024 * 1024)) ?> MB.
                </span>
            </div>

            <div class="form__row">
                <label class="form__label" for="bemerkung">Bemerkung zum Zustand (optional)</label>
                <textarea class="form__input" id="bemerkung" name="bemerkung" rows="2"
                          placeholder="z. B. Innenraum verschmutzt, Akku fast leer"><?= e($eingabe['bemerkung']) ?></textarea>
                <span class="form__hinweis">Steht im Fahrtenbuch und sperrt das Fahrzeug nicht.</span>
            </div>

            <button class="button" type="submit">Rückgabe speichern</button>
        </form>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
