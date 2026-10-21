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

/* Die Bibliothek wird gesucht, nicht geraten - nach derselben Regel wie in
 * bin/wp_abruf.php, und aus demselben Grund.
 *
 * Im entpackten Archiv liegen html/ und htmlauth/ nebeneinander, auf dem
 * INSTALLIERTEN LoxBerry in getrennten Baeumen:
 *
 *     <LoxBerry-Wurzel>/webfrontend/html/plugins/<ordner>/index.php
 *     <LoxBerry-Wurzel>/webfrontend/htmlauth/plugins/<ordner>/wp_lib.php
 *
 * dirname(__DIR__) ergibt dort .../webfrontend/html/plugins - gesucht wurde
 * bis 0.9.9 also .../html/plugins/htmlauth/wp_lib.php. Die gibt es nicht. Weil
 * display_errors oben schon auf 0 steht, endete der Aufruf mit HTTP 500 und
 * LEEREM Rumpf. Fehlerklasse Heimkino/Docker-NG.
 *
 * Welche Lage gilt, entscheidet seit 0.9.23 der EIGENE Ablageort: liegt diese
 * Datei unter .../plugins/<ordner>, ist sie installiert, sonst liegt sie in
 * einem ausgepackten Archiv. Bis 0.9.22 wurden drei Kandidaten der Reihe nach
 * probiert; aus einem Archiv unter der Laufwerkswurzel war der zweite
 * /htmlauth/plugins/html/wp_lib.php, und was dort lag, lief als Bibliothek
 * (in WSL gemessen, Pruefung-WaermepumpeCloud-0.9.23, Fall T5). Bauart
 * ZendureSolarFlow 0.9.26.
 */
if (basename(dirname(__DIR__)) === 'plugins') {
    $wp_kandidaten = array(dirname(dirname(dirname(__DIR__)))
                     . '/htmlauth/plugins/' . basename(__DIR__) . '/wp_lib.php');
} else {
    $wp_kandidaten = array(dirname(__DIR__) . '/htmlauth/wp_lib.php');
}

$wp_lib = '';
foreach ($wp_kandidaten as $wp_kand) {
    if (is_file($wp_kand)) { $wp_lib = $wp_kand; break; }
}
if ($wp_lib === '') {
    /* Nicht stillschweigend sterben. Loxone liest diese Zeile, und ein
     * benannter Fehler ist dort etwas voellig anderes als ein leerer Rumpf. */
    http_response_code(500);
    echo "WP;OK=0;GRUND=BIBLIOTHEK_FEHLT\n";
    /* Die Suchpfade gehoeren NICHT in den Rumpf. Dieser Endpunkt ist
     * unangemeldet erreichbar - an dieser Stelle sogar noch vor der
     * Tokenpruefung. Ein absoluter Pfad ist eine Auskunft ueber das System,
     * und wer hier klopft, braucht sie nicht. Wer das Plugin repariert,
     * findet sie im Fehlerprotokoll des Webservers; wp_log() steht hier
     * nicht zur Verfuegung - es fehlt ja gerade die Bibliothek. */
    error_log('Waermepumpe: wp_lib.php nicht gefunden. Gesucht: '
              . implode(', ', $wp_kandidaten));
    exit;
}
require_once $wp_lib;

function wp_ende($code, $text)
{
    http_response_code($code);
    echo rtrim($text) . "\n";
    exit;
}

/* NUR LESEN. Der unangemeldete Bereich legt kein Token an und schreibt
 * ueberhaupt nichts - siehe die Begruendung an wp_config(). */
$cfg = wp_config(false);

$soll = (string) $cfg['aktionstoken'];
$ist  = isset($_GET['token']) && !is_array($_GET['token']) ? (string) $_GET['token'] : '';

