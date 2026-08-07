<?php
/**
 * Waermepumpe Cloud fuer LoxBerry - Pfade, Konfiguration, Hersteller, Vorlage
 *
 * Bindet die herstellereigenen Cloud-Schnittstellen moderner Waermepumpen an
 * und uebersetzt SG-Ready-Zustaende aus Loxone dorthin.
 *
 * DREI HERSTELLER, DREI SEHR UNTERSCHIEDLICHE SCHNITTSTELLEN. Das ist keine
 * Unaufgeraeumtheit, sondern der Sachstand - und er steht deshalb sichtbar in
 * wp_hersteller():
 *
 *   myUplink (Nibe)   offiziell, OAuth2 mit client_credentials, kein
 *                     Tagesbudget. ABER: Schreiben verlangt ein
 *                     kostenpflichtiges myUplink-Abo. Lesen ist frei.
 *   Daikin Onecta     offiziell, OAuth2 mit Autorisierungscode. ABER: 200
 *                     Aufrufe je Tag. Ein Fuenf-Minuten-Takt waeren 288 - das
 *                     Kontingent ist vor dem Abend leer. Deshalb rechnet
 *                     dieses Plugin den Takt aus dem Budget aus, statt ihn
 *                     den Nutzer raten zu lassen.
 *   MELCloud          inoffiziell, Anmeldung mit E-Mail und Passwort gegen
 *                     einen ContextKey. Mitsubishi hat Grenzen eingefuehrt;
 *                     unter 180 Sekunden Takt sperrt der Dienst fuer Stunden
 *                     aus. Diese Untergrenze ist hier hart, nicht als
 *                     Empfehlung.
 *
 * ECHTES SG READY GIBT ES NUR BEI NIBE. Neuere Firmware macht die beiden
 * SG-Ready-Register ueber die Schnittstelle erreichbar (3032 schaltet die
 * Bedienung per Schnittstelle frei, 6008 traegt den Zustand). Daikin und
 * Mitsubishi kennen SG Ready in ihrer Cloud nicht - dort wird es
 * NACHGEBILDET, und die Oberflaeche sagt das bei jedem Hersteller
 * ausdruecklich. Eine Nachbildung, die sich als das Original ausgibt, ist
 * schlimmer als keine.
 */

define('WP_STUFEN', 4);          // SG Ready kennt genau vier Zustaende
define('WP_SPERRE_MAX', 120);    // Minuten, danach faellt die Sperre von selbst
define('WP_ZUORDNUNG_MAX', 4000);

/* ==================================================================
 * Pfade
 * ================================================================== */

function wp_paths()
{
    static $p = null;
    if ($p !== null) { return $p; }

    $home = getenv('LBHOMEDIR');
    if (!$home) { $home = '/opt/loxberry'; }
    $plugin = getenv('LBPPLUGINDIR');
    if (!$plugin) { $plugin = 'waermepumpe'; }

    $p = array(
        'home'      => rtrim($home, '/'),
        'plugin'    => $plugin,
        'configdir' => rtrim($home, '/') . '/config/plugins/' . $plugin,
        'datadir'   => rtrim($home, '/') . '/data/plugins/' . $plugin,
        'logdir'    => rtrim($home, '/') . '/log/plugins/' . $plugin,
    );
    $p['config'] = $p['configdir'] . '/waermepumpe.json';
    $p['geheim'] = $p['configdir'] . '/geheim.json';
    return $p;
}

function wp_tmpdir()
{
    $d = '/run/shm/waermepumpe';
    if (!is_dir('/run/shm')) { $d = sys_get_temp_dir() . '/waermepumpe'; }
    if (!is_dir($d)) { @mkdir($d, 0700, true); }
    return $d;
}

function wp_datadir()
{
    $p = wp_paths();
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0755, true); }
    return $p['datadir'];
}

/**
 * Protokoll.
 *
 * Schreibt NUR in die Datei, nie nach stdout: dieselbe Bibliothek wird vom
 * Endpunkt benutzt, dessen Ausgabe eine HTTP-Antwort ist.
 * Dazu eine Bremse - dieselbe Zeile fruehestens nach einer Stunde erneut.
 * Ohne sie schreibt ein Minutentakt eine unlesbare Logdatei voll.
 */
function wp_log($text, $einmalig = '')
{
    $p = wp_paths();
    if (!is_dir($p['logdir'])) { @mkdir($p['logdir'], 0755, true); }
    if ($einmalig !== '') {
        $f = wp_tmpdir() . '/log_' . md5($einmalig) . '.stamp';
        if (is_file($f) && (time() - (int) @filemtime($f)) < 3600) { return; }
        @touch($f);
    }
    $zeile = date('Y-m-d H:i:s') . ' ' . rtrim($text) . "\n";
    if (@file_put_contents($p['logdir'] . '/waermepumpe.log', $zeile, FILE_APPEND | LOCK_EX) === false) {
        fwrite(STDERR, $zeile);
    }
}

function wp_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function wp_x($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

/**
 * Ein Geheimnis fuer Protokoll und Oberflaeche unkenntlich machen.
 * Laenge zeigen, Inhalt nicht - genau so steht es in den Hausregeln.
 */
function wp_maske($wert)
{
    $wert = (string) $wert;
    $n = strlen($wert);
    if ($n === 0) { return ''; }
    if ($n <= 8) { return str_repeat('*', $n) . ' (' . $n . ')'; }
    return substr($wert, 0, 4) . str_repeat('*', min(12, $n - 8)) . substr($wert, -4) . ' (' . $n . ')';
}

/* ==================================================================
 * Hersteller
 * ================================================================== */

function wp_hersteller()
{
    return array(
        'myuplink' => array(
            'name'        => 'myUplink (Nibe)',
            'sg_echt'     => 1,     // echtes SG Ready ueber die Register
            'mindesttakt' => 60,    // Sekunden - myUplink nennt keine harte Grenze
            'budget'      => 0,     // kein Tagesbudget
            'basis'       => 'https://api.myuplink.com',
            'token_url'   => 'https://api.myuplink.com/oauth/token',
            'portal'      => 'https://dev.myuplink.com/apps',
        ),
        'onecta' => array(
            'name'        => 'Daikin Onecta',
            'sg_echt'     => 0,     // nachgebildet
            'mindesttakt' => 300,
            'budget'      => 200,   // Aufrufe je Tag, gleitendes Fenster
            'basis'       => 'https://api.onecta.daikineurope.com',
            'token_url'   => 'https://idp.onecta.daikineurope.com/v1/oidc/token',
            'auth_url'    => 'https://idp.onecta.daikineurope.com/v1/oidc/authorize',
            'portal'      => 'https://developer.cloud.daikineurope.com/',
        ),
        'melcloud' => array(
            'name'        => 'MELCloud (Mitsubishi Electric)',
            'sg_echt'     => 0,     // nachgebildet
            'mindesttakt' => 180,   // harte Untergrenze, nicht Empfehlung
            'budget'      => 0,
            'basis'       => 'https://app.melcloud.com/Mitsubishi.Wifi.Client',
            'portal'      => 'https://app.melcloud.com/',
        ),
    );
}

function wp_hersteller_info($schluessel)
{
    $h = wp_hersteller();
    return isset($h[$schluessel]) ? $h[$schluessel] : null;
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

function wp_vorgaben()
{
    return array(
        'hersteller'   => '',        // myuplink | onecta | melcloud
        'geraet'       => '',        // Geraete-Kennung beim Hersteller
        'gebaeude'     => '',        // nur MELCloud: BuildingID
        'geraetetyp'   => -1,        // nur MELCloud: 0=Luft/Luft, 1=Luft/Wasser, -1=unbekannt
        'system'       => '',        // nur myUplink: systemId
        'takt'         => 300,       // Sekunden zwischen zwei Abrufen
        'budget_schreiben' => 40,    // nur Onecta: Aufrufe, die fuers Schalten frei bleiben
        // SG Ready
        'sg_ein'       => 1,
        'sg_stufe'     => 2,         // zuletzt von Loxone gewuenschter Zustand
        // Solange dies 0 ist, hat noch NIEMAND einen Zustand angefordert -
        // und dann wird auch keiner gesetzt. Siehe wp_sg_durchsetzen().
        'sg_angefordert' => 0,
        'sperre_max'   => WP_SPERRE_MAX,
        'anhebung_3'   => 2,         // Kelvin bei Einschaltempfehlung
        'anhebung_4'   => 5,         // Kelvin bei Anlaufbefehl
        'ww_boost_4'   => 1,         // bei Anlaufbefehl Warmwasser mitziehen
        'basis_soll'   => 0,         // 0 = beim ersten Normalbetrieb selbst merken
        // Zuordnung Feld -> Pfad, eine Zeile je Feld, leer = automatisch
        'zuordnung'    => '',
        // MQTT
        'mqtt_ein'     => 1,
        'mqtt_topic'   => 'waermepumpe',
        // Endpunkt
        'aktionstoken' => '',
    );
}

function wp_config()
{
    $p = wp_paths();
    $cfg = is_file($p['config']) ? json_decode((string) @file_get_contents($p['config']), true) : array();
    if (!is_array($cfg)) { $cfg = array(); }
    $cfg = array_merge(wp_vorgaben(), $cfg);

    $h = wp_hersteller();
    if (!isset($h[$cfg['hersteller']])) { $cfg['hersteller'] = ''; }

    $cfg['takt']             = max(60, min(3600, (int) $cfg['takt']));
    $cfg['budget_schreiben'] = max(0, min(150, (int) $cfg['budget_schreiben']));
    $cfg['sg_ein']           = empty($cfg['sg_ein']) ? 0 : 1;
    $cfg['sg_stufe']         = max(1, min(WP_STUFEN, (int) $cfg['sg_stufe']));
    $cfg['sg_angefordert']   = empty($cfg['sg_angefordert']) ? 0 : 1;
    $cfg['sperre_max']       = max(5, min(720, (int) $cfg['sperre_max']));
    $cfg['anhebung_3']       = max(0, min(15, (int) $cfg['anhebung_3']));
    $cfg['anhebung_4']       = max(0, min(15, (int) $cfg['anhebung_4']));
    $cfg['ww_boost_4']       = empty($cfg['ww_boost_4']) ? 0 : 1;
    $cfg['basis_soll']       = (float) $cfg['basis_soll'];
    $cfg['geraetetyp']       = (int) $cfg['geraetetyp'];
    $cfg['mqtt_ein']         = empty($cfg['mqtt_ein']) ? 0 : 1;
    $cfg['zuordnung']        = substr((string) $cfg['zuordnung'], 0, WP_ZUORDNUNG_MAX);

    $cfg['mqtt_topic'] = preg_replace('#[^A-Za-z0-9_/\-]#', '', (string) $cfg['mqtt_topic']);
    if ($cfg['mqtt_topic'] === '') { $cfg['mqtt_topic'] = 'waermepumpe'; }

    // Geraetekennungen sind bei allen dreien harmlos geformt, aber sie landen
    // in einer URL - deshalb eng begrenzt.
    foreach (array('geraet', 'gebaeude', 'system') as $f) {
        $cfg[$f] = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $cfg[$f]);
    }

    // Der Takt darf den Mindesttakt des Herstellers nie unterschreiten. Das
    // ist keine Empfehlung: MELCloud sperrt bei zu haeufigen Anfragen fuer
    // Stunden aus, und die Sperre trifft das ganze Konto, nicht nur dieses
    // Plugin.
    $info = wp_hersteller_info($cfg['hersteller']);
    if ($info) {
        $cfg['takt'] = max($cfg['takt'], (int) $info['mindesttakt']);
        if (!empty($info['budget'])) {
            $cfg['takt'] = max($cfg['takt'], wp_takt_aus_budget($info['budget'], $cfg['budget_schreiben']));
        }
    }

    if (!preg_match('/^[A-Za-z0-9]{24,}$/', (string) $cfg['aktionstoken'])) {
        $cfg['aktionstoken'] = wp_token();
        wp_config_write($cfg);
    }
    return $cfg;
}

/**
 * Eine JSON-Datei sicher schreiben.
 *
 * ZWEI FALLEN, die beide zum Totalverlust fuehren:
 *
 * 1. json_encode() liefert bei ungueltigem UTF-8 nicht etwa eine leere
 *    Zeichenkette, sondern false. Und file_put_contents($pfad, false)
 *    schreibt daraufhin eine Datei mit NULL Bytes und gibt 0 zurueck - nicht
 *    false. Eine Pruefung auf === false greift also nie, und die
 *    Konfiguration ist weg. Bei geheim.json waere das der Verlust des
 *    Erneuerungsmerkmals und damit eine neue Anmeldung im Browser.
 *    Ein einziges Zeichen aus einer Latin-1-Zwischenablage im Passwortfeld
 *    genuegt.
 *
 * 2. Wird direkt in die Zieldatei geschrieben und faellt dabei der Strom
 *    aus, liegt dort eine halbe Datei. Deshalb erst in eine Nebendatei,
 *    dann umbenennen - rename ist auf demselben Dateisystem unteilbar.
 *
 * Die Rechte werden auf der Nebendatei gesetzt, bevor sie an ihren Platz
 * rueckt: sonst gaebe es einen Augenblick, in dem die Zugangsdaten mit den
 * Vorgaberechten dastehen.
 */
function wp_json_schreiben($pfad, $daten)
{
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false || $js === '' || $js === 'null') {
        wp_log('SCHWERWIEGEND - ' . basename($pfad) . ' NICHT geschrieben, die Daten liessen '
             . 'sich nicht in JSON wandeln (' . json_last_error_msg() . '). Die bisherige '
             . 'Datei bleibt unveraendert stehen.');
        return false;
    }
    $neben = $pfad . '.neu';
    if (@file_put_contents($neben, $js, LOCK_EX) !== strlen($js)) {
        @unlink($neben);
        wp_log('SCHWERWIEGEND - ' . basename($pfad) . ' NICHT geschrieben, die Nebendatei '
             . 'liess sich nicht anlegen. Schreibrechte auf ' . dirname($pfad) . ' pruefen.');
        return false;
    }
    @chmod($neben, 0600);
    if (!@rename($neben, $pfad)) {
        @unlink($neben);
        wp_log('SCHWERWIEGEND - ' . basename($pfad) . ' NICHT geschrieben, das Umbenennen '
             . 'ist fehlgeschlagen.');
        return false;
    }
    return true;
}

