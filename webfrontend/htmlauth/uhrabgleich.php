<?php
/**
 * Gemeinsamer Uhrabgleich fuer die LoxBerry-Plugins des Hauses (ENTWURF 1.0.0)
 *
 * DIESE DATEI IST IN MEHREREN PLUGINS BYTEWEISE GLEICH.
 * Die Stammfassung liegt unter Werkzeuge/gemeinsam/uhrabgleich.php im
 * Arbeitsordner. Ein LoxBerry-Plugin kann keine Datei eines anderen Plugins
 * laden; deshalb traegt jede Linie eine Abschrift, und
 * Werkzeuge/gemeinsam_pruefen.py meldet jede Abschrift, die von der
 * Stammfassung abweicht (--verteilen bringt die Stammfassung hinein). Wer hier
 * etwas aendert, aendert es in der Stammfassung, hebt UHR_FASSUNG an, laesst
 * uhr_selbsttest() und die Eichung laufen und verteilt danach. Geaendert wird
 * nur ergaenzend: keine Funktion entfernen oder umbenennen, keine Bedeutung
 * eines Parameters oder einer Rueckgabe aendern.
 *
 * Herkunft: Pruefzeile "Ist die Systemuhr synchron?" aus Alexa-NG 1.0.20
 * (ax_uhr_merken(), ax_uhr_lesen(), ax_uhr_pruefzeile()); Entscheidung des
 * Hausherrn zu Frage 95: ins gemeinsame Modul fuer alle Linien mit
 * Cloud-Anmeldung.
 *
 * ------------------------------------------------------------------
 * Was die Datei tut - und was nicht
 * ------------------------------------------------------------------
 *
 *   - Sie liest die Kopfzeile Date einer Antwort der Cloud (nur die Form
 *     IMF-fixdate "Thu, 19 Nov 2026 10:00:00 GMT"; jede andere Form gilt
 *     als "keine Messung", nie als Wert).
 *   - Sie merkt sich die Abweichung der Systemuhr gegen diese Kopfzeile in
 *     <datenordner>/uhr.json (0644, unteilbar geschrieben), nur in einen
 *     VORHANDENEN Ordner, den die Linie nennt. Felder: abweichung_s, zeit,
 *     quelle, fassung - abweichung_s und zeit wie in Alexa-NG 1.0.20, damit
 *     eine dort geschriebene Datei weiter gilt.
 *   - Sie baut die Zeile fuer den Reiter Test: Rueckgabe array(stand, text)
 *     mit stand 1 (Haken, Abweichung hoechstens UHR_GRENZE_S), 2 (gelb,
 *     Hinweis) oder -1 (nicht feststellbar). Nie 0: die Zeile meldet, sie
 *     blockiert nichts.
 *   - timedatectl (NTPSynchronized) steht nur als Hinweis im Text; es aendert
 *     den Stand nie (am 18.11.2026 meldete es am LoxBerry "no", waehrend die
 *     Uhr auf 1 s mit Amazon uebereinstimmte).
 *
 *   NICHT hier: der Abruf selbst, das Protokoll, das Stellen der Uhr. Die
 *   Datei schreibt in kein Protokoll und legt keinen Ordner an.
 *
 * ------------------------------------------------------------------
 * Aufrufe
 * ------------------------------------------------------------------
 *
 *   curl:  $datum = 0;
 *          curl_setopt($ch, CURLOPT_HEADERFUNCTION, uhr_kopf_rueckruf($datum));
 *          (eine schon vorhandene Rueckruffunktion der Linie als zweites
 *          Argument: uhr_kopf_rueckruf($datum, $eigene) - ihr Rueckgabewert
 *          gilt)
 *   Strom: $datum = uhr_aus_koepfen($http_response_header);
 *          (oder eine Liste "name: wert", oder ein Feld klein => wert)
 *   dann:  uhr_merken($datenordner, $datum, $dauer_ms, 'api.beispiel.dienst');
 *   Test:  list($stand, $text) = uhr_pruefzeile(array(
 *              'ordner' => $datenordner, 'dienst' => 'Amazon',
 *              't' => function ($schluessel) { return xx_t($schluessel); }));
 *          frage: uhr_frage(array('t' => ...)); Stand der Linie ueber den Namen:
 *          uhr_art($stand) -> 'ok' | 'hinweis' | 'nicht_feststellbar'; Zeichen der Zeile fuer alle Linien
 *          gleich: uhr_zeichen($stand) (Haken, gelber Punkt, grauer Strich)
 *
 *   Wahlfreie Schluessel von uhr_pruefzeile(): 'abschnitt' (Vorgabe UHR),
 *   'grenze_s' (1 bis 3600, Vorgabe UHR_GRENZE_S), 'hoechstalter_s' (0 = aus,
 *   ab Werk aus; sonst 60 bis 31536000), nur fuer den Selbsttest 'jetzt' und
 *   'ntp' (function () -> 'yes'|'no'|'').
 *
 * Die Texte stehen im Abschnitt [UHR] der Sprachdateien und kommen aus
 * Werkzeuge/gemeinsam/uhrabgleich_texte.json (Schluesselmenge =
 * uhr_sprachschluessel()).
 */

/* Kein Endpunkt (Regeln/03): die Datei liegt meist im unangemeldeten Baum
 * webfrontend/html/ und waere dort direkt aufrufbar. Ein direkter Aufruf ueber
 * den Webserver bekommt 403 und tut sonst nichts. */
if (PHP_SAPI !== 'cli') {
    $uhr_einstieg = get_included_files();
    if (isset($uhr_einstieg[0]) && realpath($uhr_einstieg[0]) === realpath(__FILE__)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "UHR;OK=0;GRUND=KEIN_ENDPUNKT\n";
        exit;
    }
    unset($uhr_einstieg);
}