/* ---------------- Selbsttest ----------------
 *
 * Ein Token muss sich pruefen lassen, OHNE dass etwas passiert.
 *
 * Ohne diesen Zweig gibt es nur zwei schlechte Moeglichkeiten: entweder man
 * setzt wirklich einen SG-Ready-Zustand ab - dann faehrt die Waermepumpe um -,
 * oder man erfaehrt nie, ob die Adresse im Miniserver noch stimmt. Beides ist
 * unbrauchbar, wenn man eine Anlage pruefen will.
 *
 * Steht bewusst VOR der Token-Abweisung, weil er den Fall "gar kein Token
 * eingerichtet" von "falsches Token" unterscheiden koennen muss. Die drei
 * Antworten sind woertlich die des Hausstandards - sie werden von aussen
 * ausgewertet und sind deshalb kein Ort fuer eigene Formulierungen.
 */
if (isset($_GET['selftest'])) {
    if ($soll === '') {
        wp_ende(403, 'SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET');
    }
    if (!hash_equals($soll, $ist)) {
        wp_ende(403, 'SELFTEST;OK=0;ERR=TOKEN');
    }
    wp_ende(200, 'SELFTEST;OK=1;TOKEN=OK');
}

/* ---------------- Token ---------------- */
if ($soll === '' || !hash_equals($soll, $ist)) {
    wp_ende(403, 'WP;OK=0;GRUND=TOKEN');
}

$erlaubte_aktionen = array('status', 'sgready', 'werte', 'ww_boost');
// is_array wie bei k1/k2: ?aktion[]=x waere sonst eine "Array to string
// conversion" - unsichtbar, weil display_errors oben auf 0 steht, aber im
// Fehlerprotokoll des Webservers bei jedem Aufruf.
$aktion = isset($_GET['aktion']) && !is_array($_GET['aktion'])
        ? (string) $_GET['aktion'] : 'status';
if (!in_array($aktion, $erlaubte_aktionen, true)) {
    wp_ende(400, "WP;OK=0;GRUND=UNBEKANNTE_AKTION\n"
                 . 'Erlaubt: ' . implode(', ', $erlaubte_aktionen));
}

/* ---------------- noch nie Daten? ----------------
 *
 * Regeln/07: gibt es noch gar keine Daten, antwortet der Endpunkt mit
 * HTTP 503 und nennt den Grund - nicht mit 200 und OK=0. Loxone schaltet dann
 * den Onlinestatus des Eingangs ab, und der Zustand ist sichtbar.
 *
 * Bis 0.9.19 kam hier 200 mit ALTER=86400. Am Geraet gemessen (17.09.2026,
 * seit 08.09. kein einziger erfolgreicher Abruf): die Statuszeile sagte
 * ALTER=86400, dieselbe Anfrage mit aktion=werte sagte "alter":-1 - zwei
 * Zahlen fuer denselben Sachverhalt, und die erste war erfunden.
 *
 * Nach dem ersten Erfolg bleibt es bei 200: dann gibt es einen Stand, OK=0
 * und ein wachsendes ALTER sind eine Aussage (Schritt 5 im Reiter
 * "Einbindung in Loxone"). */
$wp_s = wp_stand();
if (($aktion === 'status' || $aktion === 'werte') && (int) $wp_s['zeit'] <= 0) {
    $wp_grund = (string) (isset($wp_s['fehler_letzt']) && $wp_s['fehler_letzt'] !== ''
        ? $wp_s['fehler_letzt'] : 'NOCH_KEIN_ABRUF');
    $wp_grund = preg_replace('/[^A-Z0-9_]/', '_', strtoupper($wp_grund));
    if ($aktion === 'werte') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(503);
        echo json_encode(array('ok' => 0, 'grund' => 'NOCH_KEINE_DATEN',
            'fehler' => $wp_grund, 'stoerung' => (int) $wp_s['fehler_folge'])) . "\n";
        exit;
    }
    wp_ende(503, 'WP;OK=0;GRUND=NOCH_KEINE_DATEN;FEHLER=' . $wp_grund
                 . ';STOERUNG=' . (int) $wp_s['fehler_folge']);
}

/* ---------------- status ---------------- */
if ($aktion === 'status') {
    echo wp_zeile($wp_s, $cfg) . "\n";
    exit;
}