function wp_config_write($cfg)
{
    $p = wp_paths();
    @mkdir($p['configdir'], 0775, true);
    return wp_json_schreiben($p['config'], $cfg);
}

/**
 * Zugangsdaten - EIGENE Datei mit 0600.
 *
 * Bewusst getrennt von der Konfiguration: die Oberflaeche zeigt die
 * Konfiguration an, und ein vergessenes echo eines Konfigurationsfeldes waere
 * dann sofort ein ausgeplaudertes Passwort. Was hier drin steht, verlaesst
 * diese Datei nur maskiert.
 */
function wp_geheim()
{
    $p = wp_paths();
    $g = is_file($p['geheim']) ? json_decode((string) @file_get_contents($p['geheim']), true) : array();
    if (!is_array($g)) { $g = array(); }
    return array_merge(array(
        'client_id'     => '',
        'client_secret' => '',
        'benutzer'      => '',    // MELCloud: E-Mail
        'passwort'      => '',    // MELCloud
        'refresh_token' => '',    // Onecta
        'redirect_uri'  => '',    // Onecta
    ), $g);
}

function wp_geheim_write($g)
{
    $p = wp_paths();
    @mkdir($p['configdir'], 0775, true);
    return wp_json_schreiben($p['geheim'], $g);
}

function wp_token($laenge = 32)
{
    $z = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $roh = function_exists('random_bytes') ? random_bytes($laenge) : openssl_random_pseudo_bytes($laenge);
    $out = '';
    for ($i = 0; $i < $laenge; $i++) { $out .= $z[ord($roh[$i]) % strlen($z)]; }
    return $out;
}

/* ==================================================================
 * HTTP
 * ================================================================== */

/**
 * Eine Anfrage an eine fremde Cloud.
 *
 * Setzt immer einen eigenen User-Agent: ein Dienst, der Anfragen ohne
 * Kennung abweist, tut das mit einer Fehlermeldung, die nach etwas anderem
 * aussieht. Rueckgabe: array('code'=>int, 'text'=>string, 'fehler'=>string).
 */
function wp_http($methode, $url, $kopf = array(), $koerper = null, $zeit = 20)
{
    $kopf[] = 'User-Agent: LoxBerry-Waermepumpe/0.9.0';
    $kopf[] = 'Accept: application/json';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $methode);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $kopf);
        curl_setopt($ch, CURLOPT_TIMEOUT, $zeit);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $zeit));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if ($koerper !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $koerper); }
        $text = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);
        return array('code' => $code, 'text' => (string) $text, 'fehler' => $fehler);
    }

    $ctx = stream_context_create(array('http' => array(
        'method' => $methode, 'header' => implode("\r\n", $kopf),
        'content' => $koerper, 'timeout' => $zeit, 'ignore_errors' => true,
    )));
    $text = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $z) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $z, $m)) { $code = (int) $m[1]; }
        }
    }
    return array('code' => $code, 'text' => (string) $text,
                 'fehler' => $text === false ? 'Verbindung fehlgeschlagen' : '');
}

function wp_json($antwort)
{
    $d = json_decode($antwort['text'], true);
    return is_array($d) ? $d : null;
}

/* ==================================================================
 * Aufrufbuchhaltung
 *
 * Daikin erlaubt 200 Aufrufe je Tag in einem GLEITENDEN Fenster. Ein
 * Zaehler, der um Mitternacht auf null springt, wuerde das falsch
 * abbilden - deshalb wird eine Liste der Zeitstempel gefuehrt und alles
 * aelter als 24 Stunden faellt heraus.
 * ================================================================== */

function wp_budget_datei($h)
{
    return wp_datadir() . '/budget_' . preg_replace('/[^a-z]/', '', $h) . '.json';
}

function wp_budget_liste($h)
{
    $f = wp_budget_datei($h);
    $l = is_file($f) ? json_decode((string) @file_get_contents($f), true) : array();
    if (!is_array($l)) { $l = array(); }
    $grenze = time() - 86400;
    return array_values(array_filter($l, function ($t) use ($grenze) { return (int) $t > $grenze; }));
}

function wp_budget_rest($h)
{
    $info = wp_hersteller_info($h);
    if (!$info || empty($info['budget'])) { return -1; }   // -1 = kein Budget
    return max(0, (int) $info['budget'] - count(wp_budget_liste($h)));
}

function wp_budget_frei($h, $wieviel = 1)
{
    $rest = wp_budget_rest($h);
    return $rest < 0 || $rest >= $wieviel;
}

function wp_budget_buchen($h, $wieviel = 1)
{
    $info = wp_hersteller_info($h);
    if (!$info || empty($info['budget'])) { return; }
    $l = wp_budget_liste($h);
    for ($i = 0; $i < $wieviel; $i++) { $l[] = time(); }
    @file_put_contents(wp_budget_datei($h), json_encode($l), LOCK_EX);
}

/**
 * Aus Tagesbudget und Schreibreserve den kleinstmoeglichen Abruftakt rechnen.
 * Das ist der Kern der Daikin-Anbindung: nicht der Nutzer raet ein Intervall,
 * sondern das Budget gibt es vor.
 */
function wp_takt_aus_budget($budget, $reserve)
{
    $lesen = (int) $budget - (int) $reserve;
    if ($lesen < 1) { $lesen = 1; }
    return (int) ceil(86400 / $lesen);
}

