<?php
/**
 * Eine Buchung, vom Antrag über die Fahrt bis zur Rückgabe.
 */

declare(strict_types=1);

class CBuchung
{
    // Abfragen für alle Stellen, die Buchungen laden. Der Fahrzeugname steht
    // immer dabei, die Personennamen nur für den Fuhrparkleiter und bei eigenen
    // Buchungen.
    public const SQL_OHNE_NAMEN =
        'SELECT b.*, f.hersteller, f.modell, f.kennzeichen
           FROM buchungen b
           JOIN fahrzeuge f ON f.id = b.fahrzeug_id';

    public const SQL_MIT_NAMEN =
        'SELECT b.*, f.hersteller, f.modell, f.kennzeichen,
                fa.vorname AS fahrer_vorname,
                CONCAT(fa.vorname, " ", fa.nachname) AS fahrer_name,
                CONCAT(an.vorname, " ", an.nachname) AS antragsteller_name
           FROM buchungen b
           JOIN fahrzeuge f ON f.id = b.fahrzeug_id
           JOIN nutzer fa ON fa.id = b.fahrer_id
           JOIN nutzer an ON an.id = b.antragsteller_id';

    // Montage und Materialtransport gehen nur mit Auto oder Transporter.
    private const ZWECKE_NUR_AUTO = [ZWECK_MONTAGE, ZWECK_MATERIALTRANSPORT];
    private const ARTEN_FUER_MATERIAL = [ART_AUTO, ART_TRANSPORTER];

    private const MAX_KOMMENTAR = 500;

    // „Neuer Schaden?“ bei der Rückgabe: 0 heißt nein, sonst die schwere_id.
    public const KEIN_SCHADEN = 0;

    private int $m_ID;
    private int $m_FahrzeugID;
    private int $m_AntragstellerID;
    private int $m_FahrerID;
    private DateTimeImmutable $m_Start;
    private DateTimeImmutable $m_Ende;
    private int $m_ZweckID;
    private int $m_Personenanzahl;
    private int $m_StatusID;
    private DateTimeImmutable $m_BeantragtAm;
    private ?int $m_EntscheidungVon;
    private ?DateTimeImmutable $m_EntscheidungAm;
    private string $m_EntscheidungKommentar;
    private ?DateTimeImmutable $m_BegonnenAm;
    private ?int $m_KmStart;
    private ?DateTimeImmutable $m_ZurueckgegebenAm;
    private ?int $m_KmEnde;
    private string $m_Bemerkung;

    private string $m_FahrzeugName;
    private ?string $m_FahrerVorname;
    private ?string $m_FahrerName;
    private ?string $m_AntragstellerName;

    /**
     * Legt das Objekt aus einer Zeile von SQL_OHNE_NAMEN oder SQL_MIT_NAMEN an.
     */
    public function __construct(array $in_Zeile)
    {
        $this->m_ID               = (int) $in_Zeile['id'];
        $this->m_FahrzeugID       = (int) $in_Zeile['fahrzeug_id'];
        $this->m_AntragstellerID  = (int) $in_Zeile['antragsteller_id'];
        $this->m_FahrerID         = (int) $in_Zeile['fahrer_id'];
        $this->m_Start            = new DateTimeImmutable($in_Zeile['start']);
        $this->m_Ende             = new DateTimeImmutable($in_Zeile['ende']);
        $this->m_ZweckID          = (int) $in_Zeile['zweck_id'];
        $this->m_Personenanzahl   = (int) $in_Zeile['personenanzahl'];
        $this->m_StatusID         = (int) $in_Zeile['status_id'];
        $this->m_BeantragtAm      = new DateTimeImmutable($in_Zeile['beantragt_am']);
        $this->m_EntscheidungVon  = self::ZahlOderNull($in_Zeile['entscheidung_von']);
        $this->m_EntscheidungAm   = self::DatumOderNull($in_Zeile['entscheidung_am']);
        $this->m_EntscheidungKommentar = $in_Zeile['entscheidung_kommentar'] ?? '';
        $this->m_BegonnenAm       = self::DatumOderNull($in_Zeile['begonnen_am']);
        $this->m_KmStart          = self::ZahlOderNull($in_Zeile['km_start']);
        $this->m_ZurueckgegebenAm = self::DatumOderNull($in_Zeile['zurueckgegeben_am']);
        $this->m_KmEnde           = self::ZahlOderNull($in_Zeile['km_ende']);
        $this->m_Bemerkung        = $in_Zeile['bemerkung'] ?? '';

        $this->m_FahrzeugName = $in_Zeile['hersteller'] . ' ' . $in_Zeile['modell']
            . ' (' . $in_Zeile['kennzeichen'] . ')';
        $this->m_FahrerVorname     = $in_Zeile['fahrer_vorname'] ?? null;
        $this->m_FahrerName        = $in_Zeile['fahrer_name'] ?? null;
        $this->m_AntragstellerName = $in_Zeile['antragsteller_name'] ?? null;
    }

