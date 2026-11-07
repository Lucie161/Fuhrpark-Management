<?php
/**
 * Ein Schaden, gemeldet bei der Übernahme oder der Rückgabe einer Buchung.
 */

declare(strict_types=1);

class CSchaden
{
    // Abfragen für alle Stellen, die Schäden laden. Fahrzeug und Fahrer kommen
    // über die Buchung; dazu die laufende Wartung, die der Schaden ausgelöst
    // hat. Den Namen des Fahrers sieht nur der Fuhrparkleiter.
    public const SQL_OHNE_NAMEN =
        'SELECT s.*, b.fahrzeug_id, b.fahrer_id, f.art_id, w.id AS wartung_id
           FROM schaeden s
           JOIN buchungen b ON b.id = s.buchung_id
           JOIN fahrzeuge f ON f.id = b.fahrzeug_id
           LEFT JOIN wartungen w ON w.schaden_id = s.id AND w.freigegeben_am IS NULL';

    public const SQL_MIT_NAMEN =
        'SELECT s.*, b.fahrzeug_id, b.fahrer_id, f.art_id, w.id AS wartung_id,
                CONCAT(n.vorname, " ", n.nachname) AS fahrer_name
           FROM schaeden s
           JOIN buchungen b ON b.id = s.buchung_id
           JOIN fahrzeuge f ON f.id = b.fahrzeug_id
           JOIN nutzer n ON n.id = b.fahrer_id
           LEFT JOIN wartungen w ON w.schaden_id = s.id AND w.freigegeben_am IS NULL';

    // Eine Beschreibung wird bei einer Sperre zum Grund der Wartung
    // (wartungen.grund), deshalb dieselbe Grenze.
    public const MAX_BESCHREIBUNG = 200;

    private int $m_ID;
    private int $m_BuchungID;
    private int $m_AnlassID;
    private int $m_SchwereID;
    private string $m_Beschreibung;
    private DateTimeImmutable $m_GemeldetAm;
    private ?DateTimeImmutable $m_BehobenAm;
    private ?int $m_BehobenVon;

    // Aus der Buchung, dem Fahrzeug und der laufenden Wartung
    private int $m_FahrzeugID;
    private int $m_FahrerID;
    private int $m_ArtID;
    private bool $m_HaeltInWartung;
    private ?string $m_FahrerName;

    // Werden erst bei Bedarf geladen, dann gemerkt.
    private array $m_Fotos = [];
    private bool $m_FotosGeladen = false;

    /**
     * Legt das Objekt aus einer Zeile von SQL_OHNE_NAMEN oder SQL_MIT_NAMEN an.
     */
    public function __construct(array $in_Zeile)
    {
        $this->m_ID           = (int) $in_Zeile['id'];
        $this->m_BuchungID    = (int) $in_Zeile['buchung_id'];
        $this->m_AnlassID     = (int) $in_Zeile['anlass_id'];
        $this->m_SchwereID    = (int) $in_Zeile['schwere_id'];
        $this->m_Beschreibung = $in_Zeile['beschreibung'];
        $this->m_GemeldetAm   = new DateTimeImmutable($in_Zeile['gemeldet_am']);
        $this->m_BehobenAm    = $in_Zeile['behoben_am'] !== null ? new DateTimeImmutable($in_Zeile['behoben_am']) : null;
        $this->m_BehobenVon   = $in_Zeile['behoben_von'] !== null ? (int) $in_Zeile['behoben_von'] : null;

        $this->m_FahrzeugID     = (int) $in_Zeile['fahrzeug_id'];
        $this->m_FahrerID       = (int) $in_Zeile['fahrer_id'];
        $this->m_ArtID          = (int) $in_Zeile['art_id'];
        $this->m_HaeltInWartung = $in_Zeile['wartung_id'] !== null;
        $this->m_FahrerName     = $in_Zeile['fahrer_name'] ?? null;
    }

    /**
     * Lädt einen Schaden ohne den Namen des Fahrers. Gibt es ihn nicht, null.
     */
    public static function LoadByID(int $in_ID): ?CSchaden
    {
        $Statement = db()->prepare(self::SQL_OHNE_NAMEN . ' WHERE s.id = ?');
        $Statement->execute([$in_ID]);
        $Zeile = $Statement->fetch();

        return $Zeile !== false ? new CSchaden($Zeile) : null;
    }

    /**
     * Lädt den noch offenen Schaden, den der Fahrer bei der Übernahme dieser
     * Buchung notiert hat, sonst null.
     */
    public static function LoadOffenBeiUebernahme(int $in_BuchungID): ?CSchaden
    {
        $Statement = db()->prepare(
            self::SQL_OHNE_NAMEN . ' WHERE s.buchung_id = ? AND s.anlass_id = ? AND s.behoben_am IS NULL
             ORDER BY s.id LIMIT 1'
        );
        $Statement->execute([$in_BuchungID, ANLASS_UEBERNAHME]);
        $Zeile = $Statement->fetch();

        return $Zeile !== false ? new CSchaden($Zeile) : null;
    }

    // --- Anlegen und Ändern -------------------------------------------------