/* ==================================================================
 * Pfadaufloesung
 *
 * Die drei Antworten sind vollkommen verschieden gebaut. Statt drei
 * Auswertungen gibt es einen kleinen Pfadleser, der alle drei Formen kennt:
 *
 *   a.b.c                      verschachtelte Objekte
 *   a.0.b                      Feld eines Feldes
 *   a[embeddedId=climateControl].b   Element eines Feldes ueber ein Merkmal
 *   #40004                     myUplink: der Punkt mit dieser parameterId
 *   ~aussen|outdoor            myUplink: der erste Punkt, dessen Name passt
 *
 * Dahinter darf ein  |faktor  stehen:  #40004|0.1
 * ================================================================== */

function wp_pfad($daten, $pfad)
{
    // Der Faktor wird am LETZTEN senkrechten Strich abgetrennt, und nur dann,
    // wenn dahinter wirklich eine Zahl steht.
    //
    // Ein Trennen am ersten Strich waere falsch: in der Namenssuche ist der
    // Strich die Oder-Verknuepfung. Aus "~vorlauf|supply line|BT2" wuerde
    // sonst still "~vorlauf" - und genau die englischen und die
    // Kurzbezeichnungen, fuer die die Alternativen da sind, gingen verloren.
    // Das faellt niemandem auf, weil trotzdem etwas gefunden wird, nur eben
    // nicht bei einem Geraet mit englischen Punktnamen.
    $faktor = null;
    $strich = strrpos($pfad, '|');
    if ($strich !== false) {
        $f = substr($pfad, $strich + 1);
        if (is_numeric($f)) {
            $faktor = (float) $f;
            $pfad = substr($pfad, 0, $strich);
        }
    }
    $pfad = trim($pfad);
    if ($pfad === '' || !is_array($daten)) { return null; }

    $wert = null;
    if ($pfad[0] === '#' || $pfad[0] === '~') {
        $wert = wp_punkt_suchen($daten, $pfad);
    } else {
        $wert = $daten;
        foreach (wp_pfad_teile($pfad) as $t) {
            if (!is_array($wert)) { return null; }
            if (is_array($t)) {
                // Auswahl ueber ein Merkmal
                $treffer = null;
                foreach ($wert as $e) {
                    if (is_array($e) && isset($e[$t[0]]) && (string) $e[$t[0]] === $t[1]) { $treffer = $e; break; }
                }
                if ($treffer === null) { return null; }
                $wert = $treffer;
            } else {
                if (!array_key_exists($t, $wert)) { return null; }
                $wert = $wert[$t];
            }
        }
    }
    // Ein Zweig, der in einen Teilbaum zeigt statt auf einen Wert, gilt als
    // nicht gefunden - der Reiter Test meldet das Feld dann als ohne Treffer.
    if ($wert === null || is_array($wert)) { return null; }
    if (is_bool($wert)) { $wert = $wert ? 1 : 0; }
    if ($faktor !== null && is_numeric($wert)) { $wert = (float) $wert * $faktor; }
    return $wert;
}

function wp_pfad_teile($pfad)
{
    $out = array();
    foreach (explode('.', $pfad) as $t) {
        if (preg_match('/^([A-Za-z0-9_]+)\[([A-Za-z0-9_]+)=([^\]]+)\]$/', $t, $m)) {
            $out[] = $m[1];
            $out[] = array($m[2], $m[3]);
        } else {
            $out[] = $t;
        }
    }
    return $out;
}

/**
 * myUplink liefert eine flache Liste von Punkten. Gesucht wird entweder ueber
 * die parameterId (#40004) oder ueber den Namen (~aussen|outdoor).
 */
function wp_punkt_suchen($punkte, $pfad)
{
    if (!is_array($punkte)) { return null; }
    $art = $pfad[0];
    $such = substr($pfad, 1);
    foreach ($punkte as $pkt) {
        if (!is_array($pkt)) { continue; }
        if ($art === '#') {
            if (isset($pkt['parameterId']) && (string) $pkt['parameterId'] === $such) {
                return isset($pkt['value']) ? $pkt['value'] : null;
            }
        } else {
            $name = isset($pkt['parameterName']) ? (string) $pkt['parameterName'] : '';
            // Das Suchmuster kommt aus der Zuordnung des Nutzers. Ein
            // unvollstaendiges Muster - eine offene Klammer genuegt - laesst
            // preg_match false zurueckgeben; das @ haelt die Warnung aus der
            // Antwort heraus, und der Reiter Test meldet das Feld dann
            // schlicht als nicht gefunden.
            if ($name !== '' && @preg_match('/(' . str_replace('/', '\/', $such) . ')/i', $name) === 1) {
                return isset($pkt['value']) ? $pkt['value'] : null;
            }
        }
    }
    return null;
}

/* ==================================================================
 * Die Feldtabelle - eine Quelle fuer Statuszeile, MQTT und Vorlage
 *
 * Je Feld mehrere Kandidatenpfade JE HERSTELLER, der erste Treffer gewinnt.
 * Grund: die genaue Gestalt der Antwort haengt am Geraetemodell, und dieses
 * Plugin ist ohne Geraet entstanden. Statt einen Pfad zu raten und still zu
 * scheitern, werden mehrere versucht - und der Reiter Test sagt fuer JEDES
 * Feld, welcher Kandidat gegriffen hat oder dass keiner gegriffen hat.
 * ================================================================== */

function wp_felder()
{
    return array(
        // name => array(analog, min, max, Sprachschluessel, Kandidaten je Hersteller)
        'AUSSEN' => array(1, -50, 50, 'FELD.AUSSEN', array(
            'myuplink' => array('#40004', '~aussentemp|outdoor temp|BT1'),
            'onecta'   => array('managementPoints[embeddedId=climateControl].sensoryData.value.outdoorTemperature.value',
                                'managementPoints[embeddedId=climateControl].sensoryData.value.outdoorTemperature'),
            'melcloud' => array('OutdoorTemperature'),
        )),
        'VORLAUF' => array(1, -20, 100, 'FELD.VORLAUF', array(
            'myuplink' => array('#40008', '~vorlauf|supply line|BT2'),
            'onecta'   => array('managementPoints[embeddedId=climateControl].sensoryData.value.leavingWaterTemperature.value'),
            'melcloud' => array('FlowTemperature', 'SetHeatFlowTemperatureZone1'),
        )),
        'RUECKLAUF' => array(1, -20, 100, 'FELD.RUECKLAUF', array(
            'myuplink' => array('#40012', '~ruecklauf|return line|BT3'),
            'onecta'   => array('managementPoints[embeddedId=climateControl].sensoryData.value.leavingWaterTemperature.value'),
            'melcloud' => array('ReturnTemperature'),
        )),
        'RAUM' => array(1, -20, 60, 'FELD.RAUM', array(
            'myuplink' => array('#40033', '~raumtemp|room temp|BT50'),
            'onecta'   => array('managementPoints[embeddedId=climateControl].sensoryData.value.roomTemperature.value'),
            'melcloud' => array('RoomTemperatureZone1'),
        )),
        'SOLL' => array(1, -20, 60, 'FELD.SOLL', array(
            'myuplink' => array('#47398', '~raum soll|room setpoint'),
            'onecta'   => array('managementPoints[embeddedId=climateControl].temperatureControl.value.operationModes.heating.setpoints.roomTemperature.value'),
            'melcloud' => array('SetTemperatureZone1'),
        )),
        'WW' => array(1, 0, 100, 'FELD.WW', array(
            'myuplink' => array('#40014', '~warmwasser|hot water|BT7'),
            'onecta'   => array('managementPoints[embeddedId=domesticHotWaterTank].sensoryData.value.tankTemperature.value'),
            'melcloud' => array('TankWaterTemperature'),
        )),
        'WWSOLL' => array(1, 0, 100, 'FELD.WWSOLL', array(
            'myuplink' => array('#47041', '~warmwasser soll|hot water setpoint'),
            'onecta'   => array('managementPoints[embeddedId=domesticHotWaterTank].temperatureControl.value.operationModes.heating.setpoints.domesticHotWaterTemperature.value'),
            'melcloud' => array('SetTankWaterTemperature'),
        )),
        'LEISTUNG' => array(1, 0, 30000, 'FELD.LEISTUNG', array(
            'myuplink' => array('#2305', '~leistungsaufnahme|power consumption'),
            'onecta'   => array(),   // Onecta liefert keine Momentanleistung
            'melcloud' => array(),   // MELCloud auch nicht
        )),
        'KOMPRESSOR' => array(0, 0, 1, 'FELD.KOMPRESSOR', array(
            'myuplink' => array('#44064', '~verdichter|compressor'),
            'onecta'   => array(),
            'melcloud' => array(),
        )),
        'WWZWANG' => array(0, 0, 1, 'FELD.WWZWANG', array(
            'myuplink' => array(),
            'onecta'   => array('managementPoints[embeddedId=domesticHotWaterTank].powerfulMode.value'),
            'melcloud' => array('ForcedHotWaterMode'),
        )),
        'EIN' => array(0, 0, 1, 'FELD.EIN', array(
            'myuplink' => array(),
            'onecta'   => array('managementPoints[embeddedId=climateControl].onOffMode.value'),
            'melcloud' => array('Power'),
        )),
    );
}

/** Kandidatenliste eines Feldes fuer den eingestellten Hersteller, inklusive Nutzer-Zuordnung. */
function wp_kandidaten($feld, $cfg)
{
    $f = wp_felder();
    if (!isset($f[$feld])) { return array(); }
    $eigen = wp_zuordnung_lesen($cfg['zuordnung']);
    if (isset($eigen[$feld]) && $eigen[$feld] !== '') {
        // Eine eigene Zuordnung ersetzt die Kandidaten, sie ergaenzt sie nicht.
        // Sonst greift bei einem Tippfehler still wieder der Vorgabepfad, und
        // der Nutzer sucht den Fehler an der falschen Stelle.
        return array($eigen[$feld]);
    }
    $k = $f[$feld][4];
    return isset($k[$cfg['hersteller']]) ? $k[$cfg['hersteller']] : array();
}

