/**
 * src/js/app.js
 * Basic interactivity for the School Efficiency Tool
 */

// Theme initialization (runs immediately to prevent flash of light mode)
const savedTheme = localStorage.getItem('theme');
if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.body.classList.add('dark-mode');
}

document.addEventListener('DOMContentLoaded', () => {
    // Dark Mode Toggle Logic
    const darkModeBtn = document.getElementById('dark-mode-toggle');
    if (darkModeBtn) {
        darkModeBtn.addEventListener('click', (e) => {
            // Der Umschalter ist ein <a href="#">; ohne das hier springt die
            // Seite beim Klick an den Anfang zurueck. Stand vorher als
            // onclick="event.preventDefault();" im Markup.
            e.preventDefault();
            const isDark = document.body.classList.toggle('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
    }

    // Auswahlfelder, die ihr Formular selbst abschicken
    //
    // Stand in den Vorlagen als onchange="this.form.submit()". Ein Attribut
    // kann keinen CSP-Nonce tragen und waere mit der jetzigen Richtlinie tot;
    // das Feld sagt mit data-auto-submit nur noch, dass es sich so verhalten
    // soll.
    document.querySelectorAll('[data-auto-submit]').forEach(feld => {
        feld.addEventListener('change', () => {
            if (feld.form) {
                feld.form.submit();
            }
        });
    });

    // Felder, die beim Anklicken ihren Inhalt markieren
    //
    // Darin stehen Links zum Weitergeben - einmal klicken, einmal kopieren.
    // Vorher onclick="this.select()".
    document.querySelectorAll('[data-select-on-click]').forEach(feld => {
        feld.addEventListener('click', () => feld.select());
    });

    // Mobile Sidebar Toggle
    const navToggle = document.getElementById('nav-toggle');
    const appLayout = document.getElementById('appLayout');

    if (navToggle && appLayout) {
        navToggle.addEventListener('click', () => {
            appLayout.classList.toggle('collapsed');
        });
    }

    // Auto-hide status messages after 5 seconds
    const statusMessages = document.querySelectorAll('.status.success');
    if (statusMessages.length > 0) {
        setTimeout(() => {
            statusMessages.forEach(msg => {
                msg.style.transition = 'opacity 0.5s ease';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500);
            });
        }, 5000);
    }
});
