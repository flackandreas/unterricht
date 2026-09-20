<?php
/**
 * src/includes/mailer.php
 * Versand von Benachrichtigungen per SMTP.
 *
 * Zwei Dinge haben sich hier geaendert.
 *
 * 1. Die Zugangsdaten kommen aus der Umgebung, nicht mehr aus
 *    config/mail.php. Diese Datei lag im Repository und trug die Aufschrift
 *    "REPLACE these place holders with your actual credentials" - eine
 *    Einladung, das Passwort des Schulpostfachs in ein oeffentliches
 *    Repository zu schreiben. Die Datenbankzugaenge werden seit laengerem
 *    ueber die Umgebung gesetzt; hier war es als einziges noch anders.
 *
 * 2. PHPMailer kommt ueber den Autoloader von Composer. Vorher lagen unter
 *    vendor/PHPMailer/ 142 von Hand abgelegte Dateien der Fassung 6.9.1, die
 *    hier direkt eingebunden wurden. composer.json verlangt ^7.0 und
 *    composer.lock haelt 7.1.1 fest - eingebunden wurde trotzdem die alte
 *    Kopie, an Composer vorbei. "composer audit" und "composer update"
 *    sahen sie nie an.
 *
 * Hinweis fuer den Betrieb: send_notification_email() wird in diesem Modul
 * derzeit von keiner Stelle aufgerufen.
 */

require_once __DIR__ . '/../bootstrap.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sendet eine Benachrichtigung an eine Lehrkraft.
 *
 * @return bool true bei Erfolg
 */
function send_notification_email(string $to, string $subject, string $body): bool {
    if ($to === '') {
        error_log('Mailer: kein Empfaenger angegeben, nichts gesendet.');
        return false;
    }

    if (!class_exists(PHPMailer::class)) {
        // Deutlich benennen statt mit einem Fatal Error abzubrechen: das
        // Paket steht in composer.json und in composer.lock, seine Dateien
        // liegen aber nicht im mitgelieferten vendor/-Baum.
        error_log('Mailer: phpmailer/phpmailer ist nicht installiert - "composer install" im Verzeichnis src/ ausfuehren.');
        return false;
    }

    $host = (string) env('SMTP_HOST', '');
    $user = (string) env('SMTP_USER', '');
    $pass = (string) env('SMTP_PASS', '');

    if ($host === '') {
        error_log('Mailer: SMTP_HOST ist nicht gesetzt, nichts gesendet.');
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = (int) env('SMTP_PORT', '587');
        $mail->CharSet = 'UTF-8';

        // Ohne Benutzernamen kein AUTH: ein Relais im Schulnetz verlangt
        // haeufig keines, und ein leeres AUTH weist es ab.
        $mail->SMTPAuth = $user !== '';
        if ($mail->SMTPAuth) {
            $mail->Username = $user;
            $mail->Password = $pass;
        }

        $mail->SMTPSecure = env('SMTP_VERSCHLUESSELUNG', 'tls') === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;

        $mail->setFrom(
            (string) env('SMTP_ABSENDER', 'noreply@localhost'),
            (string) env('SMTP_ABSENDERNAME', (string) env('SCHULNAME', 'Schule'))
        );
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();

        return true;
    } catch (Exception $e) {
        // Protokollieren, aber die Anwendung nicht mitreissen.
        error_log('Mailer: Versand fehlgeschlagen: ' . $mail->ErrorInfo);

        return false;
    }
}