/** Die Textzuordnung "FELD=pfad" je Zeile einlesen. */
function wp_zuordnung_lesen($text)
{
    $out = array();
    foreach (preg_split('/\r?\n/', (string) $text) as $z) {
        $z = trim($z);
        if ($z === '' || $z[0] === '#') { continue; }
        if (strpos($z, '=') === false) { continue; }
        list($n, $p) = explode('=', $z, 2);
        $n = strtoupper(trim($n));
        $p = trim($p);
        if ($n !== '' && $p !== '') { $out[$n] = $p; }
    }
    return $out;
}

/**
 * Rohdaten in die Felder uebersetzen.
 * Rueckgabe: array('werte' => array(FELD=>Wert), 'wege' => array(FELD=>Pfad|''))
 */
function wp_umrechnen($roh, $cfg)
{
    $werte = array();
    $wege = array();
    foreach (wp_felder() as $name => $d) {
        $treffer = null;
        $weg = '';
        foreach (wp_kandidaten($name, $cfg) as $k) {
            $v = wp_pfad($roh, $k);
            if ($v !== null && $v !== '') { $treffer = $v; $weg = $k; break; }
        }
        if ($treffer !== null) {
            $werte[$name] = is_numeric($treffer) ? round((float) $treffer, 2) : $treffer;
        }
        $wege[$name] = $weg;
    }
    return array('werte' => $werte, 'wege' => $wege);
}

/* ==================================================================
 * Zwischenstand
 * ================================================================== */

function wp_stand()
{
    $f = wp_datadir() . '/stand.json';
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : array();
    if (!is_array($d)) { $d = array(); }
    return array_merge(array(
        'zeit' => 0, 'ok' => 0, 'werte' => array(), 'wege' => array(),
        'roh' => null, 'fehler' => '', 'geraete' => array(),
        'stufe_gesetzt' => 0, 'stufe_zeit' => 0, 'stufe_quittiert' => '',
    ), $d);
}

function wp_stand_write($s)
{
    $f = wp_datadir() . '/stand.json';
    return @file_put_contents($f, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

/* ==================================================================
 * myUplink
 *
 * OAuth2 mit client_credentials - deshalb braucht dieses Plugin keine
 * Rueckleitung ueber den Browser: Kennung und Geheimnis aus
 * dev.myuplink.com/apps genuegen. Das ist der bequemste der drei Wege.
 * ================================================================== */

function wp_mu_token()
{
    $g = wp_geheim();
    if ($g['client_id'] === '' || $g['client_secret'] === '') { return ''; }

    $f = wp_tmpdir() . '/token_myuplink.json';
    $t = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (is_array($t) && isset($t['token'], $t['bis']) && $t['bis'] > time() + 60) { return $t['token']; }

    $info = wp_hersteller_info('myuplink');
    $a = wp_http('POST', $info['token_url'],
        array('Content-Type: application/x-www-form-urlencoded'),
        http_build_query(array(
            'grant_type'    => 'client_credentials',
            'client_id'     => $g['client_id'],
            'client_secret' => $g['client_secret'],
            'scope'         => 'READSYSTEM WRITESYSTEM offline_access',
        )), 20);
    $d = wp_json($a);
    if ($a['code'] !== 200 || !isset($d['access_token'])) {
        wp_log('myUplink: Token abgelehnt (HTTP ' . $a['code'] . ')', 'mu_token');
        return '';
    }
    $gueltig = isset($d['expires_in']) ? (int) $d['expires_in'] : 3600;
    @file_put_contents($f, json_encode(array('token' => $d['access_token'], 'bis' => time() + $gueltig)));
    @chmod($f, 0600);
    return (string) $d['access_token'];
}

function wp_mu_kopf()
{
    $t = wp_mu_token();
    return $t === '' ? null : array('Authorization: Bearer ' . $t);
}

function wp_mu_geraete()
{
    $k = wp_mu_kopf();
    if ($k === null) { return array(); }
    $info = wp_hersteller_info('myuplink');
    $a = wp_http('GET', $info['basis'] . '/v2/systems/me', $k, null, 20);
    $d = wp_json($a);
    $out = array();
    if (isset($d['systems']) && is_array($d['systems'])) {
        foreach ($d['systems'] as $sys) {
            $sid = isset($sys['systemId']) ? (string) $sys['systemId'] : '';
            if (!isset($sys['devices']) || !is_array($sys['devices'])) { continue; }
            foreach ($sys['devices'] as $dev) {
                $out[] = array(
                    'id'     => isset($dev['id']) ? (string) $dev['id'] : '',
                    'name'   => isset($sys['name']) ? (string) $sys['name'] : 'myUplink',
                    'system' => $sid,
                );
            }
        }
    }
    return $out;
}

function wp_mu_lesen($cfg)
{
    $k = wp_mu_kopf();
    if ($k === null) { return array('ok' => 0, 'fehler' => 'KEIN_TOKEN', 'roh' => null); }
    if ($cfg['geraet'] === '') { return array('ok' => 0, 'fehler' => 'KEIN_GERAET', 'roh' => null); }
    $info = wp_hersteller_info('myuplink');
    $a = wp_http('GET', $info['basis'] . '/v2/devices/' . rawurlencode($cfg['geraet']) . '/points', $k, null, 25);
    $d = wp_json($a);
    if ($a['code'] !== 200 || !is_array($d)) {
        return array('ok' => 0, 'fehler' => 'HTTP_' . $a['code'], 'roh' => null);
    }
    return array('ok' => 1, 'fehler' => '', 'roh' => $d);
}

/**
 * SG Ready bei Nibe - der einzige echte Fall.
 *
 * 3032 schaltet die Bedienung ueber die Schnittstelle frei und muss auf 1
 * stehen, 6008 traegt den Zustand: 0 Sperre, 1 normal, 2 guenstiger Strom,
 * 3 Ueberschuss. Das entspricht den vier SG-Ready-Zustaenden eins zu eins.
 *
 * ACHTUNG: Diese beiden Nummern sind als Modbus-Register belegt. Bei den
 * S-Serien traegt ein Punkt in myUplink dieselbe Nummer als parameterId -
 * das Plugin PRUEFT das aber, statt es anzunehmen: wp_mu_sg_moeglich() sieht
 * in der Punkteliste nach, ob es beide gibt. Fehlen sie, sagt der Reiter Test
 * das ausdruecklich, und geschaltet wird nicht.
 */
function wp_mu_sg_register()
{
    return array('frei' => '3032', 'zustand' => '6008');
}

function wp_mu_sg_moeglich($roh)
{
    if (!is_array($roh)) { return false; }
    $r = wp_mu_sg_register();
    $gefunden = array();
    foreach ($roh as $pkt) {
        if (is_array($pkt) && isset($pkt['parameterId'])) { $gefunden[(string) $pkt['parameterId']] = 1; }
    }
    return isset($gefunden[$r['frei']]) && isset($gefunden[$r['zustand']]);
}

function wp_mu_schreiben($cfg, $stufe)
{
    $k = wp_mu_kopf();
    if ($k === null) { return array(0, 'KEIN_TOKEN'); }
    $r = wp_mu_sg_register();
    // SG-Ready-Zustand 1..4  ->  Register 6008 mit 0..3
    $wert = max(0, min(3, (int) $stufe - 1));
    $k[] = 'Content-Type: application/json';
    $info = wp_hersteller_info('myuplink');
    $a = wp_http('PATCH', $info['basis'] . '/v2/devices/' . rawurlencode($cfg['geraet']) . '/points', $k,
        json_encode(array($r['frei'] => 1, $r['zustand'] => $wert)), 25);
    if ($a['code'] >= 200 && $a['code'] < 300) { return array(1, ''); }
    // 403 heisst hier fast immer: kein myUplink-Abo. Das ist eine eigene
    // Meldung wert, sonst sucht der Nutzer den Fehler bei seinen Zugangsdaten.
    return array(0, $a['code'] === 403 ? 'KEIN_ABO' : 'HTTP_' . $a['code']);
}

/* ==================================================================
 * Daikin Onecta
 *
 * OAuth2 mit Autorisierungscode - eine Rueckleitung ueber den Browser ist
 * unvermeidlich. Das Plugin bietet beide Wege an: die Rueckleitung direkt
 * auf den LoxBerry, und - falls das Portal eine unverschluesselte Adresse
 * nicht annimmt - das Einfuegen des Codes von Hand.
 * ================================================================== */

function wp_oc_token()
{
    $g = wp_geheim();
    if ($g['client_id'] === '' || $g['client_secret'] === '') { return ''; }

    $f = wp_tmpdir() . '/token_onecta.json';
    $t = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (is_array($t) && isset($t['token'], $t['bis']) && $t['bis'] > time() + 60) { return $t['token']; }
    if ($g['refresh_token'] === '') { return ''; }

    /* Nur einer erneuert gleichzeitig.
     *
     * Daikin gibt bei jedem Erneuern ein NEUES Erneuerungsmerkmal aus und
     * entwertet das alte. Erneuern zwei Laeufe gleichzeitig - der Cron und
     * die Selbstpruefung in der Oberflaeche, die beim Oeffnen der Seite
     * ebenfalls einen Zugang braucht -, dann gewinnt einer, und der andere
     * schreibt hinterher ein bereits entwertetes Merkmal in die Datei. Das
     * Ergebnis ist eine dauerhaft tote Anbindung, die sich nur durch eine
     * neue Anmeldung im Browser wiederbeleben laesst. Deshalb die Sperre.
     */
    $sperrdatei = wp_tmpdir() . '/token_onecta.lock';
    $sp = @fopen($sperrdatei, 'c');
    if ($sp !== false) {
        if (!flock($sp, LOCK_EX)) { fclose($sp); $sp = false; }
    }
    if ($sp !== false) {
        // Waehrend des Wartens hat der andere Lauf vermutlich schon erneuert.
        clearstatcache(true, $f);
        $t = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        if (is_array($t) && isset($t['token'], $t['bis']) && $t['bis'] > time() + 60) {
            flock($sp, LOCK_UN);
            fclose($sp);
            return $t['token'];
        }
        $g = wp_geheim();   // koennte inzwischen ein neues Merkmal tragen
    }

    $info = wp_hersteller_info('onecta');
    $a = wp_http('POST', $info['token_url'],
        array('Content-Type: application/x-www-form-urlencoded'),
        http_build_query(array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $g['refresh_token'],
            'client_id'     => $g['client_id'],
            'client_secret' => $g['client_secret'],
        )), 20);
    $d = wp_json($a);
    if ($a['code'] !== 200 || !isset($d['access_token'])) {
        // 400 heisst hier fast immer: das Erneuerungsmerkmal ist abgelaufen
        // oder wurde zurueckgezogen. Dagegen hilft kein Wiederholen - es
        // braucht die einmalige Anmeldung im Browser noch einmal. Das wird
        // eigens vermerkt, damit die Selbstpruefung nicht auf die
        // Zugangsdaten zeigt, an denen nichts falsch ist.
        if ($a['code'] === 400 || $a['code'] === 401) {
            @file_put_contents(wp_tmpdir() . '/onecta_abgelaufen.stamp', (string) time());
        }
        wp_log('Onecta: Erneuern des Zugangs abgelehnt (HTTP ' . $a['code'] . ')', 'oc_token');
        if ($sp !== false) { flock($sp, LOCK_UN); fclose($sp); }
        return '';
    }
    @unlink(wp_tmpdir() . '/onecta_abgelaufen.stamp');

    // Das neue Erneuerungsmerkmal MUSS ankommen. Daikin hat das alte in
    // diesem Augenblick bereits entwertet - geht das Schreiben schief, ist
    // die Anbindung beim naechsten Lauf tot, und zwar lautlos. Deshalb wird
    // der Rueckgabewert geprueft und der Fehler ohne Bremse protokolliert.
    if (isset($d['refresh_token']) && $d['refresh_token'] !== '') {
        $g['refresh_token'] = (string) $d['refresh_token'];
        if (!wp_geheim_write($g)) {
            wp_log('SCHWERWIEGEND - Onecta: das neue Erneuerungsmerkmal konnte nicht '
                 . 'gespeichert werden. Das alte ist bereits entwertet. Nach Ablauf des '
                 . 'Zugangs ist eine neue Anmeldung im Browser noetig. Schreibrechte auf '
                 . wp_paths()['geheim'] . ' pruefen.');
        }
    }
    $gueltig = isset($d['expires_in']) ? (int) $d['expires_in'] : 3600;
    @file_put_contents($f, json_encode(array('token' => $d['access_token'], 'bis' => time() + $gueltig)));
    @chmod($f, 0600);
    if ($sp !== false) { flock($sp, LOCK_UN); fclose($sp); }
    return (string) $d['access_token'];
}

/** Ist die Onecta-Anmeldung abgelaufen (statt nur momentan gestoert)? */
function wp_oc_abgelaufen()
{
    return is_file(wp_tmpdir() . '/onecta_abgelaufen.stamp');
}

/** Aus dem einmaligen Code das dauerhafte Erneuerungsmerkmal holen. */
function wp_oc_code_einloesen($code)
{
    $g = wp_geheim();
    if ($g['client_id'] === '' || $g['client_secret'] === '' || $g['redirect_uri'] === '') {
        return array(0, 'UNVOLLSTAENDIG');
    }
    $info = wp_hersteller_info('onecta');
    $a = wp_http('POST', $info['token_url'],
        array('Content-Type: application/x-www-form-urlencoded'),
        http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $g['redirect_uri'],
            'client_id'     => $g['client_id'],
            'client_secret' => $g['client_secret'],
        )), 20);
    $d = wp_json($a);
    if ($a['code'] !== 200 || !isset($d['refresh_token'])) {
        return array(0, 'HTTP_' . $a['code']);
    }
    $g['refresh_token'] = (string) $d['refresh_token'];
    wp_geheim_write($g);
    if (isset($d['access_token'])) {
        $gueltig = isset($d['expires_in']) ? (int) $d['expires_in'] : 3600;
        $f = wp_tmpdir() . '/token_onecta.json';
        @file_put_contents($f, json_encode(array('token' => $d['access_token'], 'bis' => time() + $gueltig)));
        @chmod($f, 0600);
    }
    return array(1, '');
}

