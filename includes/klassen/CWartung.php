<?php
/**
 * Eine Wartung: Das Fahrzeug ist gesperrt, bis der Fuhrparkleiter es freigibt.
 */

declare(strict_types=1);

class CWartung
{
    // Steht in entscheidung_kommentar; der Fahrer liest ihn unter „Frühere
    // Buchungen“.
    public const STORNO_GRUND = 'Automatisch storniert: Das Fahrzeug ist in diesem Zeitraum in Wartung.';

    private const MAX_GRUND = 200;

    private int $m_ID;
    private int $m_FahrzeugID;
    private ?int $m_SchadenID;
    private string $m_Grund;
    private DateTimeImmutable $m_BegonnenAm;
    private ?DateTimeImmutable $m_VoraussichtlichBis;
    private ?int $m_AngelegtVon;
    private ?DateTimeImmutable $m_FreigegebenAm;
    private ?int $m_FreigegebenVon;

    // Buchungen, die beim Anlegen oder beim Ändern des Endes storniert wurden
    private array $m_Stornierte = [];

    /**
     * Legt das Objekt aus einer Zeile der Tabelle wartungen an.
     */
    public function __construct(array $in_Zeile)
    {
        $this->m_ID                 = (int) $in_Zeile['id'];
        $this->m_FahrzeugID         = (int) $in_Zeile['fahrzeug_id'];
        $this->m_SchadenID          = self::ZahlOderNull($in_Zeile['schaden_id']);
        $this->m_Grund              = $in_Zeile['grund'];
        $this->m_BegonnenAm         = new DateTimeImmutable($in_Zeile['begonnen_am']);
        $this->m_VoraussichtlichBis = self::DatumOderNull($in_Zeile['voraussichtlich_bis']);
        $this->m_AngelegtVon        = self::ZahlOderNull($in_Zeile['angelegt_von']);
        $this->m_FreigegebenAm      = self::DatumOderNull($in_Zeile['freigegeben_am']);
        $this->m_FreigegebenVon     = self::ZahlOderNull($in_Zeile['freigegeben_von']);
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
     * Lädt eine Wartung. Gibt es sie nicht, null.
     */
    public static function LoadByID(int $in_ID): ?CWartung
    {
        $Statement = db()->prepare('SELECT * FROM wartungen WHERE id = ?');
        $Statement->execute([$in_ID]);
        $Zeile = $Statement->fetch();

        return $Zeile !== false ? new CWartung($Zeile) : null;
    }

    /**
     * Lädt die laufende Wartung des Fahrzeugs, sonst null.
     */
    public static function LoadLaufende(int $in_FahrzeugID): ?CWartung
    {
        $Statement = db()->prepare('SELECT * FROM wartungen WHERE fahrzeug_id = ? AND freigegeben_am IS NULL');
        $Statement->execute([$in_FahrzeugID]);
        $Zeile = $Statement->fetch();

        return $Zeile !== false ? new CWartung($Zeile) : null;
    }

    // --- Anlegen und Ändern -------------------------------------------------

    /**
     * Setzt das Fahrzeug in Wartung und liefert die neue Wartung.
     *
     * Verlangt Grund und voraussichtliches Ende (frühestens heute). In
     * derselben Transaktion werden betroffene beantragte und genehmigte
     * Buchungen storniert, siehe GetStornierte(). Steht etwas im Weg, wirft sie
     * CFachlicherFehler.
     */
    public static function Create(int $in_FahrzeugID, int $in_NutzerID, string $in_Grund, DateTimeImmutable $in_Bis): CWartung
    {
        // Wer, bis wann und warum: Das lässt sich ohne Datenbank prüfen,
        // deshalb vor der Transaktion.
        self::PruefeFuhrparkleiter($in_NutzerID);
        self::PruefeEnde($in_Bis);

        $Grund = trim($in_Grund);

        if ($Grund === '' || mb_strlen($Grund) > self::MAX_GRUND) {
            throw new CFachlicherFehler(
                'Bitte geben Sie den Grund der Wartung an (höchstens ' . self::MAX_GRUND . ' Zeichen), z. B. „Inspektion“ oder „HU/AU“.'
            );
        }

        db()->beginTransaction();

        try {
            // Fahrzeug sperren, dann prüfen, ob schon eine Wartung läuft.
            // Wegen der Sperre kann keine zweite gleichzeitig entstehen.
            self::SperreFahrzeug($in_FahrzeugID);

            if (self::LoadLaufende($in_FahrzeugID) !== null) {
                throw new CFachlicherFehler('Das Fahrzeug ist bereits in Wartung.');
            }

            // Wartung ab heute eintragen
            $Statement = db()->prepare(
                'INSERT INTO wartungen (fahrzeug_id, grund, begonnen_am, voraussichtlich_bis, angelegt_von)
                 VALUES (?, ?, CURDATE(), ?, ?)'
            );
            $Statement->execute([$in_FahrzeugID, $Grund, $in_Bis->format('Y-m-d'), $in_NutzerID]);

            // Die neue Zeile als Objekt laden und die Buchungen stornieren, die
            // in die Wartung fallen. Erst danach ist alles gespeichert.
            $Wartung = self::LoadByID((int) db()->lastInsertId());
            $Wartung->StorniereBuchungen();

            db()->commit();
        } catch (Throwable $Fehler) {
            // Bei jedem Fehler alles zurücknehmen; die Seite zeigt den Grund.
            db()->rollBack();
            throw $Fehler;
        }

        return $Wartung;
    }

    /**
     * Setzt ein neues voraussichtliches Ende, später oder früher.
     *
     * Buchungen, die dadurch neu betroffen sind, werden storniert, siehe
     * GetStornierte(). Steht etwas im Weg, wirft sie CFachlicherFehler.
     */
    public function AendereEnde(int $in_NutzerID, DateTimeImmutable $in_Bis): void
    {
        self::PruefeFuhrparkleiter($in_NutzerID);
        self::PruefeEnde($in_Bis);

        db()->beginTransaction();

        try {
            // Fahrzeug sperren und prüfen, ob die Wartung noch läuft;
            // vielleicht hat sie jemand inzwischen freigegeben.
            self::SperreFahrzeug($this->m_FahrzeugID);
            $this->PruefeLaufend();

            // Neues Ende speichern
            $Statement = db()->prepare('UPDATE wartungen SET voraussichtlich_bis = ? WHERE id = ?');
            $Statement->execute([$in_Bis->format('Y-m-d'), $this->m_ID]);

            // Mit dem neuen Ende stornieren. Bei einem späteren Ende trifft
            // das Buchungen, die vorher noch frei lagen; die schon stornierten
            // findet die Abfrage nicht noch einmal.
            $this->m_VoraussichtlichBis = $in_Bis;
            $this->StorniereBuchungen();

            db()->commit();
        } catch (Throwable $Fehler) {
            db()->rollBack();
            throw $Fehler;
        }
    }

    /**
     * Gibt das Fahrzeug wieder frei.
     *
     * Hat ein Schaden die Wartung ausgelöst, gilt er damit als behoben, egal
     * ob Kleinschaden (Luxusauto) oder Schaden mit Wartung.
     */
    public function Freigeben(int $in_NutzerID): void
    {
        self::PruefeFuhrparkleiter($in_NutzerID);

        db()->beginTransaction();

        try {
            // Fahrzeug sperren und prüfen, ob die Wartung noch läuft
            self::SperreFahrzeug($this->m_FahrzeugID);
            $this->PruefeLaufend();

            // Wartung abschließen: ab heute ist das Fahrzeug wieder buchbar
            $Statement = db()->prepare('UPDATE wartungen SET freigegeben_am = CURDATE(), freigegeben_von = ? WHERE id = ?');
            $Statement->execute([$in_NutzerID, $this->m_ID]);

            // Den Schaden, der die Wartung ausgelöst hat, als behoben
            // markieren. Ohne Schaden (z. B. Inspektion) entfällt das.
            if ($this->m_SchadenID !== null) {
                $Statement = db()->prepare(
                    'UPDATE schaeden SET behoben_am = NOW(), behoben_von = ? WHERE id = ? AND behoben_am IS NULL'
                );
                $Statement->execute([$in_NutzerID, $this->m_SchadenID]);
            }

            db()->commit();
        } catch (Throwable $Fehler) {
            db()->rollBack();
            throw $Fehler;
        }

        // Das Objekt an den gespeicherten Stand anpassen
        $this->m_FreigegebenAm = new DateTimeImmutable('today');
        $this->m_FreigegebenVon = $in_NutzerID;
    }

    /**
     * Storniert die beantragten und genehmigten Buchungen, die bis zum Ende
     * der Wartung beginnen und noch nicht vorbei sind. Laufende Fahrten
     * bleiben. Läuft in der Transaktion des Aufrufers.
     */
    private function StorniereBuchungen(): void
    {
        // Betroffene Buchungen suchen: beantragt oder genehmigt, Beginn bis
        // zum Ende der Wartung, Ende heute oder später. Mit Namen, denn die
        // Bestätigung sieht nur der Fuhrparkleiter.
        $Statement = db()->prepare(
            CBuchung::SQL_MIT_NAMEN . '
             WHERE b.fahrzeug_id = ? AND b.status_id IN (?, ?) AND b.start <= ? AND b.ende >= CURDATE()
             ORDER BY b.start, b.id'
        );
        $Statement->execute([
            $this->m_FahrzeugID,
            STATUS_OFFEN,
            STATUS_GENEHMIGT,
            $this->m_VoraussichtlichBis->format('Y-m-d'),
        ]);

        // Jede einzeln stornieren, mit Grund für den Fahrer, und für die
        // Bestätigung merken
        $this->m_Stornierte = [];
        $Update = db()->prepare('UPDATE buchungen SET status_id = ?, entscheidung_kommentar = ? WHERE id = ?');

        foreach ($Statement->fetchAll() as $Zeile) {
            $Buchung = new CBuchung($Zeile);
            $Update->execute([STATUS_STORNIERT, self::STORNO_GRUND, $Buchung->GetID()]);

            $this->m_Stornierte[] = $Buchung;
        }
    }

    /**
     * Wartungen verwaltet nur der Fuhrparkleiter.
     */
    private static function PruefeFuhrparkleiter(int $in_NutzerID): void
    {
        $Nutzer = CNutzer::LoadByID($in_NutzerID);

        if ($Nutzer === null || $Nutzer->GetRolleID() !== ROLLE_FUHRPARKLEITER) {
            throw new CFachlicherFehler('Wartungen verwaltet nur der Fuhrparkleiter.');
        }
    }

    /**
     * Das voraussichtliche Ende liegt frühestens heute.
     */
    private static function PruefeEnde(DateTimeImmutable $in_Bis): void
    {
        if ($in_Bis < new DateTimeImmutable('today')) {
            throw new CFachlicherFehler('Das voraussichtliche Ende der Wartung darf nicht in der Vergangenheit liegen.');
        }
    }

    /**
     * Die Wartung muss noch laufen; vielleicht hat sie jemand inzwischen
     * freigegeben.
     */
    private function PruefeLaufend(): void
    {
        $Laufende = self::LoadLaufende($this->m_FahrzeugID);

        if ($Laufende === null || $Laufende->GetID() !== $this->m_ID) {
            throw new CFachlicherFehler('Diese Wartung ist schon abgeschlossen. Das Fahrzeug ist bereits freigegeben.');
        }
    }

    /**
     * Sperrt die Zeile des Fahrzeugs bis zum Ende der Transaktion. So entsteht
     * keine zweite Wartung, und keine gleichzeitig angelegte Buchung entgeht
     * dem Stornieren. Gibt es das Fahrzeug nicht oder ist es ausgemustert,
     * wirft sie CFachlicherFehler.
     */
    private static function SperreFahrzeug(int $in_FahrzeugID): void
    {
        $Statement = db()->prepare('SELECT geloescht_am FROM fahrzeuge WHERE id = ? FOR UPDATE');
        $Statement->execute([$in_FahrzeugID]);
        $Zeile = $Statement->fetch();

        if ($Zeile === false || $Zeile['geloescht_am'] !== null) {
            throw new CFachlicherFehler('Dieses Fahrzeug gibt es nicht mehr.');
        }
    }

    // --- Getter -------------------------------------------------------------

    /**
     * Die Buchungen, die beim Anlegen oder beim letzten Ändern des Endes
     * storniert wurden, mit Namen. Für die Bestätigung an den Fuhrparkleiter.
     */
    public function GetStornierte(): array
    {
        return $this->m_Stornierte;
    }

    /**
     * Läuft die Wartung noch?
     */
    public function IstLaufend(): bool
    {
        return $this->m_FreigegebenAm === null;
    }

    public function GetID(): int
    {
        return $this->m_ID;
    }

    public function GetFahrzeugID(): int
    {
        return $this->m_FahrzeugID;
    }

    /**
     * Schaden, der die Wartung ausgelöst hat, sonst null.
     */
    public function GetSchadenID(): ?int
    {
        return $this->m_SchadenID;
    }

    public function GetGrund(): string
    {
        return $this->m_Grund;
    }

    public function GetBegonnenAm(): DateTimeImmutable
    {
        return $this->m_BegonnenAm;
    }

    /**
     * Voraussichtliches Ende, null solange es offen ist.
     */
    public function GetVoraussichtlichBis(): ?DateTimeImmutable
    {
        return $this->m_VoraussichtlichBis;
    }

    /**
     * Fuhrparkleiter, der sie angelegt hat; null bei einer Rückgabe mit Schaden.
     */
    public function GetAngelegtVon(): ?int
    {
        return $this->m_AngelegtVon;
    }

    public function GetFreigegebenAm(): ?DateTimeImmutable
    {
        return $this->m_FreigegebenAm;
    }

    public function GetFreigegebenVon(): ?int
    {
        return $this->m_FreigegebenVon;
    }
}
