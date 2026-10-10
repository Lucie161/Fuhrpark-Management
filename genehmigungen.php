<?php
/**
 * Anträge genehmigen (Anwendungsfall 9), nur für den Fuhrparkleiter.
 *
 * Alle offenen Anträge, älteste zuerst. Der Fahrer steht nur dabei, wenn er
 * nicht selbst beantragt hat („Buchen für“). Genehmigen geht nur, wenn das
 * Fahrzeug im Zeitraum noch frei, nicht in Wartung und nicht überfällig ist
 * (genehmigung_hindernis()); eine Ablehnung braucht eine Begründung, die der
 * Antragsteller liest (siehe docs/technisches-konzept.md, Regel 9).
 *
 * Prototyp: Die Anträge kommen aus includes/beispieldaten.php. Eine
 * Entscheidung wird geprüft und angezeigt, aber noch nicht gespeichert. Mit
 * Datenbank:
 *
 *   SELECT * FROM buchungen WHERE status = 'offen' ORDER BY beantragt_am
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

// --- Anträge aufbereiten ----------------------------------------------------
// Offene Anträge, älteste zuerst. 'hindernis': warum Genehmigen nicht geht,
// sonst null.

$antraege = array_filter(beispiel_buchungen(), fn (array $b): bool => $b['status'] === 'offen');
uasort($antraege, fn (array $a, array $b): int => $a['beantragt_am'] <=> $b['beantragt_am']);

foreach ($antraege as &$antrag) {
    $antrag['hindernis'] = genehmigung_hindernis($antrag);
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
            'text' => 'Antrag von ' . nutzer_name($antrag['antragsteller_id']) . ' für '
                . fahrzeug_name($antrag['fahrzeug_id']) . ' (' . zeitraum_text($antrag['start'], $antrag['ende']) . ') '
                . ($entscheidung === 'genehmigen' ? 'genehmigt.' : 'abgelehnt.'),
            'art'  => $entscheidung === 'genehmigen' ? 'erfolg' : 'hinweis',
        ];

        // Prototyp: Weil nichts gespeichert wird, steht der Antrag auf der
        // Übersicht danach wieder in der Liste.
        if ($zurueck === 'index') {
            merke_meldung($bestaetigung['text'], $bestaetigung['art']);
            redirect('index.php');
        }

        // Nur für diese Anzeige; gespeichert wird noch nicht. Der entschiedene
        // Antrag verschwindet aus der Liste.
        unset($antraege[$id]);
        $offenerAntrag = null;
        $kommentar = '';
    }
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
                    <?= e($antrag['beantragt_am']->format('d.m.Y')) ?>
                    <span class="table__zusatz"><?= e($antrag['beantragt_am']->format('H:i')) ?> Uhr</span>
                </td>
                <td>
                    <?= e(nutzer_name($antrag['antragsteller_id'])) ?>
                    <?php if ($antrag['fahrer_id'] !== $antrag['antragsteller_id']): ?>
                        <span class="table__zusatz">für <?= e(nutzer_name($antrag['fahrer_id'])) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= url('fahrzeug.php?id=' . $antrag['fahrzeug_id']) ?>"><?= e(fahrzeug_name($antrag['fahrzeug_id'])) ?></a>
                </td>
                <td><?= e(zeitraum_text($antrag['start'], $antrag['ende'])) ?></td>
                <td><?= e($zweckText[$antrag['zweck']] ?? $antrag['zweck']) ?></td>
                <td class="table__num"><?= e((string) $antrag['personenanzahl']) ?></td>
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