if (!defined('UHR_FASSUNG')) {

define('UHR_FASSUNG', '1.0.0');
/** Ab dieser Abweichung (Sekunden, Betrag) wird die Zeile gelb. Entscheidung Frage 95: 30 s. */
define('UHR_GRENZE_S', 30);
/** Neu geschrieben wird nur bei einer Aenderung um mindestens so viele Sekunden ... */
define('UHR_SCHREIB_DELTA_S', 2);
/** ... oder wenn der letzte Eintrag aelter ist als so viele Sekunden (wie Alexa-NG 1.0.20). */
define('UHR_SCHREIB_ALTER_S', 600);
/** Name der Ablage im Datenordner der Linie (wie Alexa-NG 1.0.20). */
define('UHR_DATEI', 'uhr.json');
/** Laengste Anfragedauer, die noch als Messung zaehlt (Millisekunden). */
define('UHR_DAUER_MAX_MS', 600000);
/** Zeitgrenze fuer timedatectl (Sekunden). */
define('UHR_NTP_TMO', 5);

/** Die Kennungen, zu denen ein Satz im Abschnitt [UHR] stehen muss. */
function uhr_sprachschluessel()
{
    return array('F_UHR', 'A_UHR_NIE', 'A_UHR_OK', 'A_UHR_ABWEICHUNG', 'A_UHR_ZUKUNFT', 'A_UHR_ALT',
                 'A_UHR_KAPUTT', 'A_UHR_AUFRUF', 'A_UHR_NTP', 'A_UHR_NTP_UNBEKANNT',
                 'D_S', 'D_MIN', 'D_H', 'D_TAG');
}

/** Wie viele Platzhalter (%s, %d) jeder Satz traegt - in de und en gleich. */
function uhr_platzhalter()
{
    return array('F_UHR' => 0, 'A_UHR_NIE' => 2, 'A_UHR_OK' => 3, 'A_UHR_ABWEICHUNG' => 4, 'A_UHR_ZUKUNFT' => 2,
                 'A_UHR_ALT' => 2, 'A_UHR_KAPUTT' => 1, 'A_UHR_AUFRUF' => 1, 'A_UHR_NTP' => 1,
                 'A_UHR_NTP_UNBEKANNT' => 0, 'D_S' => 1, 'D_MIN' => 1, 'D_H' => 1, 'D_TAG' => 1);
}

/**
 * Den Wert einer Kopfzeile Date lesen. Nur IMF-fixdate (RFC 9110, 5.6.7):
 * "Thu, 19 Nov 2026 10:00:00 GMT". Der Wochentag muss zum Datum passen.
 * Rueckgabe: Unixzeit, oder 0, wenn der Wert diese Form nicht hat.
 */
function uhr_datum_lesen($wert)
{
    if (!is_string($wert)) { return 0; }
    $tage = array('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun');
    $monate = array('Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
                    'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12);
    if (!preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), ([0-9]{2}) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) ([0-9]{4}) ([0-9]{2}):([0-9]{2}):([0-9]{2}) GMT\z/',
                    trim($wert), $m)) {
        return 0;
    }
    $tag = (int) $m[2]; $mon = $monate[$m[3]]; $jahr = (int) $m[4];
    $h = (int) $m[5]; $i = (int) $m[6]; $s = (int) $m[7];
    if ($jahr < 2000 || $h > 23 || $i > 59 || $s > 59 || !checkdate($mon, $tag, $jahr)) { return 0; }
    $t = gmmktime($h, $i, $s, $mon, $tag, $jahr);
    if (!is_int($t) || $t <= 0) { return 0; }
    if (gmdate('D', $t) !== $m[1] || !in_array($m[1], $tage, true)) { return 0; }
    return $t;
}

/** Eine einzelne Kopfzeile ("Date: ..."); Rueckgabe Unixzeit oder 0. */
function uhr_kopfzeile($zeile)
{
    if (!is_string($zeile) || !preg_match('/^Date:[ \t]*(.*?)[ \t\r\n]*\z/i', $zeile, $m)) { return 0; }
    return uhr_datum_lesen($m[1]);
}

/**
 * Rueckruf fuer CURLOPT_HEADERFUNCTION. Setzt $datum auf die Kopfzeile Date
 * der LETZTEN Antwort (eine Statuszeile HTTP/... setzt zurueck: bei einer
 * Weiterleitung zaehlt nur die Antwort, die ankommt). Mit $weiter ruft er die
 * Rueckruffunktion der Linie mit und gibt deren Rueckgabe weiter.
 */
function uhr_kopf_rueckruf(&$datum, $weiter = null)
{
    $datum = 0;
    return function ($ch, $zeile) use (&$datum, $weiter) {
        if (is_string($zeile)) {
            if (strncasecmp($zeile, 'HTTP/', 5) === 0) {
                $datum = 0;
            } else {
                $t = uhr_kopfzeile($zeile);
                if ($t > 0) { $datum = $t; }
            }
        }
        if ($weiter !== null && is_callable($weiter)) { return call_user_func($weiter, $ch, $zeile); }
        return is_string($zeile) ? strlen($zeile) : 0;
    };
}

/**
 * Date aus einer Menge von Kopfzeilen: eine Liste wie $http_response_header
 * (Statuszeilen und "name: wert"; es zaehlt die letzte Antwort) oder ein Feld
 * name => wert (Name gleich welcher Schreibung). Rueckgabe Unixzeit oder 0.
 */
function uhr_aus_koepfen($koepfe)
{
    if (!is_array($koepfe) || !$koepfe) { return 0; }
    $datum = 0;
    foreach ($koepfe as $n => $w) {
        if (is_string($n)) {
            if (strcasecmp($n, 'date') === 0) { $datum = uhr_datum_lesen(is_string($w) ? $w : ''); }
            continue;
        }
        if (!is_string($w)) { continue; }
        if (strncasecmp($w, 'HTTP/', 5) === 0) { $datum = 0; continue; }
        $t = uhr_kopfzeile($w);
        if ($t > 0) { $datum = $t; }
    }
    return $datum;
}

