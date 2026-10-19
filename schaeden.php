<?php
/**
 * Schäden (Anwendungsfall 10), nur für den Fuhrparkleiter.
 *
 * Alle gemeldeten Schäden, zuerst die offenen, dann die behobenen, jeweils
 * neueste zuerst: Schäden aus Rückgaben (Kleinschaden oder mit Wartung) und
 * vorhandene Schäden, die ein Fahrer bei Fahrtbeginn notiert hat („bei
 * Übernahme“). Gemeldet hat jeweils der Fahrer der Buchung. Bemerkungen ohne
 * Schaden stehen nur im Fahrtenbuch (docs/aenderungen/aenderungen-todo2.md, Punkt 10).
 *
 * Bei Luxusautos sperrt schon ein Kleinschaden das Fahrzeug (entschieden am
 * 19.10.2026, schaden_sperrt()); er wird wie ein Schaden mit Wartung durch das
 * Freigeben behoben und ist markiert.
 *
 * Ein offener Schaden lässt sich als behoben markieren. Steht das Fahrzeug
 * wegen des Schadens in Wartung, wird es stattdessen freigegeben; das
 * Formular schickt an fahrzeuge.php, das Freigeben gilt dort zugleich als
 * „behoben“.
 *
 * Prototyp: Die Daten kommen aus includes/beispieldaten.php. „Behoben“ wird
 * geprüft und angezeigt, aber noch nicht gespeichert. Mit Datenbank:
 *
 *   SELECT s.*, b.fahrzeug_id, b.fahrer_id
 *     FROM schaeden s JOIN buchungen b ON b.id = s.buchung_id
 *    ORDER BY s.behoben_am IS NOT NULL, s.gemeldet_am DESC
 *   SELECT schaden_id, datei FROM schadensfotos WHERE schaden_id IN (...)
 *
 * Beim Speichern:
 *   UPDATE schaeden SET behoben_am = NOW(), behoben_von = :ich
 *    WHERE id = :id AND behoben_am IS NULL
 *
 * Schadensfotos sieht nur der Fuhrparkleiter (Datenschutz, siehe
 * docs/konzept/technisches-konzept.md, Regel 6). Im Prototyp gibt es noch keine
 * abgelegten Fotos, die Vorschau zeigt deshalb Platzhalter. Später die Fotos
 * nicht direkt aus uploads/schaeden/ verlinken, sondern über ein Skript
 * ausliefern, das die Rolle prüft.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle(ROLLE_FUHRPARKLEITER);

$pageTitle = 'Schäden';

$fahrzeuge = beispiel_fahrzeuge();
$schaeden  = beispiel_schaeden();

// --- „Behoben“ verarbeiten --------------------------------------------------
// Läuft vor header.php, damit später eine Weiterleitung möglich ist.

$fehler = [];
$bestaetigung = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) (is_string($_POST['schaden'] ?? null) ? $_POST['schaden'] : 0);

    if (!isset($schaeden[$id])) {
        $fehler[] = 'Diesen Schaden gibt es nicht.';
    } elseif ($schaeden[$id]['behoben_am'] !== null) {
        $fehler[] = 'Der Schaden ist bereits als behoben markiert.';
    } elseif (schaden_sperrt($schaeden[$id]['schwere_id'], $fahrzeuge[$schaeden[$id]['fahrzeug_id']]['art_id'])
        && $fahrzeuge[$schaeden[$id]['fahrzeug_id']]['in_wartung']) {
        $fehler[] = 'Das Fahrzeug steht wegen dieses Schadens in Wartung. Bitte geben Sie es frei; der Schaden gilt dann als behoben.';
    } else {
        // Nur für diese Anzeige; gespeichert wird noch nicht.
        $schaeden[$id]['behoben_am'] = new DateTimeImmutable('today');
        $bestaetigung = 'Schaden an ' . fahrzeug_name($schaeden[$id]['fahrzeug_id']) . ' als behoben markiert.';
    }
}

// --- Offen und behoben, jeweils neueste zuerst -------------------------------

uasort($schaeden, fn (array $a, array $b): int => $b['gemeldet_am'] <=> $a['gemeldet_am']);

$offene   = array_filter($schaeden, fn (array $s): bool => $s['behoben_am'] === null);
$behobene = array_filter($schaeden, fn (array $s): bool => $s['behoben_am'] !== null);

$anzahlSperrend = count(array_filter($offene, fn (array $s): bool => schaden_sperrt($s['schwere_id'], $fahrzeuge[$s['fahrzeug_id']]['art_id'])));

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Gemeldete Schäden, zuerst die offenen. <?= e((string) count($offene)) ?> offen, davon
    <?= e((string) $anzahlSperrend) ?> mit Wartung. Ein Kleinschaden sperrt das Fahrzeug nicht und
    muss nicht sofort behoben werden; die Fahrer sehen ihn vor Fahrtbeginn und müssen ihn nicht
    erneut melden. Ausnahme Luxusautos: Dort sperrt jeder Schaden bis zur Freigabe. Bemerkungen ohne Schaden stehen im Fahrtenbuch.
</p>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($bestaetigung !== null): ?>
    <p class="alert alert--erfolg"><?= e($bestaetigung) ?></p>
<?php endif; ?>

<?php foreach (['Offen' => $offene, 'Behoben' => $behobene] as $titel => $liste): ?>
    <section class="section">
        <h2><?= e($titel) ?></h2>

        <table class="table">
            <thead>
                <tr>
                    <th>Gemeldet</th>
                    <th>Fahrzeug</th>
                    <th>Fahrer</th>
                    <th>Art</th>
                    <th>Beschreibung</th>
                    <th><?= $titel === 'Offen' ? 'Aktionen' : 'Behoben am' ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($liste as $id => $schaden): ?>
                    <?php
                    $fahrzeug = $fahrzeuge[$schaden['fahrzeug_id']];
                    $sperrend = schaden_sperrt($schaden['schwere_id'], $fahrzeug['art_id']);
                    ?>
                    <tr>
                        <td><?= e($schaden['gemeldet_am']->format('d.m.Y')) ?></td>
                        <td>
                            <a href="<?= url('fahrzeug.php?id=' . $schaden['fahrzeug_id']) ?>"><?= e(fahrzeug_name($schaden['fahrzeug_id'])) ?></a>
                            <?php if ($fahrzeug['in_wartung'] && $schaden['behoben_am'] === null): ?>
                                <span class="table__zusatz">in Wartung</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(nutzer_name($schaden['fahrer_id'])) ?></td>
                        <td>
                            <span class="badge<?= $sperrend ? ' badge--abgelehnt' : '' ?>"><?= e(SCHWEREGRADE[$schaden['schwere_id']]) ?></span>
                            <?php if ($schaden['schwere_id'] === SCHWERE_KLEIN && $sperrend && $schaden['behoben_am'] === null): ?>
                                <span class="table__zusatz"><?= $fahrzeug['in_wartung'] ? 'Luxusauto: sperrt das Fahrzeug' : 'Luxusauto: wird nach der Rückgabe gesperrt' ?></span>
                            <?php endif; ?>
                            <span class="table__zusatz"><?= e(SCHADENSANLAESSE[$schaden['anlass_id']]) ?></span>
                        </td>
                        <td>
                            <?= e($schaden['beschreibung']) ?>

                            <?php if ($schaden['fotos'] !== []): ?>
                                <ul class="schadensfotos" aria-label="Fotos zum Schaden">
                                    <?php foreach ($schaden['fotos'] as $nr => $datei): ?>
                                        <li class="schadensfotos__bild schadensfotos__bild--platzhalter">
                                            Foto <?= e((string) ($nr + 1)) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($schaden['behoben_am'] !== null): ?>
                                <?= e($schaden['behoben_am']->format('d.m.Y')) ?>
                            <?php elseif ($sperrend && $fahrzeug['in_wartung']): ?>
                                <form method="post" action="<?= url('fahrzeuge.php') ?>">
                                    <input type="hidden" name="fahrzeug" value="<?= e((string) $schaden['fahrzeug_id']) ?>">
                                    <input type="hidden" name="aktion" value="freigeben">
                                    <button class="button button--klein" type="submit">Fahrzeug freigeben</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="<?= url('schaeden.php') ?>">
                                    <input type="hidden" name="schaden" value="<?= e((string) $id) ?>">
                                    <button class="button button--klein" type="submit">Als behoben markieren</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($liste === []): ?>
                    <tr>
                        <td colspan="6" class="table__empty"><?= $titel === 'Offen' ? 'Keine offenen Schäden.' : 'Noch keine behobenen Schäden.' ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
<?php endforeach; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
