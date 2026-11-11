<?php
/**
 * Ein offener Antrag als Schild zum Entscheiden: Kopf mit Fahrzeug und Stand,
 * Kennwertfelder (Antragsteller, Zeitraum, Zweck mit Personenzahl, wie lange
 * er schon wartet), rechts Genehmigen und Ablehnen. Gleich auf der Übersicht
 * des Fuhrparkleiters (index.php) und in genehmigungen.php.
 *
 * Erwartet von der einbindenden Seite:
 *   $Antrag         der Antrag (CBuchung)
 *   $Zwecke         CListeSchluessel('zwecke')
 *   $Arten          CListeSchluessel('fahrzeugarten')
 *   $zurueck, $offenerAntrag, $kommentar  für includes/antrag-entscheidung.php
 */

declare(strict_types=1);

// Für das Formular: Nummer und Hindernis des Antrags
$id = $Antrag->GetID();
$hindernis = $Antrag->GetGenehmigungHindernis();

$SchildFahrzeug = CFahrzeug::LoadByID($Antrag->GetFahrzeugID());
$marke = $hindernis === null ? ['Antrag', 'gelb'] : ['Gesperrt', 'rot'];
?>
<article class="schild">
    <?php require __DIR__ . '/schild-kopf.php'; ?>

    <div class="schild__koerper">
        <dl class="felder felder--antrag">
            <div class="feld">
                <dt>Antragsteller</dt>
                <dd>
                    <?= e($Antrag->GetAntragstellerName()) ?>
                    <?php if ($Antrag->GetFahrerID() !== $Antrag->GetAntragstellerID()): ?>
                        <small>für <?= e($Antrag->GetFahrerName()) ?></small>
                    <?php endif; ?>
                </dd>
            </div>
            <div class="feld">
                <dt>Zeitraum</dt>
                <dd><?= e(zeitraum_kurz($Antrag->GetStart(), $Antrag->GetEnde())) ?></dd>
            </div>
            <div class="feld">
                <dt>Zweck</dt>
                <dd>
                    <?= e($Zwecke->GetBezeichnung($Antrag->GetZweckID())) ?>
                    <?php $personen = $Antrag->GetPersonenanzahl(); ?>
                    <small><?= e((string) $personen) ?> <?= $personen === 1 ? 'Person' : 'Personen' ?></small>
                </dd>
            </div>
            <div class="feld">
                <dt>Wartet seit</dt>
                <dd><?= e(wartet_seit($Antrag->GetBeantragtAm())) ?></dd>
            </div>
        </dl>

        <div class="schild__aktionen">
            <?php require __DIR__ . '/antrag-entscheidung.php'; ?>
        </div>
    </div>
</article>