/** Abweichung in Sekunden: Mitte der Anfrage minus Date. Positiv: die Systemuhr geht vor. */
function uhr_abweichung($datum, $dauer_ms, $jetzt)
{
    $mitte = (int) $jetzt - (int) round(((int) $dauer_ms) / 2000);
    return $mitte - (int) $datum;
}

/** Ein Herkunftsname wie "alexa.amazon.de": nur Buchstaben, Ziffern, Punkt, Strich, hoechstens 253 Zeichen. */
function uhr_quelle_ok($q)
{
    return is_string($q) && preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}\z/', $q) === 1;
}

/** Der Pfad der Ablage zu einem Datenordner. */
function uhr_pfad($ordner)
{
    return rtrim((string) $ordner, '/\\') . '/' . UHR_DATEI;
}

/**
 * Die Abweichung zu einer Antwort merken. Rueckgabe eine Kennung:
 * 'geschrieben', 'unveraendert', 'kein_datum', 'dauer_ungueltig',
 * 'quelle_ungueltig', 'kein_ordner', 'schreibfehler'. Wirft nie.
 * $o: 'jetzt' (nur Selbsttest), 'rechte' (Vorgabe 0644).
 */
function uhr_merken($ordner, $datum, $dauer_ms, $quelle, array $o = array())
{
    if (!is_int($datum) || $datum <= 0) { return 'kein_datum'; }
    if (!is_int($dauer_ms) || $dauer_ms < 0 || $dauer_ms > UHR_DAUER_MAX_MS) { return 'dauer_ungueltig'; }
    if (!uhr_quelle_ok($quelle)) { return 'quelle_ungueltig'; }
    if (!is_string($ordner) || $ordner === '' || !is_dir($ordner)) { return 'kein_ordner'; }
    $jetzt = isset($o['jetzt']) && is_int($o['jetzt']) ? $o['jetzt'] : time();
    $ab = uhr_abweichung($datum, $dauer_ms, $jetzt);
    $f = uhr_pfad($ordner);
    $alt = uhr_lesen($ordner);
    if (is_array($alt) && $alt['quelle'] === $quelle && abs($alt['abweichung_s'] - $ab) < UHR_SCHREIB_DELTA_S
        && $jetzt - $alt['zeit'] < UHR_SCHREIB_ALTER_S && $jetzt >= $alt['zeit']) {
        return 'unveraendert';
    }
    $json = json_encode(array('abweichung_s' => $ab, 'zeit' => $jetzt, 'quelle' => $quelle, 'fassung' => UHR_FASSUNG),
                        JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) { return 'schreibfehler'; }
    $tmp = $f . '.' . getmypid() . '.tmp';
    $rechte = isset($o['rechte']) && is_int($o['rechte']) ? $o['rechte'] : 0644;
    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) { @unlink($tmp); return 'schreibfehler'; }
    @chmod($tmp, $rechte);
    if (!@rename($tmp, $f)) { @unlink($tmp); return 'schreibfehler'; }
    return 'geschrieben';
}

/**
 * Die zuletzt gemerkte Abweichung. Rueckgabe null (keine Ablage), false
 * (Ablage da, aber nicht lesbar oder nicht in der Form) oder
 * array('abweichung_s' => int, 'zeit' => int, 'quelle' => string).
 */
function uhr_lesen($ordner)
{
    if (!is_string($ordner) || $ordner === '') { return null; }
    $f = uhr_pfad($ordner);
    if (!is_file($f)) { return null; }
    $roh = @file_get_contents($f);
    $d = is_string($roh) ? json_decode($roh, true) : null;
    if (!is_array($d) || !isset($d['abweichung_s'], $d['zeit']) || !is_int($d['abweichung_s']) || !is_int($d['zeit'])
        || $d['zeit'] <= 0) {
        return false;
    }
    $q = isset($d['quelle']) && uhr_quelle_ok($d['quelle']) ? $d['quelle'] : '';
    return array('abweichung_s' => $d['abweichung_s'], 'zeit' => $d['zeit'], 'quelle' => $q);
}

/** timedatectl show -p NTPSynchronized --value: 'yes', 'no' oder '' (nicht lesbar). Nur Hinweis. */
function uhr_ntp()
{
    if (DIRECTORY_SEPARATOR === '\\' || !function_exists('proc_open')) { return ''; }
    $rohr = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $p = @proc_open(array('timedatectl', 'show', '-p', 'NTPSynchronized', '--value'), $rohr, $r);
    if (!is_resource($p)) { return ''; }
    stream_set_blocking($r[1], false);
    $aus = '';
    $ende = microtime(true) + UHR_NTP_TMO;
    while (microtime(true) < $ende) {
        $lesen = array($r[1]); $w = null; $e = null;
        if (@stream_select($lesen, $w, $e, 0, 200000) > 0) {
            $teil = fread($r[1], 64);
            if ($teil === false || $teil === '') { if (feof($r[1])) { break; } } else { $aus .= $teil; }
        }
        if (strlen($aus) > 64) { break; }
        $st = proc_get_status($p);
        if (!$st['running'] && feof($r[1])) { break; }
    }
    $st = proc_get_status($p);
    if ($st['running']) { @proc_terminate($p); }
    fclose($r[1]); fclose($r[2]);
    $rc = proc_close($p);
    $w = trim($aus);
    if (isset($st['exitcode']) && !$st['running'] && $st['exitcode'] !== -1) { $rc = $st['exitcode']; }
    return ($rc === 0 && ($w === 'yes' || $w === 'no')) ? $w : '';
}

