<?php
/**
 * Waermepumpe Cloud fuer LoxBerry - Endpunkt fuer den Miniserver
 *
 * Liegt im UNANGEMELDETEN Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist deshalb durch ein Token geschuetzt. Verglichen wird mit
 * hash_equals - ein einfaches == liesse sich ueber die Antwortzeit Zeichen
 * fuer Zeichen erraten.
 *
 *   /plugins/<Ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Aktionen:
 *   status    die Statuszeile fuer einen virtuellen HTTP-Eingang
 *   sgready   den gewuenschten SG-Ready-Zustand setzen
 *             &stufe=1..4   oder   &k1=0|1&k2=0|1
 *   werte     dieselben Werte als JSON, fuer eigene Auswertungen
 *
 * BEWUSST NICHT MOEGLICH: einen Abruf ausloesen. Der Endpunkt liest den
 * Zwischenstand, er holt ihn nicht. Sonst koennte ein zu haeufig pollender
 * virtueller Eingang das Daikin-Tagesbudget leerraeumen oder das
 * MELCloud-Konto aussperren - und zwar ohne dass irgendwo etwas davon steht.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');

require_once dirname(__DIR__) . '/htmlauth/wp_lib.php';

function wp_ende($code, $text)
{
    http_response_code($code);
    echo rtrim($text) . "\n";
    exit;
}

$cfg = wp_config();

/* ---------------- Token ---------------- */
$soll = (string) $cfg['aktionstoken'];
$ist  = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($soll === '' || !hash_equals($soll, $ist)) {
    wp_ende(403, 'WP;OK=0;GRUND=TOKEN');
}

$erlaubte_aktionen = array('status', 'sgready', 'werte');
$aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($aktion, $erlaubte_aktionen, true)) {
    wp_ende(400, "WP;OK=0;GRUND=UNBEKANNTE_AKTION\n"
                 . 'Erlaubt: ' . implode(', ', $erlaubte_aktionen));
}

/* ---------------- status ---------------- */
if ($aktion === 'status') {
    echo wp_zeile(wp_stand(), $cfg) . "\n";
    exit;
}

/* ---------------- werte ---------------- */
if ($aktion === 'werte') {
    header('Content-Type: application/json; charset=utf-8');
    $s = wp_stand();
    echo json_encode(array(
        'ok'     => (int) $s['ok'],
        'zeit'   => (int) $s['zeit'],
        'alter'  => $s['zeit'] > 0 ? time() - (int) $s['zeit'] : -1,
        'stufe'  => (int) ($s['stufe_gesetzt'] ?: $cfg['sg_stufe']),
        'budget' => wp_budget_rest($cfg['hersteller']),
        'werte'  => $s['werte'],
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

/* ---------------- sgready ---------------- */
if (empty($cfg['sg_ein'])) {
    wp_ende(403, 'WP;OK=0;GRUND=SGREADY_AUS');
}

// Zwei Schreibweisen: die Zustandsnummer, oder die beiden Klemmen einzeln.
// Die Klemmen sind der ehrlichere Weg - so haengt es in Loxone am Ende auch
// an zwei Ausgaengen -, aber die Nummer ist bequemer.
$stufe = 0;
if (isset($_GET['stufe'])) {
    $roh = (string) $_GET['stufe'];
    if (!preg_match('/^[1-4]$/', $roh)) {
        wp_ende(400, 'WP;OK=0;GRUND=STUFE_UNGUELTIG');
    }
    $stufe = (int) $roh;
} elseif (isset($_GET['k1']) || isset($_GET['k2'])) {
    /* Die Werte EINMAL einlesen und danach nur noch die Variablen benutzen.
     *
     * Bis 0.9.0 pruefte die Schleife mit einem Ersatzwert, die Zeile danach
     * griff aber unmittelbar auf $_GET['k1'] und $_GET['k2'] zu. Wird nur
     * eine der beiden Klemmen uebergeben - was zulaessig ist, die Bedingung
     * oben laesst es ausdruecklich zu -, meldet PHP:
     *     7.4  Notice:  Undefined index: k2
     *     8.1  Warning: Undefined array key "k2"
     * Beides landet mitten in der Klartextantwort, die Loxone einliest. */
    $klemmen = array();
    foreach (array('k1', 'k2') as $k) {
        if (isset($_GET[$k]) && is_array($_GET[$k])) {
            // ?k1[]=1 ist keine Klemmenangabe, sondern Unfug - und ohne diese
            // Zeile eine "Array to string conversion" mitten in der Antwort.
            wp_ende(400, 'WP;OK=0;GRUND=KLEMME_UNGUELTIG');
        }
        // Nicht uebergeben heisst 0 - die Bedingung oben laesst ausdruecklich
        // zu, dass nur eine der beiden Klemmen genannt wird.
        $v = isset($_GET[$k]) ? (string) $_GET[$k] : '0';
        if (!preg_match('/^[01]$/', $v)) {
            wp_ende(400, 'WP;OK=0;GRUND=KLEMME_UNGUELTIG');
        }
        $klemmen[$k] = (int) $v;
    }
    $stufe = wp_sg_stufe_aus_klemmen($klemmen['k1'], $klemmen['k2']);
} else {
    wp_ende(400, 'WP;OK=0;GRUND=STUFE_FEHLT');
}

$cfg['sg_stufe'] = $stufe;
// Ab jetzt darf gesetzt werden - vorher hat niemand darum gebeten.
$cfg['sg_angefordert'] = 1;
if (!wp_config_write($cfg)) {
    wp_ende(500, 'WP;OK=0;GRUND=NICHT_GESPEICHERT');
}

// Sofort durchsetzen, nicht bis zum naechsten Cron warten: wer per PV-Ueber-
// schuss anlaufen laesst, will nicht bis zu einer Minute Verzug.
list($ok, $grund, $was) = wp_sg_durchsetzen($cfg);
if (!$ok) {
    wp_ende(502, 'WP;OK=0;STUFE=' . $stufe . ';GRUND=' . $grund);
}
list($k1, $k2) = wp_sg_klemmen($stufe);
printf("WP;OK=1;AKTION=sgready;STUFE=%d;K1=%d;K2=%d\n", $stufe, $k1, $k2);