function wp_oc_anmeldeadresse()
{
    $g = wp_geheim();
    $info = wp_hersteller_info('onecta');
    return $info['auth_url'] . '?' . http_build_query(array(
        'response_type' => 'code',
        'client_id'     => $g['client_id'],
        'redirect_uri'  => $g['redirect_uri'],
        'scope'         => 'openid onecta:basic.integration',
        'state'         => substr(md5((string) $g['client_id'] . wp_paths()['plugin']), 0, 16),
    ));
}

function wp_oc_geraete()
{
    $t = wp_oc_token();
    if ($t === '') { return array(); }
    if (!wp_budget_frei('onecta')) { return array(); }
    $info = wp_hersteller_info('onecta');
    $a = wp_http('GET', $info['basis'] . '/v1/gateway-devices', array('Authorization: Bearer ' . $t), null, 25);
    wp_budget_buchen('onecta');
    $d = wp_json($a);
    if (!is_array($d)) { return array(); }
    $out = array();
    foreach ($d as $dev) {
        if (!is_array($dev)) { continue; }
        $out[] = array(
            'id'     => isset($dev['id']) ? (string) $dev['id'] : '',
            'name'   => isset($dev['deviceModel']) ? (string) $dev['deviceModel'] : 'Daikin',
            'system' => '',
        );
    }
    return $out;
}

function wp_oc_lesen($cfg)
{
    $t = wp_oc_token();
    if ($t === '') { return array('ok' => 0, 'fehler' => 'KEIN_TOKEN', 'roh' => null); }
    if ($cfg['geraet'] === '') { return array('ok' => 0, 'fehler' => 'KEIN_GERAET', 'roh' => null); }
    if (!wp_budget_frei('onecta')) {
        return array('ok' => 0, 'fehler' => 'BUDGET_LEER', 'roh' => null);
    }
    $info = wp_hersteller_info('onecta');
    $a = wp_http('GET', $info['basis'] . '/v1/gateway-devices/' . rawurlencode($cfg['geraet']),
        array('Authorization: Bearer ' . $t), null, 25);
    wp_budget_buchen('onecta');
    $d = wp_json($a);
    if ($a['code'] === 429) { return array('ok' => 0, 'fehler' => 'BUDGET_LEER', 'roh' => null); }
    if ($a['code'] !== 200 || !is_array($d)) {
        return array('ok' => 0, 'fehler' => 'HTTP_' . $a['code'], 'roh' => null);
    }
    return array('ok' => 1, 'fehler' => '', 'roh' => $d);
}

/** Ein Merkmal setzen. $pfad ist optional (verschachtelte Merkmale wie temperatureControl). */
function wp_oc_setzen($cfg, $punkt, $merkmal, $wert, $pfad = '')
{
    $t = wp_oc_token();
    if ($t === '') { return array(0, 'KEIN_TOKEN'); }
    if (!wp_budget_frei('onecta')) { return array(0, 'BUDGET_LEER'); }
    $info = wp_hersteller_info('onecta');
    $koerper = array('value' => $wert);
    if ($pfad !== '') { $koerper['path'] = $pfad; }
    $a = wp_http('PATCH',
        $info['basis'] . '/v1/gateway-devices/' . rawurlencode($cfg['geraet'])
        . '/management-points/' . rawurlencode($punkt) . '/characteristics/' . rawurlencode($merkmal),
        array('Authorization: Bearer ' . $t, 'Content-Type: application/json'),
        json_encode($koerper), 25);
    wp_budget_buchen('onecta');
    if ($a['code'] >= 200 && $a['code'] < 300) { return array(1, ''); }
    return array(0, $a['code'] === 429 ? 'BUDGET_LEER' : 'HTTP_' . $a['code']);
}

/* ==================================================================
 * MELCloud
 *
 * Inoffiziell. Anmeldung mit E-Mail und Passwort gegen einen ContextKey,
 * der als Kopfzeile X-MitsContextKey mitgeht. Der Schluessel wird
 * zwischengespeichert - eine Anmeldung je Abruf waere die doppelte Last auf
 * einem Dienst, der ohnehin Grenzen zieht.
 * ================================================================== */

function wp_ml_schluessel($erzwingen = false)
{
    $f = wp_tmpdir() . '/token_melcloud.json';
    if (!$erzwingen) {
        $t = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        if (is_array($t) && isset($t['key'], $t['bis']) && $t['bis'] > time()) { return (string) $t['key']; }
    }
    $g = wp_geheim();
    if ($g['benutzer'] === '' || $g['passwort'] === '') { return ''; }

    $info = wp_hersteller_info('melcloud');
    $a = wp_http('POST', $info['basis'] . '/Login/ClientLogin',
        array('Content-Type: application/json; charset=UTF-8'),
        json_encode(array(
            'Email' => $g['benutzer'], 'Password' => $g['passwort'],
            'Language' => 4, 'AppVersion' => '1.19.1.1',
            'Persist' => true, 'CaptchaResponse' => null,
        )), 25);
    $d = wp_json($a);
    if (!is_array($d) || !isset($d['LoginData']['ContextKey'])) {
        // ErrorId 1 heisst: Zugangsdaten falsch. Das gehoert eigens gemeldet.
        $grund = (isset($d['ErrorId']) && (int) $d['ErrorId'] === 1) ? 'Zugangsdaten abgelehnt' : 'HTTP ' . $a['code'];
        wp_log('MELCloud: Anmeldung fehlgeschlagen (' . $grund . ')', 'ml_login');
        return '';
    }
    $key = (string) $d['LoginData']['ContextKey'];
    @file_put_contents($f, json_encode(array('key' => $key, 'bis' => time() + 12 * 3600)));
    @chmod($f, 0600);
    return $key;
}

function wp_ml_kopf($key)
{
    return array('X-MitsContextKey: ' . $key, 'X-Requested-With: XMLHttpRequest');
}

