<?php
/**
 * Anträge genehmigen (Anwendungsfall 9), nur für den Fuhrparkleiter.
 *
 * Alle offenen Anträge, älteste zuerst. Der Fahrer steht nur dabei, wenn er
 * nicht selbst beantragt hat („Buchen für“). Genehmigen geht nur, wenn das Fahrzeug
 * im Zeitraum noch frei ist; eine Ablehnung braucht eine Begründung, die der
 * Antragsteller liest (siehe docs/technisches-konzept.md, Regel 9).
 *
 * Prototyp: Anträge und Belegung sind feste Beispieldaten, dieselben wie die
 * beantragten Buchungen in kalender.php. Eine Entscheidung wird geprüft und
 * angezeigt, aber noch nicht gespeichert. Mit Datenbank:
 *
 *   SELECT b.*, a.name AS antragsteller, f.name AS fahrer
 *     FROM buchungen b
 *     JOIN nutzer a ON a.id = b.antragsteller_id
 *     JOIN nutzer f ON f.id = b.fahrer_id
 *    WHERE b.status = 'offen' ORDER BY b.beantragt_am
 *
 * Beim Speichern:
 *   UPDATE buchungen SET status = 'genehmigt' | 'abgelehnt',
 *          entscheidung_von = :ich, entscheidung_am = NOW(),
 *          entscheidung_kommentar = :kommentar
 *    WHERE id = :id AND status = 'offen'
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle('fuhrparkleiter');

$pageTitle = 'Anträge genehmigen';

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

// Beispiel-Fuhrpark mit gespeichertem Status, wie in fahrzeuge.php.
$fahrzeuge = [
    1 => ['name' => 'Volkswagen Passat Variant (M-HS 101)', 'status' => 'verfuegbar'],
    2 => ['name' => 'Škoda Octavia Combi (M-HS 102)',       'status' => 'verfuegbar'],
    3 => ['name' => 'Ford Transit (M-HS 201)',              'status' => 'verfuegbar'],
    4 => ['name' => 'Mercedes-Benz Sprinter (M-HS 202)',    'status' => 'wartung'],
    5 => ['name' => 'Volkswagen ID.3 (M-HS 301E)',          'status' => 'verfuegbar'],
    6 => ['name' => 'Tesla Model 3 (M-HS 302E)',            'status' => 'verfuegbar'],
    7 => ['name' => 'Vespa Primavera 125 (M-HS 401)',       'status' => 'verfuegbar'],
    8 => ['name' => 'Riese & Müller Charger4 (Rad 1)',      'status' => 'verfuegbar'],
    9 => ['name' => 'Riese & Müller Charger4 (Rad 2)',      'status' => 'verfuegbar'],
];

// Offene Anträge, älteste zuerst. Tage relativ zu heute; 'beantragt' ist der
// Tag des Antrags, dazu die Uhrzeit. Der Antrag für den Sprinter zeigt, dass
// ein Fahrzeug nach dem Antrag in Wartung gehen kann.
$antraege = [
    19 => ['fahrzeug_id' => 4, 'antragsteller' => 'Kenneth Sander',  'fahrer' => 'Kenneth Sander',  'von' => 3,  'bis' => 4,  'zweck' => 'materialtransport', 'personen' => 2, 'beantragt' => -5, 'uhrzeit' => '14:12'],
    14 => ['fahrzeug_id' => 2, 'antragsteller' => 'Kenneth Sander',  'fahrer' => 'Lucie Schneider', 'von' => 5,  'bis' => 6,  'zweck' => 'montage',           'personen' => 3, 'beantragt' => -3, 'uhrzeit' => '08:45'],
    20 => ['fahrzeug_id' => 6, 'antragsteller' => 'Kenneth Sander',  'fahrer' => 'Kenneth Sander',  'von' => 8,  'bis' => 9,  'zweck' => 'aufmass',           'personen' => 1, 'beantragt' => -2, 'uhrzeit' => '16:30'],
    15 => ['fahrzeug_id' => 1, 'antragsteller' => 'Lucie Schneider', 'fahrer' => 'Lucie Schneider', 'von' => 10, 'bis' => 11, 'zweck' => 'lieferant',         'personen' => 2, 'beantragt' => -1, 'uhrzeit' => '10:05'],
];

// Bereits vergebene Zeiträume (genehmigt oder unterwegs), wie in
// kalender.php. Gegen sie wird beim Genehmigen geprüft.
$vergeben = [
    ['fahrzeug_id' => 1, 'von' => 1,  'bis' => 1],
    ['fahrzeug_id' => 1, 'von' => 4,  'bis' => 6],
    ['fahrzeug_id' => 3, 'von' => 0,  'bis' => 2],
    ['fahrzeug_id' => 3, 'von' => 7,  'bis' => 8],
    ['fahrzeug_id' => 5, 'von' => 2,  'bis' => 2],
    ['fahrzeug_id' => 7, 'von' => -2, 'bis' => -1],
    ['fahrzeug_id' => 8, 'von' => 0,  'bis' => 0],
    ['fahrzeug_id' => 9, 'von' => 3,  'bis' => 4],
];

$heute = new DateTimeImmutable('today');

// --- Anträge aufbereiten ----------------------------------------------------
// 'hindernis': warum Genehmigen nicht geht, sonst null.

foreach ($antraege as &$antrag) {
    $antrag['start'] = $heute->modify($antrag['von'] . ' day');
    $antrag['ende']  = $heute->modify($antrag['bis'] . ' day');

    $antrag['hindernis'] = null;

    if ($fahrzeuge[$antrag['fahrzeug_id']]['status'] === 'wartung') {
        $antrag['hindernis'] = 'Das Fahrzeug ist in Wartung.';
    } else {
        foreach ($vergeben as $belegung) {
            if ($belegung['fahrzeug_id'] === $antrag['fahrzeug_id']
                && $belegung['von'] <= $antrag['bis'] && $belegung['bis'] >= $antrag['von']) {
                $antrag['hindernis'] = 'Das Fahrzeug ist im Zeitraum bereits vergeben.';
                break;
            }
        }
    }
}
unset($antrag);

// --- Entscheidung verarbeiten -----------------------------------------------
// Läuft vor header.php, damit die Weiterleitung zur Übersicht möglich ist.
// Das Formular steht in includes/antrag-entscheidung.php und kommt auch von
// index.php (mit zurueck=index). Nach Erfolg geht es dann dorthin zurück;
// nach einem Fehler bleibt es hier, mit Meldung und der Eingabe im Feld.

$fehler = [];
$bestaetigung = null;

// Bei einem Fehler im Ablehnen bleibt das Feld dieses Antrags offen und
// behält die Eingabe. $zurueck bleibt in den Formularen, damit es nach der
// Korrektur trotzdem zur Übersicht zurückgeht.
$offenerAntrag = null;
$kommentar = '';
$zurueck = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) (is_string($_POST['antrag'] ?? null) ? $_POST['antrag'] : 0);
    $entscheidung = is_string($_POST['entscheidung'] ?? null) ? $_POST['entscheidung'] : '';
    $kommentar = is_string($_POST['kommentar'] ?? null) ? trim($_POST['kommentar']) : '';
    $zurueck = ($_POST['zurueck'] ?? '') === 'index' ? 'index' : '';

    if (!isset($antraege[$id])) {
        $fehler[] = 'Diesen Antrag gibt es nicht mehr. Vielleicht wurde er schon entschieden.';
    } elseif ($entscheidung === 'genehmigen') {
        if ($antraege[$id]['hindernis'] !== null) {
            $fehler[] = 'Der Antrag kann nicht genehmigt werden. ' . $antraege[$id]['hindernis'];
        }
    } elseif ($entscheidung === 'ablehnen') {
        $offenerAntrag = $id;

        if ($kommentar === '') {
            $fehler[] = 'Bitte begründen Sie die Ablehnung. Der Antragsteller sieht die Begründung.';
        } elseif (mb_strlen($kommentar) > 500) {
            $fehler[] = 'Die Begründung darf höchstens 500 Zeichen lang sein.';
        }
    } else {
        $fehler[] = 'Diese Entscheidung gibt es nicht.';
    }

    if ($fehler === []) {
        $antrag = $antraege[$id];
        $bestaetigung = [
            'text' => 'Antrag von ' . $antrag['antragsteller'] . ' für '
                . $fahrzeuge[$antrag['fahrzeug_id']]['name'] . ' (' . zeitraum($antrag) . ') '
                . ($entscheidung === 'genehmigen' ? 'genehmigt.' : 'abgelehnt.'),
            'art'  => $entscheidung === 'genehmigen' ? 'erfolg' : 'hinweis',
        ];

        // Prototyp: Weil nichts gespeichert wird, steht der Antrag auf der
        // Übersicht danach wieder in der Liste. Mit Datenbank entfällt der
        // Zusatz.
        if ($zurueck === 'index') {
            merke_meldung($bestaetigung['text'] . ' (Prototyp: noch nicht gespeichert.)', $bestaetigung['art']);
            redirect('index.php');
        }

        // Nur für diese Anzeige; gespeichert wird noch nicht. Der entschiedene
        // Antrag verschwindet aus der Liste.
        unset($antraege[$id]);
        $offenerAntrag = null;
        $kommentar = '';
    }
}

/**
 * Zeitraum eines Antrags als Text, eintägig ohne „bis“.
 */