    private static function ZahlOderNull(mixed $in_Wert): ?int
    {
        return $in_Wert !== null ? (int) $in_Wert : null;
    }

    private static function DatumOderNull(?string $in_Wert): ?DateTimeImmutable
    {
        return $in_Wert !== null ? new DateTimeImmutable($in_Wert) : null;
    }

    /**
     * Lädt eine Buchung ohne Personennamen. Gibt es sie nicht, null.
     */
    public static function LoadByID(int $in_ID): ?CBuchung
    {
        $Statement = db()->prepare(self::SQL_OHNE_NAMEN . ' WHERE b.id = ?');
        $Statement->execute([$in_ID]);
        $Zeile = $Statement->fetch();

        return $Zeile !== false ? new CBuchung($Zeile) : null;
    }

    /**
     * Legt eine Buchung an und liefert sie zurück.
     *
     * Prüft alle Regeln noch einmal, denn die Anzeige in buchen.php ist nur
     * Komfort. Die Transaktion sperrt das Fahrzeug, damit nicht zwei Personen
     * gleichzeitig denselben Zeitraum buchen. Nach der Ampel ist die Buchung
     * beantragt oder sofort genehmigt. Steht etwas im Weg, wirft sie
     * CFachlicherFehler.
     */
    public static function Create(
        int $in_FahrzeugID,
        int $in_AntragstellerID,
        int $in_FahrerID,
        DateTimeImmutable $in_Start,
        DateTimeImmutable $in_Ende,
        int $in_ZweckID,
        int $in_Personenanzahl
    ): CBuchung {
        db()->beginTransaction();

        try {
            self::SperreFahrzeug($in_FahrzeugID);

            self::PruefeZeitraum($in_Start, $in_Ende);
            $Fahrer = self::PruefePersonen($in_AntragstellerID, $in_FahrerID);
            $Fahrzeug = self::PruefeFahrzeug($in_FahrzeugID, $Fahrer, $in_Start, $in_Ende);
            self::PruefeFahrt($Fahrzeug, $in_ZweckID, $in_Personenanzahl);

            $Statement = db()->prepare(
                'INSERT INTO buchungen (fahrzeug_id, antragsteller_id, fahrer_id, start, ende,
                                        zweck_id, personenanzahl, status_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $Statement->execute([
                $in_FahrzeugID,
                $in_AntragstellerID,
                $in_FahrerID,
                $in_Start->format('Y-m-d'),
                $in_Ende->format('Y-m-d'),
                $in_ZweckID,
                $in_Personenanzahl,
                $Fahrzeug->BrauchtGenehmigung() ? STATUS_OFFEN : STATUS_GENEHMIGT,
            ]);

            $ID = (int) db()->lastInsertId();
            db()->commit();
        } catch (Throwable $Fehler) {
            db()->rollBack();
            throw $Fehler;
        }

        return self::LoadByID($ID);
    }

    /**
     * Der Zeitraum darf nicht in der Vergangenheit liegen und nicht rückwärts
     * laufen.
     */
    private static function PruefeZeitraum(DateTimeImmutable $in_Start, DateTimeImmutable $in_Ende): void
    {
        if ($in_Start < new DateTimeImmutable('today')) {
            throw new CFachlicherFehler('Eine Buchung kann nicht in der Vergangenheit beginnen.');
        }

        if ($in_Ende < $in_Start) {
            throw new CFachlicherFehler('„Bis“ darf nicht vor „Von“ liegen.');
        }
    }

    /**
     * Fahrer muss ein aktiver Mitarbeiter sein, und weder er noch der
     * Antragsteller dürfen eine überfällige Rückgabe haben. Liefert den Fahrer.
     */
    private static function PruefePersonen(int $in_AntragstellerID, int $in_FahrerID): CNutzer
    {
        $Antragsteller = CNutzer::LoadByID($in_AntragstellerID);
        $Fahrer = CNutzer::LoadByID($in_FahrerID);

        if ($Fahrer === null || $Fahrer->IstAusgeschieden() || $Fahrer->GetRolleID() !== ROLLE_MITARBEITER) {
            throw new CFachlicherFehler('Diesen Fahrer gibt es nicht.');
        }

        if ($Antragsteller === null || $Antragsteller->GetSperreBuchen() !== null) {
            throw new CFachlicherFehler('Sie können derzeit nicht buchen. Ihre Rückgabe ist überfällig.');
        }

        if ($Fahrer->GetSperreBuchen() !== null) {
            throw new CFachlicherFehler(
                'Für ' . $Fahrer->GetName() . ' kann derzeit nicht gebucht werden: ' . $Fahrer->GetSperreBuchen()
            );
        }

        return $Fahrer;
    }

    /**
     * Das Fahrzeug muss im Bestand sein, zum Fahrer passen und im Zeitraum frei
     * sein. Liefert das Fahrzeug.
     */
    private static function PruefeFahrzeug(
        int $in_FahrzeugID,
        CNutzer $in_Fahrer,
        DateTimeImmutable $in_Start,
        DateTimeImmutable $in_Ende
    ): CFahrzeug {
        $Fahrzeug = CFahrzeug::LoadByID($in_FahrzeugID);

        if ($Fahrzeug === null || $Fahrzeug->IstAusgemustert()) {
            throw new CFachlicherFehler('Dieses Fahrzeug gibt es nicht mehr.');
        }

        if (!$in_Fahrer->HatFuehrerschein($Fahrzeug->GetFuehrerschein())) {
            throw new CFachlicherFehler('Das Fahrzeug braucht einen Führerschein, den der Fahrer nicht hat.');
        }

        if ($Fahrzeug->FehltFreigabe($in_Fahrer)) {
            throw new CFachlicherFehler('Das Fahrzeug ist ein Luxusauto, für das der Fahrer nicht freigegeben ist.');
        }

        if ($Fahrzeug->SperrtWartung($in_Start, $in_Ende)) {
            throw new CFachlicherFehler('Das Fahrzeug ist im gewählten Zeitraum in Wartung.');
        }

        if ($Fahrzeug->IstUeberfaellig()) {
            throw new CFachlicherFehler('Das Fahrzeug ist noch nicht zurückgegeben.');
        }

        // Auch ein offener Antrag belegt das Fahrzeug.
        if (self::IstVergeben($in_FahrzeugID, $in_Start, $in_Ende, STATUS_OFFEN)) {
            throw new CFachlicherFehler('Das Fahrzeug ist im gewählten Zeitraum bereits vergeben.');
        }

        return $Fahrzeug;
    }

    /**
     * Zweck und Personenzahl müssen zum Fahrzeug passen.
     */
    private static function PruefeFahrt(CFahrzeug $in_Fahrzeug, int $in_ZweckID, int $in_Personenanzahl): void
    {
        $Zwecke = new CListeSchluessel('zwecke');

        if ($Zwecke->GetByID($in_ZweckID) === null) {
            throw new CFachlicherFehler('Bitte wählen Sie den Zweck der Fahrt.');
        }

        if (!self::PasstZweck($in_ZweckID, $in_Fahrzeug->GetArtID())) {
            throw new CFachlicherFehler('Für diesen Zweck kommen nur Autos und Transporter in Frage.');
        }

        if ($in_Personenanzahl < 1 || $in_Personenanzahl > $in_Fahrzeug->GetSitzplaetze()) {
            throw new CFachlicherFehler('Das Fahrzeug hat nicht genug Sitzplätze.');
        }
    }

    /**
     * Passt der Zweck zur Fahrzeugart? 0 heißt: noch kein Zweck gewählt.
     */
    public static function PasstZweck(int $in_ZweckID, int $in_ArtID): bool
    {
        return !in_array($in_ZweckID, self::ZWECKE_NUR_AUTO, true)
            || in_array($in_ArtID, self::ARTEN_FUER_MATERIAL, true);
    }

    /**
     * Genehmigt den Antrag.
     *
     * Prüft in einer Transaktion, ob das Fahrzeug im Zeitraum noch frei ist,
     * und speichert die Entscheidung. Steht etwas im Weg, wirft sie
     * CFachlicherFehler.
     */
    public function Genehmigen(int $in_NutzerID): void
    {
        self::PruefeFuhrparkleiter($in_NutzerID);

        db()->beginTransaction();

        try {
            self::SperreFahrzeug($this->m_FahrzeugID);

            $Hindernis = $this->GetGenehmigungHindernis();

            if ($Hindernis !== null) {
                throw new CFachlicherFehler('Der Antrag kann nicht genehmigt werden. ' . $Hindernis);
            }

            $this->SpeichereEntscheidung(STATUS_GENEHMIGT, $in_NutzerID, null);
            db()->commit();
        } catch (Throwable $Fehler) {
            db()->rollBack();
            throw $Fehler;
        }
    }

    /**
     * Lehnt den Antrag ab. Die Begründung ist Pflicht, der Antragsteller liest
     * sie unter „Frühere Buchungen“.
     */
    public function Ablehnen(int $in_NutzerID, string $in_Kommentar): void
    {
        self::PruefeFuhrparkleiter($in_NutzerID);

        $Kommentar = trim($in_Kommentar);

        if ($Kommentar === '') {
            throw new CFachlicherFehler('Bitte begründen Sie die Ablehnung. Der Antragsteller sieht die Begründung.');
        }

        if (mb_strlen($Kommentar) > self::MAX_KOMMENTAR) {
            throw new CFachlicherFehler('Die Begründung darf höchstens ' . self::MAX_KOMMENTAR . ' Zeichen lang sein.');
        }

        $this->SpeichereEntscheidung(STATUS_ABGELEHNT, $in_NutzerID, $Kommentar);
    }

    /**
     * Anträge entscheidet nur der Fuhrparkleiter.
     */
    private static function PruefeFuhrparkleiter(int $in_NutzerID): void
    {
        $Nutzer = CNutzer::LoadByID($in_NutzerID);

        if ($Nutzer === null || $Nutzer->GetRolleID() !== ROLLE_FUHRPARKLEITER) {
            throw new CFachlicherFehler('Anträge entscheidet nur der Fuhrparkleiter.');
        }
    }

    /**
     * Speichert die Entscheidung, aber nur, solange der Antrag noch offen ist.
     */
    private function SpeichereEntscheidung(int $in_StatusID, int $in_NutzerID, ?string $in_Kommentar): void
    {
        $Statement = db()->prepare(
            'UPDATE buchungen
                SET status_id = ?, entscheidung_von = ?, entscheidung_am = NOW(), entscheidung_kommentar = ?
              WHERE id = ? AND status_id = ?'
        );
        $Statement->execute([$in_StatusID, $in_NutzerID, $in_Kommentar, $this->m_ID, STATUS_OFFEN]);

        if ($Statement->rowCount() === 0) {
            throw new CFachlicherFehler('Diesen Antrag gibt es nicht mehr. Vielleicht wurde er schon entschieden.');
        }

        $this->m_StatusID = $in_StatusID;
        $this->m_EntscheidungVon = $in_NutzerID;
        $this->m_EntscheidungAm = new DateTimeImmutable();
        $this->m_EntscheidungKommentar = $in_Kommentar ?? '';
    }

    // --- Ablauf der Fahrt ---------------------------------------------------

    /**
     * Storniert die Buchung. Das dürfen Antragsteller und Fahrer, solange die
     * Fahrt nicht begonnen hat. Steht etwas im Weg, wirft sie CFachlicherFehler.
     */
    public function Stornieren(int $in_NutzerID): void
    {
        if (!$this->KannStornieren($in_NutzerID)) {
            throw new CFachlicherFehler('Diese Buchung können Sie nicht stornieren.');
        }

        // Nur ändern, solange sie noch beantragt oder genehmigt ist; vielleicht
        // hat die Fahrt inzwischen begonnen.
        $Statement = db()->prepare('UPDATE buchungen SET status_id = ? WHERE id = ? AND status_id IN (?, ?)');
        $Statement->execute([STATUS_STORNIERT, $this->m_ID, STATUS_OFFEN, STATUS_GENEHMIGT]);

        if ($Statement->rowCount() === 0) {
            throw new CFachlicherFehler('Die Buchung lässt sich nicht mehr stornieren. Vielleicht hat die Fahrt schon begonnen.');
        }

        $this->m_StatusID = STATUS_STORNIERT;
    }

    /**
     * Beginnt die Fahrt: Der Fahrer übernimmt das Fahrzeug.
     *
     * Prüft in einer Transaktion noch einmal GetSperreFahrtbeginn(), übernimmt
     * den km-Stand des Fahrzeugs und speichert einen Schaden, den der Fahrer
     * bei der Übernahme notiert hat (optional). Steht etwas im Weg, wirft sie
     * CFachlicherFehler.
     */
    public function FahrtBeginnen(int $in_NutzerID, string $in_Schaden): void
    {
        if (!$this->KannBeginnen($in_NutzerID)) {
            throw new CFachlicherFehler('Diese Fahrt können Sie heute nicht beginnen.');
        }

        $Schaden = CSchaden::PruefeBeschreibung($in_Schaden);

        db()->beginTransaction();

        try {
            // Fahrzeug sperren, dann mit dem aktuellen Stand prüfen
            self::SperreFahrzeug($this->m_FahrzeugID);

            $Sperre = $this->GetSperreFahrtbeginn();

            if ($Sperre !== null) {
                throw new CFachlicherFehler('Die Fahrt kann nicht beginnen. ' . $Sperre);
            }

            // Fahrt beginnen, aber nur, solange die Buchung noch genehmigt ist
            $KmStart = CFahrzeug::LoadByID($this->m_FahrzeugID)->GetKmStand();

            $Statement = db()->prepare(
                'UPDATE buchungen SET status_id = ?, begonnen_am = NOW(), km_start = ?
                  WHERE id = ? AND status_id = ?'
            );
            $Statement->execute([STATUS_UNTERWEGS, $KmStart, $this->m_ID, STATUS_GENEHMIGT]);

            if ($Statement->rowCount() === 0) {
                throw new CFachlicherFehler('Die Fahrt lässt sich nicht mehr beginnen. Vielleicht wurde die Buchung storniert.');
            }

            // Notierter Schaden: schon vorhanden, also kein Schaden dieses Fahrers
            if ($Schaden !== '') {
                CSchaden::Create($this->m_ID, ANLASS_UEBERNAHME, SCHWERE_KLEIN, $Schaden);
            }

            db()->commit();
        } catch (Throwable $Fehler) {
            db()->rollBack();
            throw $Fehler;
        }

        $this->m_StatusID = STATUS_UNTERWEGS;
        $this->m_BegonnenAm = new DateTimeImmutable();
        $this->m_KmStart = $KmStart;
    }

    /**
     * Gibt das Fahrzeug zurück und schließt die Fahrt ab.
     *
     * Speichert in einer Transaktion km-Stand (auch am Fahrzeug), Bemerkung
     * und einen neuen Schaden mit seinen Fotos. Sperrt ein Schaden das
     * Fahrzeug, kommt es in die Wartung, siehe SetzeInWartung(). $in_KmEnde
     * entfällt bei Fahrzeugen ohne km-Stand, $in_SchwereID ist KEIN_SCHADEN
     * oder eine schwere_id. $in_Fotos sind die geprüften Fotos aus
     * CSchadensfoto::Pruefe(); ohne Schaden werden sie ignoriert. Steht etwas
     * im Weg, wirft sie CFachlicherFehler.
     */
    public function Zurueckgeben(
        int $in_NutzerID,
        ?int $in_KmEnde,
        int $in_SchwereID,
        string $in_Beschreibung,
        string $in_Bemerkung,
        array $in_Fotos = []
    ): void {
        // Eingaben prüfen; das geht ohne Datenbank, deshalb vor der Transaktion
        $this->PruefeRueckgabe($in_NutzerID, $in_KmEnde);
        $Beschreibung = self::PruefeSchaden($in_SchwereID, $in_Beschreibung);
        $Bemerkung = trim($in_Bemerkung);

        if (mb_strlen($Bemerkung) > self::MAX_KOMMENTAR) {
            throw new CFachlicherFehler('Die Bemerkung darf höchstens ' . self::MAX_KOMMENTAR . ' Zeichen lang sein.');
        }

        // Ohne km-Stand (Fahrrad) wird auch keiner gespeichert
        $KmEnde = $this->m_KmStart !== null ? $in_KmEnde : null;

        db()->beginTransaction();

        try {
            self::SperreFahrzeug($this->m_FahrzeugID);

            // Fahrt abschließen, aber nur, solange sie noch läuft
            $Statement = db()->prepare(
                'UPDATE buchungen SET status_id = ?, zurueckgegeben_am = NOW(), km_ende = ?, bemerkung = ?
                  WHERE id = ? AND status_id = ?'
            );
            $Statement->execute([
                STATUS_ABGESCHLOSSEN,
                $KmEnde,
                $Bemerkung !== '' ? $Bemerkung : null,
                $this->m_ID,
                STATUS_UNTERWEGS,
            ]);

            if ($Statement->rowCount() === 0) {
                throw new CFachlicherFehler('Diese Fahrt ist schon abgeschlossen.');
            }

            // Neuen km-Stand am Fahrzeug merken, die nächste Fahrt beginnt damit
            if ($KmEnde !== null) {
                $Statement = db()->prepare('UPDATE fahrzeuge SET kmstand = ? WHERE id = ?');
                $Statement->execute([$KmEnde, $this->m_FahrzeugID]);
            }

            // Neuen Schaden speichern und das Fahrzeug bei Bedarf sperren
            $Schaden = null;

            if ($in_SchwereID !== self::KEIN_SCHADEN) {
                $Schaden = CSchaden::Create($this->m_ID, ANLASS_RUECKGABE, $in_SchwereID, $Beschreibung);
            }

            $this->SetzeInWartung($Schaden);

            // Fotos zuletzt ablegen, damit nach einem Fehler davor keine
            // Dateien ohne Eintrag zurückbleiben
            if ($Schaden !== null) {
                foreach ($in_Fotos as $Foto) {
                    CSchadensfoto::Create($Schaden->GetID(), $Foto);
                }
            }

            db()->commit();
        } catch (Throwable $Fehler) {
            db()->rollBack();
            throw $Fehler;
        }

        $this->m_StatusID = STATUS_ABGESCHLOSSEN;
        $this->m_ZurueckgegebenAm = new DateTimeImmutable();
        $this->m_KmEnde = $KmEnde;
        $this->m_Bemerkung = $Bemerkung;
    }

    /**
     * Zurückgeben darf nur der Fahrer eine laufende Fahrt. Der km-Stand muss
     * angegeben sein und darf nicht kleiner sein als bei Fahrtbeginn, außer das
     * Fahrzeug hat keinen.
     */
    private function PruefeRueckgabe(int $in_NutzerID, ?int $in_KmEnde): void
    {
        if ($this->m_StatusID !== STATUS_UNTERWEGS || $in_NutzerID !== $this->m_FahrerID) {
            throw new CFachlicherFehler('Diese Fahrt können Sie nicht zurückgeben.');
        }

        if ($this->m_KmStart === null) {
            return;
        }

        if ($in_KmEnde === null) {
            throw new CFachlicherFehler('Bitte geben Sie den Kilometerstand als ganze Zahl ein.');
        }

        if ($in_KmEnde < $this->m_KmStart) {
            throw new CFachlicherFehler(
                'Der Kilometerstand darf nicht kleiner sein als bei Fahrtbeginn ('
                    . number_format($this->m_KmStart, 0, ',', '.') . ' km).'
            );
        }
    }

    /**
     * Prüft die Angabe „Neuer Schaden?“. Bei einem Schaden ist die
     * Beschreibung Pflicht, ohne Schaden wird sie ignoriert. Liefert die
     * bereinigte Beschreibung.
     */
    private static function PruefeSchaden(int $in_SchwereID, string $in_Beschreibung): string
    {
        if (!in_array($in_SchwereID, [self::KEIN_SCHADEN, SCHWERE_KLEIN, SCHWERE_WARTUNG], true)) {
            throw new CFachlicherFehler('Bitte geben Sie an, ob ein neuer Schaden entstanden ist.');
        }

        if ($in_SchwereID === self::KEIN_SCHADEN) {
            return '';
        }

        $Beschreibung = CSchaden::PruefeBeschreibung($in_Beschreibung);

        if ($Beschreibung === '') {
            throw new CFachlicherFehler('Bitte beschreiben Sie den Schaden.');
        }

        return $Beschreibung;
    }

    /**
     * Setzt das Fahrzeug nach der Rückgabe in die Wartung, wenn ein Schaden es
     * sperrt: der neue Schaden (CSchaden::Sperrt()) oder, bei einem Luxusauto,
     * der bei der Übernahme notierte. Das Ende bleibt offen, bis der
     * Fuhrparkleiter es festlegt. Läuft schon eine Wartung, bleibt es bei ihr.
     * Läuft in der Transaktion von Zurueckgeben().
     */
    private function SetzeInWartung(?CSchaden $in_Schaden): void
    {
        $Fahrzeug = CFahrzeug::LoadByID($this->m_FahrzeugID);

        // Welcher Schaden sperrt? Zuerst der neue, dann der bei der Übernahme
        $Sperrend = null;

        if ($in_Schaden !== null && $in_Schaden->Sperrt()) {
            $Sperrend = $in_Schaden;
        } elseif ($Fahrzeug->IstLuxus()) {
            $Sperrend = CSchaden::LoadOffenBeiUebernahme($this->m_ID);
        }

        if ($Sperrend === null || $Fahrzeug->IstInWartung()) {
            return;
        }

        // Wartung ab heute, ohne Ende und ohne Fuhrparkleiter
        $Statement = db()->prepare(
            'INSERT INTO wartungen (fahrzeug_id, schaden_id, grund, begonnen_am) VALUES (?, ?, ?, CURDATE())'
        );
        $Statement->execute([$this->m_FahrzeugID, $Sperrend->GetID(), $Sperrend->GetBeschreibung()]);
    }

    // --- Fachfunktionen -----------------------------------------------------

    /**
     * Hat der Fahrer bei der Übernahme einen Schaden notiert, der noch offen
     * ist? Bei einem Luxusauto geht es dann nach der Rückgabe in die Wartung.
     */
    public function HatSchadenBeiUebernahme(): bool
    {
        return CSchaden::LoadOffenBeiUebernahme($this->m_ID) !== null;
    }

    /**
     * Belegt die Buchung ihr Fahrzeug? Ja, solange sie beantragt, genehmigt
     * oder unterwegs ist.
     */
    public function BelegtFahrzeug(): bool
    {
        return $this->m_StatusID <= STATUS_UNTERWEGS;
    }

    /**
     * Aktiv: genehmigt, und heute liegt im Zeitraum.
     */
    public function IstAktiv(): bool
    {
        $Heute = new DateTimeImmutable('today');

        return $this->m_StatusID === STATUS_GENEHMIGT && $this->m_Start <= $Heute && $Heute <= $this->m_Ende;
    }

    /**
     * Überfällig: unterwegs, und das Ende ist vorbei.
     */
    public function IstUeberfaellig(): bool
    {
        return $this->m_StatusID === STATUS_UNTERWEGS && $this->m_Ende < new DateTimeImmutable('today');
    }

    /**
     * Stornieren dürfen Antragsteller und Fahrer, solange die Fahrt nicht
     * begonnen hat.
     */
    public function KannStornieren(int $in_NutzerID): bool
    {
        return in_array($this->m_StatusID, [STATUS_OFFEN, STATUS_GENEHMIGT], true)
            && in_array($in_NutzerID, [$this->m_AntragstellerID, $this->m_FahrerID], true);
    }

    /**
     * Beginnen darf nur der Fahrer an der aktiven Buchung. Ob die Fahrt wirklich
     * beginnen kann, sagt GetSperreFahrtbeginn().
     */
    public function KannBeginnen(int $in_NutzerID): bool
    {
        return $this->IstAktiv() && $in_NutzerID === $this->m_FahrerID;
    }

    /**
     * Warum die Fahrt nicht beginnen kann, sonst null.
     */
    public function GetSperreFahrtbeginn(): ?string
    {
        $Fahrzeug = CFahrzeug::LoadByID($this->m_FahrzeugID);
        $Fahrer = CNutzer::LoadByID($this->m_FahrerID);

        return match (true) {
            $Fahrer->HatUeberfaelligeRueckgabe() => 'Bitte geben Sie zuerst Ihr überfälliges Fahrzeug zurück.',
            $Fahrzeug->IstUeberfaellig()         => 'Das Fahrzeug ist vom vorigen Fahrer noch nicht zurückgegeben.',
            $Fahrzeug->IstInWartung()            => 'Das Fahrzeug ist noch in Wartung.',
            $Fahrzeug->FehltFreigabe($Fahrer)    => 'Sie sind für Luxusautos nicht freigegeben.',
            default                              => null,
        };
    }

    /**
     * Warum der Antrag nicht genehmigt werden kann, sonst null. Andere offene
     * Anträge stehen nicht im Weg, nur genehmigte und laufende Fahrten.
     */
    public function GetGenehmigungHindernis(): ?string
    {
        $Fahrzeug = CFahrzeug::LoadByID($this->m_FahrzeugID);
        $Fahrer = CNutzer::LoadByID($this->m_FahrerID);

        return match (true) {
            $Fahrzeug->FehltFreigabe($Fahrer) => 'Der Fahrer ist für Luxusautos nicht freigegeben.',
            $Fahrzeug->SperrtWartung($this->m_Start, $this->m_Ende) => 'Das Fahrzeug ist im Zeitraum in Wartung.',
            $Fahrzeug->IstUeberfaellig() => 'Die Rückgabe des Fahrzeugs ist überfällig.',
            self::IstVergeben($this->m_FahrzeugID, $this->m_Start, $this->m_Ende, STATUS_GENEHMIGT, $this->m_ID)
                => 'Das Fahrzeug ist im Zeitraum bereits vergeben.',
            default => null,
        };
    }

    // --- Belegung des Fahrzeugs ---------------------------------------------

    /**
     * Sperrt die Zeile des Fahrzeugs bis zum Ende der Transaktion. Wer dasselbe
     * Fahrzeug bucht oder genehmigt, wartet so, bis die erste Buchung gespeichert
     * ist, und sieht sie dann bei seiner Prüfung.
     */
    private static function SperreFahrzeug(int $in_FahrzeugID): void
    {
        $Statement = db()->prepare('SELECT id FROM fahrzeuge WHERE id = ? FOR UPDATE');
        $Statement->execute([$in_FahrzeugID]);
    }

    /**
     * Überschneidet eine andere laufende Buchung den Zeitraum? $in_AbStatus
     * legt fest, ab welchem Status sie zählt: STATUS_OFFEN mit Anträgen,
     * STATUS_GENEHMIGT ohne.
     */
    private static function IstVergeben(
        int $in_FahrzeugID,
        DateTimeImmutable $in_Start,
        DateTimeImmutable $in_Ende,
        int $in_AbStatus,
        int $in_OhneBuchungID = 0
    ): bool {
        $Statement = db()->prepare(
            'SELECT COUNT(*) FROM buchungen
              WHERE fahrzeug_id = ? AND id <> ?
                AND status_id BETWEEN ? AND ?
                AND start <= ? AND ende >= ?'
        );
        $Statement->execute([
            $in_FahrzeugID,
            $in_OhneBuchungID,
            $in_AbStatus,
            STATUS_UNTERWEGS,
            $in_Ende->format('Y-m-d'),
            $in_Start->format('Y-m-d'),
        ]);

        return (int) $Statement->fetchColumn() > 0;
    }

    // --- Getter -------------------------------------------------------------

    public function GetID(): int
    {
        return $this->m_ID;
    }

    public function GetFahrzeugID(): int
    {
        return $this->m_FahrzeugID;
    }

    public function GetAntragstellerID(): int
    {
        return $this->m_AntragstellerID;
    }

    public function GetFahrerID(): int
    {
        return $this->m_FahrerID;
    }

    public function GetStart(): DateTimeImmutable
    {
        return $this->m_Start;
    }

    public function GetEnde(): DateTimeImmutable
    {
        return $this->m_Ende;
    }

    public function GetZweckID(): int
    {
        return $this->m_ZweckID;
    }

    public function GetPersonenanzahl(): int
    {
        return $this->m_Personenanzahl;
    }

    public function GetStatusID(): int
    {
        return $this->m_StatusID;
    }

    public function GetBeantragtAm(): DateTimeImmutable
    {
        return $this->m_BeantragtAm;
    }

    public function GetEntscheidungVon(): ?int
    {
        return $this->m_EntscheidungVon;
    }

    public function GetEntscheidungAm(): ?DateTimeImmutable
    {
        return $this->m_EntscheidungAm;
    }

    public function GetEntscheidungKommentar(): string
    {
        return $this->m_EntscheidungKommentar;
    }

    public function GetBegonnenAm(): ?DateTimeImmutable
    {
        return $this->m_BegonnenAm;
    }

    public function GetKmStart(): ?int
    {
        return $this->m_KmStart;
    }

    public function GetZurueckgegebenAm(): ?DateTimeImmutable
    {
        return $this->m_ZurueckgegebenAm;
    }

    public function GetKmEnde(): ?int
    {
        return $this->m_KmEnde;
    }

    public function GetBemerkung(): string
    {
        return $this->m_Bemerkung;
    }

    /**
     * „Hersteller Modell (Kennzeichen)“.
     */
    public function GetFahrzeugName(): string
    {
        return $this->m_FahrzeugName;
    }

    /**
     * Vorname des Fahrers, null wenn ohne Namen geladen.
     */
    public function GetFahrerVorname(): ?string
    {
        return $this->m_FahrerVorname;
    }

    /**
     * Vor- und Nachname des Fahrers, null wenn ohne Namen geladen.
     */
    public function GetFahrerName(): ?string
    {
        return $this->m_FahrerName;
    }

    /**
     * Vor- und Nachname des Antragstellers, null wenn ohne Namen geladen.
     */
    public function GetAntragstellerName(): ?string
    {
        return $this->m_AntragstellerName;
    }
}