function wp_ml_geraete()
{
    $key = wp_ml_schluessel();
    if ($key === '') { return array(); }
    $info = wp_hersteller_info('melcloud');
    $a = wp_http('GET', $info['basis'] . '/User/ListDevices', wp_ml_kopf($key), null, 25);
    $d = wp_json($a);
    if (!is_array($d)) { return array(); }
    $out = array();
    // ListDevices liefert Gebaeude, darin Etagen/Bereiche, darin Geraete. Die
    // Verschachtelung schwankt je Konto - deshalb wird rekursiv nach allem
    // gesucht, was eine DeviceID traegt.
    $sammeln = function ($knoten, $gebaeude) use (&$sammeln, &$out) {
        if (!is_array($knoten)) { return; }
        if (isset($knoten['DeviceID'])) {
            $out[] = array(
                'id'     => (string) $knoten['DeviceID'],
                'name'   => isset($knoten['DeviceName']) ? (string) $knoten['DeviceName'] : 'MELCloud',
                'system' => (string) $gebaeude,
                'typ'    => isset($knoten['Device']['DeviceType']) ? (int) $knoten['Device']['DeviceType'] : -1,
            );
            return;
        }
        $g = isset($knoten['ID']) && isset($knoten['Structure']) ? $knoten['ID'] : $gebaeude;
        foreach ($knoten as $k => $v) { if (is_array($v)) { $sammeln($v, $g); } }
    };
    $sammeln($d, '');
    return $out;
}

function wp_ml_lesen($cfg)
{
    $key = wp_ml_schluessel();
    if ($key === '') { return array('ok' => 0, 'fehler' => 'KEIN_TOKEN', 'roh' => null); }
    if ($cfg['geraet'] === '') { return array('ok' => 0, 'fehler' => 'KEIN_GERAET', 'roh' => null); }
    $info = wp_hersteller_info('melcloud');
    $a = wp_http('GET', $info['basis'] . '/Device/Get?id=' . rawurlencode($cfg['geraet'])
        . '&buildingID=' . rawurlencode($cfg['gebaeude']), wp_ml_kopf($key), null, 25);
    if ($a['code'] === 401) {
        // Schluessel abgelaufen - einmal neu anmelden, dann aufgeben.
        $key = wp_ml_schluessel(true);
        if ($key === '') { return array('ok' => 0, 'fehler' => 'KEIN_TOKEN', 'roh' => null); }
        $a = wp_http('GET', $info['basis'] . '/Device/Get?id=' . rawurlencode($cfg['geraet'])
            . '&buildingID=' . rawurlencode($cfg['gebaeude']), wp_ml_kopf($key), null, 25);
    }
    $d = wp_json($a);
    if ($a['code'] !== 200 || !is_array($d)) {
        return array('ok' => 0, 'fehler' => 'HTTP_' . $a['code'], 'roh' => null);
    }
    return array('ok' => 1, 'fehler' => '', 'roh' => $d);
}

/**
 * EffectiveFlags - die Bitmaske, die MELCloud sagt, WELCHE der mitgesendeten
 * Felder es uebernehmen soll. Ohne das passende Bit wird ein Feld
 * stillschweigend ignoriert.
 *
 * Hier stehen nur Werte, die aus einer laufenden Anbindung belegt sind. Fuer
 * ProhibitZone1 und ProhibitHotWater - die einer Sperre am naechsten kaemen -
 * ist das Bit NICHT belegt, deshalb werden sie hier nur gelesen und nie
 * geschrieben. Ein geratenes Bit waere ein Schreibbefehl, von dem niemand
 * weiss, was er trifft.
 */
function wp_ml_flags()
{
    return array(
        'Power'                => 1,
        'OperationMode'        => 8,
        'ForcedHotWaterMode'   => 65536,
        'SetTemperatureZone1'  => 8589934592,
        'SetTankWaterTemperature' => 281474976710688,
    );
}

/**
 * MELCloud-Geraetetypen. Nur der Luft/Wasser-Typ wird bedient.
 *
 * WARUM NICHT EINFACH DEN TYP DURCHREICHEN: Der Endpunkt /Device/SetAtw ist
 * fuer Luft/Wasser gebaut. Eine Klimaanlage (Typ 0) verlangt /Device/SetAta
 * mit voellig anderen Feldern UND anderen EffectiveFlags-Bits. Diese Bits
 * sind hier nicht belegt, und ein geratenes Bit ist ein Schreibbefehl, von
 * dem niemand weiss, was er trifft. Deshalb wird abgewiesen statt geraten.
 */
function wp_ml_typen()
{
    return array(0 => 'Luft/Luft (Klimageraet)', 1 => 'Luft/Wasser (Ecodan)',
                 3 => 'Lueftung (Lossnay)');
}

function wp_ml_setzen($cfg, $felder)
{
    // Typ -1 heisst: noch nicht gesucht. Dann wird Luft/Wasser angenommen,
    // denn das ist der Fall, fuer den dieses Plugin gebaut ist - und der
    // Reiter Test sagt ausdruecklich, dass es eine Annahme ist.
    $typ = (int) $cfg['geraetetyp'];
    if ($typ !== 1 && $typ !== -1) {
        return array(0, 'GERAETETYP_' . $typ);
    }
    $key = wp_ml_schluessel();
    if ($key === '') { return array(0, 'KEIN_TOKEN'); }
    $flags = wp_ml_flags();
    $maske = 0;
    foreach (array_keys($felder) as $f) {
        if (!isset($flags[$f])) { return array(0, 'UNBEKANNTES_FELD_' . $f); }
        $maske |= $flags[$f];
    }
    $koerper = array_merge($felder, array(
        'EffectiveFlags'    => $maske,
        'DeviceID'          => (int) $cfg['geraet'],
        'DeviceType'        => 1,          // 1 = Luft/Wasser (Ecodan)
        'HasPendingCommand' => true,
        'Offline'           => false,
    ));
    $info = wp_hersteller_info('melcloud');
    $kopf = wp_ml_kopf($key);
    $kopf[] = 'Content-Type: application/json; charset=UTF-8';
    $a = wp_http('POST', $info['basis'] . '/Device/SetAtw', $kopf, json_encode($koerper), 25);
    if ($a['code'] >= 200 && $a['code'] < 300) { return array(1, ''); }
    return array(0, 'HTTP_' . $a['code']);
}

/* ==================================================================
 * Gemeinsame Klammer
 * ================================================================== */

function wp_geraete_suchen($hersteller)
{
    switch ($hersteller) {
        case 'myuplink': return wp_mu_geraete();
        case 'onecta':   return wp_oc_geraete();
        case 'melcloud': return wp_ml_geraete();
    }
    return array();
}

function wp_lesen($cfg)
{
    switch ($cfg['hersteller']) {
        case 'myuplink': return wp_mu_lesen($cfg);
        case 'onecta':   return wp_oc_lesen($cfg);
        case 'melcloud': return wp_ml_lesen($cfg);
    }
    return array('ok' => 0, 'fehler' => 'KEIN_HERSTELLER', 'roh' => null);
}

/* ==================================================================
 * SG Ready
 *
 * Die vier Zustaende der Norm, so wie sie an den beiden Klemmen anliegen:
 *
 *   1  1:0  Sperre            EVU-Sperre, hoechstens zwei Stunden
 *   2  0:0  Normalbetrieb
 *   3  0:1  Einschaltempfehlung
 *   4  1:1  Anlaufbefehl      Zwang, z. B. bei PV-Ueberschuss
 * ================================================================== */

function wp_sg_klemmen($stufe)
{
    switch ((int) $stufe) {
        case 1: return array(1, 0);
        case 2: return array(0, 0);
        case 3: return array(0, 1);
        case 4: return array(1, 1);
    }
    return array(0, 0);
}

function wp_sg_stufe_aus_klemmen($k1, $k2)
{
    $k1 = $k1 ? 1 : 0;
    $k2 = $k2 ? 1 : 0;
    if ($k1 && !$k2) { return 1; }
    if (!$k1 && !$k2) { return 2; }
    if (!$k1 && $k2) { return 3; }
    return 4;
}

/** Bildet dieser Hersteller SG Ready echt ab oder nur nach? */
function wp_sg_echt($hersteller)
{
    $i = wp_hersteller_info($hersteller);
    return $i ? (int) $i['sg_echt'] : 0;
}

/**
 * Den gewuenschten Zustand an die Waermepumpe bringen.
 *
 * Rueckgabe: array(ok, grund, beschreibung) - die Beschreibung sagt in
 * Klartext, WAS getan wurde. Bei einer Nachbildung ist das der wichtigste
 * Teil der Antwort: der Nutzer muss sehen, dass "Sperre" bei Daikin
 * "Heizkreis aus" heisst und nicht mehr.
 */
