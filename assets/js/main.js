/**
 * Allgemeines Skript der Anwendung.
 * Aufklappen der Navigation auf schmalen Bildschirmen, die Schadensfelder
 * der Rückgabe, „Abbrechen“ in aufgeklappten Formularen und Popups, die nach
 * einem Fehler wieder offen sein sollen.
 */

document.addEventListener('DOMContentLoaded', function () {
    // Rückgabe (rueckgabe.php): Beschreibung und Fotos nur zeigen, wenn ein
    // Schaden gewählt ist (Wert 0 = „Nein“). Ohne JavaScript bleiben sie
    // sichtbar; der Server ignoriert sie bei „Nein“.
    var schadenWahl = document.querySelectorAll('input[name="schaden"]');
    var schadenFelder = document.querySelectorAll('[data-nur-bei-schaden]');

    function schadenFelderZeigen() {
        var gewaehlt = document.querySelector('input[name="schaden"]:checked');
        var mitSchaden = gewaehlt !== null && gewaehlt.value !== '0';

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

    // Popup, das nach einem Fehler offen bleiben soll (Fahrt beginnen,
    // Zeitraum ändern): gleich wieder öffnen.
    document.querySelectorAll('[popover][data-offen]').forEach(function (popup) {
        if (popup.showPopover) {
            popup.showPopover();
        }
    });

    // Aufgeklapptes Formular (Ablehnen): „Abbrechen“
    // klappt es zu, statt die Seite neu zu laden.
    document.querySelectorAll('.klappaktion__abbrechen').forEach(function (link) {
        link.addEventListener('click', function (ereignis) {
            var klappaktion = link.closest('details');

            if (klappaktion) {
                ereignis.preventDefault();
                klappaktion.open = false;
            }
        });
    });

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