function zeitraum(array $antrag): string
{
    $von = $antrag['start']->format('d.m.Y');
    $bis = $antrag['ende']->format('d.m.Y');

    return $von === $bis ? $von : $von . ' bis ' . $bis;
}

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    <?php if ($antraege === []): ?>
        Keine offenen Anträge.
    <?php else: ?>
        <?= e((string) count($antraege)) ?>
        <?= count($antraege) === 1 ? 'Antrag wartet' : 'Anträge warten' ?> auf Ihre Entscheidung,
        älteste zuerst.
    <?php endif; ?>
</p>

<p class="note">
    Prototyp &ndash; Beispieldaten. Entscheidungen werden geprüft und angezeigt, aber noch nicht
    gespeichert.
</p>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($bestaetigung !== null): ?>
    <p class="alert alert--<?= e($bestaetigung['art']) ?>"><?= e($bestaetigung['text']) ?></p>
<?php endif; ?>

<table class="table">
    <thead>
        <tr>
            <th>Beantragt am</th>
            <th>Antragsteller</th>
            <th>Fahrzeug</th>
            <th>Zeitraum</th>
            <th>Zweck</th>
            <th class="table__num">Personen</th>
            <th>Entscheidung</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($antraege as $id => $antrag): ?>
            <tr>
                <td>
                    <?= e($heute->modify($antrag['beantragt'] . ' day')->format('d.m.Y')) ?>
                    <span class="table__zusatz"><?= e($antrag['uhrzeit']) ?> Uhr</span>
                </td>
                <td>
                    <?= e($antrag['antragsteller']) ?>
                    <?php if ($antrag['fahrer'] !== $antrag['antragsteller']): ?>
                        <span class="table__zusatz">für <?= e($antrag['fahrer']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= url('fahrzeug.php?id=' . $antrag['fahrzeug_id']) ?>"><?= e($fahrzeuge[$antrag['fahrzeug_id']]['name']) ?></a>
                </td>
                <td><?= e(zeitraum($antrag)) ?></td>
                <td><?= e($zweckText[$antrag['zweck']] ?? $antrag['zweck']) ?></td>
                <td class="table__num"><?= e((string) $antrag['personen']) ?></td>
                <td>
                    <?php require __DIR__ . '/includes/antrag-entscheidung.php'; ?>
                </td>
            </tr>
        <?php endforeach; ?>

        <?php if ($antraege === []): ?>
            <tr>
                <td colspan="7" class="table__empty">Keine offenen Anträge.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
