<?php
/**
 * reserve.php
 * Empfängt eine Café-Reservierungsanfrage per POST (JSON) vom Formular auf der Webseite,
 * speichert sie und schickt eine Mail an das Café mit einem Bestätigungslink.
 * Erst wenn das Café auf diesen Link klickt (confirm.php), gilt die Reservierung als bestätigt
 * und der Gast bekommt automatisch eine Bestätigungsmail.
 *
 * VORAUSSETZUNGEN FÜR DEN EINSATZ:
 * - Ein Webhosting mit PHP UND funktionierendem mail()/SMTP (die meisten Shared-Hoster
 *   unterstützen das; lokal mit `php -S` funktioniert mail() NICHT ohne extra Konfiguration).
 * - Der Ordner "data/" muss für PHP beschreibbar sein (chmod 755/775).
 * - CAFE_EMAIL unten auf die echte Café-Adresse setzen.
 * - SITE_URL unten auf die echte Domain setzen (für den Bestätigungslink).
 */

header('Content-Type: application/json; charset=utf-8');

// ---- Konfiguration ----
const CAFE_EMAIL = 'hallo@bloommistry.de';   // Café-Postfach, das Anfragen bekommt
const SITE_URL    = 'https://www.bloommistry.de/backend'; // eure echte Domain + Pfad zu diesem Ordner, ohne Slash am Ende
const FROM_EMAIL  = 'noreply@bloommistry.de'; // Absenderadresse (sollte zur eigenen Domain gehören)
const DATA_FILE   = __DIR__ . '/data/reservations.json';

// Nur POST erlauben
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Eingehende Daten lesen (JSON-Body)
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    // Fallback: normales Formular (application/x-www-form-urlencoded)
    $input = $_POST;
}

$name   = trim($input['name']   ?? '');
$date   = trim($input['date']   ?? '');
$time   = trim($input['time']   ?? '');
$guests = trim($input['guests'] ?? '');
$email  = trim($input['email']  ?? '');

// Minimal-Validierung
$errors = [];
if ($name === '')  $errors[] = 'Name fehlt';
if ($date === '')  $errors[] = 'Datum fehlt';
if ($time === '')  $errors[] = 'Uhrzeit fehlt';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Gültige E-Mail fehlt';

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => implode(', ', $errors)]);
    exit;
}

// Reservierung anlegen
$token = bin2hex(random_bytes(16));
$reservation = [
    'token'      => $token,
    'name'       => $name,
    'date'       => $date,
    'time'       => $time,
    'guests'     => $guests,
    'email'      => $email,
    'status'     => 'pending', // pending -> confirmed
    'created_at' => date('c'),
];

// In data/reservations.json speichern (einfacher Datei-Speicher; für mehr Traffic besser eine DB nutzen)
if (!is_dir(dirname(DATA_FILE))) {
    mkdir(dirname(DATA_FILE), 0775, true);
}
$all = [];
if (file_exists(DATA_FILE)) {
    $all = json_decode(file_get_contents(DATA_FILE), true) ?: [];
}
$all[] = $reservation;
file_put_contents(DATA_FILE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Bestätigungslink fürs Café
$confirmLink = SITE_URL . '/confirm.php?token=' . urlencode($token);

// Mail ans Café (im Hintergrund, ohne dass der Gast ein Mailprogramm öffnen muss)
$subject = "Neue Café-Reservierung: $date um $time Uhr";
$body =
    "Neue Reservierungsanfrage über die Webseite:\n\n" .
    "Name: $name\n" .
    "Datum: $date\n" .
    "Uhrzeit: $time\n" .
    "Personen: $guests\n" .
    "E-Mail des Gasts: $email\n\n" .
    "Zum Bestätigen anklicken (der Gast bekommt dann automatisch eine Bestätigungsmail):\n" .
    "$confirmLink\n";

$headers = "From: Bloommistry Website <" . FROM_EMAIL . ">\r\n" .
           "Reply-To: $email\r\n" .
           "Content-Type: text/plain; charset=UTF-8\r\n";

$mailSent = @mail(CAFE_EMAIL, $subject, $body, $headers);

if (!$mailSent) {
    // mail() ist auf vielen lokalen/Test-Umgebungen nicht konfiguriert.
    // Die Reservierung wurde trotzdem gespeichert; ein Admin kann sie manuell bestätigen.
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'mail_sent' => false,
        'note' => 'Reservierung gespeichert, aber der Mailversand ist auf diesem Server nicht konfiguriert (mail()/SMTP prüfen).',
    ]);
    exit;
}

echo json_encode(['ok' => true, 'mail_sent' => true]);