function wp_sg_anwenden($cfg, $stufe, $stand = null)
{
    $stufe = max(1, min(WP_STUFEN, (int) $stufe));
    if ($stand === null) { $stand = wp_stand(); }
    $basis = (float) $cfg['basis_soll'];

    switch ($cfg['hersteller']) {

        case 'myuplink':
            list($ok, $grund) = wp_mu_schreiben($cfg, $stufe);
            return array($ok, $grund, 'SGREADY=' . $stufe);

        case 'onecta':
            // Nachgebildet. Es gibt bei Onecta keinen SG-Ready-Eingang.
            $getan = array();
            if ($stufe === 1) {
                list($ok, $grund) = wp_oc_setzen($cfg, 'climateControl', 'onOffMode', 'off');
                $getan[] = 'climateControl.onOffMode=off';
                return array($ok, $grund, implode(' ', $getan));
            }
            list($ok, $grund) = wp_oc_setzen($cfg, 'climateControl', 'onOffMode', 'on');
            $getan[] = 'climateControl.onOffMode=on';
            if (!$ok) { return array(0, $grund, implode(' ', $getan)); }

            if ($basis > 0) {
                $soll = $basis + ($stufe === 3 ? $cfg['anhebung_3'] : ($stufe === 4 ? $cfg['anhebung_4'] : 0));
                list($ok, $grund) = wp_oc_setzen($cfg, 'climateControl', 'temperatureControl', $soll,
                    '/operationModes/heating/setpoints/roomTemperature');
                $getan[] = 'Sollwert=' . $soll;
                if (!$ok) { return array(0, $grund, implode(' ', $getan)); }
            }
            if ($stufe === 4 && $cfg['ww_boost_4']) {
                list($ok, $grund) = wp_oc_setzen($cfg, 'domesticHotWaterTank', 'powerfulMode', 'on');
                $getan[] = 'Warmwasser-Zwang=on';
                if (!$ok) { return array(0, $grund, implode(' ', $getan)); }
            } elseif ($cfg['ww_boost_4']) {
                list($ok, $grund) = wp_oc_setzen($cfg, 'domesticHotWaterTank', 'powerfulMode', 'off');
                $getan[] = 'Warmwasser-Zwang=off';
            }
            return array($ok, $grund, implode(' ', $getan));

        case 'melcloud':
            // Nachgebildet. MELCloud kennt kein SG Ready.
            $felder = array();
            if ($stufe === 1) {
                $felder['Power'] = false;
                list($ok, $grund) = wp_ml_setzen($cfg, $felder);
                return array($ok, $grund, 'Power=false');
            }
            $felder['Power'] = true;
            if ($basis > 0) {
                $felder['SetTemperatureZone1'] = $basis
                    + ($stufe === 3 ? $cfg['anhebung_3'] : ($stufe === 4 ? $cfg['anhebung_4'] : 0));
            }
            if ($cfg['ww_boost_4']) {
                $felder['ForcedHotWaterMode'] = ($stufe === 4);
            }
            list($ok, $grund) = wp_ml_setzen($cfg, $felder);
            $t = array();
            foreach ($felder as $k => $v) { $t[] = $k . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : $v); }
            return array($ok, $grund, implode(' ', $t));
    }
    return array(0, 'KEIN_HERSTELLER', '');
}

/**
 * Ist die Sperre zu lange her?
 *
 * SG Ready sieht fuer den Sperrzustand hoechstens zwei Stunden vor. Bleibt
 * Loxone haengen oder faellt der Miniserver aus, waehrend gerade gesperrt
 * ist, stuende die Heizung sonst unbegrenzt still - im Januar ist das kein
 * Schoenheitsfehler. Deshalb faellt die Sperre nach der eingestellten Zeit
 * von selbst auf Normalbetrieb zurueck.
 */
function wp_sg_sperre_abgelaufen($cfg, $stand)
{
    if ((int) $stand['stufe_gesetzt'] !== 1) { return false; }
    if ((int) $stand['stufe_zeit'] <= 0) { return false; }
    return (time() - (int) $stand['stufe_zeit']) > ((int) $cfg['sperre_max'] * 60);
}

/* ==================================================================
 * MQTT
 * ================================================================== */

function wp_mqtt_zustand()
{
    $p = wp_paths();
    $f = $p['home'] . '/config/system/general.json';
    $aus = array('gefunden' => false, 'udpport' => 0, 'autostart' => false);
    if (!is_file($f)) { return $aus; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!isset($d['Mqtt'])) { return $aus; }
    $aus['gefunden'] = true;
    $aus['udpport'] = isset($d['Mqtt']['Udpinport']) ? (int) $d['Mqtt']['Udpinport'] : 0;
    $aus['autostart'] = !empty($d['Mqtt']['Autostart']);
    return $aus;
}

function wp_mqtt_senden($werte)
{
    $cfg = wp_config();
    if (empty($cfg['mqtt_ein'])) { return false; }
    $m = wp_mqtt_zustand();
    if (!$m['gefunden'] || !$m['udpport']) { return false; }
    $sock = @fsockopen('udp://127.0.0.1', (int) $m['udpport'], $en, $es, 2);
    if (!$sock) { return false; }
    // UDP ist verbindungslos: dass das Gateway die Zeile bekommt, laesst sich
    // von hier aus nicht feststellen. Was sich feststellen laesst, ist ob sie
    // ueberhaupt den Rechner verlassen hat - und genau das wurde bisher
    // weggeworfen. Geht gar nichts hinaus, ist das eine Meldung wert.
    $raus = 0;
    $alle = 0;
    foreach ($werte as $name => $wert) {
        $zeile = 'publish ' . $cfg['mqtt_topic'] . '/' . $name . ' ' . $wert . "\n";
        $alle++;
        if (@fwrite($sock, $zeile) !== false) { $raus++; }
    }
    @fclose($sock);
    if ($alle > 0 && $raus === 0) {
        wp_log('MQTT: keine einzige Zeile liess sich absenden (UDP-Eingang '
             . (int) $m['udpport'] . '). Laeuft das Gateway?', 'mqtt_tot');
        return false;
    }
    return true;
}

/* ==================================================================
 * Loxone-Vorlage
 *
 * Geprueefter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 * CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 * Uebernommen aus LoxBerry-Plugin-APC-UPS, nur das Kuerzel getauscht.
 * ================================================================== */

function wp_endpunkt($aktion = 'status')
{
    $p = wp_paths();
    $cfg = wp_config();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    return 'http://' . $host . '/plugins/' . $p['plugin'] . '/index.php'
         . '?token=' . $cfg['aktionstoken'] . '&aktion=' . $aktion;
}

/** Die Felder der Statuszeile - Zustandsfelder zuerst, dann die Messwerte. */
function wp_statusfelder()
{
    $out = array(
        'OK'     => array(0, 0, 1, 'FELD.OK'),
        'STUFE'  => array(1, 1, 4, 'FELD.STUFE'),
        'ALTER'  => array(1, 0, 86400, 'FELD.ALTER'),
        'BUDGET' => array(1, -1, 1000, 'FELD.BUDGET'),
    );
    foreach (wp_felder() as $name => $d) {
        $out[$name] = array($d[0], $d[1], $d[2], $d[3]);
    }
    return $out;
}

