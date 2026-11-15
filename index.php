<?php
/**
 * Überblick nach der Anmeldung, je Rolle. Jede Buchung und jeder neue Schaden
 * steht als Schild: Kennzeichen im Kopf, Kennwerte in Feldern, Aktionen rechts.
 *
 * Beide Rollen: ganz oben eine rote Meldung, sobald eine Rückgabe überfällig
 * ist (includes/banner.php).
 *
 * Mitarbeiter: oben je eine Benachrichtigung über eine Ablehnung oder eine
 * automatische Stornierung, bis sie mit „Gelesen“ weggeklickt wird. Links
 * „Meine Fahrten“ mit laufender Fahrt („Zurückgeben“), Fahrt, die beginnen
 * kann („Fahrt beginnen“), und der nächsten genehmigten Fahrt. Rechts
 * höchstens drei noch nicht bestätigte Anträge.
 *
 * Fuhrparkleiter: links „Zu erledigen“ mit den drei nächsten offenen Anträgen
 * zum direkten Entscheiden. Rechts die neuen Schäden (Fahrzeug gesperrt,
 * noch ohne Wartungsende), die Zahl der Fahrzeuge, die ein Schaden in
 * Wartung hält und ein Hinweis auf überschrittene Wartungsenden. Zuletzt über
 * die ganze Breite der Fuhrpark als eine Feldzeile (Fahrzeuge, frei,
 * unterwegs, in Wartung, heute). Er bucht nicht selbst.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_angemeldet();

$ich = aktueller_nutzer();
$heute = new DateTimeImmutable('today');

// „Gelesen“ an einer Benachrichtigung (includes/banner.php): danach zurück
// zur Übersicht, ohne JavaScript.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'gelesen') {
    $id = (int) (is_string($_POST['buchung'] ?? null) ? $_POST['buchung'] : 0);
    $GeleseneBuchung = CBuchung::LoadByID($id);

    try {
        if ($GeleseneBuchung === null) {
            throw new CFachlicherFehler('Diese Benachrichtigung gibt es nicht.');
        }

        $GeleseneBuchung->EntscheidungGelesen($ich);
    } catch (CFachlicherFehler $Fehler) {
        merke_meldung($Fehler->getMessage(), 'hinweis');
    }

    redirect('index.php');
}

$pageTitle = 'Übersicht';
$pageZusatz = datum_lang($heute);

// So viele offene Anträge stehen direkt auf der Übersicht: beim
// Fuhrparkleiter die nächsten zum Entscheiden (die übrigen in
// genehmigungen.php), beim Mitarbeiter die nächsten (die übrigen in
// meine-buchungen.php).
$antraegeAufUebersicht = 3;
$schaedenAufUebersicht = 5;

$Zwecke = new CListeSchluessel('zwecke');
$Arten = new CListeSchluessel('fahrzeugarten');

// --- Mitarbeiter -------------------------------------------------------------
// Laufend: unterwegs, ich bin Fahrer (nur er gibt zurück). Aktiv: genehmigt,
// heute im Zeitraum, ich bin Fahrer. Beantragt: noch nicht bestätigt. Alle
// übrigen Buchungen stehen in meine-buchungen.php.

$laufende   = [];
$aktive     = [];
$beantragte = [];
$Naechste   = null;

if (!ist_fuhrparkleiter()) {
    // „Fahrzeug buchen“ neben dem Titel nur, wenn Buchen gerade möglich ist
    // (nicht bei eigener überfälliger Rückgabe, dann zeigt das Banner den Weg)
    if (CNutzer::LoadByID($ich)->GetSperreBuchen() === null) {
        $pageAktion = ['Fahrzeug buchen', 'buchen.php', 'plus'];
    }

    // Eigene Buchungen, die nächste zuerst; Namen sind erlaubt
    $ListeMeine = new CListeBuchungen();
    $ListeMeine->LadenAktuelle($ich);

    foreach ($ListeMeine->GetAlle() as $Buchung) {
        if ($Buchung->GetStatusID() === STATUS_UNTERWEGS && $Buchung->GetFahrerID() === $ich) {
            $laufende[] = $Buchung;
        } elseif ($Buchung->KannBeginnen($ich)) {
            $aktive[] = $Buchung;
        } elseif ($Buchung->GetStatusID() === STATUS_OFFEN) {
            $beantragte[] = $Buchung;
        }
    }

    $Naechste = $ListeMeine->GetNaechsteGenehmigte();

    // Benachrichtigungen über Ablehnungen und automatische Stornierungen,
    // bis sie als gelesen markiert sind
    $ListeEntscheidungen = new CListeBuchungen();
    $ListeEntscheidungen->LadenUngeleseneEntscheidungen($ich);
    $bannerEntscheidungen = $ListeEntscheidungen->GetAlle();

    // Rotes Banner: nur die eigenen überfälligen Fahrten
    $bannerFahrten = array_filter($laufende, fn (CBuchung $Buchung): bool => $Buchung->IstUeberfaellig());
    $bannerMitNamen = false;
}

$anzahlBeantragte = count($beantragte);
$beantragte = array_slice($beantragte, 0, $antraegeAufUebersicht);

// --- Fuhrparkleiter -----------------------------------------------------------

$fuhrpark = null;

if (ist_fuhrparkleiter()) {
    $ListeFahrzeuge = new CListeFahrzeuge();
    $ListeFahrzeuge->LadenImBestand();

    // Überfällige Rückgaben mit Fahrernamen, die älteste zuerst
    $ListeUeberfaellige = new CListeBuchungen();
    $ListeUeberfaellige->LadenUeberfaellige(true);

    $bannerFahrten = $ListeUeberfaellige->GetAlle();
    $bannerMitNamen = true;

    // Offene Anträge, die nächste Fahrt zuerst, wie in genehmigungen.php
    $ListeAntraege = new CListeBuchungen();
    $ListeAntraege->LadenOffeneAntraege();

    // Gezählt werden nur Schäden, mit denen ein Fahrzeug in Wartung steht.
    // Kleinschäden sperren nichts; sie stehen in schaeden.php.
    $ListeSchaeden = new CListeSchaeden();
    $ListeSchaeden->LadenAlle();

    // Neue Schäden: Wartung nach einer Rückgabe mit Schaden, noch ohne Ende.
    // Dazu Wartungen, deren geplantes Ende vorbei ist. Der Fuhrparkleiter
    // trägt ein Wartungsende ein oder gibt das Fahrzeug frei.
    $ListeNeueSchaeden = new CListeWartungen();
    $ListeNeueSchaeden->LadenNeueSchaeden();

    $ListeUeberschritten = new CListeWartungen();
    $ListeUeberschritten->LadenEndeUeberschritten();

    // Bekannte Schäden, noch nicht behoben, der neueste zuerst. Ohne die
    // Schäden, die oben schon als neu stehen (Fahrzeug in Wartung ohne Ende).
    $neueFahrzeuge = array_map(fn (CWartung $Wartung): int => $Wartung->GetFahrzeugID(), $ListeNeueSchaeden->GetAlle());
    $offeneSchaeden = array_values(array_filter(
        $ListeSchaeden->GetAlle(),
        fn (CSchaden $Schaden): bool => $Schaden->IstOffen()
            && !($Schaden->HaeltInWartung() && in_array($Schaden->GetFahrzeugID(), $neueFahrzeuge, true)),
    ));

    // Fahrten, die heute beginnen oder enden
    $ListeHeute = new CListeBuchungen();
    $ListeHeute->LadenHeute();

    // Stand je Fahrzeug wie in fahrzeuge.php: Wartung geht vor unterwegs,
    // so zählt jedes Fahrzeug genau einmal.
    $proStand = ['verfuegbar' => 0, 'unterwegs' => 0, 'wartung' => 0];

    foreach ($ListeFahrzeuge->GetAlle() as $Fahrzeug) {
        $proStand[$Fahrzeug->GetStand()]++;
    }

    $fuhrpark = [
        'fahrzeuge'       => $ListeFahrzeuge->Count(),
        'stand'           => $proStand,
        'antraege'        => array_slice($ListeAntraege->GetAlle(), 0, $antraegeAufUebersicht),
        'anzahl_antraege' => $ListeAntraege->Count(),
        'schaeden'        => $ListeSchaeden->CountSperrende(),
        'neue_schaeden'   => $ListeNeueSchaeden->GetAlle(),
        'offene_schaeden' => array_slice($offeneSchaeden, 0, $schaedenAufUebersicht),
        'anzahl_offene_schaeden' => count($offeneSchaeden),
        'ende_ueberschritten' => $ListeUeberschritten->GetAlle(),
        'beginnen_heute'  => $ListeHeute->CountBeginnenHeute(),
        'rueckgaben_heute' => $ListeHeute->CountRueckgabenHeute(),
    ];

    // Für das Formular aus includes/antrag-entscheidung.php: nach der
    // Entscheidung zurück zur Übersicht.
    $zurueck = 'index';
    $offenerAntrag = null;
    $kommentar = '';
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($fuhrpark !== null): ?>

    <div class="uebersicht">
        <section class="uebersicht__haupt" aria-labelledby="zu-erledigen">
            <?php $anzahl = $fuhrpark['anzahl_antraege']; ?>
            <h2 class="abschnitt" id="zu-erledigen">
                Zu erledigen
                <span class="abschnitt__wert">
                    <?= $anzahl === 0 ? 'keine offenen Anträge' : e((string) $anzahl) . ($anzahl === 1 ? ' offener Antrag' : ' offene Anträge') ?>
                </span>
                <?php if ($anzahl > count($fuhrpark['antraege'])): ?>
                    <span class="abschnitt__zusatz">die <?= e((string) count($fuhrpark['antraege'])) ?> nächsten</span>
                <?php endif; ?>
            </h2>

            <?php foreach ($fuhrpark['antraege'] as $Antrag): ?>
                <?php require __DIR__ . '/includes/schild-antrag.php'; ?>
            <?php endforeach; ?>

            <?php if ($anzahl === 0): ?>
                <div class="schild schild--leer">
                    <p>Keine offenen Anträge. Neue Anträge erscheinen hier, sobald jemand ein Fahrzeug bucht, das eine Genehmigung braucht.</p>
                </div>
            <?php endif; ?>

            <?php if ($anzahl > count($fuhrpark['antraege'])): ?>
                <p class="weiter">
                    <a href="<?= url('genehmigungen.php') ?>">Alle <?= e((string) $anzahl) ?> Anträge</a>
                </p>
            <?php endif; ?>

        </section>

        <aside class="uebersicht__neben">
            <section aria-labelledby="schaeden">
                <?php $neue = count($fuhrpark['neue_schaeden']); ?>
                <h2 class="abschnitt" id="schaeden">
                    Schäden
                    <span class="abschnitt__wert"><?= $neue === 0 ? 'keine neuen' : e((string) $neue) . ' neu' ?></span>
                </h2>

                <?php foreach ($fuhrpark['neue_schaeden'] as $Wartung): ?>
                    <?php
                    $SchildFahrzeug = CFahrzeug::LoadByID($Wartung->GetFahrzeugID());
                    $marke = ['Gesperrt', 'rot'];
                    ?>
                    <article class="schild">
                        <?php require __DIR__ . '/includes/schild-kopf.php'; ?>

                        <div class="schild__text">
                            <p class="schild__grund"><?= e($Wartung->GetGrund()) ?></p>
                            <p>
                                Gemeldet bei der Rückgabe am <?= e($Wartung->GetBegonnenAm()->format('d.m.Y')) ?>.
                                Das Fahrzeug bleibt gesperrt, bis Sie ein Wartungsende eintragen oder es freigeben.
                            </p>
                        </div>

                        <div class="schild__aktionen">
                            <div class="aktionen">
                                <a class="button button--klein" href="<?= url('fahrzeuge.php?formular=' . $Wartung->GetFahrzeugID()) ?>#formular">Wartungsende eintragen</a>
                                <a class="button button--klein button--zweitrangig" href="<?= url('schaeden.php') ?>">Zum Schaden</a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>

                <!-- Darunter die bekannten, noch nicht behobenen Schäden als kurze Liste -->
                <?php
                $anzahlOffen = $fuhrpark['anzahl_offene_schaeden'];
                $schaeden = $fuhrpark['schaeden'];
                ?>
                <h3 class="abschnitt abschnitt--klein">
                    Noch nicht behoben
                    <span class="abschnitt__wert">
                        <?= $anzahlOffen === 0 ? 'keine' : e((string) $anzahlOffen) ?>
                    </span>
                    <?php if ($schaeden > 0): ?>
                        <span class="abschnitt__zusatz"><?= e((string) $schaeden) ?> <?= $schaeden === 1 ? 'Fahrzeug' : 'Fahrzeuge' ?> in Wartung</span>
                    <?php endif; ?>
                </h3>

                <?php if ($fuhrpark['offene_schaeden'] !== []): ?>
                    <article class="schild">
                        <ul class="schadenliste">
                            <?php foreach ($fuhrpark['offene_schaeden'] as $Schaden): ?>
                                <?php
                                $ListeFahrzeug = CFahrzeug::LoadByID($Schaden->GetFahrzeugID());
                                $inWartung = $Schaden->HaeltInWartung();
                                ?>
                                <li class="schadenliste__eintrag">
                                    <span class="schadenliste__kopf">
                                        <a class="schadenliste__fahrzeug" href="<?= url('fahrzeug.php?id=' . $ListeFahrzeug->GetID()) ?>"><?= e($ListeFahrzeug->GetHersteller() . ' ' . $ListeFahrzeug->GetModell()) ?></a>
                                        <span class="marke marke--<?= $inWartung ? 'gelb' : 'grau' ?>"><?= $inWartung ? 'In Wartung' : 'Kleinschaden' ?></span>
                                    </span>
                                    <span class="schadenliste__text"><?= e($Schaden->GetBeschreibung()) ?></span>
                                    <span class="schadenliste__zusatz">
                                        <?= e($ListeFahrzeug->GetKennzeichen()) ?> &middot; gemeldet am <?= e($Schaden->GetGemeldetAm()->format('d.m.Y')) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                <?php else: ?>
                    <p class="zeile">Alle bekannten Schäden sind behoben.</p>
                <?php endif; ?>

                <p class="weiter">
                    <a href="<?= url('schaeden.php') ?>"><?= $anzahlOffen > count($fuhrpark['offene_schaeden']) ? 'Alle ' . e((string) $anzahlOffen) . ' offenen Schäden' : 'Zu den Schäden' ?></a>
                </p>

                <?php $ueberschritten = count($fuhrpark['ende_ueberschritten']); ?>
                <?php if ($ueberschritten > 0): ?>
                    <p class="zeile">
                        <?= e((string) $ueberschritten) ?> <?= $ueberschritten === 1 ? 'Fahrzeug ist' : 'Fahrzeuge sind' ?>
                        über das geplante Wartungsende hinaus gesperrt
                        &middot; <a href="<?= url('fahrzeuge.php?status=wartung') ?>">Zu den Fahrzeugen</a>
                    </p>
                <?php endif; ?>
            </section>

        </aside>
    </div>

    <?php
    $beginnen = $fuhrpark['beginnen_heute'];
    $rueckgaben = $fuhrpark['rueckgaben_heute'];
    ?>
    <!-- Fuhrpark zuletzt: eine Feldzeile über die ganze Breite -->
    <section class="fuhrpark-leiste" aria-labelledby="fuhrpark">
        <h2 class="abschnitt" id="fuhrpark">Fuhrpark</h2>

        <article class="schild">
            <div class="schild__koerper">
                <dl class="felder felder--fuhrpark">
                    <div class="feld">
                        <dt>Fahrzeuge</dt>
                        <dd class="feld__zahl"><?= e((string) $fuhrpark['fahrzeuge']) ?></dd>
                    </div>
                    <div class="feld">
                        <dt>Frei</dt>
                        <dd class="feld__zahl"><?= e((string) $fuhrpark['stand']['verfuegbar']) ?></dd>
                    </div>
                    <div class="feld">
                        <dt>Unterwegs</dt>
                        <dd class="feld__zahl"><?= e((string) $fuhrpark['stand']['unterwegs']) ?></dd>
                    </div>
                    <div class="feld">
                        <dt>In Wartung</dt>
                        <dd class="feld__zahl"><?= e((string) $fuhrpark['stand']['wartung']) ?></dd>
                    </div>
                    <div class="feld">
                        <dt>Heute</dt>
                        <dd>
                            <?= e((string) $beginnen) ?> <?= $beginnen === 1 ? 'Fahrt beginnt' : 'Fahrten beginnen' ?>,
                            <?= e((string) $rueckgaben) ?> <?= $rueckgaben === 1 ? 'Rückgabe' : 'Rückgaben' ?> fällig
                        </dd>
                    </div>
                </dl>

                <div class="schild__aktionen">
                    <div class="aktionen">
                        <a class="button button--klein button--zweitrangig" href="<?= url('kalender.php') ?>">Kalender</a>
                        <a class="button button--klein button--zweitrangig" href="<?= url('fahrzeuge.php') ?>">Alle Fahrzeuge</a>
                    </div>
                </div>
            </div>
        </article>
    </section>

<?php else: ?>

    <div class="uebersicht">
        <section class="uebersicht__haupt" aria-labelledby="meine-fahrten">
            <?php $anzahlFahrten = count($laufende) + count($aktive) + ($Naechste !== null ? 1 : 0); ?>
            <h2 class="abschnitt" id="meine-fahrten">
                Meine Fahrten
                <span class="abschnitt__wert">
                    <?php if ($bannerFahrten !== []): ?>
                        Rückgabe überfällig
                    <?php elseif ($laufende !== []): ?>
                        Unterwegs
                    <?php elseif ($aktive !== []): ?>
                        Fahrt kann beginnen
                    <?php elseif ($Naechste !== null): ?>
                        Nächste am <?= e($Naechste->GetStart()->format('d.m.')) ?>
                    <?php else: ?>
                        Nichts geplant
                    <?php endif; ?>
                </span>
            </h2>

            <?php foreach ($laufende as $Buchung): ?>
                <?php
                $ueberfaellig = $Buchung->IstUeberfaellig();
                $heuteFaellig = $Buchung->GetEnde() == $heute;
                $tage = $Buchung->GetEnde()->diff($heute)->days;

                $SchildFahrzeug = CFahrzeug::LoadByID($Buchung->GetFahrzeugID());
                $marke = buchung_stand($Buchung, $ich);
                ?>
                <article class="schild">
                    <?php require __DIR__ . '/includes/schild-kopf.php'; ?>

                    <div class="schild__koerper">
                        <dl class="felder">
                            <div class="feld">
                                <dt>Zeitraum</dt>
                                <dd><?= e(zeitraum_kurz($Buchung->GetStart(), $Buchung->GetEnde())) ?></dd>
                            </div>
                            <div class="feld">
                                <dt>Rückgabe</dt>
                                <dd>
                                    <?php if ($ueberfaellig): ?>
                                        seit <?= e((string) $tage) ?> <?= $tage === 1 ? 'Tag' : 'Tagen' ?> überfällig
                                    <?php elseif ($heuteFaellig): ?>
                                        heute fällig
                                    <?php else: ?>
                                        fällig am <?= e($Buchung->GetEnde()->format('d.m.Y')) ?>
                                    <?php endif; ?>
                                </dd>
                            </div>
                            <div class="feld">
                                <dt>Zweck</dt>
                                <dd><?= e($Zwecke->GetBezeichnung($Buchung->GetZweckID())) ?></dd>
                            </div>
                        </dl>

                        <div class="schild__aktionen">
                            <a class="button button--klein" href="<?= url('rueckgabe.php?buchung=' . $Buchung->GetID()) ?>"><span class="icon icon--zurueck" aria-hidden="true"></span>Zurückgeben</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>

            <?php foreach ($aktive as $Buchung): ?>
                <?php
                $SchildFahrzeug = CFahrzeug::LoadByID($Buchung->GetFahrzeugID());
                $marke = buchung_stand($Buchung, $ich);
                ?>
                <article class="schild">
                    <?php require __DIR__ . '/includes/schild-kopf.php'; ?>

                    <div class="schild__koerper">
                        <dl class="felder">
                            <div class="feld">
                                <dt>Zeitraum</dt>
                                <dd><?= e(zeitraum_kurz($Buchung->GetStart(), $Buchung->GetEnde())) ?></dd>
                            </div>
                            <div class="feld">
                                <dt>Zweck</dt>
                                <dd><?= e($Zwecke->GetBezeichnung($Buchung->GetZweckID())) ?></dd>
                            </div>
                            <div class="feld">
                                <dt>Personen</dt>
                                <dd><?= e((string) $Buchung->GetPersonenanzahl()) ?></dd>
                            </div>
                        </dl>

                        <div class="schild__aktionen">
                            <?php
                            // Das Formular schickt nach dem Fahrtbeginn hierher zurück.
                            $zurueck = 'index';
                            require __DIR__ . '/includes/fahrt-beginnen.php';
                            ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>

            <?php if ($Naechste !== null): ?>
                <?php
                $SchildFahrzeug = CFahrzeug::LoadByID($Naechste->GetFahrzeugID());
                $marke = buchung_stand($Naechste, $ich);
                ?>
                <article class="schild">
                    <?php require __DIR__ . '/includes/schild-kopf.php'; ?>

                    <div class="schild__koerper">
                        <dl class="felder">
                            <div class="feld">
                                <dt>Nächste Fahrt</dt>
                                <dd><?= e(zeitraum_kurz($Naechste->GetStart(), $Naechste->GetEnde())) ?></dd>
                            </div>
                            <div class="feld">
                                <dt>Zweck</dt>
                                <dd><?= e($Zwecke->GetBezeichnung($Naechste->GetZweckID())) ?></dd>
                            </div>
                            <div class="feld">
                                <dt>Fahrer</dt>
                                <dd><?= $Naechste->GetFahrerID() === $ich ? 'Sie' : e($Naechste->GetFahrerName()) ?></dd>
                            </div>
                        </dl>

                        <div class="schild__aktionen">
                            <p class="schild__hinweis">
                                <?php if ($Naechste->GetFahrerID() === $ich): ?>
                                    Fahrt beginnen ab <?= e($Naechste->GetStart()->format('d.m.Y')) ?>
                                <?php else: ?>
                                    Die Fahrt beginnt der Fahrer ab <?= e($Naechste->GetStart()->format('d.m.Y')) ?>.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </article>
            <?php endif; ?>

            <?php if ($laufende === [] && $aktive === [] && $Naechste === null): ?>
                <div class="schild schild--leer">
                    <p>Für Sie ist gerade keine Fahrt geplant.</p>
                    <a class="button" href="<?= url('buchen.php') ?>">Fahrzeug buchen</a>
                </div>
            <?php endif; ?>

            <p class="weiter"><a href="<?= url('meine-buchungen.php') ?>">Alle meine Buchungen</a></p>
        </section>

        <aside class="uebersicht__neben">
            <section aria-labelledby="noch-offen">
                <h2 class="abschnitt" id="noch-offen">
                    Noch nicht bestätigt
                    <?php if ($anzahlBeantragte > 0): ?>
                        <span class="abschnitt__wert"><?= e((string) $anzahlBeantragte) ?> <?= $anzahlBeantragte === 1 ? 'Antrag' : 'Anträge' ?></span>
                    <?php endif; ?>
                </h2>

                <?php foreach ($beantragte as $Buchung): ?>
                    <?php
                    $SchildFahrzeug = CFahrzeug::LoadByID($Buchung->GetFahrzeugID());
                    $marke = buchung_stand($Buchung, $ich);
                    ?>
                    <article class="schild">
                        <?php require __DIR__ . '/includes/schild-kopf.php'; ?>

                        <dl class="felder">
                            <div class="feld">
                                <dt>Zeitraum</dt>
                                <dd><?= e(zeitraum_kurz($Buchung->GetStart(), $Buchung->GetEnde())) ?></dd>
                            </div>
                            <div class="feld">
                                <dt>Zweck</dt>
                                <dd>
                                    <?= e($Zwecke->GetBezeichnung($Buchung->GetZweckID())) ?>
                                    <?php if ($Buchung->GetFahrerID() !== $ich): ?>
                                        <small>Fahrer: <?= e($Buchung->GetFahrerName()) ?></small>
                                    <?php elseif ($Buchung->GetAntragstellerID() !== $ich): ?>
                                        <small>gebucht von <?= e($Buchung->GetAntragstellerName()) ?></small>
                                    <?php endif; ?>
                                </dd>
                            </div>
                        </dl>
                    </article>
                <?php endforeach; ?>

                <?php if ($beantragte === []): ?>
                    <p class="zeile">Keine Anträge, die auf den Fuhrparkleiter warten.</p>
                <?php endif; ?>

                <?php if ($anzahlBeantragte > count($beantragte)): ?>
                    <p class="weiter">
                        <a href="<?= url('meine-buchungen.php') ?>">Alle <?= e((string) $anzahlBeantragte) ?> unter Meine Buchungen</a>
                    </p>
                <?php endif; ?>
            </section>

        </aside>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