/** Text zu einer Kennung ueber den Uebersetzer der Linie; ohne Uebersetzer der Schluessel selbst. */
function uhr_t($id, array $k)
{
    $ab = isset($k['abschnitt']) && is_string($k['abschnitt']) && $k['abschnitt'] !== '' ? $k['abschnitt'] : 'UHR';
    $s = $ab . '.' . $id;
    if (isset($k['t']) && is_callable($k['t'])) { return (string) call_user_func($k['t'], $s); }
    return $s;
}

/** Die Frage der Zeile ("Ist die Systemuhr synchron?"). */
function uhr_frage(array $k)
{
    return uhr_t('F_UHR', $k);
}

/**
 * Der Stand als Name: 'ok', 'hinweis' oder 'nicht_feststellbar'. Die Linien zaehlen ihre Staende
 * verschieden (gemessen 19.11.2026: Alexa-NG 2 = gelb, Heimkino 2 = "?" und jeder andere Wert ein
 * rotes Kreuz, VolkswagenID bildet 2 auf -1 ab) - eine Linie uebersetzt deshalb ueber den Namen,
 * nie ueber die Zahl.
 */
function uhr_art($stand)
{
    if ($stand === 1) { return 'ok'; }
    if ($stand === 2) { return 'hinweis'; }
    return 'nicht_feststellbar';
}

/**
 * Das Zeichen der Zeile im Reiter Test, fuer alle Linien gleich (Entscheidung des Hausherrn zu Frage 110: der gelbe
 * Stand gehoert ins Modul). Haken in der Hausklasse sm-an, der gelbe Punkt und der graue Strich mit eigener Farbe,
 * weil der Hausstandard keine Klasse dafuer kennt (gemessen 19.11.2026: VORLAGE_hausstandard.css.html fuehrt sm-an
 * und sm-aus, nichts fuer gelb). Rueckgabe HTML, ohne Eingaben des Aufrufers - es gibt nichts zu maskieren.
 */
function uhr_zeichen($stand)
{
    $art = uhr_art($stand);
    if ($art === 'ok') { return '<span class="sm-an">&#10004;</span>'; }
    if ($art === 'hinweis') { return '<span style="color:#e0a000;font-weight:700;">&#9679;</span>'; }
    return '<span style="color:#888;">&#8211;</span>';
}

/** "vor 5 s", "vor 3 min", "vor 2 h", "vor 4 Tagen" (Stufen wie ax_dauer_text() in Alexa-NG). */
function uhr_dauer_text($sek, array $k)
{
    $sek = max(0, (int) $sek);
    if ($sek < 90) { return sprintf(uhr_t('D_S', $k), $sek); }
    if ($sek < 5400) { return sprintf(uhr_t('D_MIN', $k), (int) round($sek / 60)); }
    if ($sek < 172800) { return sprintf(uhr_t('D_H', $k), (int) round($sek / 3600)); }
    return sprintf(uhr_t('D_TAG', $k), (int) round($sek / 86400));
}

/**
 * Die Zeile "Ist die Systemuhr synchron?" fuer den Reiter Test.
 * Rueckgabe array(stand, text): 1 Haken, 2 gelb (Hinweis), -1 nicht feststellbar.
 */
function uhr_pruefzeile(array $k)
{
    $ntp = isset($k['ntp']) && is_callable($k['ntp']) ? (string) call_user_func($k['ntp']) : uhr_ntp();
    $ntp_t = sprintf(uhr_t('A_UHR_NTP', $k), ($ntp === 'yes' || $ntp === 'no') ? $ntp : uhr_t('A_UHR_NTP_UNBEKANNT', $k));
    $fehl = array();
    if (!isset($k['ordner']) || !is_string($k['ordner']) || $k['ordner'] === '') { $fehl[] = 'ordner'; }
    if (!isset($k['dienst']) || !is_string($k['dienst']) || trim($k['dienst']) === '' || preg_match('/[\x00-\x1f]/', $k['dienst'])) { $fehl[] = 'dienst'; }
    $grenze = UHR_GRENZE_S;
    if (array_key_exists('grenze_s', $k)) {
        if (!is_int($k['grenze_s']) || $k['grenze_s'] < 1 || $k['grenze_s'] > 3600) { $fehl[] = 'grenze_s'; } else { $grenze = $k['grenze_s']; }
    }
    $hoechst = 0;
    if (array_key_exists('hoechstalter_s', $k)) {
        if (!is_int($k['hoechstalter_s']) || ($k['hoechstalter_s'] !== 0 && ($k['hoechstalter_s'] < 60 || $k['hoechstalter_s'] > 31536000))) {
            $fehl[] = 'hoechstalter_s';
        } else {
            $hoechst = $k['hoechstalter_s'];
        }
    }
    if ($fehl) { return array(-1, sprintf(uhr_t('A_UHR_AUFRUF', $k), implode(', ', $fehl)) . ' ' . $ntp_t); }
    $dienst = $k['dienst'];
    $jetzt = isset($k['jetzt']) && is_int($k['jetzt']) ? $k['jetzt'] : time();
    $u = uhr_lesen($k['ordner']);
    if ($u === null) { return array(-1, sprintf(uhr_t('A_UHR_NIE', $k), $dienst, $dienst) . ' ' . $ntp_t); }
    if ($u === false) { return array(-1, sprintf(uhr_t('A_UHR_KAPUTT', $k), UHR_DATEI) . ' ' . $ntp_t); }
    $alter = $jetzt - $u['zeit'];
    if ($alter < -$grenze) {
        return array(2, sprintf(uhr_t('A_UHR_ZUKUNFT', $k), $dienst, -$alter) . ' ' . $ntp_t);
    }
    $wann = uhr_dauer_text($alter, $k);
    if ($hoechst > 0 && $alter > $hoechst) {
        return array(-1, sprintf(uhr_t('A_UHR_ALT', $k), $dienst, $wann) . ' ' . $ntp_t);
    }
    if (abs($u['abweichung_s']) <= $grenze) {
        return array(1, sprintf(uhr_t('A_UHR_OK', $k), $dienst, $u['abweichung_s'], $wann) . ' ' . $ntp_t);
    }
    return array(2, sprintf(uhr_t('A_UHR_ABWEICHUNG', $k), $dienst, $u['abweichung_s'], $wann, $grenze) . ' ' . $ntp_t);
}

