<?php
/**
 * Fahrzeug zurückgeben (Anwendungsfall 6), nur für Mitarbeiter.
 *
 * Kein Navigationspunkt: Aufruf über „Zurückgeben“ an der laufenden Fahrt in
 * index.php (auch aus dem roten Banner) und meine-buchungen.php, jeweils mit
 * rueckgabe.php?buchung=12. Ohne Angabe ist bei nur einer laufenden Fahrt
 * diese gewählt.
 *
 * Prototyp: Die laufenden Fahrten sind feste Beispieldaten. Das Formular wird
 * geprüft und bestätigt, aber noch nicht gespeichert. Mit Datenbank:
 *
 *   SELECT b.*, f.kmstand, ... FROM buchungen b JOIN fahrzeuge f ON f.id = b.fahrzeug_id
 *    WHERE b.fahrer_id = :ich AND b.status = 'unterwegs'
 *
 * Beim Speichern (siehe docs/technisches-konzept.md, Regel 6):
 *   UPDATE buchungen  SET status = 'abgeschlossen', zurueckgegeben_am = NOW(),
 *                         km_ende = :km, schaden = :schaden, bemerkung = :bemerkung
 *   UPDATE fahrzeuge  SET kmstand = :km [, status = 'wartung' bei Schaden]
 *
 * Schadensfotos werden geprüft (Anzahl, Größe, Dateityp am Inhalt), aber im
 * Prototyp noch nicht abgelegt. Später: move_uploaded_file() nach
 * uploads/schaeden/ unter einem zufälligen Namen, dazu je Foto
 *   INSERT INTO schadensfotos (buchung_id, datei) VALUES (:buchung, :datei)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle('mitarbeiter');

$pageTitle = 'Fahrzeug zurückgeben';

// Angemeldeter Nutzer. Kommt später aus der Session.
$ich = 'Lucie Schneider';

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

$heute = new DateTimeImmutable('today');

// Meine laufenden Fahrten (Status „unterwegs“), wie in meine-buchungen.php.
// km_start ist null bei Fahrzeugen ohne km-Stand (Fahrrad).
$laufende = [
    12 => [
        'fahrzeug_id' => 7,
        'fahrzeug'    => 'Vespa Primavera 125 (M-HS 401)',
        'start'       => $heute->modify('-2 day'),
        'ende'        => $heute->modify('-1 day'),
        'zweck'       => 'kundentermin',
        'km_start'    => 6400,
    ],
];

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
    'km_ende'   => trim((string) ($_POST['km_ende'] ?? '')),
    'schaden'   => (string) ($_POST['schaden'] ?? 'nein'),
    'bemerkung' => trim((string) ($_POST['bemerkung'] ?? '')),
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

        if (!in_array($eingabe['schaden'], ['nein', 'ja'], true)) {
            $fehler[] = 'Bitte geben Sie an, ob ein neuer Schaden entstanden ist.';
        } elseif ($eingabe['schaden'] === 'ja' && $eingabe['bemerkung'] === '') {
            $fehler[] = 'Bitte beschreiben Sie den Schaden in der Bemerkung.';
        }

        // Fotos gehören zur Schadensmeldung und werden ohne Schaden ignoriert.
        $fotos = [];

        if ($eingabe['schaden'] === 'ja') {
            [$fotos, $fotoFehler] = pruefe_fotos($_FILES['fotos'] ?? [], $maxFotos, $maxFotoBytes, $fotoTypen);
            $fehler = array_merge($fehler, $fotoFehler);
        }

        if ($fehler === []) {
            $bestaetigung = [
                'fahrzeug' => $fahrt['fahrzeug'],
                'schaden'  => $eingabe['schaden'] === 'ja',
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

/**
 * Zeitraum einer Fahrt als Text, eintägig ohne „bis“.
 */
function zeitraum(array $fahrt): string
{
    $von = $fahrt['start']->format('d.m.Y');
    $bis = $fahrt['ende']->format('d.m.Y');

    return $von === $bis ? $von : $von . ' bis ' . $bis;
}

require_once __DIR__ . '/includes/header.php';
?>

<p class="note">
    Prototyp &ndash; Beispieldaten. Die Rückgabe wird geprüft, aber noch nicht gespeichert.
    Angemeldet als <?= e($ich) ?>.
</p>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($bestaetigung !== null): ?>

    <?php if ($bestaetigung['schaden']): ?>
        <p class="alert alert--hinweis">
            Rückgabe gespeichert. <?= e($bestaetigung['fahrzeug']) ?> steht wegen des gemeldeten
            Schadens jetzt &bdquo;in Wartung&ldquo;. Der Fuhrparkleiter sieht Ihre Meldung und gibt
            das Fahrzeug nach der Reparatur wieder frei.
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
                        <td><?= e(zeitraum($laufend)) ?></td>
                        <td><?= e($laufend['fahrzeug']) ?></td>
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
            <h2 class="form__titel">Rückgabe: <?= e($fahrt['fahrzeug']) ?></h2>

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
                        <label>
                            <input type="radio" name="schaden" value="nein"
                                   <?= $eingabe['schaden'] !== 'ja' ? 'checked' : '' ?>>
                            Nein
                        </label>
                        <label>
                            <input type="radio" name="schaden" value="ja"
                                   <?= $eingabe['schaden'] === 'ja' ? 'checked' : '' ?>>
                            Ja
                        </label>
                    </div>
                    <span class="form__hinweis">
                        Bei einem Schaden wird das Fahrzeug gesperrt, bis der Fuhrparkleiter es
                        wieder freigibt.
                    </span>
                </fieldset>
            </div>

            <div class="form__row">
                <label class="form__label" for="bemerkung">Bemerkung zum Zustand</label>
                <textarea class="form__input" id="bemerkung" name="bemerkung" rows="3"
                          placeholder="z. B. Delle hinten links, Innenraum verschmutzt, Warnleuchte an"><?= e($eingabe['bemerkung']) ?></textarea>
                <span class="form__hinweis">Optional, bei einem Schaden Pflicht: Was ist beschädigt?</span>
            </div>

            <div class="form__row">
                <label class="form__label" for="fotos">Fotos vom Schaden (optional)</label>
                <input class="form__input" type="file" id="fotos" name="fotos[]" multiple
                       accept="image/jpeg,image/png,image/webp">
                <span class="form__hinweis">
                    Nur bei einem Schaden. Höchstens <?= e((string) $maxFotos) ?> Fotos
                    (JPEG, PNG oder WebP) zu je <?= e((string) intdiv($maxFotoBytes, 1024 * 1024)) ?> MB.
                </span>
            </div>

            <button class="button" type="submit">Rückgabe speichern</button>
        </form>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
