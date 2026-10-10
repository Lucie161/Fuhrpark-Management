/**
 * Allgemeines Skript der Anwendung.
 * Aufklappen der Navigation auf schmalen Bildschirmen, die Personenauswahl
 * und die Schadensfelder der Rückgabe.
 */

document.addEventListener('DOMContentLoaded', function () {
    // Rückgabe (rueckgabe.php): Beschreibung und Fotos nur zeigen, wenn ein
    // Schaden gewählt ist. Ohne JavaScript bleiben sie sichtbar; der Server
    // ignoriert sie bei „Nein“.
    var schadenWahl = document.querySelectorAll('input[name="schaden"]');
    var schadenFelder = document.querySelectorAll('[data-nur-bei-schaden]');

    function schadenFelderZeigen() {
        var gewaehlt = document.querySelector('input[name="schaden"]:checked');
        var mitSchaden = gewaehlt !== null && gewaehlt.value !== 'nein';

        schadenFelder.forEach(function (feld) {
            feld.hidden = !mitSchaden;
        });
    }

    if (schadenWahl.length > 0) {
        schadenWahl.forEach(function (radio) {
            radio.addEventListener('change', schadenFelderZeigen);
        });
        schadenFelderZeigen();
    }

    // Rollenauswahl (nur Prototyp): wechselt sofort beim Auswählen. Ohne
    // JavaScript bleibt der Knopf „Wechseln“ sichtbar.
    var rollenwahl = document.querySelector('.rollenwahl');

    if (rollenwahl) {
        rollenwahl.querySelector('.rollenwahl__knopf').hidden = true;
        rollenwahl.querySelector('select').addEventListener('change', function () {
            rollenwahl.submit();
        });
    }

    var toggle = document.querySelector('.nav-toggle');
    var nav = document.getElementById('hauptnavigation');

    if (!toggle || !nav) {
        return;
    }

    toggle.addEventListener('click', function () {
        var offen = nav.classList.toggle('nav--offen');
        toggle.setAttribute('aria-expanded', offen ? 'true' : 'false');
    });
});