/** Die Textdatei neben der Stammfassung (nur im Arbeitsordner vorhanden), oder null. */
function uhr_texte_laden()
{
    $f = __DIR__ . '/uhrabgleich_texte.json';
    if (!is_file($f)) { return null; }
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : false;
}

/**
 * Selbsttest ohne Netz und ohne Geraet. Schreibt nur in einen eigenen
 * Zeitordner unter sys_get_temp_dir() und raeumt ihn ab.
 * Rueckgabe array(Faelle, Fehlschlaege, Zeilen).
 */
function uhr_selbsttest()
{
    $n = 0; $f = 0; $z = array();
    $pruef = function ($name, $ist, $soll) use (&$n, &$f, &$z) {
        $n++;
        if ($ist === $soll) { return; }
        $f++;
        $z[] = 'FEHL ' . $name . ': ist ' . var_export($ist, true) . ', soll ' . var_export($soll, true);
    };
    $t0 = gmmktime(10, 0, 0, 11, 19, 2026);

    /* Lesen der Kopfzeile */
    $pruef('datum gut', uhr_datum_lesen('Thu, 19 Nov 2026 10:00:00 GMT'), $t0);
    $pruef('datum Leerraum aussen', uhr_datum_lesen("  Thu, 19 Nov 2026 10:00:00 GMT \r\n"), $t0);
    $pruef('datum falscher Wochentag', uhr_datum_lesen('Wed, 19 Nov 2026 10:00:00 GMT'), 0);
    $pruef('datum UTC statt GMT', uhr_datum_lesen('Thu, 19 Nov 2026 10:00:00 UTC'), 0);
    $pruef('datum RFC 850', uhr_datum_lesen('Thursday, 19-Nov-26 10:00:00 GMT'), 0);
    $pruef('datum 31. Februar', uhr_datum_lesen('Tue, 31 Feb 2026 10:00:00 GMT'), 0);
    $pruef('datum Stunde 24', uhr_datum_lesen('Thu, 19 Nov 2026 24:00:00 GMT'), 0);
    $pruef('datum Sekunde 60', uhr_datum_lesen('Thu, 19 Nov 2026 10:00:60 GMT'), 0);
    $pruef('datum Rest dahinter', uhr_datum_lesen('Thu, 19 Nov 2026 10:00:00 GMT x'), 0);
    $pruef('datum vor 2000', uhr_datum_lesen('Thu, 01 Jan 1970 00:00:00 GMT'), 0);
    $pruef('datum leer', uhr_datum_lesen(''), 0);
    $pruef('datum kein Text', uhr_datum_lesen(array('x')), 0);
    $pruef('datum Schaltjahr', uhr_datum_lesen('Thu, 29 Feb 2024 12:34:56 GMT'), gmmktime(12, 34, 56, 2, 29, 2024));
    $pruef('kopfzeile', uhr_kopfzeile("Date: Thu, 19 Nov 2026 10:00:00 GMT\r\n"), $t0);
    $pruef('kopfzeile klein ohne Leerraum', uhr_kopfzeile('date:Thu, 19 Nov 2026 10:00:00 GMT'), $t0);
    $pruef('kopfzeile fremder Name', uhr_kopfzeile('X-Date: Thu, 19 Nov 2026 10:00:00 GMT'), 0);
    $pruef('kopfzeile Last-Modified', uhr_kopfzeile('Last-Modified: Thu, 19 Nov 2026 10:00:00 GMT'), 0);

    /* Rueckruf fuer curl */
    $d = -5;
    $rr = uhr_kopf_rueckruf($d);
    $pruef('rueckruf setzt zurueck', $d, 0);
    $pruef('rueckruf Laenge', $rr(null, "HTTP/1.1 200 OK\r\n"), 17);
    $rr(null, "Content-Type: text/plain\r\n");
    $rr(null, "Date: Thu, 19 Nov 2026 10:00:00 GMT\r\n");
    $pruef('rueckruf Date', $d, $t0);
    $rr(null, "HTTP/1.1 302 Found\r\n");
    $pruef('rueckruf Weiterleitung setzt zurueck', $d, 0);
    $rr(null, "Location: /x\r\n");
    $pruef('rueckruf Antwort ohne Date', $d, 0);
    $gesehen = array();
    $d2 = 0;
    $rr2 = uhr_kopf_rueckruf($d2, function ($ch, $zeile) use (&$gesehen) { $gesehen[] = $zeile; return 99; });
    $pruef('rueckruf weiter Rueckgabe', $rr2(null, "Date: Thu, 19 Nov 2026 10:00:00 GMT\r\n"), 99);
    $pruef('rueckruf weiter gesehen', count($gesehen), 1);
    $pruef('rueckruf weiter Date', $d2, $t0);

    /* Kopfzeilen als Liste oder Feld */
    $pruef('koepfe Liste letzte Antwort', uhr_aus_koepfen(array('HTTP/1.1 301 Moved', 'Date: Thu, 19 Nov 2026 09:00:00 GMT',
        'HTTP/1.1 200 OK', 'Date: Thu, 19 Nov 2026 10:00:00 GMT')), $t0);
    $pruef('koepfe Liste letzte ohne Date', uhr_aus_koepfen(array('HTTP/1.1 301 Moved', 'Date: Thu, 19 Nov 2026 09:00:00 GMT',
        'HTTP/1.1 200 OK')), 0);
    $pruef('koepfe Feld', uhr_aus_koepfen(array('date' => 'Thu, 19 Nov 2026 10:00:00 GMT', 'server' => 'x')), $t0);
    $pruef('koepfe Feld Grossschreibung', uhr_aus_koepfen(array('Date' => 'Thu, 19 Nov 2026 10:00:00 GMT')), $t0);
    $pruef('koepfe leer', uhr_aus_koepfen(array()), 0);
    $pruef('koepfe kein Feld', uhr_aus_koepfen(null), 0);

    /* Abweichung */
    $pruef('abweichung vor', uhr_abweichung($t0, 2000, $t0 + 41), 40);
    $pruef('abweichung nach', uhr_abweichung($t0, 0, $t0 - 7), -7);

    /* Merken und Lesen */
    $tmp = rtrim(sys_get_temp_dir(), '/\\') . '/uhrabgleich_st_' . getmypid() . '_' . mt_rand(1000, 9999);
    @mkdir($tmp, 0700);
    $pruef('zeitordner angelegt', is_dir($tmp), true);
    $pruef('lesen ohne Ablage', uhr_lesen($tmp), null);
    $pruef('merken ohne Datum', uhr_merken($tmp, 0, 10, 'api.test'), 'kein_datum');
    $pruef('merken Datum als Text', uhr_merken($tmp, (string) $t0, 10, 'api.test'), 'kein_datum');
    $pruef('merken Dauer negativ', uhr_merken($tmp, $t0, -1, 'api.test'), 'dauer_ungueltig');
    $pruef('merken Dauer zu lang', uhr_merken($tmp, $t0, UHR_DAUER_MAX_MS + 1, 'api.test'), 'dauer_ungueltig');
    $pruef('merken Quelle leer', uhr_merken($tmp, $t0, 10, ''), 'quelle_ungueltig');
    $pruef('merken Quelle Leerzeichen', uhr_merken($tmp, $t0, 10, 'a b'), 'quelle_ungueltig');
    $pruef('merken Quelle Zeilenumbruch', uhr_merken($tmp, $t0, 10, "a\nb"), 'quelle_ungueltig');
    $pruef('merken ohne Ordner', uhr_merken($tmp . '/fehlt', $t0, 10, 'api.test'), 'kein_ordner');
    $pruef('merken legt keinen Ordner an', is_dir($tmp . '/fehlt'), false);
    $pruef('merken nichts geschrieben', is_file(uhr_pfad($tmp)), false);
    $m = function ($ab, $jetzt, $q = 'api.test') use ($tmp) { return uhr_merken($tmp, $jetzt - $ab, 0, $q, array('jetzt' => $jetzt)); };
    $pruef('merken erstes Mal', $m(5, $t0 + 5), 'geschrieben');
    $u = uhr_lesen($tmp);
    $pruef('lesen Wert', $u, array('abweichung_s' => 5, 'zeit' => $t0 + 5, 'quelle' => 'api.test'));
    $roh = json_decode((string) @file_get_contents(uhr_pfad($tmp)), true);
    $pruef('ablage Fassung', is_array($roh) && isset($roh['fassung']) ? $roh['fassung'] : null, UHR_FASSUNG);
    $pruef('ablage keine Zwischendatei', count(glob($tmp . '/*.tmp')), 0);
    if (DIRECTORY_SEPARATOR !== '\\') {
        clearstatcache();
        $pruef('ablage Rechte 0644', substr(sprintf('%o', fileperms(uhr_pfad($tmp))), -4), '0644');
    }
    $pruef('merken Mitte der Anfrage', uhr_merken($tmp, $t0, 4000, 'api.drei', array('jetzt' => $t0 + 12)), 'geschrieben');
    $pruef('lesen Mitte der Anfrage', uhr_lesen($tmp)['abweichung_s'], 10);
    $pruef('merken wieder erste Quelle', $m(5, $t0 + 15), 'geschrieben');
    $pruef('merken 1 s anders, frisch', $m(6, $t0 + 75), 'unveraendert');
    $pruef('merken 1 s anders, Wert bleibt', uhr_lesen($tmp)['abweichung_s'], 5);
    $pruef('merken 2 s anders', $m(7, $t0 + 80), 'geschrieben');
    $pruef('lesen nach 2 s', uhr_lesen($tmp)['abweichung_s'], 7);
    $pruef('merken -2 s anders', $m(5, $t0 + 81), 'geschrieben');
    $pruef('merken gleich, 599 s alt', $m(5, $t0 + 81 + 599), 'unveraendert');
    $pruef('merken gleich, 600 s alt', $m(5, $t0 + 81 + 600), 'geschrieben');
    $pruef('lesen Zeit nach 600 s', uhr_lesen($tmp)['zeit'], $t0 + 681);
    $pruef('merken andere Quelle', $m(5, $t0 + 682, 'api.zwei'), 'geschrieben');
    $pruef('lesen andere Quelle', uhr_lesen($tmp)['quelle'], 'api.zwei');
    $pruef('merken Uhr zurueckgesprungen', $m(5, $t0 + 100, 'api.zwei'), 'geschrieben');
    @file_put_contents(uhr_pfad($tmp), '{kaputt');
    $pruef('lesen kaputt', uhr_lesen($tmp), false);
    $pruef('merken ueber kaputte Ablage', $m(0, $t0), 'geschrieben');
    @file_put_contents(uhr_pfad($tmp), '{"abweichung_s":"5","zeit":' . $t0 . '}');
    $pruef('lesen Abweichung als Text', uhr_lesen($tmp), false);
    @file_put_contents(uhr_pfad($tmp), '{"abweichung_s":5}');
    $pruef('lesen ohne Zeit', uhr_lesen($tmp), false);
    @file_put_contents(uhr_pfad($tmp), '{"abweichung_s":5,"zeit":0}');
    $pruef('lesen Zeit 0', uhr_lesen($tmp), false);
    @file_put_contents(uhr_pfad($tmp), '{"abweichung_s":1,"zeit":' . $t0 . ',"quelle":"alexa.amazon.de"}');
    $pruef('lesen Ablage aus Alexa-NG 1.0.20', uhr_lesen($tmp), array('abweichung_s' => 1, 'zeit' => $t0, 'quelle' => 'alexa.amazon.de'));
    @file_put_contents(uhr_pfad($tmp), '{"abweichung_s":1,"zeit":' . $t0 . ',"quelle":"a b"}');
    $pruef('lesen fremde Quelle wird leer', uhr_lesen($tmp)['quelle'], '');

    /* Texte: aus der Textdatei, sonst ein Uebersetzer, der Schluessel mit Platzhaltern nennt */
    $texte = uhr_texte_laden();
    if (is_array($texte) && isset($texte['de']) && is_array($texte['de'])) {
        $de = $texte['de'];
        $tr = function ($s) use ($de) { $id = substr($s, 4); return isset($de[$id]) ? $de[$id] : $s; };
    } else {
        $z[] = 'nicht geprueft: Textdatei uhrabgleich_texte.json liegt nicht neben dem Modul (in einer Abschrift ist das so)';
        $pl = uhr_platzhalter();
        $tr = function ($s) use ($pl) { $id = substr($s, 4); return $id . (isset($pl[$id]) ? str_repeat('|%s', $pl[$id]) : ''); };
    }
    $k = array('ordner' => $tmp, 'dienst' => 'Pruefdienst', 't' => $tr, 'jetzt' => $t0 + 100, 'ntp' => function () { return 'no'; });
    $ntp_no = ' ' . sprintf($tr('UHR.A_UHR_NTP'), 'no');
    /* Erwarteter Text: der Satz mit genau diesen Werten, dahinter der NTP-Hinweis. */
    $soll = function ($id) use ($tr, $ntp_no) { $a = func_get_args(); array_shift($a); return vsprintf($tr('UHR.' . $id), $a) . $ntp_no; };
    $schreib = function ($ab, $zeit) use ($tmp) {
        @file_put_contents(uhr_pfad($tmp), json_encode(array('abweichung_s' => $ab, 'zeit' => $zeit, 'quelle' => 'api.test')));
    };
    $vor100 = uhr_dauer_text(100, array('t' => $tr));
    @unlink(uhr_pfad($tmp));
    $pruef('zeile ohne Messung', uhr_pruefzeile($k), array(-1, $soll('A_UHR_NIE', 'Pruefdienst', 'Pruefdienst')));
    $schreib(30, $t0);
    $pruef('zeile 30 s', uhr_pruefzeile($k), array(1, $soll('A_UHR_OK', 'Pruefdienst', 30, $vor100)));
    $schreib(-30, $t0);
    $pruef('zeile -30 s', uhr_pruefzeile($k), array(1, $soll('A_UHR_OK', 'Pruefdienst', -30, $vor100)));
    $schreib(31, $t0);
    $pruef('zeile 31 s', uhr_pruefzeile($k), array(2, $soll('A_UHR_ABWEICHUNG', 'Pruefdienst', 31, $vor100, 30)));
    $schreib(-31, $t0);
    $pruef('zeile -31 s', uhr_pruefzeile($k), array(2, $soll('A_UHR_ABWEICHUNG', 'Pruefdienst', -31, $vor100, 30)));
    $schreib(0, $t0);
    $pruef('zeile NTP no aendert den Stand nicht', uhr_pruefzeile($k)[0], 1);
    $r = uhr_pruefzeile(array('ntp' => function () { return 'yes'; }) + $k);
    $pruef('zeile NTP yes', $r, array(1, vsprintf($tr('UHR.A_UHR_OK'), array('Pruefdienst', 0, $vor100)) . ' ' . sprintf($tr('UHR.A_UHR_NTP'), 'yes')));
    $schreib(500, $t0);
    $pruef('zeile NTP yes macht gelb nicht gruen', uhr_pruefzeile(array('ntp' => function () { return 'yes'; }) + $k)[0], 2);
    $schreib(0, $t0);
    $r = uhr_pruefzeile(array('ntp' => function () { return 'vielleicht'; }) + $k);
    $pruef('zeile NTP unlesbar', $r[1], vsprintf($tr('UHR.A_UHR_OK'), array('Pruefdienst', 0, $vor100)) . ' '
        . sprintf($tr('UHR.A_UHR_NTP'), $tr('UHR.A_UHR_NTP_UNBEKANNT')));
    $schreib(5, $t0 - 10 * 86400);
    $vor10t = uhr_dauer_text(100 + 10 * 86400, array('t' => $tr));
    $pruef('zeile alt ohne Hoechstalter (ab Werk aus)', uhr_pruefzeile($k), array(1, $soll('A_UHR_OK', 'Pruefdienst', 5, $vor10t)));
    $pruef('zeile alt mit Hoechstalter', uhr_pruefzeile(array('hoechstalter_s' => 3600) + $k), array(-1, $soll('A_UHR_ALT', 'Pruefdienst', $vor10t)));
    $schreib(5, $t0 + 100 - 3600);
    $pruef('zeile Hoechstalter genau erreicht', uhr_pruefzeile(array('hoechstalter_s' => 3600) + $k)[0], 1);
    $schreib(0, $t0 + 100 + 31);
    $pruef('zeile Messung in der Zukunft', uhr_pruefzeile($k), array(2, $soll('A_UHR_ZUKUNFT', 'Pruefdienst', 31)));
    $schreib(0, $t0 + 100 + 30);
    $pruef('zeile Zukunft innerhalb der Grenze', uhr_pruefzeile($k)[0], 1);
    $schreib(45, $t0);
    $pruef('zeile Grenze 60', uhr_pruefzeile(array('grenze_s' => 60) + $k)[0], 1);
    $schreib(61, $t0);
    $pruef('zeile Grenze 60 ueberschritten', uhr_pruefzeile(array('grenze_s' => 60) + $k), array(2, $soll('A_UHR_ABWEICHUNG', 'Pruefdienst', 61, $vor100, 60)));
    foreach (array('Text' => '60', 'null' => 0, 'negativ' => -5, 'zu gross' => 3601) as $was => $g) {
        $pruef('zeile Grenze ' . $was, uhr_pruefzeile(array('grenze_s' => $g) + $k), array(-1, $soll('A_UHR_AUFRUF', 'grenze_s')));
    }
    $pruef('zeile Hoechstalter 30 abgewiesen', uhr_pruefzeile(array('hoechstalter_s' => 30) + $k), array(-1, $soll('A_UHR_AUFRUF', 'hoechstalter_s')));
    $pruef('zeile Hoechstalter als Text abgewiesen', uhr_pruefzeile(array('hoechstalter_s' => '3600') + $k)[0], -1);
    $pruef('zeile ohne Dienst', uhr_pruefzeile(array('dienst' => '') + $k), array(-1, $soll('A_UHR_AUFRUF', 'dienst')));
    $pruef('zeile Dienst mit Zeilenumbruch', uhr_pruefzeile(array('dienst' => "a\nb") + $k), array(-1, $soll('A_UHR_AUFRUF', 'dienst')));
    $pruef('zeile ohne Ordner', uhr_pruefzeile(array('ordner' => '') + $k), array(-1, $soll('A_UHR_AUFRUF', 'ordner')));
    $pruef('zeile alle Beanstandungen gesammelt', uhr_pruefzeile(array('grenze_s' => 0, 'dienst' => '') + $k),
        array(-1, $soll('A_UHR_AUFRUF', 'dienst, grenze_s')));
    @file_put_contents(uhr_pfad($tmp), 'kaputt');
    $pruef('zeile kaputte Ablage', uhr_pruefzeile($k), array(-1, $soll('A_UHR_KAPUTT', UHR_DATEI)));
    $pruef('frage', uhr_frage(array('t' => $tr)), $tr('UHR.F_UHR'));
    $pruef('abschnitt waehlbar', uhr_t('F_UHR', array('abschnitt' => 'TEST')), 'TEST.F_UHR');
    $pruef('ohne Uebersetzer der Schluessel', uhr_t('F_UHR', array()), 'UHR.F_UHR');
    $pruef('art 1', uhr_art(1), 'ok');
    $pruef('art 2', uhr_art(2), 'hinweis');
    $pruef('art -1', uhr_art(-1), 'nicht_feststellbar');
    $pruef('art Text 1', uhr_art('1'), 'nicht_feststellbar');
    $pruef('zeichen 1', uhr_zeichen(1), '<span class="sm-an">&#10004;</span>');
    $pruef('zeichen 2 gelb', uhr_zeichen(2), '<span style="color:#e0a000;font-weight:700;">&#9679;</span>');
    $pruef('zeichen -1 grau', uhr_zeichen(-1), '<span style="color:#888;">&#8211;</span>');
    $pruef('zeichen 0 nie rot', strpos(uhr_zeichen(0), 'sm-aus'), false);
    $kd = array('t' => function ($s) { return substr($s, 4) . '%d'; });
    $pruef('dauer s', uhr_dauer_text(89, $kd), 'D_S89');
    $pruef('dauer min', uhr_dauer_text(120, $kd), 'D_MIN2');
    $pruef('dauer h', uhr_dauer_text(7200, $kd), 'D_H2');
    $pruef('dauer Tage', uhr_dauer_text(200000, $kd), 'D_TAG2');

    /* Textdatei gegen die Schluessel- und Platzhaltermenge */
    if (is_array($texte)) {
        foreach (array('de', 'en') as $sp) {
            $d = isset($texte[$sp]) && is_array($texte[$sp]) ? $texte[$sp] : array();
            $pruef('texte ' . $sp . ' Schluessel', array_keys($d), uhr_sprachschluessel());
            foreach (uhr_platzhalter() as $id => $zahl) {
                $satz = isset($d[$id]) && is_string($d[$id]) ? $d[$id] : '';
                $pruef('texte ' . $sp . ' ' . $id . ' Platzhalter', preg_match_all('/%[sd]/', $satz), $zahl);
                $pruef('texte ' . $sp . ' ' . $id . ' ohne gerade Doppel-Anfuehrung', strpos($satz, '"'), false);
            }
        }
    } elseif ($texte === false) {
        $pruef('textdatei lesbar', false, true);
    }
    $pruef('schluessel = platzhalterliste', array_keys(uhr_platzhalter()), uhr_sprachschluessel());

    foreach (glob($tmp . '/*') ?: array() as $x) { @unlink($x); }
    @rmdir($tmp);
    $pruef('zeitordner abgeraeumt', is_dir($tmp), false);
    return array($n, $f, $z);
}

}   // Ende des Blocks "noch keine Abschrift geladen"

/* Aufruf von der Kommandozeile: php uhrabgleich.php --selbsttest */
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv) && realpath($argv[0]) === realpath(__FILE__)
    && in_array('--selbsttest', $argv, true)) {
    list($uhr_n, $uhr_f, $uhr_z) = uhr_selbsttest();
    foreach ($uhr_z as $uhr_x) { echo $uhr_x, "\n"; }
    printf("Uhrabgleich %s: %d Faelle geprueft, %d Fehlschlaege.\n", UHR_FASSUNG, $uhr_n, $uhr_f);
    exit($uhr_f === 0 ? 0 : 1);
}
