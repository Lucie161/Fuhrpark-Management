<?php
/**
 * Ein Nutzer mit Rolle und Führerscheinen.
 *
 * Nutzer werden nicht über die Anwendung angelegt oder geändert, Stammdaten
 * werden direkt in der Datenbank gepflegt.
 */

declare(strict_types=1);

class CNutzer
{
    private int $m_ID;
    private string $m_Vorname;
    private string $m_Nachname;
    private int $m_RolleID;
    private string $m_PasswortHash;
    private bool $m_DarfLuxus;
    private ?DateTimeImmutable $m_GeloeschtAm;
    private array $m_Fuehrerscheine = [];
    private bool $m_FuehrerscheineGeladen = false;

    /**
     * Legt das Objekt aus einer Zeile der Tabelle nutzer an.
     */
    public function __construct(array $in_Zeile)
    {
        $this->m_ID           = (int) $in_Zeile['id'];
        $this->m_Vorname      = $in_Zeile['vorname'];
        $this->m_Nachname     = $in_Zeile['nachname'];
        $this->m_RolleID      = (int) $in_Zeile['rolle_id'];
        $this->m_PasswortHash = $in_Zeile['passwort_hash'];
        $this->m_DarfLuxus    = (bool) $in_Zeile['darf_luxus'];
        $this->m_GeloeschtAm  = $in_Zeile['geloescht_am'] !== null
            ? new DateTimeImmutable($in_Zeile['geloescht_am'])
            : null;
    }

    /**
     * Lädt einen Nutzer, auch einen ausgeschiedenen. Gibt es ihn nicht, null.
     */
    public static function LoadByID(int $in_ID): ?CNutzer
    {
        $Statement = db()->prepare('SELECT * FROM nutzer WHERE id = ?');
        $Statement->execute([$in_ID]);
        $Zeile = $Statement->fetch();

        return $Zeile !== false ? new CNutzer($Zeile) : null;
    }

    public function GetID(): int
    {
        return $this->m_ID;
    }

    public function GetVorname(): string
    {
        return $this->m_Vorname;
    }

    public function GetNachname(): string
    {
        return $this->m_Nachname;
    }

    /**
     * Vor- und Nachname.
     */
    public function GetName(): string
    {
        return $this->m_Vorname . ' ' . $this->m_Nachname;
    }

    public function GetRolleID(): int
    {
        return $this->m_RolleID;
    }

    public function GetGeloeschtAm(): ?DateTimeImmutable
    {
        return $this->m_GeloeschtAm;
    }

    /**
     * Ausgeschieden: keine Anmeldung, nicht mehr als Fahrer wählbar. Im
     * Fahrtenbuch bleibt der Name.
     */
    public function IstAusgeschieden(): bool
    {
        return $this->m_GeloeschtAm !== null;
    }

    /**
     * Darf der Nutzer Luxusautos fahren?
     */
    public function DarfLuxus(): bool
    {
        return $this->m_DarfLuxus;
    }

    /**
     * Prüft das eingegebene Passwort gegen den gespeicherten Hash.
     */
    public function PruefePasswort(string $in_Passwort): bool
    {
        return password_verify($in_Passwort, $this->m_PasswortHash);
    }

    /**
     * Führerscheinklassen des Nutzers, z. B. ['A1', 'B']. Sie werden erst beim
     * ersten Zugriff geladen.
     */
    public function GetFuehrerscheine(): array
    {
        if (!$this->m_FuehrerscheineGeladen) {
            $Statement = db()->prepare('SELECT klasse FROM nutzer_fuehrerscheine WHERE nutzer_id = ? ORDER BY klasse');
            $Statement->execute([$this->m_ID]);

            $this->m_Fuehrerscheine = $Statement->fetchAll(PDO::FETCH_COLUMN);
            $this->m_FuehrerscheineGeladen = true;
        }

        return $this->m_Fuehrerscheine;
    }

    /**
     * Hat der Nutzer diese Klasse? null heißt: Das Fahrzeug braucht keinen
     * Führerschein, wie fahrzeuge.fuehrerschein beim Fahrrad.
     */
    public function HatFuehrerschein(?string $in_Klasse): bool
    {
        return $in_Klasse === null || in_array($in_Klasse, $this->GetFuehrerscheine(), true);
    }

    /**
     * Hat der Nutzer als Fahrer eine überfällige Rückgabe? Dann darf er nichts
     * neu buchen und keine Fahrt beginnen.
     */
    public function HatUeberfaelligeRueckgabe(): bool
    {
        $Statement = db()->prepare(
            'SELECT COUNT(*) FROM buchungen WHERE fahrer_id = ? AND status_id = ? AND ende < CURDATE()'
        );
        $Statement->execute([$this->m_ID, STATUS_UNTERWEGS]);

        return (int) $Statement->fetchColumn() > 0;
    }

    /**
     * Warum der Nutzer nicht buchen und nicht als Fahrer eingetragen werden
     * darf, sonst null.
     */
    public function GetSperreBuchen(): ?string
    {
        return $this->HatUeberfaelligeRueckgabe()
            ? 'Die Rückgabe von ' . $this->GetName() . ' ist überfällig.'
            : null;
    }
}
