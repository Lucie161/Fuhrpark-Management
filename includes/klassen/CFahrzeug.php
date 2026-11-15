<?php
/**
 * Ein Fahrzeug mit seiner laufenden Wartung.
 */

declare(strict_types=1);

class CFahrzeug
{
    // Abfrage für alle Stellen, die Fahrzeuge laden: dazu die laufende Wartung,
    // höchstens eine je Fahrzeug (das prüft CWartung::Create()).
    public const SQL =
        'SELECT f.*, w.id AS wartung_id, w.voraussichtlich_bis AS wartung_bis,
                w.schaden_id AS wartung_schaden_id
           FROM fahrzeuge f
           LEFT JOIN wartungen w ON w.fahrzeug_id = f.id AND w.freigegeben_am IS NULL';

    // HU/AU gilt als bald fällig, wenn sie in diesem oder den nächsten zwei
    // Monaten ansteht.
    private const HU_AU_VORLAUF = '+2 month';

    // Steht in entscheidung_kommentar der Buchungen, die beim Ausmustern
    // storniert werden; der Fahrer liest ihn unter „Frühere Buchungen“.
    public const STORNO_AUSGEMUSTERT = 'Automatisch storniert: Das Fahrzeug wurde ausgemustert.';

    private int $m_ID;
    private string $m_Kennzeichen;
    private int $m_ArtID;
    private string $m_Typ;
    private string $m_Hersteller;
    private string $m_Modell;
    private ?int $m_Baujahr;
    private ?string $m_Bild;
    private int $m_Sitzplaetze;
    private ?int $m_AntriebID;
    private ?int $m_KmStand;
    private ?string $m_Fuehrerschein;
    private ?DateTimeImmutable $m_HuAu;
    private ?DateTimeImmutable $m_GeloeschtAm;

    // Laufende Wartung aus dem LEFT JOIN, sonst null
    private ?int $m_WartungID;
    private ?DateTimeImmutable $m_WartungBis;
    private ?int $m_WartungSchadenID;

    // Wird erst bei Bedarf abgefragt, dann gemerkt.
    private ?bool $m_Unterwegs = null;

