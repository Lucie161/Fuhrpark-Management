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
                braucht_genehmigung($Fahrzeug['art_id']) ? STATUS_OFFEN : STATUS_GENEHMIGT,
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
     * sein. Liefert die Angaben zum Fahrzeug.
     */
    private static function PruefeFahrzeug(
        int $in_FahrzeugID,
        CNutzer $in_Fahrer,
        DateTimeImmutable $in_Start,
        DateTimeImmutable $in_Ende
    ): array {
        $Fahrzeug = self::LadeFahrzeug($in_FahrzeugID);

        if ($Fahrzeug === null || $Fahrzeug['geloescht_am'] !== null) {
            throw new CFachlicherFehler('Dieses Fahrzeug gibt es nicht mehr.');
        }

        if (!$in_Fahrer->HatFuehrerschein($Fahrzeug['fuehrerschein'])) {
            throw new CFachlicherFehler('Das Fahrzeug braucht einen Führerschein, den der Fahrer nicht hat.');
        }

        if (self::FehltLuxusfreigabe($Fahrzeug, $in_Fahrer)) {
            throw new CFachlicherFehler('Das Fahrzeug ist ein Luxusauto, für das der Fahrer nicht freigegeben ist.');
        }

        if (wartung_sperrt($Fahrzeug, $in_Start, $in_Ende)) {
            throw new CFachlicherFehler('Das Fahrzeug ist im gewählten Zeitraum in Wartung.');
        }

        if (self::IstFahrzeugUeberfaellig($in_FahrzeugID)) {
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
    private static function PruefeFahrt(array $in_Fahrzeug, int $in_ZweckID, int $in_Personenanzahl): void
    {
        $Zwecke = new CListeSchluessel('zwecke');

        if ($Zwecke->GetByID($in_ZweckID) === null) {
            throw new CFachlicherFehler('Bitte wählen Sie den Zweck der Fahrt.');
        }

        if (!self::PasstZweck($in_ZweckID, $in_Fahrzeug['art_id'])) {
            throw new CFachlicherFehler('Für diesen Zweck kommen nur Autos und Transporter in Frage.');
        }

        if ($in_Personenanzahl < 1 || $in_Personenanzahl > $in_Fahrzeug['sitzplaetze']) {
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

    // --- Fachfunktionen -----------------------------------------------------

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
        $Fahrzeug = self::LadeFahrzeug($this->m_FahrzeugID);
        $Fahrer = CNutzer::LoadByID($this->m_FahrerID);

        return match (true) {
            $Fahrer->HatUeberfaelligeRueckgabe() => 'Bitte geben Sie zuerst Ihr überfälliges Fahrzeug zurück.',
            self::IstFahrzeugUeberfaellig($this->m_FahrzeugID)
                => 'Das Fahrzeug ist vom vorigen Fahrer noch nicht zurückgegeben.',
            $Fahrzeug['in_wartung'] => 'Das Fahrzeug ist noch in Wartung.',
            self::FehltLuxusfreigabe($Fahrzeug, $Fahrer) => 'Sie sind für Luxusautos nicht freigegeben.',
            default => null,
        };
    }

    /**
     * Warum der Antrag nicht genehmigt werden kann, sonst null. Andere offene
     * Anträge stehen nicht im Weg, nur genehmigte und laufende Fahrten.
     */
    public function GetGenehmigungHindernis(): ?string
    {
        $Fahrzeug = self::LadeFahrzeug($this->m_FahrzeugID);
        $Fahrer = CNutzer::LoadByID($this->m_FahrerID);

        return match (true) {
            self::FehltLuxusfreigabe($Fahrzeug, $Fahrer) => 'Der Fahrer ist für Luxusautos nicht freigegeben.',
            wartung_sperrt($Fahrzeug, $this->m_Start, $this->m_Ende) => 'Das Fahrzeug ist im Zeitraum in Wartung.',
            self::IstFahrzeugUeberfaellig($this->m_FahrzeugID) => 'Die Rückgabe des Fahrzeugs ist überfällig.',
            self::IstVergeben($this->m_FahrzeugID, $this->m_Start, $this->m_Ende, STATUS_GENEHMIGT, $this->m_ID)
                => 'Das Fahrzeug ist im Zeitraum bereits vergeben.',
            default => null,
        };
    }

    // --- Angaben zum Fahrzeug -----------------------------------------------
    // Übergang: LadeFahrzeug(), IstFahrzeugUeberfaellig() und
    // FehltLuxusfreigabe() werden durch CFahrzeug ersetzt.

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
     * Die Angaben zum Fahrzeug für die Fachregeln, mit der laufenden Wartung.
     * Gibt es das Fahrzeug nicht, null.
     */
    private static function LadeFahrzeug(int $in_FahrzeugID): ?array
    {
        $Statement = db()->prepare(
            'SELECT f.art_id, f.sitzplaetze, f.fuehrerschein, f.geloescht_am,
                    w.id IS NOT NULL AS in_wartung, w.voraussichtlich_bis AS wartung_bis
               FROM fahrzeuge f
               LEFT JOIN wartungen w ON w.fahrzeug_id = f.id AND w.freigegeben_am IS NULL
              WHERE f.id = ?'
        );
        $Statement->execute([$in_FahrzeugID]);
        $Zeile = $Statement->fetch();

        if ($Zeile === false) {
            return null;
        }

        $Zeile['art_id'] = (int) $Zeile['art_id'];
        $Zeile['sitzplaetze'] = (int) $Zeile['sitzplaetze'];
        $Zeile['in_wartung'] = (bool) $Zeile['in_wartung'];
        $Zeile['wartung_bis'] = self::DatumOderNull($Zeile['wartung_bis']);

        return $Zeile;
    }

    /**
     * Ist eine Fahrt mit dem Fahrzeug überfällig?
     */
    private static function IstFahrzeugUeberfaellig(int $in_FahrzeugID): bool
    {
        $Statement = db()->prepare(
            'SELECT COUNT(*) FROM buchungen WHERE fahrzeug_id = ? AND status_id = ? AND ende < CURDATE()'
        );
        $Statement->execute([$in_FahrzeugID, STATUS_UNTERWEGS]);

        return (int) $Statement->fetchColumn() > 0;
    }

    /**
     * Luxusautos dürfen nur Nutzer mit darf_luxus fahren.
     */
    private static function FehltLuxusfreigabe(array $in_Fahrzeug, CNutzer $in_Fahrer): bool
    {
        return $in_Fahrzeug['art_id'] === ART_LUXUS && !$in_Fahrer->DarfLuxus();
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