/* ---------------- werte ---------------- */
if ($aktion === 'werte') {
    header('Content-Type: application/json; charset=utf-8');
    $s = wp_stand();
    /* OK und STUFE aus derselben Quelle wie Statuszeile und MQTT (Bauliste C4,
     * C5): OK=0 ab dem Dreifachen des Takts, STUFE die wirklich gesetzte oder
     * "-". Bis 0.9.26 stand hier die angeforderte Stufe. "alter" bleibt. */
    $wp_a = wp_ausgabewerte($s, $cfg);
    echo json_encode(array(
        'ok'     => (int) $wp_a['OK'],
        'zeit'   => (int) $s['zeit'],
        'alter'  => $s['zeit'] > 0 ? time() - (int) $s['zeit'] : -1,
        'stufe'  => $wp_a['STUFE'],
        'budget' => wp_budget_rest($cfg['hersteller']),
        'werte'  => $s['werte'],
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

/* ---------------- ww_boost und sgready ----------------
 * Gruppe 4 / MQTT (0.9.35, Entwurf wp 3.2): die Pruefung steht in wp_befehl_ausfuehren() (wp_lib.php); dieselbe
 * Funktion ruft die Befehlsbruecke (bin/befehle_zuordnung.php, Quelle 'mqtt'). Antwort, Code und Protokoll dieses
 * Endpunkts sind unveraendert (Wirkungstest Quelle gegen Bau): ww_boost 501 NICHT_UNTERSTUETZT vor allem anderen,
 * EIN_UNGUELTIG (auch ein Feld ?ein[]=), UNVERAENDERT binnen 60 s, 502 mit dem Grund; sgready 403 SGREADY_AUS vor
 * den Stufenfehlern, STUFE_UNGUELTIG, KLEMME_UNGUELTIG, STUFE_FEHLT, X-7 UNVERAENDERT, 500 NICHT_GESPEICHERT, 502.
 * Hier bleiben nur das Einlesen der Abfrage und die Klemmenform k1/k2 (Endpunkt, nicht MQTT).
 *
 * ww_boost ist bewusst NICHT an sg_ein gebunden: wer SG Ready abgeschaltet hat, kann den Speicher trotzdem laden
 * wollen. */
if ($aktion === 'ww_boost') {
    /* Nicht uebergeben heisst ein=1 (wie bis 0.9.34); ein Feld ?ein[]=... ist ungueltig wie jeder andere Wert. */
    $wp_par = array();
    if (isset($_GET['ein'])) { $wp_par['ein'] = is_array($_GET['ein']) ? '' : (string) $_GET['ein']; }
    list($wp_code, $wp_zeile) = wp_befehl_ausfuehren('ww_boost', $wp_par, 'http');
    wp_ende($wp_code, $wp_zeile);
}

/* ---------------- sgready ---------------- */
/* SGREADY_AUS kommt VOR den Fehlern der Stufe (wie bis 0.9.34) - die Funktion prueft es fuer den MQTT-Weg ebenso. */
if (empty($cfg['sg_ein'])) {
    wp_ende(403, 'WP;OK=0;GRUND=SGREADY_AUS');
}

// Zwei Schreibweisen: die Zustandsnummer, oder die beiden Klemmen einzeln.
// Die Klemmen sind der ehrlichere Weg - so haengt es in Loxone am Ende auch
// an zwei Ausgaengen -, aber die Nummer ist bequemer.
if (isset($_GET['stufe'])) {
    // Ein Feld (?stufe[]=) ist so ungueltig wie jede falsche Stufe: STUFE_UNGUELTIG aus der Funktion.
    $wp_roh = is_array($_GET['stufe']) ? '' : (string) $_GET['stufe'];
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
    $wp_roh = (string) wp_sg_stufe_aus_klemmen($klemmen['k1'], $klemmen['k2']);
} else {
    wp_ende(400, 'WP;OK=0;GRUND=STUFE_FEHLT');
}

/* Befehlsbremse X-7, Schreiben nur bei einer Aenderung, sofortiges Durchsetzen mit Wiederholregel: alles in
 * wp_befehl_ausfuehren(), Antwort wortgleich wie bis 0.9.34. */
list($wp_code, $wp_zeile) = wp_befehl_ausfuehren('sgready', array('stufe' => $wp_roh), 'http');
wp_ende($wp_code, $wp_zeile);