    /**
     * Trägt einen Schaden zu einer Buchung ein und liefert ihn zurück.
     *
     * Nur für CBuchung beim Fahrtbeginn und bei der Rückgabe; dort sind die
     * Angaben schon geprüft. Läuft in der Transaktion des Aufrufers.
     */
    public static function Create(int $in_BuchungID, int $in_AnlassID, int $in_SchwereID, string $in_Beschreibung): CSchaden
    {
        $Statement = db()->prepare(
            'INSERT INTO schaeden (buchung_id, anlass_id, schwere_id, beschreibung, gemeldet_am)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $Statement->execute([$in_BuchungID, $in_AnlassID, $in_SchwereID, $in_Beschreibung]);

        return self::LoadByID((int) db()->lastInsertId());
    }

    /**
     * Markiert den Schaden als behoben.
     *
     * Nur für den Fuhrparkleiter und nur, solange der Schaden offen ist. Hält
     * er sein Fahrzeug in Wartung, wird stattdessen das Fahrzeug freigegeben
     * (CWartung::Freigeben()). Steht etwas im Weg, wirft sie CFachlicherFehler.
     */
    public function Beheben(int $in_NutzerID): void
    {
        $Nutzer = CNutzer::LoadByID($in_NutzerID);

        if ($Nutzer === null || $Nutzer->GetRolleID() !== ROLLE_FUHRPARKLEITER) {
            throw new CFachlicherFehler('Schäden markiert nur der Fuhrparkleiter als behoben.');
        }

        db()->beginTransaction();

        try {
            // Fahrzeug sperren, damit nicht gleichzeitig eine Rückgabe eine
            // Wartung zu diesem Schaden anlegt; dann mit dem aktuellen Stand
            // prüfen
            $Statement = db()->prepare('SELECT id FROM fahrzeuge WHERE id = ? FOR UPDATE');
            $Statement->execute([$this->m_FahrzeugID]);

            $Aktuell = self::LoadByID($this->m_ID);

            if (!$Aktuell->IstOffen()) {
                throw new CFachlicherFehler('Der Schaden ist bereits als behoben markiert.');
            }

            if ($Aktuell->HaeltInWartung()) {
                throw new CFachlicherFehler(
                    'Das Fahrzeug steht wegen dieses Schadens in Wartung. Bitte geben Sie es frei; der Schaden gilt dann als behoben.'
                );
            }

            // Als behoben speichern
            $Statement = db()->prepare('UPDATE schaeden SET behoben_am = NOW(), behoben_von = ? WHERE id = ?');
            $Statement->execute([$in_NutzerID, $this->m_ID]);

            db()->commit();
        } catch (Throwable $Fehler) {
            db()->rollBack();
            throw $Fehler;
        }

        $this->m_BehobenAm = new DateTimeImmutable();
        $this->m_BehobenVon = $in_NutzerID;
    }

    /**
     * Kürzt eine Schadensbeschreibung um Leerzeichen und prüft ihre Länge.
     * Liefert die bereinigte Beschreibung, leer heißt: kein Schaden angegeben.
     */
    public static function PruefeBeschreibung(string $in_Beschreibung): string
    {
        $Beschreibung = trim($in_Beschreibung);

        if (mb_strlen($Beschreibung) > self::MAX_BESCHREIBUNG) {
            throw new CFachlicherFehler(
                'Die Beschreibung des Schadens darf höchstens ' . self::MAX_BESCHREIBUNG . ' Zeichen lang sein.'
            );
        }

        return $Beschreibung;
    }

    // --- Fachfunktionen -----------------------------------------------------

    /**
     * Offen: noch nicht als behoben markiert.
     */
    public function IstOffen(): bool
    {
        return $this->m_BehobenAm === null;
    }

    /**
     * Sperrt der Schaden das Fahrzeug? Ein Schaden mit Wartung immer, bei einem
     * Luxusauto auch ein Kleinschaden.
     */
    public function Sperrt(): bool
    {
        return $this->m_SchwereID === SCHWERE_WARTUNG || $this->m_ArtID === ART_LUXUS;
    }

    /**
     * Hat der Schaden die laufende Wartung seines Fahrzeugs ausgelöst? Dann
     * gilt er erst mit dem Freigeben als behoben.
     */
    public function HaeltInWartung(): bool
    {
        return $this->m_HaeltInWartung;
    }

    /**
     * Die Fotos zum Schaden (CSchadensfoto). Sie werden erst beim ersten
     * Zugriff geladen.
     */
    public function GetFotos(): array
    {
        if (!$this->m_FotosGeladen) {
            $this->m_Fotos = CSchadensfoto::LadenZuSchaden($this->m_ID);
            $this->m_FotosGeladen = true;
        }

        return $this->m_Fotos;
    }

    // --- Getter -------------------------------------------------------------

    public function GetID(): int
    {
        return $this->m_ID;
    }

    public function GetBuchungID(): int
    {
        return $this->m_BuchungID;
    }

    /**
     * ANLASS_UEBERNAHME oder ANLASS_RUECKGABE.
     */
    public function GetAnlassID(): int
    {
        return $this->m_AnlassID;
    }

    /**
     * SCHWERE_KLEIN oder SCHWERE_WARTUNG.
     */
    public function GetSchwereID(): int
    {
        return $this->m_SchwereID;
    }

    public function GetBeschreibung(): string
    {
        return $this->m_Beschreibung;
    }

    public function GetGemeldetAm(): DateTimeImmutable
    {
        return $this->m_GemeldetAm;
    }

    public function GetBehobenAm(): ?DateTimeImmutable
    {
        return $this->m_BehobenAm;
    }

    public function GetBehobenVon(): ?int
    {
        return $this->m_BehobenVon;
    }

    public function GetFahrzeugID(): int
    {
        return $this->m_FahrzeugID;
    }

    /**
     * Der Fahrer der Buchung; er hat den Schaden gemeldet.
     */
    public function GetFahrerID(): int
    {
        return $this->m_FahrerID;
    }

    /**
     * Vor- und Nachname des Fahrers, null wenn ohne Namen geladen.
     */
    public function GetFahrerName(): ?string
    {
        return $this->m_FahrerName;
    }
}
