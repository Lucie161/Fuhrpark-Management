/**
 * Allgemeines Skript der Anwendung.
 * Derzeit nur das Aufklappen der Navigation auf schmalen Bildschirmen.
 */

document.addEventListener('DOMContentLoaded', function () {
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