    /**
     * Legt das Objekt aus einer Zeile von SQL an.
     */
    public function __construct(array $in_Zeile)
    {
        $this->m_ID            = (int) $in_Zeile['id'];
        $this->m_Kennzeichen   = $in_Zeile['kennzeichen'];
        $this->m_ArtID         = (int) $in_Zeile['art_id'];
        $this->m_Typ           = $in_Zeile['typ'];
        $this->m_Hersteller    = $in_Zeile['hersteller'];
        $this->m_Modell        = $in_Zeile['modell'];
        $this->m_Baujahr       = self::ZahlOderNull($in_Zeile['baujahr']);
        $this->m_Bild          = $in_Zeile['bild'];
        $this->m_Sitzplaetze   = (int) $in_Zeile['sitzplaetze'];
        $this->m_AntriebID     = self::ZahlOderNull($in_Zeile['antrieb_id']);
        $this->m_KmStand       = self::ZahlOderNull($in_Zeile['kmstand']);
        $this->m_Fuehrerschein = $in_Zeile['fuehrerschein'];
        $this->m_HuAu          = self::DatumOderNull($in_Zeile['hu_au']);
        $this->m_GeloeschtAm   = self::DatumOderNull($in_Zeile['geloescht_am']);

        $this->m_WartungID        = self::ZahlOderNull($in_Zeile['wartung_id']);
        $this->m_WartungBis       = self::DatumOderNull($in_Zeile['wartung_bis']);
        $this->m_WartungSchadenID = self::ZahlOderNull($in_Zeile['wartung_schaden_id']);
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
     * Lädt ein Fahrzeug, auch ein ausgemustertes. Gibt es es nicht, null.
     */
    public static function LoadByID(int $in_ID): ?CFahrzeug
    {
        $Statement = db()->prepare(self::SQL . ' WHERE f.id = ?');
        $Statement->execute([$in_ID]);
        $Zeile = $Statement->fetch();

        return $Zeile !== false ? new CFahrzeug($Zeile) : null;
    }

    /**
     * Mustert das Fahrzeug aus. Nur der Fuhrparkleiter, und nur, wenn niemand
     * damit unterwegs ist.
     *
     * In einer Transaktion: Beantragte und genehmigte Buchungen werden
     * storniert, eine laufende Wartung endet heute und offene Schäden gelten
     * als erledigt. Steckbrief und Fahrtenbuch bleiben. Liefert die
     * stornierten Buchungen mit Namen, für die Bestätigung. Steht etwas im
     * Weg, wirft sie CFachlicherFehler.
     */
    public function Ausmustern(int $in_NutzerID): array
    {
        $Nutzer = CNutzer::LoadByID($in_NutzerID);

        if ($Nutzer === null || $Nutzer->GetRolleID() !== ROLLE_FUHRPARKLEITER) {
            throw new CFachlicherFehler('Ausmustern darf nur der Fuhrparkleiter.');
        }

        db()->beginTransaction();

        try {
            // Fahrzeug sperren, damit keine Buchung oder Fahrt dazwischenkommt
            $Statement = db()->prepare('SELECT geloescht_am FROM fahrzeuge WHERE id = ? FOR UPDATE');
            $Statement->execute([$this->m_ID]);
            $Zeile = $Statement->fetch();

            if ($Zeile === false || $Zeile['geloescht_am'] !== null) {
                throw new CFachlicherFehler('Dieses Fahrzeug ist bereits ausgemustert.');
            }

            $Statement = db()->prepare('SELECT COUNT(*) FROM buchungen WHERE fahrzeug_id = ? AND status_id = ?');
            $Statement->execute([$this->m_ID, STATUS_UNTERWEGS]);

            if ((int) $Statement->fetchColumn() > 0) {
                throw new CFachlicherFehler('Mit dem Fahrzeug ist noch jemand unterwegs. Bitte erst nach der Rückgabe ausmustern.');
            }

            // Beantragte und genehmigte Buchungen merken und stornieren
            $Statement = db()->prepare(CBuchung::SQL_MIT_NAMEN . ' WHERE b.fahrzeug_id = ? AND b.status_id IN (?, ?) ORDER BY b.start');
            $Statement->execute([$this->m_ID, STATUS_OFFEN, STATUS_GENEHMIGT]);
            $Stornierte = array_map(fn (array $Zeile): CBuchung => new CBuchung($Zeile), $Statement->fetchAll());

            $Statement = db()->prepare(
                'UPDATE buchungen SET status_id = ?, entscheidung_am = NOW(), entscheidung_kommentar = ?
                  WHERE fahrzeug_id = ? AND status_id IN (?, ?)'
            );
            $Statement->execute([STATUS_STORNIERT, self::STORNO_AUSGEMUSTERT, $this->m_ID, STATUS_OFFEN, STATUS_GENEHMIGT]);

            // Laufende Wartung beenden, offene Schäden als erledigt markieren
            $Statement = db()->prepare(
                'UPDATE wartungen SET freigegeben_am = CURDATE(), freigegeben_von = ? WHERE fahrzeug_id = ? AND freigegeben_am IS NULL'
            );
            $Statement->execute([$in_NutzerID, $this->m_ID]);

            $Statement = db()->prepare(
                'UPDATE schaeden s JOIN buchungen b ON b.id = s.buchung_id
                    SET s.behoben_am = NOW(), s.behoben_von = ?
                  WHERE b.fahrzeug_id = ? AND s.behoben_am IS NULL'
            );
            $Statement->execute([$in_NutzerID, $this->m_ID]);

            // Ausmustern
            $Statement = db()->prepare('UPDATE fahrzeuge SET geloescht_am = NOW() WHERE id = ?');
            $Statement->execute([$this->m_ID]);

            db()->commit();
        } catch (Throwable $Fehler) {
            // Bei jedem Fehler alles zurücknehmen; die Seite zeigt den Grund.
            db()->rollBack();
            throw $Fehler;
        }

        $this->m_GeloeschtAm = new DateTimeImmutable();

        return $Stornierte;
    }

    // --- Fachfunktionen -----------------------------------------------------

    /**
     * „Hersteller Modell (Kennzeichen)“.
     */
    public function GetName(): string
    {
        return $this->m_Hersteller . ' ' . $this->m_Modell . ' (' . $this->m_Kennzeichen . ')';
    }

    /**
     * Ausgemustert: nicht mehr buchbar, das Fahrtenbuch bleibt.
     */
    public function IstAusgemustert(): bool
    {
        return $this->m_GeloeschtAm !== null;
    }

    public function IstLuxus(): bool
    {
        return $this->m_ArtID === ART_LUXUS;
    }

    /**
     * Ampel: Autos, Luxusautos und Transporter werden beantragt, Roller und
     * Fahrräder sind sofort genehmigt.
     */
    public function BrauchtGenehmigung(): bool
    {
        return !in_array($this->m_ArtID, [ART_ROLLER, ART_FAHRRAD], true);
    }

    /**
     * Fehlt dem Fahrer die Freigabe? Luxusautos dürfen nur Nutzer mit
     * darf_luxus fahren.
     */
    public function FehltFreigabe(CNutzer $in_Fahrer): bool
    {
        return $this->IstLuxus() && !$in_Fahrer->DarfLuxus();
    }

    public function IstInWartung(): bool
    {
        return $this->m_WartungID !== null;
    }

    /**
     * Hat ein gemeldeter Schaden die laufende Wartung ausgelöst? Mit dem
     * Freigeben gilt er als behoben.
     */
    public function IstWartungWegenSchaden(): bool
    {
        return $this->m_WartungSchadenID !== null;
    }

    /**
     * Ist das Ende der Wartung offen? Entweder noch nicht festgelegt (nach
     * einer Rückgabe mit Schaden) oder vorbei, ohne dass das Fahrzeug
     * freigegeben wurde. Der Fuhrparkleiter muss dann das Ende festlegen.
     */
    public function IstWartungEndeOffen(): bool
    {
        return $this->IstInWartung()
            && ($this->m_WartungBis === null || $this->m_WartungBis < new DateTimeImmutable('today'));
    }

    /**
     * Sperrt die Wartung das Fahrzeug im Zeitraum? Gesperrt ist es bis
     * einschließlich zum voraussichtlichen Ende, bei offenem Ende immer.
     */
    public function SperrtWartung(DateTimeImmutable $in_Von, DateTimeImmutable $in_Bis): bool
    {
        if (!$this->IstInWartung()) {
            return false;
        }

        // Die Wartung hat schon begonnen. Der Zeitraum ist also gesperrt, wenn
        // er spätestens am letzten Wartungstag beginnt. Sein Ende spielt keine
        // Rolle, $in_Bis steht nur für die einheitliche Form da.
        return $this->IstWartungEndeOffen() || $in_Von <= $this->m_WartungBis;
    }

    /**
     * Stand der Wartung als Text: „voraussichtlich bis 14.10.2026“, „Ende noch
     * offen“ oder „war bis 14.10.2026 geplant“. Ohne Wartung leer.
     */
    public function GetWartungText(): string
    {
        return match (true) {
            !$this->IstInWartung()        => '',
            $this->m_WartungBis === null  => 'Ende noch offen',
            $this->IstWartungEndeOffen()  => 'war bis ' . $this->m_WartungBis->format('d.m.Y') . ' geplant',
            default                       => 'voraussichtlich bis ' . $this->m_WartungBis->format('d.m.Y'),
        };
    }

    /**
     * Ist jemand mit dem Fahrzeug unterwegs?
     */
    public function IstUnterwegs(): bool
    {
        // Nur beim ersten Aufruf abfragen, danach den gemerkten Wert nehmen.
        // Die Fahrzeugliste fragt mehrmals je Fahrzeug.
        if ($this->m_Unterwegs === null) {
            $Statement = db()->prepare('SELECT COUNT(*) FROM buchungen WHERE fahrzeug_id = ? AND status_id = ?');
            $Statement->execute([$this->m_ID, STATUS_UNTERWEGS]);

            $this->m_Unterwegs = (int) $Statement->fetchColumn() > 0;
        }

        return $this->m_Unterwegs;
    }

    /**
     * Ist die Rückgabe überfällig? Dann ist das Fahrzeug nicht buchbar, keine
     * Fahrt damit kann beginnen und kein Antrag dafür genehmigt werden.
     */
    public function IstUeberfaellig(): bool
    {
        $Statement = db()->prepare(
            'SELECT COUNT(*) FROM buchungen WHERE fahrzeug_id = ? AND status_id = ? AND ende < CURDATE()'
        );
        $Statement->execute([$this->m_ID, STATUS_UNTERWEGS]);

        return (int) $Statement->fetchColumn() > 0;
    }

    /**
     * Stand der HU/AU: 'ueberfaellig' vor diesem Monat, 'faellig' in diesem
     * oder den nächsten zwei Monaten, sonst null (auch ohne HU/AU).
     */
    public function GetHuAuStand(): ?string
    {
        if ($this->m_HuAu === null) {
            return null;
        }

        // hu_au ist immer ein Monatserster, deshalb wird mit dem Ersten dieses
        // Monats verglichen: davor überfällig, bis zwei Monate danach fällig.
        $DiesenMonat = (new DateTimeImmutable('today'))->modify('first day of this month');

        return match (true) {
            $this->m_HuAu < $DiesenMonat                               => 'ueberfaellig',
            $this->m_HuAu <= $DiesenMonat->modify(self::HU_AU_VORLAUF) => 'faellig',
            default                                                    => null,
        };
    }

    /**
     * Angezeigter Status: 'ausgemustert' vor 'wartung' vor 'unterwegs' vor
     * 'verfuegbar'. Die Beschriftung wählt die Seite.
     */
    public function GetStand(): string
    {
        return match (true) {
            $this->IstAusgemustert() => 'ausgemustert',
            $this->IstInWartung()    => 'wartung',
            $this->IstUnterwegs()    => 'unterwegs',
            default                  => 'verfuegbar',
        };
    }

    // --- Getter -------------------------------------------------------------

    public function GetID(): int
    {
        return $this->m_ID;
    }

    public function GetKennzeichen(): string
    {
        return $this->m_Kennzeichen;
    }

    public function GetArtID(): int
    {
        return $this->m_ArtID;
    }

    public function GetTyp(): string
    {
        return $this->m_Typ;
    }

    public function GetHersteller(): string
    {
        return $this->m_Hersteller;
    }

    public function GetModell(): string
    {
        return $this->m_Modell;
    }

    public function GetBaujahr(): ?int
    {
        return $this->m_Baujahr;
    }

    /**
     * Dateiname in assets/img/, null für den Platzhalter.
     */
    public function GetBild(): ?string
    {
        return $this->m_Bild;
    }

    public function GetSitzplaetze(): int
    {
        return $this->m_Sitzplaetze;
    }

    public function GetAntriebID(): ?int
    {
        return $this->m_AntriebID;
    }

    /**
     * km-Stand, null beim Fahrrad.
     */
    public function GetKmStand(): ?int
    {
        return $this->m_KmStand;
    }

    /**
     * Nötige Führerscheinklasse, null wenn keiner nötig ist.
     */
    public function GetFuehrerschein(): ?string
    {
        return $this->m_Fuehrerschein;
    }

    /**
     * Nächste HU/AU, der Monatserste des fälligen Monats.
     */
    public function GetHuAu(): ?DateTimeImmutable
    {
        return $this->m_HuAu;
    }

    public function GetGeloeschtAm(): ?DateTimeImmutable
    {
        return $this->m_GeloeschtAm;
    }

    /**
     * Voraussichtliches Ende der laufenden Wartung; null ohne Wartung oder bei
     * offenem Ende.
     */
    public function GetWartungBis(): ?DateTimeImmutable
    {
        return $this->m_WartungBis;
    }
}
