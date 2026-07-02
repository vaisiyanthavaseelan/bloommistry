<?php
/**
 * confirm.php
 * Wird aufgerufen, wenn das Café auf den Bestätigungslink aus der Reservierungsmail klickt.
 * Markiert die Reservierung als bestätigt und schickt AUTOMATISCH eine Bestätigungsmail an den Gast.
 */

const GUEST_FROM_EMAIL = 'noreply@bloommistry.de'; // sollte zur eigenen Domain gehören
const DATA_FILE = __DIR__ . '/data/reservations.json';

function renderPage($title, $message, $ok = true) {
    $color = $ok ? '#2E7D4F' : '#C13A64';
    echo "<!DOCTYPE html><html lang=\"de\"><head><meta charset=\"utf-8\">
    <title>$title</title>
    <style>
      body { font-family: 'Jost', Helvetica, sans-serif; background:#FDFAF9; color:#3D2C33; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
      .card { max-width: 440px; text-align:center; padding: 48px 40px; }
      h1 { font-family: Georgia, serif; font-size: 30px; color: $color; margin: 0 0 16px; }
      p { font-size: 16px; line-height: 1.6; color: #7A6470; }
    </style></head><body><div class=\"card\"><h1>$title</h1><p>$message</p></div></body></html>";
}

$token = $_GET['token'] ?? '';

if (!$token || !file_exists(DATA_FILE)) {
    http_response_code(404);
    renderPage('Nicht gefunden', 'Dieser Bestätigungslink ist ungültig oder abgelaufen.', false);
    exit;
}

$all = json_decode(file_get_contents(DATA_FILE), true) ?: [];
$found = false;

foreach ($all as &$res) {
    if ($res['token'] === $token) {
        $found = true;

        if ($res['status'] === 'confirmed') {
            renderPage('Bereits bestätigt', 'Diese Reservierung wurde bereits bestätigt.');
            exit;
        }

        $res['status'] = 'confirmed';
        $res['confirmed_at'] = date('c');

        // Automatische Bestätigungsmail an den Gast
        $subject = "Deine Reservierung bei Bloommistry ist bestätigt";
        $body =
            "Hallo {$res['name']},\n\n" .
            "deine Reservierung im Bloommistry-Café ist bestätigt:\n\n" .
            "Datum: {$res['date']}\n" .
            "Uhrzeit: {$res['time']}\n" .
            "Personen: {$res['guests']}\n\n" .
            "Wir freuen uns auf dich!\n\nDein Bloommistry-Team";

        $headers = "From: Bloommistry Café <" . GUEST_FROM_EMAIL . ">\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n";

        @mail($res['email'], $subject, $body, $headers);
        break;
    }
}
unset($res);

if (!$found) {
    http_response_code(404);
    renderPage('Nicht gefunden', 'Dieser Bestätigungslink ist ungültig oder abgelaufen.', false);
    exit;
}

file_put_contents(DATA_FILE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

renderPage('Reservierung bestätigt', 'Der Gast wurde automatisch per E-Mail benachrichtigt. Danke!');