function wp_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . wp_x($kopf['title']) . '" ';
    $o .= 'Comment="' . wp_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . wp_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . wp_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . wp_x($c['title']) . '" ';
        $o .= 'Comment="' . wp_x($c['comment']) . '" ';
        $o .= 'Check="' . wp_x($c['check']) . '" ';
        $o .= 'Signed="' . ($c['min'] < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . (int) $c['min'] . '" ';
        $o .= 'MaxVal="' . (int) $c['max'] . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

function wp_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'Title="' . wp_x($kopf['title']) . '" ';
    $o .= 'Comment="' . wp_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . wp_x($kopf['address']) . '" ';
    $o .= 'CloseAfterSend="' . (!empty($kopf['close']) ? 'true' : 'false') . '" ';
    $o .= 'CmdSep="' . wp_x(isset($kopf['cmdsep']) ? $kopf['cmdsep'] : '') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . wp_x($c['title']) . '" ';
        $o .= 'Comment="' . wp_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="' . wp_x(isset($c['methode']) ? $c['methode'] : 'GET') . '" ';
        $o .= 'CmdOn="' . wp_x($c['ein']) . '" ';
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

function wp_vorlage_ein()
{
    $cmds = array();
    foreach (wp_statusfelder() as $name => $d) {
        list($analog, $min, $max, $schluessel) = $d;
        $cmds[] = array(
            'title'   => 'WP_' . $name,
            'comment' => trim(strip_tags(html_entity_decode(wp_t($schluessel), ENT_QUOTES, 'UTF-8'))),
            // Das Semikolon gehoert ins Muster, und zwar zwingend.
            // Loxone sucht die Zeichenkette woertlich und nimmt den ERSTEN
            // Treffer. Ohne fuehrendes Semikolon findet "SOLL=" auch die
            // Stelle in "WWSOLL=50" - solange SOLL vorher in der Zeile steht,
            // faellt das nicht auf, aber sobald ein Modell keinen
            // Raum-Sollwert liefert, stuende der Warmwasser-Sollwert im
            // falschen Eingang. Ein falscher Wert ist schlimmer als keiner.
            // In wp_zeile() ist jedem Feld ein Semikolon vorangestellt.
            'check'   => '\i;' . $name . '=\i\v',
            'analog'  => $analog, 'min' => $min, 'max' => $max,
        );
    }
    $cfg = wp_config();
    return array('VI_waermepumpe.xml', wp_xml_virtual_in_http(array(
        'title'   => 'Waermepumpe Cloud',
        'address' => wp_endpunkt('status'),
        'polling' => (string) max(60, (int) $cfg['takt']),
        'comment' => 'Erzeugt vom LoxBerry-Plugin Waermepumpe Cloud (' . date('d.m.Y') . '). '
                   . 'Loxone Config legt beim Import neu an und ueberschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine.',
    ), $cmds));
}

function wp_vorlage_aus()
{
    // Vier Ausgaenge, einer je SG-Ready-Zustand. Ein einzelner analoger
    // Ausgang waere kuerzer, aber dann muesste der Anwender in Loxone eine
    // Zahl bilden - vier Digitalausgaenge lassen sich direkt an die vier
    // Ausgaenge einer Logik haengen.
    $cmds = array();
    for ($s = 1; $s <= WP_STUFEN; $s++) {
        $cmds[] = array(
            'title'   => 'WP_SGREADY_' . $s,
            'comment' => trim(strip_tags(html_entity_decode(wp_t('SG.STUFE' . $s), ENT_QUOTES, 'UTF-8'))),
            'ein'     => wp_endpunkt('sgready') . '&stufe=' . $s,
            'methode' => 'GET',
            'analog'  => 0,
        );
    }
    $p = wp_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    return array('VQ_waermepumpe.xml', wp_xml_virtual_out(array(
        'title'   => 'Waermepumpe SG Ready',
        'address' => 'http://' . $host,
        'comment' => 'Erzeugt vom LoxBerry-Plugin Waermepumpe Cloud (' . date('d.m.Y') . '). '
                   . 'Je Zustand ein Ausgang - immer nur einen gleichzeitig auf 1 setzen.',
        'close'   => 1,
        'cmdsep'  => '',
    ), $cmds));
}

/* ==================================================================
 * Statuszeile und Abruf
 *
 * EINE Funktion baut die Zeile, und Endpunkt wie Selbstpruefung benutzen
 * genau diese. Zwei Stellen, die dasselbe Format erzeugen, laufen frueher
 * oder spaeter auseinander - dann stimmt die Vorlage nicht mehr zur
 * Wirklichkeit, und der Fehler faellt erst in Loxone Config auf.
 * ================================================================== */

function wp_zeile($stand, $cfg)
{
    $alter = $stand['zeit'] > 0 ? time() - (int) $stand['zeit'] : 86400;
    $teile = array('WP');
    $teile[] = 'OK=' . ((int) $stand['ok'] ? 1 : 0);
    $teile[] = 'STUFE=' . (int) ($stand['stufe_gesetzt'] ?: $cfg['sg_stufe']);
    $teile[] = 'ALTER=' . min(86400, max(0, $alter));
    $teile[] = 'BUDGET=' . wp_budget_rest($cfg['hersteller']);
    foreach (wp_felder() as $name => $d) {
        if (!isset($stand['werte'][$name])) { continue; }
        $v = $stand['werte'][$name];
        $teile[] = $name . '=' . (is_numeric($v) ? (0 + $v) : $v);
    }
    return implode(';', $teile);
}

/**
 * Einen Abruf durchfuehren und den Zwischenstand fortschreiben.
 *
 * Wird vom Cron und vom Reiter Test benutzt. $erzwingen umgeht den Takt -
 * aber niemals das Tagesbudget: ein Knopf, der ein Kontingent leerraeumt,
 * das erst am naechsten Tag zurueckkommt, waere eine Falle.
 */
function wp_abrufen($erzwingen = false)
{
    $cfg = wp_config();
    $stand = wp_stand();

    if ($cfg['hersteller'] === '') { return array(0, 'KEIN_HERSTELLER'); }

    if (!$erzwingen && $stand['zeit'] > 0 && (time() - (int) $stand['zeit']) < (int) $cfg['takt']) {
        return array(1, 'NOCH_FRISCH');
    }
    if (!wp_budget_frei($cfg['hersteller'])) {
        wp_log('Tagesbudget aufgebraucht - kein Abruf', 'budget_leer');
        return array(0, 'BUDGET_LEER');
    }

    /* Zufaellige Verzoegerung vor dem tatsaechlichen Abruf.
     *
     * Cron startet zur Sekunde 00. Ohne Streuung schlagen alle
     * Installationen, die gerade faellig sind, in derselben Sekunde beim
     * Hersteller auf - und nach einer Stoerung, wenn alle zugleich wieder
     * anlaufen, erst recht. Das beantworten Cloud-Dienste gern mit 502 oder
     * 504, was hier wie ein eigener Fehler aussieht.
     *
     * Die Verzoegerung steht bewusst HIER und nicht am Anfang von
     * wp_abruf.php: dort wuerde sie jede Minute laufen, auch in den 8 von 9
     * Durchgaengen, in denen ohnehin nichts abgerufen wird - ein PHP-Prozess,
     * der jede Minute drei Sekunden lang schlaeft, ohne etwas zu tun.
     * Beim erzwungenen Abruf ueber den Knopf im Reiter Test entfaellt sie,
     * dort soll es zuegig gehen.
     */
    if (!$erzwingen) { usleep(mt_rand(0, 3000000)); }

    $erg = wp_lesen($cfg);
    $stand['ok'] = (int) $erg['ok'];
    $stand['fehler'] = (string) $erg['fehler'];

    if ($erg['ok']) {
        $u = wp_umrechnen($erg['roh'], $cfg);

        // Rohantwort und Feldwege werden IMMER fortgeschrieben, auch wenn
        // nichts Brauchbares drinsteht - der Reiter Test lebt davon, dass man
        // sich ansehen kann, was tatsaechlich gekommen ist.
        $stand['roh'] = $erg['roh'];
        $stand['wege'] = $u['wege'];

        if ($u['werte']) {
            $stand['werte'] = $u['werte'];
            $stand['zeit'] = time();

            // Den Sollwert im Normalbetrieb einmal merken. Ohne ihn weiss die
            // Nachbildung nicht, worauf sie nach einer Anhebung zurueckstellen
            // soll - und wuerde die Anhebung bei jedem Durchlauf aufaddieren.
            if ((float) $cfg['basis_soll'] <= 0
                && (int) ($stand['stufe_gesetzt'] ?: 2) === 2
                && isset($stand['werte']['SOLL']) && (float) $stand['werte']['SOLL'] > 0) {
                $cfg['basis_soll'] = (float) $stand['werte']['SOLL'];
                wp_config_write($cfg);
                wp_log('Sollwert im Normalbetrieb gemerkt: ' . $cfg['basis_soll']);
            }

        } elseif ($stand['werte']) {
            // HTTP 200, gueltiges JSON - und trotzdem kein einziger Wert
            // darin. Das kommt vor: MELCloud antwortet in Stoerfaellen mit
            // einem gueltigen Objekt, das nur eine ErrorId traegt, und eine
            // voruebergehend offline gemeldete Anlage liefert eine Huelle
            // ohne Messwerte.
            //
            // Ein solcher Durchlauf darf NICHT als Erfolg zaehlen. Wuerde
            // hier zeit fortgeschrieben, saehe die Anlage in Loxone taufrisch
            // aus, waehrend in Wirklichkeit seit Stunden nichts mehr kommt -
            // und die Ausfallerkennung ueber WP_ALTER, die genau dagegen
            // gebaut ist, loeste nie aus. Die alten Werte bleiben stehen,
            // ALTER waechst weiter, und Loxone meldet den Ausfall.
            $stand['ok'] = 0;
            $stand['fehler'] = 'LEERE_ANTWORT';
            $erg['ok'] = 0;
            $erg['fehler'] = 'LEERE_ANTWORT';
            wp_log('Antwort ohne verwertbare Werte - alter Stand bleibt stehen, '
                 . 'Datenalter laeuft weiter', 'leere_antwort');
        }
        // Sonderfall: noch nie Werte gehabt (erste Einrichtung, Zuordnung
        // stimmt noch nicht). Dann bleibt es bei ok=1, denn es gibt keinen
        // alten Stand zu schuetzen - und der Reiter Test soll sagen koennen,
        // dass die Verbindung steht und nur die Zuordnung fehlt.

    } else {
        wp_log('Abruf fehlgeschlagen: ' . $erg['fehler'], 'abruf_' . $erg['fehler']);
    }
    wp_stand_write($stand);

    if ($erg['ok']) {
        $werte = $stand['werte'];
        $werte['OK'] = 1;
        $werte['STUFE'] = (int) ($stand['stufe_gesetzt'] ?: $cfg['sg_stufe']);
        $werte['BUDGET'] = wp_budget_rest($cfg['hersteller']);
        wp_mqtt_senden($werte);
    }
    return array((int) $erg['ok'], (string) $erg['fehler']);
}

/**
 * Den gewuenschten SG-Ready-Zustand durchsetzen, wenn noetig.
 *
 * Geschrieben wird NUR bei einer Aenderung. Ein Schreibbefehl je Minute
 * waere bei Daikin nach drei Stunden das Tagesbudget und bei MELCloud ein
 * Grund, ausgesperrt zu werden.
 */
function wp_sg_durchsetzen($cfg = null)
{
    if ($cfg === null) { $cfg = wp_config(); }
    if (empty($cfg['sg_ein'])) { return array(1, 'SG_AUS', ''); }

    /* Nichts schalten, bevor jemand darum gebeten hat.
     *
     * sg_stufe traegt als Vorgabe die 2 (Normalbetrieb). Ohne diese Sperre
     * wuerde der erste Cron-Lauf nach dem Einrichten also ungefragt
     * "Normalbetrieb" an die Waermepumpe schicken - bei Daikin heisst das
     * onOffMode=on, und damit liefe ein Heizkreis wieder an, den der
     * Bewohner womoeglich mit Absicht ausgeschaltet hatte. Ein Plugin, das
     * beim ersten Start von sich aus die Heizung anwirft, ist ein Plugin,
     * dem man beim zweiten Mal nicht mehr traut.
     *
     * Gesetzt wird die Marke erst, wenn Loxone ueber den Endpunkt oder der
     * Nutzer ueber die Knoepfe im Reiter Test einen Zustand anfordert.
     */
    if (empty($cfg['sg_angefordert'])) { return array(1, 'NIE_ANGEFORDERT', ''); }

    $stand = wp_stand();

    $wunsch = (int) $cfg['sg_stufe'];
    if (wp_sg_sperre_abgelaufen($cfg, $stand)) {
        wp_log('Sperre laeuft seit mehr als ' . (int) $cfg['sperre_max']
             . ' Minuten - Rueckfall auf Normalbetrieb.');
        $wunsch = 2;
        $cfg['sg_stufe'] = 2;
        wp_config_write($cfg);
    }
    if ((int) $stand['stufe_gesetzt'] === $wunsch) { return array(1, 'UNVERAENDERT', ''); }

    list($ok, $grund, $was) = wp_sg_anwenden($cfg, $wunsch, $stand);
    if ($ok) {
        $stand['stufe_gesetzt'] = $wunsch;
        $stand['stufe_zeit'] = time();
        $stand['stufe_quittiert'] = $was;
        wp_stand_write($stand);
        wp_log('SG Ready ' . $wunsch . ' gesetzt (' . $was . ')');
        wp_mqtt_senden(array('STUFE' => $wunsch));
    } else {
        wp_log('SG Ready ' . $wunsch . ' fehlgeschlagen: ' . $grund, 'sg_' . $grund);
    }
    return array($ok, $grund, $was);
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch.
 * ================================================================== */

function wp_sprache()
{
    $s = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $s = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $s = getenv('LBLANG');
    }
    $s = strtolower(substr((string) $s, 0, 2));
    return in_array($s, array('de', 'en'), true) ? $s : 'en';
}

function wp_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $p = wp_paths();
        $pfad = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
        if (!is_dir($pfad)) { $pfad = dirname(dirname(__DIR__)) . '/templates/lang'; }
        $texte = @parse_ini_file($pfad . '/language_' . wp_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) { $texte[$ab][$s] = trim((string) $w, '"'); }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}
