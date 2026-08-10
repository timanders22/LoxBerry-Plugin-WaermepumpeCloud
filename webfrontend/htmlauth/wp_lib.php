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


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function wp_paths()
{
    static $p = null;
    if ($p !== null) { return $p; }

    $home = getenv('LBHOMEDIR');
    if (!$home) { $home = lb_wurzel_ermitteln(); }
    /* LBPPLUGINDIR ist die Auskunft von LoxBerry selbst und hat Vorrang.
     * Fehlt sie, wird der Ordner aus dem Ablageort DIESER Datei genommen -
     * installiert liegt sie unter htmlauth/plugins/<ordner>/. Erst wenn auch
     * das nachweislich keinen Plugin-Ordner ergibt, greift der feste Name.
     *
     * Der feste Name allein waere zu wenig: Haengt LoxBerry bei einer
     * Zweitinstallation einen Zaehler an (waermepumpe_01), zeigten deren
     * Pfade sonst auf die erste - gemeinsame geheim.json mit den
     * Erneuerungsmerkmalen dreier Herstellerclouds. */
    $plugin = getenv('LBPPLUGINDIR');
    if (!$plugin) { $plugin = basename(dirname(__FILE__)); }
    if ($plugin === '' || $plugin === '.' || $plugin === '/'
        || $plugin === 'htmlauth' || $plugin === 'plugins') {
        $plugin = 'waermepumpe';
    }

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
/**
 * Die eingestellte Ausfuehrlichkeit des Protokolls.
 *
 * CUSTOM_LOGLEVELS in der plugin.cfg blendet in der Plugin-Verwaltung einen
 * Waehler ein. Der wirkt aber nur, wenn das Plugin den gewaehlten Wert auch
 * LIEST - bis 0.9.3 tat es das nicht, und der Waehler war ein Bedienelement
 * ohne Wirkung. Das ist schlimmer als keines: der Anwender stellt etwas ein
 * und sucht dann, warum sich nichts aendert.
 *
 * Gebraucht wird der Wert an genau einer Stelle, siehe wp_log(): bei 7
 * (Debug) faellt die Bremse weg, die dieselbe Meldung nur einmal je Stunde
 * durchlaesst. Genau die steht bei der Fehlersuche im Weg.
 *
 * 6 ist die Vorgabe von LoxBerry (Notice) und gilt ueberall dort, wo es die
 * SDK-Klasse nicht gibt - etwa im Pruefaufbau.
 */
function wp_loglevel()
{
    static $stufe = null;
    if ($stufe === null) {
        $stufe = 6;
        if (class_exists('LBSystem', false) && method_exists('LBSystem', 'pluginloglevel')) {
            $v = LBSystem::pluginloglevel();
            if (is_numeric($v)) { $stufe = (int) $v; }
        }
    }
    return $stufe;
}

function wp_log($text, $einmalig = '')
{
    $p = wp_paths();
    if (!is_dir($p['logdir'])) { @mkdir($p['logdir'], 0755, true); }
    if ($einmalig !== '' && wp_loglevel() < 7) {
        $f = wp_tmpdir() . '/log_' . md5($einmalig) . '.stamp';
        if (is_file($f) && (time() - (int) @filemtime($f)) < 3600) { return; }
        @touch($f);
    }
    $datei = $p['logdir'] . '/waermepumpe.log';
    /* Kuerzen, bevor angehaengt wird.
     *
     * Bis 0.9.0 fehlte das ganz: die Datei wuchs unbegrenzt. Bei einem Lauf
     * je Minute und einer Zeile je Lauf sind das rund eine halbe Million
     * Zeilen im Jahr - und die Oberflaeche las sie im Reiter Protokoll
     * vollstaendig ein.
     *
     * Gekuerzt wird auf die letzten 300 Zeilen, sobald 512 kB ueberschritten
     * sind. clearstatcache, weil filesize() innerhalb eines Laufs sonst einen
     * gemerkten Wert liefert. */
    clearstatcache(true, $datei);
    if (is_file($datei) && filesize($datei) > 512000) {
        $rest = array_reverse(wp_log_ende($datei, 300));
        @file_put_contents($datei, implode("\n", $rest) . "\n", LOCK_EX);
    }
    $zeile = date('Y-m-d H:i:s') . ' ' . rtrim($text) . "\n";
    if (@file_put_contents($datei, $zeile, FILE_APPEND | LOCK_EX) === false) {
        fwrite(STDERR, $zeile);
    }
}

/**
 * Die letzten $anzahl Zeilen einer Datei, neueste zuerst.
 *
 * Bis 0.9.0 las die Oberflaeche das Protokoll mit file() vollstaendig ein.
 * Der Hinweis auf den Speicher war berechtigt - der vorgeschlagene Weg ueber
 * exec("tail") ist aber der langsamste der drei. An einer Datei an der
 * Rotationsgrenze gemessen, PHP 7.4 und 8.1:
 *
 *   file() ganz einlesen     rund 0,3 ms   Spitze rund 1,4 MB
 *   exec("tail -n 300")      rund 1,9 ms   Spitze rund  75 kB
 *   rueckwaerts mit fseek    rund 0,05 ms  Spitze rund 125 kB
 *
 * Ein Prozessstart kostet mehr, als das Einlesen je gespart hat - und er
 * braucht eine Shell, die man wieder absichern muesste.
 */
function wp_log_ende($datei, $anzahl = 200, $block = 8192)
{
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
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
        'emsesp' => array(
            'name'        => 'EMS-ESP (Bosch, Buderus, Junkers, Nefit, Worcester, Sieger)',
            // Nachgebildet - siehe die lange Begruendung ueber wp_ems_lesen().
            // Mit zwei Relais am Gateway waeren es echte Klemmen; das ist eine
            // Einstellung, keine Eigenschaft des Herstellers, und steht
            // deshalb nicht hier.
            'sg_echt'     => 0,
            // Das Gateway steht im eigenen Netz. Es gibt keinen fremden
            // Dienst, der aussperren koennte - die Untergrenze schuetzt nur
            // den EMS-Bus, der langsam ist und nichts davon hat, oefter
            // gefragt zu werden.
            'mindesttakt' => 60,
            'budget'      => 0,     // kein Tagesbudget, es ist das eigene Geraet
            'basis'       => '',    // die Adresse steht in der Konfiguration
            'portal'      => 'https://bbqkees-electronics.nl/',
        ),
        'vaillant' => array(
            'name'        => 'myVAILLANT (Vaillant, Saunier Duval, Bulex, Glow-worm, DemirDoekuem)',
            'sg_echt'     => 0,     // nachgebildet
            // 300 s ist keine Grenze des Anbieters, sondern eine des Geraets:
            // der sensoCOMFORT meldet Aenderungen ohnehin nur alle fuenf
            // Minuten. Ein schnellerer Takt liefert dieselben Zahlen und
            // kostet nur Anfragen.
            'mindesttakt' => 300,
            'budget'      => 0,     // keine veroeffentlichte Tagesgrenze
            'basis'       => 'https://api.vaillant-group.com/service-connected-control',
            'auth_basis'  => 'https://identity.vaillant-group.com/auth/realms',
            'portal'      => 'https://www.vaillant.de/heizung/produkte/app-myvaillant-1/',
        ),
    );
}

/* ==================================================================
 * myVAILLANT - Marken und Laender
 *
 * Der Anmeldebereich (Realm) heisst  <marke>-<land>-b2c , bei drei Marken
 * ohne Land nur  <marke>-b2c . Wer hier das falsche Land waehlt, bekommt
 * keine verstaendliche Fehlermeldung, sondern eine Anmeldeseite, auf der
 * sein Konto nicht existiert - deshalb steht die Liste vollstaendig hier
 * und wird nicht frei eingetippt.
 * ================================================================== */

function wp_va_marken()
{
    return array(
        'vaillant'    => 'Vaillant',
        'sdbg'        => 'Saunier Duval',
        'bulex'       => 'Bulex',
        'glow-worm'   => 'Glow-worm',
        'demirdokum'  => 'DemirDoekuem',
    );
}

/** Marken ohne Landesteil im Realm. */
function wp_va_marken_ohne_land()
{
    return array('bulex', 'glow-worm', 'demirdokum');
}

function wp_va_laender($marke = 'vaillant')
{
    $vaillant = array(
        'albania' => 'Albanien', 'austria' => 'Oesterreich', 'belgium' => 'Belgien',
        'bosnia' => 'Bosnien und Herzegowina', 'bulgaria' => 'Bulgarien',
        'croatia' => 'Kroatien', 'cyprus' => 'Zypern', 'czechrepublic' => 'Tschechien',
        'denmark' => 'Daenemark', 'estonia' => 'Estland', 'finland' => 'Finnland',
        'france' => 'Frankreich', 'georgia' => 'Georgien', 'germany' => 'Deutschland',
        'greece' => 'Griechenland', 'hungary' => 'Ungarn', 'ireland' => 'Irland',
        'italy' => 'Italien', 'kosovo' => 'Kosovo', 'latvia' => 'Lettland',
        'lithuania' => 'Litauen', 'luxembourg' => 'Luxemburg', 'moldavia' => 'Moldau',
        'netherlands' => 'Niederlande', 'new-zealand' => 'Neuseeland',
        'north-macedonia' => 'Nordmazedonien', 'norway' => 'Norwegen',
        'poland' => 'Polen', 'portugal' => 'Portugal', 'romania' => 'Rumaenien',
        'serbia' => 'Serbien', 'slovakia' => 'Slowakei', 'slovenia' => 'Slowenien',
        'spain' => 'Spanien', 'sweden' => 'Schweden', 'switzerland' => 'Schweiz',
        'turkiye' => 'Tuerkei', 'ukraine' => 'Ukraine',
        'unitedkingdom' => 'Vereinigtes Koenigreich', 'uzbekistan' => 'Usbekistan',
    );
    if ($marke === 'sdbg') {
        $nur = array('austria', 'czechrepublic', 'finland', 'france', 'greece',
                     'hungary', 'italy', 'lithuania', 'luxembourg', 'poland',
                     'portugal', 'romania', 'slovakia', 'spain');
        return array_intersect_key($vaillant, array_flip($nur));
    }
    if (in_array($marke, wp_va_marken_ohne_land(), true)) {
        return array();
    }
    return $vaillant;
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
        'hersteller'   => '',        // myuplink | onecta | melcloud | vaillant
        'geraet'       => '',        // Geraete-Kennung beim Hersteller
        'gebaeude'     => '',        // nur MELCloud: BuildingID
        'geraetetyp'   => -1,        // nur MELCloud: 0=Luft/Luft, 1=Luft/Wasser, -1=unbekannt
        'system'       => '',        // myUplink: systemId, myVAILLANT: systemId
        // nur myVAILLANT
        'marke'        => 'vaillant',
        'land'         => 'germany',
        'regler'       => '',        // tli | vrc700, leer = beim Abruf selbst ermitteln
        'zone'         => 0,         // Index der Heizzone
        'dhw'          => 255,       // Index des Warmwasserkreises (Vaillant zaehlt ab 255)
        'veto_stunden' => 3,         // Laufzeit der Schnellabweichung, siehe wp_va_veto()
        // nur EMS-ESP
        'ems_url'      => '',        // http://ems-esp.local oder die IP
        'ems_hc'       => 1,         // Heizkreis, den das Plugin bedient
        'ems_thermostat' => 1,       // Thermostatwerte mitlesen
        'ems_sg_art'   => 'nachbildung',   // nachbildung | klemmen
        'ems_gpio1'    => 0,         // GPIO fuer Klemme 1, 0 = nicht benutzt
        'ems_gpio4'    => 0,         // GPIO fuer Klemme 4, 0 = nicht benutzt
        // COP
        'cop_ein'      => 1,
        'cop_tage'     => 30,        // Fenster fuer die mittlere Arbeitszahl
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

    /* EMS-ESP. Die Adresse wird NICHT durch einen Zeichenfilter gedreht -
     * sie ist eine Adresse und keine Kennung. Geprueft wird stattdessen die
     * Form; was nicht passt, wird beim Speichern beanstandet und landet gar
     * nicht erst hier. */
    $cfg['ems_url'] = trim((string) $cfg['ems_url']);
    if ($cfg['ems_url'] !== '' && !preg_match('#^https?://#i', $cfg['ems_url'])) {
        $cfg['ems_url'] = '';
    }
    $cfg['ems_hc']         = max(1, min(8, (int) $cfg['ems_hc']));
    $cfg['ems_thermostat'] = empty($cfg['ems_thermostat']) ? 0 : 1;
    if (!in_array($cfg['ems_sg_art'], array('nachbildung', 'klemmen'), true)) {
        $cfg['ems_sg_art'] = 'nachbildung';
    }
    // 0 heisst "nicht benutzt". Negative oder unsinnig hohe Nummern gaebe es
    // an keinem ESP32 - sie wuerden am Gateway stillschweigend verpuffen.
    $cfg['ems_gpio1'] = max(0, min(48, (int) $cfg['ems_gpio1']));
    $cfg['ems_gpio4'] = max(0, min(48, (int) $cfg['ems_gpio4']));

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
        'benutzer'      => '',    // MELCloud und myVAILLANT: E-Mail
        'passwort'      => '',    // MELCloud und myVAILLANT
        'refresh_token' => '',    // Onecta
        'redirect_uri'  => '',    // Onecta
        'va_refresh'    => '',    // myVAILLANT: eigenes Erneuerungsmerkmal
        'ems_token'     => '',    // EMS-ESP: Zugriffsmerkmal (JWT) fuers Schreiben
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
    $kopf[] = 'User-Agent: LoxBerry-Waermepumpe/0.9.2';
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
    // Auch hier temp + rename: in dieser Liste steht, wie viele Abrufe des
    // Tagesbudgets schon verbraucht sind. Eine halb geschriebene Datei laesst
    // den Zaehler auf null zurueckfallen - und dann wird munter weiter
    // abgerufen, bis der Hersteller sperrt.
    wp_json_schreiben(wp_budget_datei($h), $l);
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
            'vaillant' => array('state.system.outdoorTemperature'),
            // dampedoutdoortemp ist die getraegte Kurve des Thermostats -
            // als Rueckfall brauchbar, als erster Kandidat nicht: sie
            // hinkt der Wirklichkeit um Stunden hinterher.
            'emsesp'   => array('boiler.outdoortemp', 'thermostat.dampedoutdoortemp'),
        )),
        'VORLAUF' => array(1, -20, 100, 'FELD.VORLAUF', array(
            'myuplink' => array('#40008', '~vorlauf|supply line|BT2'),
            'onecta'   => array('managementPoints[embeddedId=climateControl].sensoryData.value.leavingWaterTemperature.value'),
            'melcloud' => array('FlowTemperature', 'SetHeatFlowTemperatureZone1'),
            // Der Kreis zuerst, die Anlage als Rueckfall: bei mehreren Kreisen
            // ist der Kreiswert der aussagekraeftige.
            'vaillant' => array('state.circuits.0.currentCircuitFlowTemperature',
                                'state.system.systemFlowTemperature'),
            'emsesp'   => array('boiler.curflowtemp'),
        )),
        'VLSOLL' => array(1, -20, 100, 'FELD.VLSOLL', array(
            'myuplink' => array(),
            'onecta'   => array(),
            'melcloud' => array('SetHeatFlowTemperatureZone1'),
            'vaillant' => array('state.circuits.0.heatingCircuitFlowSetpoint'),
            'emsesp'   => array('thermostat.hc.targetflowtemp', 'boiler.selflowtemp'),
        )),
        'HEIZKURVE' => array(1, 0, 5, 'FELD.HEIZKURVE', array(
            'myuplink' => array(),
            'onecta'   => array(),
            'melcloud' => array(),
            // Die Heizkurve steht in der Einstellung, nicht im Zustand.
            'vaillant' => array('configuration.circuits.0.heatingCurve',
                                'state.circuits.0.heatingCurve'),
            // Bosch fuehrt keine Heizkurvenzahl. Es gibt Auslegungs- und
            // Normaussentemperatur, aus denen sich die Kurve ergibt - aber
            // keinen Wert, der "die Heizkurve" waere. Bewusst leer: ein
            // naheliegender falscher Pfad ist schlimmer als kein Wert.
            'emsesp'   => array(),
        )),
        'RUECKLAUF' => array(1, -20, 100, 'FELD.RUECKLAUF', array(
            'myuplink' => array('#40012', '~ruecklauf|return line|BT3'),
            'onecta'   => array('managementPoints[embeddedId=climateControl].sensoryData.value.leavingWaterTemperature.value'),
            'melcloud' => array('ReturnTemperature'),
            // myVAILLANT gibt keine Ruecklauftemperatur heraus. Bewusst leer -
            // ein naheliegender, aber falscher Pfad waere schlimmer als nichts.
            'vaillant' => array(),
            'emsesp'   => array('boiler.rettemp', 'boiler.hptc0'),
        )),
        'RAUM' => array(1, -20, 60, 'FELD.RAUM', array(
            'myuplink' => array('#40033', '~raumtemp|room temp|BT50'),
            'onecta'   => array('managementPoints[embeddedId=climateControl].sensoryData.value.roomTemperature.value'),
            'melcloud' => array('RoomTemperatureZone1'),
            'vaillant' => array('state.zones.0.currentRoomTemperature'),
            'emsesp'   => array('thermostat.hc.currtemp', 'thermostat.currtemp'),
        )),
        'SOLL' => array(1, -20, 60, 'FELD.SOLL', array(
            'myuplink' => array('#47398', '~raum soll|room setpoint'),
            'onecta'   => array('managementPoints[embeddedId=climateControl].temperatureControl.value.operationModes.heating.setpoints.roomTemperature.value'),
            'melcloud' => array('SetTemperatureZone1'),
            'vaillant' => array('state.zones.0.desiredRoomTemperatureSetpoint',
                                'configuration.zones.0.heating.manualModeSetpointHeating'),
            'emsesp'   => array('thermostat.hc.seltemp', 'thermostat.seltemp'),
        )),
        'WW' => array(1, 0, 100, 'FELD.WW', array(
            'myuplink' => array('#40014', '~warmwasser|hot water|BT7'),
            'onecta'   => array('managementPoints[embeddedId=domesticHotWaterTank].sensoryData.value.tankTemperature.value'),
            'melcloud' => array('TankWaterTemperature'),
            'vaillant' => array('state.dhw.0.currentDhwTemperature',
                                'state.system.cylinderTemperatureSensorTopDHW'),
            // Der Zweig dhw zuerst, die flache Form als Rueckfall: welche
            // von beiden kommt, haengt an der Fassung der Firmware.
            'emsesp'   => array('boiler.dhw.curtemp', 'boiler.curtemp',
                                'boiler.dhw.curtemp2', 'boiler.hptw1'),
        )),
        'WWSOLL' => array(1, 0, 100, 'FELD.WWSOLL', array(
            'myuplink' => array('#47041', '~warmwasser soll|hot water setpoint'),
            'onecta'   => array('managementPoints[embeddedId=domesticHotWaterTank].temperatureControl.value.operationModes.heating.setpoints.domesticHotWaterTemperature.value'),
            'melcloud' => array('SetTankWaterTemperature'),
            'vaillant' => array('configuration.dhw.0.tappingSetpoint'),
            'emsesp'   => array('boiler.dhw.seltemp', 'boiler.dhw.settemp',
                                'boiler.settemp'),
        )),
        'LEISTUNG' => array(1, 0, 30000, 'FELD.LEISTUNG', array(
            'myuplink' => array('#2305', '~leistungsaufnahme|power consumption'),
            'onecta'   => array(),   // Onecta liefert keine Momentanleistung
            'melcloud' => array(),   // MELCloud auch nicht
            // Nur, wenn die Anlage einen Energiemanager meldet. Fehlt der
            // Zweig, bleibt das Feld leer - das ist der Normalfall.
            'vaillant' => array('_currentSystem.primaryHeatGenerator.mpc.currentPower'),
            // hpcurrpower ist die AUFGENOMMENE elektrische Leistung in W -
            // das ist die Groesse, die dieses Feld meint. hppower waere die
            // abgegebene thermische Leistung in kW und damit dieselbe Zahl
            // in einer anderen Bedeutung; sie gehoert nicht hierher.
            'emsesp'   => array('boiler.hpcurrpower'),
        )),
        'KOMPRESSOR' => array(0, 0, 1, 'FELD.KOMPRESSOR', array(
            'myuplink' => array('#44064', '~verdichter|compressor'),
            'onecta'   => array(),
            'melcloud' => array(),
            'vaillant' => array(),
            'emsesp'   => array('boiler.hpcompon'),
        )),
        'WWZWANG' => array(0, 0, 1, 'FELD.WWZWANG', array(
            'myuplink' => array(),
            'onecta'   => array('managementPoints[embeddedId=domesticHotWaterTank].powerfulMode.value'),
            'melcloud' => array('ForcedHotWaterMode'),
            // Ein Text, keine Eins: CYLINDER_BOOST heisst "laeuft gerade".
            // wp_umrechnen reicht Texte unveraendert durch.
            'vaillant' => array('state.dhw.0.currentSpecialFunction'),
            'emsesp'   => array('boiler.dhw.charging', 'boiler.charging'),
        )),
        'EIN' => array(0, 0, 1, 'FELD.EIN', array(
            'myuplink' => array(),
            'onecta'   => array('managementPoints[embeddedId=climateControl].onOffMode.value'),
            'melcloud' => array('Power'),
            'vaillant' => array(),
            'emsesp'   => array('boiler.heatingactivated'),
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
    return wp_json_schreiben($f, $s);
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
    // Ueber wp_json_schreiben(): temp + rename, sonst kann der Minutencron
    // waehrend eines Lesezugriffs eine halbe Datei hinterlassen - und ein
    // json_decode, das dabei scheitert, verwirft die noch gueltige Marke.
    wp_json_schreiben($f, array('token' => $d['access_token'], 'bis' => time() + $gueltig));
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
    // Ueber wp_json_schreiben(): temp + rename, sonst kann der Minutencron
    // waehrend eines Lesezugriffs eine halbe Datei hinterlassen - und ein
    // json_decode, das dabei scheitert, verwirft die noch gueltige Marke.
    wp_json_schreiben($f, array('token' => $d['access_token'], 'bis' => time() + $gueltig));
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
        // Ueber wp_json_schreiben(): temp + rename, sonst kann der Minutencron
    // waehrend eines Lesezugriffs eine halbe Datei hinterlassen - und ein
    // json_decode, das dabei scheitert, verwirft die noch gueltige Marke.
    wp_json_schreiben($f, array('token' => $d['access_token'], 'bis' => time() + $gueltig));
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
    wp_json_schreiben($f, array('key' => $key, 'bis' => time() + 12 * 3600));
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
 * myVAILLANT
 *
 * Die Schnittstelle hinter der myVAILLANT-App ist nicht veroeffentlicht.
 * Alles hier ist der Python-Bibliothek myPyllant entnommen
 * (github.com/signalkraft/myPyllant, MIT) - Anmeldefluss, Adressen,
 * Kopfzeilen und Feldnamen. Nichts davon ist geraten; wo etwas geraten
 * waere, steht es nicht drin.
 *
 * Die Anmeldung ist ein OpenID-Connect-Fluss mit PKCE gegen einen
 * Keycloak, aber ohne Browser - deshalb in drei Schritten:
 *
 *   1. GET  .../protocol/openid-connect/auth?...     liefert die
 *      Anmeldeseite als HTML. Darin steht die Adresse des Formulars.
 *   2. POST auf genau diese Adresse mit Benutzer und Passwort. Die
 *      Antwort ist eine Umleitung, in deren Adresse der Code steht.
 *   3. POST .../protocol/openid-connect/token mit Code und Pruefwort.
 *
 * ZWEI DINGE, DIE DABEI LEICHT SCHIEFGEHEN und deshalb hier stehen:
 *
 *   Kekse.  Schritt 1 setzt Sitzungskekse, und Schritt 2 gelingt nur mit
 *   ihnen. Ohne Keksglas antwortet der Keycloak mit einer neuen
 *   Anmeldeseite statt mit einer Umleitung - was von aussen wie ein
 *   falsches Passwort aussieht. Deshalb braucht dieser Weg curl: die
 *   Rueckfallebene ueber Datenstroeme in wp_http() fuehrt keine Kekse.
 *
 *   Umleitungen. Der Code steht in der Kopfzeile Location. Wer curl
 *   folgen laesst, sieht ihn nie - CURLOPT_FOLLOWLOCATION bleibt aus.
 * ================================================================== */

/** Der Anmeldebereich: <marke>-<land>-b2c, bei drei Marken ohne Land. */
function wp_va_realm($marke, $land)
{
    $marke = (string) $marke;
    if (in_array($marke, wp_va_marken_ohne_land(), true) || (string) $land === '') {
        return $marke . '-b2c';
    }
    return $marke . '-' . $land . '-b2c';
}

/**
 * Pruefwort und Pruefwert fuer PKCE.
 * 128 Zeichen, dann SHA-256, dann Base64 in der URL-Schreibweise ohne
 * Fuellzeichen - genau so, wie es myPyllant erzeugt.
 */
function wp_va_pkce()
{
    $zeichen = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $roh = function_exists('random_bytes') ? random_bytes(128) : openssl_random_pseudo_bytes(128);
    $verifier = '';
    for ($i = 0; $i < 128; $i++) {
        $verifier .= $zeichen[ord($roh[$i]) % strlen($zeichen)];
    }
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return array($verifier, $challenge);
}

/** Das Keksglas der laufenden Anmeldung. Liegt im fluechtigen Ordner. */
function wp_va_keksglas()
{
    return wp_tmpdir() . '/va_kekse.txt';
}

/**
 * Eine Anfrage mit Keksglas und ohne Umleitungsverfolgung.
 *
 * Rueckgabe: array('code'=>int, 'text'=>string, 'ort'=>string, 'fehler'=>string).
 * 'ort' ist der Inhalt der Kopfzeile Location - dort steht in Schritt 2 der Code.
 */
function wp_va_http($methode, $url, $kopf = array(), $koerper = null, $zeit = 25)
{
    if (!function_exists('curl_init')) {
        return array('code' => 0, 'text' => '', 'ort' => '',
                     'fehler' => 'Die PHP-Erweiterung curl fehlt. Der myVAILLANT-Anmeldefluss '
                               . 'braucht ein Keksglas; der Rueckfallweg ueber Datenstroeme kann das nicht.');
    }
    $kopf[] = 'User-Agent: okhttp/4.9.2';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $methode);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $kopf);
    curl_setopt($ch, CURLOPT_TIMEOUT, $zeit);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $zeit));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_COOKIEJAR, wp_va_keksglas());
    curl_setopt($ch, CURLOPT_COOKIEFILE, wp_va_keksglas());
    if ($koerper !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $koerper);
    }
    $ort = '';
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($unused, $zeile) use (&$ort) {
        if (stripos($zeile, 'Location:') === 0) {
            $ort = trim(substr($zeile, 9));
        }
        return strlen($zeile);
    });
    $text = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fehler = curl_error($ch);
    curl_close($ch);
    @chmod(wp_va_keksglas(), 0600);
    return array('code' => $code, 'text' => (string) $text, 'ort' => $ort, 'fehler' => $fehler);
}

/**
 * Vollstaendige Anmeldung. Rueckgabe: array(ok, Grund).
 * Bei Erfolg liegen Zugriffsmerkmal und Ablaufzeit im fluechtigen Ordner,
 * das Erneuerungsmerkmal in der Geheimnisdatei.
 */
function wp_va_anmelden()
{
    $g = wp_geheim();
    $cfg = wp_config();
    if ($g['benutzer'] === '' || $g['passwort'] === '') {
        return array(0, 'KEINE_ZUGANGSDATEN');
    }
    $info = wp_hersteller_info('vaillant');
    $realm = wp_va_realm($cfg['marke'], $cfg['land']);
    $basis = $info['auth_basis'] . '/' . rawurlencode($realm);

    @unlink(wp_va_keksglas());   // jede Anmeldung faengt mit leerem Glas an
    list($verifier, $challenge) = wp_va_pkce();

    /* Schritt 1: die Anmeldeseite holen. */
    $frage = http_build_query(array(
        'response_type'         => 'code',
        'client_id'             => 'myvaillant',
        'code'                  => 'code_challenge',
        'redirect_uri'          => 'enduservaillant.page.link://login',
        'code_challenge_method' => 'S256',
        'code_challenge'        => $challenge,
    ));
    $a = wp_va_http('GET', $basis . '/protocol/openid-connect/auth?' . $frage);
    if ($a['fehler'] !== '') { return array(0, 'NETZ: ' . $a['fehler']); }
    if ($a['code'] === 404) {
        // Der haeufigste Einrichtungsfehler: falsches Land oder falsche Marke.
        return array(0, 'REALM_UNBEKANNT_' . $realm);
    }

    $code = '';
    if ($a['ort'] !== '') {
        // Eine bestehende Sitzung - dann kommt der Code sofort.
        $code = wp_va_code_aus_adresse($a['ort']);
    }

    if ($code === '') {
        /* Schritt 2: die Adresse des Anmeldeformulars aus dem HTML holen.
         *
         * Gesucht wird die Adresse von login-actions/authenticate samt ihrer
         * Parameter. Sie steht im action-Attribut und ist dort HTML-maskiert
         * (&amp; statt &) - ohne das Aufloesen laeuft der POST ins Leere. */
        $muster = '#' . preg_quote($basis . '/login-actions/authenticate', '#') . '\?[^"\']*#';
        if (!preg_match($muster, $a['text'], $m)) {
            return array(0, 'KEINE_ANMELDEADRESSE');
        }
        $adresse = html_entity_decode($m[0], ENT_QUOTES, 'UTF-8');

        $b = wp_va_http('POST', $adresse,
            array('Content-Type: application/x-www-form-urlencoded'),
            http_build_query(array(
                'username'     => $g['benutzer'],
                'password'     => $g['passwort'],
                'credentialId' => '',
            )));
        if ($b['fehler'] !== '') { return array(0, 'NETZ: ' . $b['fehler']); }
        if ($b['ort'] === '') {
            /* Keine Umleitung heisst: der Keycloak hat die Anmeldeseite noch
             * einmal geschickt. Das ist so gut wie immer ein falsches Passwort
             * - und genau das gehoert gemeldet, statt eines nackten HTTP 200. */
            return array(0, 'ZUGANGSDATEN_ABGELEHNT');
        }
        $code = wp_va_code_aus_adresse($b['ort']);
        if ($code === '') { return array(0, 'KEIN_CODE'); }
    }

    /* Schritt 3: Code gegen die Merkmale tauschen. */
    $c = wp_va_http('POST', $basis . '/protocol/openid-connect/token',
        array('Content-Type: application/x-www-form-urlencoded'),
        http_build_query(array(
            'grant_type'    => 'authorization_code',
            'client_id'     => 'myvaillant',
            'code'          => $code,
            'code_verifier' => $verifier,
            'redirect_uri'  => 'enduservaillant.page.link://login',
        )));
    return wp_va_merkmale_ablegen($c);
}

/** Den Parameter code aus einer Umleitungsadresse holen. */
function wp_va_code_aus_adresse($ort)
{
    $teil = parse_url($ort, PHP_URL_QUERY);
    if (!$teil) { return ''; }
    $p = array();
    parse_str($teil, $p);
    return isset($p['code']) ? (string) $p['code'] : '';
}

/** Die Antwort des Token-Endpunkts auswerten und ablegen. */
function wp_va_merkmale_ablegen($antwort)
{
    $d = json_decode($antwort['text'], true);
    if (!is_array($d) || !isset($d['access_token'])) {
        $grund = isset($d['error']) ? (string) $d['error'] : ('HTTP_' . $antwort['code']);
        return array(0, 'TOKEN_ABGELEHNT_' . $grund);
    }
    $gueltig = isset($d['expires_in']) ? (int) $d['expires_in'] : 300;
    // Eine Minute Sicherheitsabstand: ein Merkmal, das waehrend des Abrufs
    // ablaeuft, erzeugt einen 401 mitten in der Runde.
    wp_json_schreiben(wp_tmpdir() . '/token_vaillant.json', array(
        'access' => (string) $d['access_token'],
        'bis'    => time() + max(60, $gueltig - 60),
    ));
    @chmod(wp_tmpdir() . '/token_vaillant.json', 0600);

    if (isset($d['refresh_token'])) {
        $g = wp_geheim();
        $g['va_refresh'] = (string) $d['refresh_token'];
        wp_geheim_write($g);
    }
    return array(1, '');
}

/** Nur das Erneuerungsmerkmal einloesen. Rueckgabe: array(ok, Grund). */
function wp_va_erneuern()
{
    $g = wp_geheim();
    if ($g['va_refresh'] === '') { return array(0, 'KEIN_REFRESH'); }
    $cfg = wp_config();
    $info = wp_hersteller_info('vaillant');
    $basis = $info['auth_basis'] . '/' . rawurlencode(wp_va_realm($cfg['marke'], $cfg['land']));
    $a = wp_va_http('POST', $basis . '/protocol/openid-connect/token',
        array('Content-Type: application/x-www-form-urlencoded'),
        http_build_query(array(
            'grant_type'    => 'refresh_token',
            'client_id'     => 'myvaillant',
            'refresh_token' => $g['va_refresh'],
        )));
    return wp_va_merkmale_ablegen($a);
}

/**
 * Ein gueltiges Zugriffsmerkmal besorgen.
 *
 * Reihenfolge: gemerktes Merkmal, sonst erneuern, sonst voll anmelden. Die
 * volle Anmeldung ist der teuerste Weg und steht deshalb hinten - sie holt
 * eine HTML-Seite und schickt das Passwort.
 */
function wp_va_token($erzwingen = false)
{
    $f = wp_tmpdir() . '/token_vaillant.json';
    if (!$erzwingen) {
        $t = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        if (is_array($t) && isset($t['access'], $t['bis']) && $t['bis'] > time()) {
            return (string) $t['access'];
        }
    }
    list($ok, $grund) = wp_va_erneuern();
    if (!$ok) {
        list($ok, $grund) = wp_va_anmelden();
        if (!$ok) {
            wp_log('myVAILLANT: Anmeldung fehlgeschlagen (' . $grund . ')', 'va_login');
            return '';
        }
    }
    $t = json_decode((string) @file_get_contents($f), true);
    return is_array($t) && isset($t['access']) ? (string) $t['access'] : '';
}

/**
 * Die Kopfzeilen der Datenanfragen.
 *
 * Der Abonnementschluessel ist kein Geheimnis des Nutzers, sondern der der
 * App - er steht so in myPyllant und ist fuer alle gleich. Er gehoert
 * deshalb NICHT in die Geheimnisdatei.
 */
function wp_va_kopf($token)
{
    return array(
        'Authorization: Bearer ' . $token,
        'x-app-identifier: VAILLANT',
        'x-idm-identifier: KEYCLOAK',
        'x-client-locale: de-DE',
        'Accept-Language: de-DE',
        'Accept: application/json, text/plain, */*',
        'ocp-apim-subscription-key: 1e0a2f3511fb4c5bbb1c7f9fedd20b1c',
    );
}

/** Die Grundadresse je Reglerart. */
function wp_va_api($regler)
{
    $info = wp_hersteller_info('vaillant');
    return $info['basis'] . (((string) $regler === 'vrc700')
        ? '/vrc700/v1' : '/end-user-app-api/v1');
}

/** Die Adresse eines Systems - bei tli haengt ein /tli hinten dran. */
function wp_va_systembasis($system, $regler)
{
    $b = wp_va_api($regler) . '/systems/' . rawurlencode($system);
    return ((string) $regler === 'vrc700') ? $b : ($b . '/tli');
}

/**
 * Eine Datenanfrage. Bei 401 wird genau einmal neu angemeldet.
 * Rueckgabe: array(code, daten|null, fehler).
 */
function wp_va_abfrage($methode, $url, $koerper = null)
{
    $token = wp_va_token();
    if ($token === '') { return array(0, null, 'KEIN_TOKEN'); }
    $kopf = wp_va_kopf($token);
    if ($koerper !== null) { $kopf[] = 'Content-Type: application/json'; }
    $a = wp_va_http($methode, $url, $kopf, $koerper);
    if ($a['code'] === 401) {
        $token = wp_va_token(true);
        if ($token === '') { return array(401, null, 'KEIN_TOKEN'); }
        $kopf = wp_va_kopf($token);
        if ($koerper !== null) { $kopf[] = 'Content-Type: application/json'; }
        $a = wp_va_http($methode, $url, $kopf, $koerper);
    }
    if ($a['fehler'] !== '') { return array(0, null, 'NETZ: ' . $a['fehler']); }
    $d = json_decode($a['text'], true);
    return array($a['code'], is_array($d) ? $d : null, '');
}

/** Welche Reglerart hat dieses System? Rueckgabe: 'tli', 'vrc700' oder ''. */
function wp_va_regler_ermitteln($system)
{
    list($code, $d) = wp_va_abfrage('GET', wp_va_api('tli') . '/systems/'
        . rawurlencode($system) . '/meta-info/control-identifier');
    if ($code === 200 && isset($d['controlIdentifier'])) {
        return (string) $d['controlIdentifier'];
    }
    return '';
}

/** Die Anlagen des Kontos. Gleiche Form wie bei den anderen Herstellern. */
function wp_va_geraete()
{
    list($code, $d, $fehler) = wp_va_abfrage('GET', wp_va_api('tli') . '/homes');
    if ($code !== 200 || !is_array($d)) {
        wp_log('myVAILLANT: Anlagenliste fehlgeschlagen (' . ($fehler !== '' ? $fehler : 'HTTP ' . $code) . ')',
               'va_homes');
        return array();
    }
    $out = array();
    foreach ($d as $h) {
        if (!is_array($h) || empty($h['systemId'])) { continue; }
        $name = '';
        if (!empty($h['homeName'])) { $name = (string) $h['homeName']; }
        if (!empty($h['nomenclature'])) {
            $name = trim($name . ' ' . (string) $h['nomenclature']);
        }
        $out[] = array(
            'id'     => (string) $h['systemId'],
            'name'   => $name !== '' ? $name : 'myVAILLANT',
            'system' => (string) $h['systemId'],
            'typ'    => -1,
        );
    }
    return $out;
}

/**
 * Den Zustand lesen.
 *
 * Geliefert wird die Antwort des Systemendpunkts, ergaenzt um currentSystem
 * unter dem Schluessel _currentSystem. Beides in einem Objekt, damit die
 * Feldzuordnung wie bei den anderen Herstellern mit einem Pfad auskommt.
 */
function wp_va_lesen($cfg)
{
    if ($cfg['system'] === '') {
        return array('ok' => 0, 'fehler' => 'KEIN_GERAET', 'roh' => null);
    }
    $regler = (string) $cfg['regler'];
    if ($regler === '') {
        $regler = wp_va_regler_ermitteln($cfg['system']);
        if ($regler !== '') {
            $neu = wp_config();
            $neu['regler'] = $regler;
            wp_config_write($neu);
            wp_log('myVAILLANT: Reglerart ermittelt: ' . $regler);
        } else {
            // Nicht raten. tli ist zwar der haeufige Fall, aber ein falsch
            // geratener Zweig liefert 404 und sieht aus wie ein Netzfehler.
            return array('ok' => 0, 'fehler' => 'REGLERART_UNBEKANNT', 'roh' => null);
        }
    }

    list($code, $d, $fehler) = wp_va_abfrage('GET', wp_va_systembasis($cfg['system'], $regler));
    if ($code !== 200 || !is_array($d)) {
        return array('ok' => 0,
                     'fehler' => $fehler !== '' ? $fehler : ('HTTP_' . $code),
                     'roh' => null);
    }

    /* VRC700 nennt den Warmwasserkreis domesticHotWater, tli nennt ihn dhw.
     * Damit eine einzige Feldzuordnung fuer beide reicht, wird der laengere
     * Name zusaetzlich unter dem kuerzeren abgelegt. Kopiert, nicht
     * umbenannt - wer den Originalnamen sucht, findet ihn weiterhin. */
    foreach (array('state', 'configuration', 'properties') as $teil) {
        if (isset($d[$teil]['domesticHotWater']) && !isset($d[$teil]['dhw'])) {
            $d[$teil]['dhw'] = $d[$teil]['domesticHotWater'];
        }
    }

    // currentSystem bringt die Geraete samt Kennungen - die braucht die
    // Arbeitszahl. Ein Fehlschlag hier macht den Abruf nicht ungueltig.
    list($c2, $d2) = wp_va_abfrage('GET', wp_va_api($regler) . '/emf/v2/'
        . rawurlencode($cfg['system']) . '/currentSystem');
    if ($c2 === 200 && is_array($d2)) {
        $d['_currentSystem'] = $d2;
    }
    return array('ok' => 1, 'fehler' => '', 'roh' => $d);
}

/* ---------------- Arbeitszahl ----------------
 *
 * Die Energiewerte kommen nicht aus dem Zustand, sondern aus eigenen
 * Endpunkten: currentSystem nennt die Geraete und je Geraet, welche
 * Zaehlreihen es ueberhaupt gibt (operationMode und energyType). Zu jeder
 * Reihe laesst sich dann ein Zeitraum abrufen.
 *
 * Die Arbeitszahl ist der Quotient aus abgegebener Waerme und aufgenommenem
 * Strom:  HEAT_GENERATED / CONSUMED_ELECTRICAL_ENERGY. Genauso rechnet die
 * offizielle Home-Assistant-Anbindung (EfficiencySensor in sensor.py).
 *
 * WICHTIG UND UNGEPRUEFT: ob die eigene Anlage BEIDE Reihen liefert, sagt
 * erst das Geraet. Liefert sie nur den Stromverbrauch, gibt es keine
 * Arbeitszahl - dann wird nichts gerechnet und nichts gesendet, statt eine
 * Zahl zu erfinden. Der Reiter Test fuehrt das als eigene Zeile.
 * ---------------------------------------------------------------- */

/** Die Zaehlreihen aus currentSystem: Liste aus Geraet, Modus, Energieart. */
function wp_va_reihen($roh)
{
    $out = array();
    $cs = isset($roh['_currentSystem']) && is_array($roh['_currentSystem'])
        ? $roh['_currentSystem'] : array();
    $sammeln = function ($knoten) use (&$sammeln, &$out) {
        if (!is_array($knoten)) { return; }
        if (isset($knoten['deviceUuid']) && isset($knoten['data']) && is_array($knoten['data'])) {
            foreach ($knoten['data'] as $r) {
                if (!is_array($r) || empty($r['operationMode'])) { continue; }
                $out[] = array(
                    'geraet' => (string) $knoten['deviceUuid'],
                    'name'   => isset($knoten['productName']) ? (string) $knoten['productName'] : '',
                    'modus'  => (string) $r['operationMode'],
                    'art'    => isset($r['valueType']) ? (string) $r['valueType']
                              : (isset($r['energyType']) ? (string) $r['energyType'] : ''),
                );
            }
            return;
        }
        foreach ($knoten as $v) { if (is_array($v)) { $sammeln($v); } }
    };
    $sammeln($cs);
    return $out;
}

/** Eine Zaehlreihe ueber einen Zeitraum summieren. Rueckgabe: float|null. */
function wp_va_reihe_summe($cfg, $reihe, $von, $bis)
{
    $frage = http_build_query(array(
        'resolution'    => 'DAY',
        'operationMode' => $reihe['modus'],
        'energyType'    => $reihe['art'],
        'startDate'     => gmdate('Y-m-d\TH:i:s.000\Z', $von),
        'endDate'       => gmdate('Y-m-d\TH:i:s.000\Z', $bis),
    ));
    list($code, $d) = wp_va_abfrage('GET', wp_va_api($cfg['regler']) . '/emf/v2/'
        . rawurlencode($cfg['system']) . '/devices/' . rawurlencode($reihe['geraet'])
        . '/buckets?' . $frage);
    if ($code !== 200 || !is_array($d) || !isset($d['data']) || !is_array($d['data'])) {
        return null;
    }
    $summe = 0.0;
    $hatte = false;
    foreach ($d['data'] as $eimer) {
        if (is_array($eimer) && isset($eimer['value']) && is_numeric($eimer['value'])) {
            $summe += (float) $eimer['value'];
            $hatte = true;
        }
    }
    return $hatte ? $summe : null;
}

/**
 * Arbeitszahl fuer einen Zeitraum.
 * Rueckgabe: array('strom'=>Wh|null, 'waerme'=>Wh|null, 'cop'=>float|null, 'grund'=>string)
 */
function wp_va_cop($cfg, $roh, $tage)
{
    $leer = array('strom' => null, 'waerme' => null, 'cop' => null, 'grund' => '');
    $reihen = wp_va_reihen($roh);
    if (!$reihen) {
        $leer['grund'] = 'KEINE_ZAEHLREIHEN';
        return $leer;
    }
    $bis = time();
    $von = $bis - max(1, (int) $tage) * 86400;

    $strom = null;
    $waerme = null;
    $abgefragt = 0;
    foreach ($reihen as $r) {
        if ($r['art'] !== 'CONSUMED_ELECTRICAL_ENERGY' && $r['art'] !== 'HEAT_GENERATED') {
            continue;
        }
        if (++$abgefragt > 12) {
            // Harte Obergrenze: eine Anlage mit vielen Geraeten und Modi
            // koennte sonst Dutzende Anfragen je Durchlauf ausloesen.
            wp_log('myVAILLANT: mehr als 12 Zaehlreihen - der Rest bleibt fuer '
                 . 'die Arbeitszahl unberuecksichtigt.', 'va_reihen_viele');
            break;
        }
        $s = wp_va_reihe_summe($cfg, $r, $von, $bis);
        if ($s === null) { continue; }
        if ($r['art'] === 'CONSUMED_ELECTRICAL_ENERGY') {
            $strom = ($strom === null ? 0.0 : $strom) + $s;
        } else {
            $waerme = ($waerme === null ? 0.0 : $waerme) + $s;
        }
    }

    $erg = array('strom' => $strom, 'waerme' => $waerme, 'cop' => null, 'grund' => '');
    if ($strom === null || $waerme === null) {
        // Eine der beiden Reihen fehlt. Kein Quotient - und der Grund dazu.
        $erg['grund'] = ($strom === null && $waerme === null) ? 'KEINE_WERTE'
                      : ($strom === null ? 'KEIN_STROM' : 'KEINE_WAERME');
        return $erg;
    }
    if ($strom <= 0) {
        $erg['grund'] = 'STROM_NULL';
        return $erg;
    }
    $erg['cop'] = round($waerme / $strom, 2);
    return $erg;
}

/* ---------------- Schreiben ---------------- */

/**
 * Schnellabweichung fuer die Heizzone (Quick Veto).
 *
 * Bewusst NICHT der Handbetriebs-Sollwert: die Schnellabweichung laeuft von
 * selbst ab. Bleibt der Miniserver haengen, waehrend gerade angehoben ist,
 * faellt die Anlage nach der eingestellten Laufzeit von allein zurueck - die
 * Heizung ueberheizt das Haus also nicht unbegrenzt weiter. Genau dieselbe
 * Ueberlegung steht hinter wp_sg_sperre_abgelaufen().
 *
 * Erster Aufruf ist ein POST, ein bereits laufender Veto wird per PATCH
 * geaendert - deshalb wird bei 409 einmal auf PATCH gewechselt.
 */
function wp_va_veto($cfg, $temperatur, $stunden)
{
    $url = wp_va_systembasis($cfg['system'], $cfg['regler'])
         . '/zones/' . (int) $cfg['zone'] . '/quick-veto';
    $koerper = json_encode(array(
        'desiredRoomTemperatureSetpoint' => round((float) $temperatur, 1),
        'duration' => max(0.5, min(24, (float) $stunden)),
    ));
    list($code, $unused, $fehler) = wp_va_abfrage('POST', $url, $koerper);
    if ($code === 409 || $code === 400) {
        list($code, $unused, $fehler) = wp_va_abfrage('PATCH', $url, $koerper);
    }
    if ($code >= 200 && $code < 300) { return array(1, ''); }
    return array(0, $fehler !== '' ? $fehler : ('HTTP_' . $code));
}

/** Eine laufende Schnellabweichung beenden. */
function wp_va_veto_ende($cfg)
{
    list($code, $unused, $fehler) = wp_va_abfrage('DELETE',
        wp_va_systembasis($cfg['system'], $cfg['regler'])
        . '/zones/' . (int) $cfg['zone'] . '/quick-veto');
    // 404 heisst: es lief gar keine Abweichung. Das ist kein Fehler.
    if (($code >= 200 && $code < 300) || $code === 404) { return array(1, ''); }
    return array(0, $fehler !== '' ? $fehler : ('HTTP_' . $code));
}

/** Warmwasser-Schnellaufheizung ein oder aus. */
function wp_va_ww_boost($cfg, $ein)
{
    $url = wp_va_systembasis($cfg['system'], $cfg['regler'])
         . '/domestic-hot-water/' . (int) $cfg['dhw'] . '/boost';
    if ($ein) {
        list($code, $unused, $fehler) = wp_va_abfrage('POST', $url, '{}');
    } else {
        list($code, $unused, $fehler) = wp_va_abfrage('DELETE', $url);
    }
    if (($code >= 200 && $code < 300) || (!$ein && $code === 404)) { return array(1, ''); }
    return array(0, $fehler !== '' ? $fehler : ('HTTP_' . $code));
}

/* ==================================================================
 * EMS-ESP (Bosch, Buderus, Junkers, Nefit, Worcester, Sieger)
 *
 * DER EINZIGE HERSTELLER IN DIESEM PLUGIN, DER NICHT IN DER WOLKE STEHT.
 * Zwischen Plugin und Waermepumpe haengt ein Gateway am EMS-Bus - meist
 * eines von BBQKees mit der freien Firmware EMS-ESP. Es spricht HTTP und
 * liefert JSON, und zwar im eigenen Netz.
 *
 * Was daraus folgt, und warum es hier anders aussieht als bei den anderen:
 *
 *   - Keine Anmeldung fuers Lesen. GET braucht kein Merkmal.
 *   - Kein Tagesbudget. Es ist das eigene Geraet; die Untergrenze von 60
 *     Sekunden schuetzt den EMS-Bus, nicht einen fremden Dienst.
 *   - Schreiben braucht ein Zugriffsmerkmal (JWT) im Kopf
 *     "Authorization: Bearer ...". Das ist Absicht der Firmware: GET darf
 *     jeder, POST nur mit Merkmal. Zu holen in der Weboberflaeche des
 *     Gateways unter Settings, Security, Manage Users, Schluesselsymbol
 *     beim Benutzer mit Verwalterrecht. Es verfaellt nicht.
 *
 * Die Adressen:
 *
 *   GET  /api/<geraet>/info        alle Werte, ausfuehrlich
 *   GET  /api/<geraet>/commands    was sich schreiben laesst
 *   GET  /api/<geraet>/entities    alle vorhandenen Groessen
 *   POST /api/<geraet>/<groesse>   schreiben, Rumpf {"value": ...}
 *   POST /api/<geraet>/hc<n>/<g>   dasselbe fuer einen Heizkreis
 *
 * <geraet> ist die Kurzform: boiler, thermostat, heatpump, mixer, solar,
 * system und weitere. EINE BESONDERHEIT, die zwei Stunden Suche kostet,
 * wenn man sie nicht weiss: eine Bosch-WAERMEPUMPE meldet sich als
 * "boiler", nicht als "heatpump". Unter "heatpump" laeuft ein kleines
 * Zusatzmodul. Deshalb wird hier boiler gelesen und heatpump nicht.
 *
 * ------------------------------------------------------------------
 * SG READY - was das Gateway kann und was nicht
 * ------------------------------------------------------------------
 *
 * Eine Bosch-Waermepumpe hat vier Klemmeneingaenge. Eingang 1 und 4 sind
 * die SG-Ready-Klemmen:
 *
 *     E1  E4
 *     an  aus   EVU-Sperre
 *     aus aus   Normalbetrieb
 *     aus an    verstaerkter Betrieb        (Einschaltempfehlung)
 *     an  an    erzwungener verstaerkter Betrieb (Anlaufbefehl)
 *
 * Das ist genau die Belegung, die wp_sg_klemmen() ohnehin liefert.
 *
 * ABER: hpin1 bis hpin4 sind in EMS-ESP nur LESBAR. Sie zeigen, was an der
 * Klemme anliegt - sie legen dort nichts an. Ueber den EMS-Bus laesst sich
 * SG Ready nicht setzen, weil es nicht ueber den Bus laeuft, sondern ueber
 * Draehte. Wer etwas anderes behauptet, hat es nicht ausprobiert.
 *
 * Deshalb zwei Wege, und der Nutzer waehlt:
 *
 *   nachbildung  (Vorgabe)  ueber die schreibbaren Groessen des Gateways.
 *                Braucht keine zusaetzliche Hardware und tut ungefaehr das
 *                Richtige - aber es ist eine Nachbildung, und die
 *                Oberflaeche sagt das.
 *
 *   klemmen      zwei GPIO des Gateways als Digitalausgang, ueber je ein
 *                Relais oder einen Optokoppler an E1 und E4. Dann ist es
 *                echtes SG Ready, mit allem, was die Waermepumpe selbst
 *                daraus macht.
 *
 *                DAZU EINE WARNUNG, DIE NICHT VON MIR STAMMT, SONDERN VOM
 *                HERSTELLER DES GATEWAYS: die Platinen sind dafuer nicht
 *                gebaut, ein Anbau an die GPIO bricht die EMV-Erklaerung,
 *                und ein Relais gehoert nie unmittelbar an den Pin,
 *                sondern hinter eine Trennstufe. Deshalb steht dieser Weg
 *                auf "aus", bis ihn jemand ausdruecklich einschaltet.
 * ================================================================== */

/** Die Basisadresse ohne Schraegstrich am Ende. Leer, wenn nichts gesetzt. */
/**
 * Ein Meldekuerzel in Klartext - oder das Kuerzel selbst, wenn es dafuer
 * keinen Text gibt. Die HTTP-Kuerzel (HTTP_500 und Verwandte) entstehen zur
 * Laufzeit und koennen in keiner Sprachdatei stehen; ohne diesen Umweg
 * stuende dann woertlich "MELD.HTTP_500" auf dem Bildschirm.
 */
function wp_meld($kuerzel)
{
    $t = wp_t('MELD.' . $kuerzel);
    return $t === 'MELD.' . $kuerzel ? $kuerzel : $t;
}

function wp_ems_basis($cfg)
{
    $u = trim((string) $cfg['ems_url']);
    if ($u === '' || !preg_match('#^https?://#i', $u)) { return ''; }
    return rtrim($u, '/');
}

/**
 * Eine Auskunft holen. Rueckgabe: array(Feld|null, Grund).
 *
 * Der Grund ist ein Kuerzel und wird in der Oberflaeche uebersetzt. Ein
 * leeres Feld waere hier falsch: "keine Antwort" und "Antwort ohne Inhalt"
 * sind zwei verschiedene Lagen, und nur eine davon ist ein Netzproblem.
 */
function wp_ems_holen($cfg, $pfad)
{
    $basis = wp_ems_basis($cfg);
    if ($basis === '') { return array(null, 'KEINE_ADRESSE'); }
    $a = wp_http('GET', $basis . '/api/' . ltrim($pfad, '/'), array(), null, 15);
    if ($a['fehler'] !== '') { return array(null, 'NICHT_ERREICHBAR'); }
    if ($a['code'] === 404) { return array(null, 'NICHT_VORHANDEN'); }
    if ($a['code'] < 200 || $a['code'] >= 300) { return array(null, 'HTTP_' . $a['code']); }
    $d = wp_json($a);
    if ($d === null) {
        /* Kommt HTML zurueck, hat nicht das Gateway geantwortet, sondern ein
         * Router, ein Anmeldeportal oder ein anderes Geraet unter derselben
         * Adresse. Das gehoert unterschieden - sonst sucht man den Fehler an
         * der Firmware. */
        $anfang = ltrim(substr($a['text'], 0, 40));
        if ($anfang !== '' && $anfang[0] === '<') { return array(null, 'HTML_STATT_JSON'); }
        return array(null, 'KEIN_JSON');
    }
    return array($d, '');
}

/**
 * Alles lesen, was gebraucht wird.
 *
 * Zusammengesetzt wird EIN Dokument mit den Zweigen boiler, thermostat und
 * system - so bleiben die Pfade in wp_felder() eindeutig. Das ist noetig,
 * weil EMS-ESP Kurznamen benutzt und mehrere davon doppelt vergeben sind:
 * "seltemp" ist am thermostat die Raumtemperatur und am boiler die
 * Warmwassertemperatur. Ohne den Zweig davor waere nicht zu erkennen, welche
 * gemeint ist.
 *
 * Zusaetzlich wird der eingestellte Heizkreis unter thermostat.hc gespiegelt.
 * Damit kommen die Kandidatenpfade ohne Kreisnummer aus und gelten fuer alle
 * acht Kreise.
 */
function wp_ems_lesen($cfg)
{
    $basis = wp_ems_basis($cfg);
    if ($basis === '') { return array('ok' => 0, 'fehler' => 'KEINE_ADRESSE', 'roh' => null); }

    $roh = array();
    list($b, $grund) = wp_ems_holen($cfg, 'boiler/info');
    if ($b === null) { return array('ok' => 0, 'fehler' => $grund, 'roh' => null); }
    $roh['boiler'] = $b;

    if (!empty($cfg['ems_thermostat'])) {
        list($t, $tg) = wp_ems_holen($cfg, 'thermostat/info');
        if (is_array($t)) {
            $hc = 'hc' . (int) $cfg['ems_hc'];
            if (isset($t[$hc]) && is_array($t[$hc])) { $t['hc'] = $t[$hc]; }
            $roh['thermostat'] = $t;
        } else {
            /* Kein Thermostat ist kein Fehler: es gibt Anlagen, die ohne
             * Raumgeraet laufen. Der Grund wird trotzdem festgehalten, damit
             * der Reiter Test ihn zeigen kann. */
            $roh['thermostat_grund'] = $tg;
        }
    }
    return array('ok' => 1, 'fehler' => '', 'roh' => $roh);
}

/**
 * Welche Geraete haengen am Bus?
 *
 * Es gibt keinen Endpunkt "gib mir alle Geraete" in der Form, die dieses
 * Plugin braucht - also wird gefragt, wer antwortet. Sieben GET auf ein
 * Geraet im eigenen Netz sind guenstiger als eine Auswertung von
 * system/info, deren Aufbau sich zwischen den Fassungen geaendert hat.
 */
function wp_ems_geraete()
{
    $cfg = wp_config();
    if (wp_ems_basis($cfg) === '') { return array(); }
    $out = array();
    foreach (array('boiler', 'thermostat', 'heatpump', 'mixer', 'solar',
                   'ventilation', 'heatsource') as $g) {
        list($d, $grund) = wp_ems_holen($cfg, $g . '/values');
        if (!is_array($d) || !$d) { continue; }
        $out[] = array(
            'id'     => $g,
            'name'   => $g . ' (' . count($d) . ' ' . wp_t('EMS.WERTE') . ')',
            'system' => '',
        );
    }
    return $out;
}

/** Die schreibbaren Groessen eines Geraets. Rueckgabe: array(Namen), Grund. */
function wp_ems_befehle($cfg, $geraet = 'boiler')
{
    list($d, $grund) = wp_ems_holen($cfg, $geraet . '/commands');
    if (!is_array($d)) { return array(array(), $grund); }
    $out = array();
    foreach ($d as $k => $v) {
        // Je nach Fassung ist es eine Zuordnung Name => Beschreibung oder
        // eine schlichte Liste. Beide Formen kommen vor.
        $out[] = is_string($k) ? $k : (string) $v;
    }
    sort($out);
    return array($out, '');
}

/**
 * Eine Groesse schreiben. Rueckgabe: array(ok, Grund).
 *
 * $geraet darf einen Heizkreis enthalten: 'thermostat/hc1'.
 */
function wp_ems_schreiben($cfg, $geraet, $groesse, $wert)
{
    $basis = wp_ems_basis($cfg);
    if ($basis === '') { return array(0, 'KEINE_ADRESSE'); }
    $g = wp_geheim();
    if (trim((string) $g['ems_token']) === '') { return array(0, 'KEIN_TOKEN'); }

    $rumpf = json_encode(array('value' => $wert));
    $a = wp_http('POST', $basis . '/api/' . trim($geraet, '/') . '/' . $groesse,
        array('Content-Type: application/json',
              'Authorization: Bearer ' . trim((string) $g['ems_token'])),
        $rumpf, 15);

    if ($a['fehler'] !== '') { return array(0, 'NICHT_ERREICHBAR'); }
    /* 401 heisst hier fast immer: Merkmal fehlt oder ist von einem Benutzer
     * ohne Verwalterrecht. Das ist etwas anderes als "Groesse gibt es nicht"
     * (404) - und beides sieht in einer Sammelmeldung gleich aus. */
    if ($a['code'] === 401 || $a['code'] === 403) { return array(0, 'TOKEN_ABGELEHNT'); }
    if ($a['code'] === 404) { return array(0, 'GROESSE_UNBEKANNT'); }
    if ($a['code'] < 200 || $a['code'] >= 300) { return array(0, 'HTTP_' . $a['code']); }

    /* Das Gateway antwortet mit 200 auch dann, wenn es den Befehl abgelehnt
     * hat - der eigentliche Bescheid steht im Rumpf. Ohne diese Pruefung
     * meldete das Plugin Erfolg, waehrend nichts geschehen ist. */
    $d = wp_json($a);
    if (is_array($d) && isset($d['message']) && strtolower((string) $d['message']) !== 'ok') {
        return array(0, 'ABGELEHNT_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_',
            substr((string) $d['message'], 0, 40))));
    }
    return array(1, '');
}

/**
 * Einen GPIO des Gateways schalten.
 *
 * EMS-ESP fuehrt Ausgangspins als "analogsensor" - auch die digitalen. Der
 * Pin muss in der Weboberflaeche des Gateways vorher als Digital out
 * angelegt sein; ist er das nicht, antwortet das Gateway mit einem Fehler,
 * und der wird hier durchgereicht statt geschluckt.
 */
function wp_ems_gpio($cfg, $pin, $an)
{
    $pin = (int) $pin;
    if ($pin <= 0) { return array(1, 'GPIO_UNBENUTZT'); }
    $basis = wp_ems_basis($cfg);
    if ($basis === '') { return array(0, 'KEINE_ADRESSE'); }
    $g = wp_geheim();
    if (trim((string) $g['ems_token']) === '') { return array(0, 'KEIN_TOKEN'); }

    $a = wp_http('POST', $basis . '/api/analogsensor/setvalue',
        array('Content-Type: application/json',
              'Authorization: Bearer ' . trim((string) $g['ems_token'])),
        json_encode(array('value' => $an ? 1 : 0, 'id' => $pin)), 15);

    if ($a['fehler'] !== '') { return array(0, 'NICHT_ERREICHBAR'); }
    if ($a['code'] === 401 || $a['code'] === 403) { return array(0, 'TOKEN_ABGELEHNT'); }
    if ($a['code'] < 200 || $a['code'] >= 300) { return array(0, 'HTTP_' . $a['code']); }
    return array(1, '');
}

/**
 * Die Arbeitszahl aus den Energiezaehlern.
 *
 * Die CS5800i, CS6800i und die Buderus WLW176/186i fuehren beide Reihen als
 * Gesamtzaehler seit Inbetriebnahme:
 *
 *   nrgtotal    erzeugte Waerme in kWh   (Heizen, Kuehlen, Warmwasser)
 *   metertotal  eingesetzter Strom in kWh
 *
 * Aus zwei Gesamtstaenden wird eine Arbeitszahl fuer den Zeitraum dazwischen:
 *
 *   COP = (Waerme_neu - Waerme_alt) / (Strom_neu - Strom_alt)
 *
 * Der Quotient der GESAMTSTAENDE waere die Arbeitszahl seit Inbetriebnahme -
 * eine Zahl, die sich kaum noch bewegt und im Februar so aussieht wie im
 * Juli. Gefragt ist die der letzten Wochen.
 *
 * Deshalb wird stuendlich ein Zaehlerstand fortgeschrieben und der aelteste
 * innerhalb des Fensters als Vergleich genommen. Aeltere Staende fliegen
 * raus, sonst waechst die Datei unbegrenzt.
 *
 * Zwei Faelle geben BEWUSST nichts zurueck statt einer Zahl:
 *   - es liegt erst ein Stand vor (frisch eingerichtet),
 *   - im Fenster wurde weniger als eine Kilowattstunde Strom verbraucht.
 * Im zweiten Fall waere der Quotient aus zwei fast gleichen Zahlen ein
 * Zufallswert - im Sommer kaeme eine Arbeitszahl von 40 heraus.
 */
function wp_ems_cop($cfg, $roh, $tage)
{
    $leer = array('cop' => null, 'strom' => null, 'waerme' => null, 'grund' => '');

    $waerme = wp_pfad($roh, 'boiler.nrgtotal');
    if ($waerme === null) { $waerme = wp_pfad($roh, 'boiler.nrgsupptotal'); }
    $strom  = wp_pfad($roh, 'boiler.metertotal');
    if ($strom === null)  { $strom = wp_pfad($roh, 'boiler.nrgconstotal'); }

    if (!is_numeric($waerme) || !is_numeric($strom)) {
        $leer['grund'] = 'ZAEHLER_FEHLEN';
        return $leer;
    }
    $waerme = (float) $waerme;
    $strom  = (float) $strom;

    $datei = wp_datadir() . '/ems_zaehler.json';
    $reihe = is_file($datei) ? json_decode((string) @file_get_contents($datei), true) : array();
    if (!is_array($reihe)) { $reihe = array(); }

    $jetzt = time();
    $fenster = max(1, min(365, (int) $tage)) * 86400;

    // Nur behalten, was ins Fenster faellt - plus den einen Stand davor, denn
    // der ist der Vergleichspunkt fuer den Fensteranfang.
    $behalten = array();
    foreach ($reihe as $e) {
        if (!is_array($e) || !isset($e[0], $e[1], $e[2])) { continue; }
        if ($jetzt - (int) $e[0] <= $fenster) { $behalten[] = $e; }
    }
    // Harte Obergrenze - eine Schleife ohne Grenze ist eine Zeitbombe, und
    // ein Fenster von 365 Tagen ergaebe sonst 8760 Eintraege.
    if (count($behalten) > 1000) { $behalten = array_slice($behalten, -1000); }

    $letzter = $behalten ? $behalten[count($behalten) - 1] : null;
    if ($letzter === null || ($jetzt - (int) $letzter[0]) >= 3540) {
        $behalten[] = array($jetzt, round($waerme, 3), round($strom, 3));
        wp_json_schreiben($datei, $behalten);
    }

    if (count($behalten) < 2) {
        $leer['grund'] = 'ZU_WENIG_DATEN';
        return $leer;
    }
    $alt = $behalten[0];
    $d_waerme = $waerme - (float) $alt[1];
    $d_strom  = $strom  - (float) $alt[2];

    /* Ein Zaehler, der rueckwaerts laeuft, hat einen Neustart oder einen
     * Austausch hinter sich. Dann ist die alte Reihe wertlos - sie wird
     * verworfen statt eine negative Arbeitszahl zu melden. */
    if ($d_waerme < 0 || $d_strom < 0) {
        wp_json_schreiben($datei, array(array($jetzt, round($waerme, 3), round($strom, 3))));
        $leer['grund'] = 'ZAEHLER_ZURUECKGESETZT';
        return $leer;
    }
    if ($d_strom < 1.0) {
        $leer['grund'] = 'ZU_WENIG_ENERGIE';
        $leer['strom'] = round($d_strom, 1);
        $leer['waerme'] = round($d_waerme, 1);
        return $leer;
    }
    return array(
        'cop'    => round($d_waerme / $d_strom, 2),
        'strom'  => round($d_strom, 1),
        'waerme' => round($d_waerme, 1),
        'grund'  => '',
    );
}

/**
 * SG Ready bei EMS-ESP.
 *
 * Rueckgabe wie bei den anderen: array(ok, grund, beschreibung).
 */
function wp_ems_sg($cfg, $stufe, $stand)
{
    $stufe = max(1, min(WP_STUFEN, (int) $stufe));
    $getan = array();

    /* ---------------- Der echte Weg: zwei Klemmen ---------------- */
    if ($cfg['ems_sg_art'] === 'klemmen') {
        if ((int) $cfg['ems_gpio1'] <= 0 || (int) $cfg['ems_gpio4'] <= 0) {
            return array(0, 'KEINE_GPIO', '');
        }
        list($k1, $k4) = wp_sg_klemmen($stufe);
        list($ok1, $g1) = wp_ems_gpio($cfg, $cfg['ems_gpio1'], $k1);
        $getan[] = 'GPIO' . (int) $cfg['ems_gpio1'] . '=' . (int) $k1;
        if (!$ok1) { return array(0, $g1, implode(' ', $getan)); }
        list($ok4, $g4) = wp_ems_gpio($cfg, $cfg['ems_gpio4'], $k4);
        $getan[] = 'GPIO' . (int) $cfg['ems_gpio4'] . '=' . (int) $k4;
        return array($ok4, $g4, implode(' ', $getan));
    }

    /* ---------------- Die Nachbildung ----------------
     *
     * Stufe 1 schaltet den Heizbetrieb ab. Das ist NICHT dasselbe wie eine
     * EVU-Sperre: die Waermepumpe kennt bei einer echten Sperre ihre
     * Hoechstdauer und faengt danach von selbst wieder an. Hier haengt das
     * an wp_sg_sperre_abgelaufen() und damit am Plugin. Faellt der LoxBerry
     * aus, waehrend gesperrt ist, bleibt es aus - deshalb gibt es die
     * Hoechstdauer und deshalb steht sie in der Oberflaeche.
     *
     * Stufe 3 und 4 heben die RAUMTEMPERATUR an, nicht die Vorlauf-
     * temperatur. Zwei Gruende: es ist der Weg, den Bosch selbst fuer den
     * PV-Ueberschuss vorsieht (pvraiseheat wirkt genau dort), und die
     * Anlage rechnet die Vorlauftemperatur daraus mit ihrer eigenen
     * Heizkurve. Wer stattdessen selflowtemp beschreibt, uebergeht die
     * Kurve - und die naechste Berechnung der Anlage macht es wieder
     * rueckgaengig.
     */
    $basis = (float) $cfg['basis_soll'];
    $kreis = 'thermostat/hc' . (int) $cfg['ems_hc'];

    if ($stufe === 1) {
        list($ok, $grund) = wp_ems_schreiben($cfg, 'boiler', 'heatingactivated', false);
        $getan[] = 'boiler.heatingactivated=aus';
        return array($ok, $grund, implode(' ', $getan));
    }

    list($ok, $grund) = wp_ems_schreiben($cfg, 'boiler', 'heatingactivated', true);
    $getan[] = 'boiler.heatingactivated=ein';
    if (!$ok) { return array(0, $grund, implode(' ', $getan)); }

    if ($stufe === 2) {
        // Zurueck auf den gemerkten Grundsollwert. Ohne ihn wird nichts
        // geschrieben - sonst stellte das Plugin auf eine Zahl, die es sich
        // ausgedacht hat.
        if ($basis > 0) {
            list($ok, $grund) = wp_ems_schreiben($cfg, $kreis, 'seltemp', $basis);
            $getan[] = 'hc' . (int) $cfg['ems_hc'] . '.seltemp=' . $basis;
        }
        return array($ok, $grund, implode(' ', $getan));
    }

    if ($basis <= 0) { return array(0, 'KEIN_GRUNDSOLLWERT', implode(' ', $getan)); }
    $soll = $basis + ($stufe === 3 ? (float) $cfg['anhebung_3'] : (float) $cfg['anhebung_4']);
    list($ok, $grund) = wp_ems_schreiben($cfg, $kreis, 'seltemp', $soll);
    $getan[] = 'hc' . (int) $cfg['ems_hc'] . '.seltemp=' . $soll;
    if (!$ok) { return array(0, $grund, implode(' ', $getan)); }

    if ($stufe === 4 && !empty($cfg['ww_boost_4'])) {
        /* Einmalladung Warmwasser. Sie laeuft von selbst aus, sobald die
         * Stopptemperatur erreicht ist - deshalb wird sie bei Stufe 3 und 2
         * NICHT abgeschaltet. Ein Abschalten mittendrin liesse den Speicher
         * halb geladen zurueck. */
        list($ok, $grund) = wp_ems_schreiben($cfg, 'boiler', 'onetime', true);
        $getan[] = 'boiler.onetime=ein';
    }
    return array($ok, $grund, implode(' ', $getan));
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
        case 'vaillant': return wp_va_geraete();
        case 'emsesp':   return wp_ems_geraete();
    }
    return array();
}

function wp_lesen($cfg)
{
    switch ($cfg['hersteller']) {
        case 'myuplink': return wp_mu_lesen($cfg);
        case 'onecta':   return wp_oc_lesen($cfg);
        case 'melcloud': return wp_ml_lesen($cfg);
        case 'vaillant': return wp_va_lesen($cfg);
        case 'emsesp':   return wp_ems_lesen($cfg);
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

        case 'emsesp':
            return wp_ems_sg($cfg, $stufe, $stand);

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

        case 'vaillant':
            /* Nachgebildet. myVAILLANT kennt keinen SG-Ready-Eingang.
             *
             * Bewusst OHNE Ausschalten: die Anlage laesst sich ueber diese
             * Schnittstelle zwar in den Betriebsmodus OFF bringen, aber das
             * ist keine EVU-Sperre. Der Frostschutz und die Legionellen-
             * schaltung laufen dann nicht in gleicher Weise weiter, und im
             * Januar ist das kein Schoenheitsfehler. Die Sperre beendet
             * deshalb nur, was dieses Plugin selbst angehoben hat, und
             * schreibt sonst nichts.
             *
             * WER WIRKLICH SPERREN WILL, nimmt die beiden Klemmen am Geraet.
             * Bei dieser Anlage ist ohnehin der Multifunktionseingang der
             * vorgesehene Weg (Klemme S21 an der Hydraulikstation, im
             * sensoCOMFORT auf "Photovoltaik" gestellt).
             */
            $getan = array();
            if ($stufe === 1 || $stufe === 2) {
                list($ok, $grund) = wp_va_veto_ende($cfg);
                $getan[] = 'Schnellabweichung beendet';
                if ($ok && $cfg['ww_boost_4']) {
                    list($ok2, $grund2) = wp_va_ww_boost($cfg, false);
                    $getan[] = 'Warmwasser-Aufheizung aus';
                    if (!$ok2) { return array(0, $grund2, implode(' ', $getan)); }
                }
                if ($stufe === 1) {
                    $getan[] = 'KEINE Sperre gesendet - dafuer die Klemme';
                }
                return array($ok, $grund, implode(' ', $getan));
            }

            // Stufe 3 und 4: anheben. Ohne gemerkten Grundsollwert wird nicht
            // angehoben - sonst addiert sich die Anhebung bei jedem Durchlauf.
            if ($basis <= 0) {
                return array(0, 'KEIN_GRUNDSOLLWERT', '');
            }
            $soll = $basis + ($stufe === 3 ? $cfg['anhebung_3'] : $cfg['anhebung_4']);
            list($ok, $grund) = wp_va_veto($cfg, $soll, $cfg['veto_stunden']);
            $getan[] = 'Schnellabweichung ' . $soll . ' Grad fuer '
                     . (float) $cfg['veto_stunden'] . ' h';
            if (!$ok) { return array(0, $grund, implode(' ', $getan)); }

            if ($cfg['ww_boost_4']) {
                list($ok, $grund) = wp_va_ww_boost($cfg, $stufe === 4);
                $getan[] = 'Warmwasser-Aufheizung ' . ($stufe === 4 ? 'ein' : 'aus');
            }
            return array($ok, $grund, implode(' ', $getan));
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

/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung, einem Geraetenamen oder der Ausgabe eines Systembefehls -
 * zerlegt die Uebertragung, und aus den Bruchstuecken bildet das Gateway
 * erfundene Themen. Ein Tabulator schadet ebenso, weil Leerzeichen Thema und
 * Wert trennt.
 */
function wp_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
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
        $zeile = 'publish ' . $cfg['mqtt_topic'] . '/' . $name . ' '
               . wp_mqtt_wert_saeubern($wert) . "\n";
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
    /* Die Arbeitszahl steht hinten, weil sie nicht aus dem Zustand kommt,
     * sondern aus den Energiezaehlern - und nur bei Herstellern, die beide
     * Reihen liefern. Wo sie fehlt, wird nichts gesendet statt einer Null. */
    $out['COP']    = array(1, 0, 10, 'FELD.COP');
    $out['STROM']  = array(1, 0, 100000, 'FELD.STROM');
    $out['WAERME'] = array(1, 0, 400000, 'FELD.WAERME');
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

        /* Arbeitszahl - hoechstens einmal je Stunde.
         *
         * Sie braucht je Zaehlreihe eine eigene Anfrage, und die Zahlen
         * aendern sich im Tagesraster ohnehin kaum. Sie im Abruftakt zu
         * holen waere ein Vielfaches an Anfragen fuer denselben Wert. */
        if (!empty($cfg['cop_ein']) && $erg['ok']
            && in_array($cfg['hersteller'], array('vaillant', 'emsesp'), true)) {
            $faellig = ((int) (isset($stand['cop_zeit']) ? $stand['cop_zeit'] : 0)) + 3600 <= time();
            if ($faellig || $erzwingen) {
                /* Frisch einlesen: wp_va_lesen() kann die Reglerart eben erst
                 * ermittelt und geschrieben haben. Die Kopie hier oben waere
                 * dann noch leer, und die Energieadresse zeigte ins Leere. */
                $cfg = wp_config();
                /* Zwei Hersteller, zwei Wege zur selben Zahl: myVAILLANT
                 * liefert fertige Summen je Zeitraum, EMS-ESP nur
                 * Gesamtzaehler - daraus wird die Differenz gebildet. */
                $c = $cfg['hersteller'] === 'emsesp'
                    ? wp_ems_cop($cfg, $erg['roh'], (int) $cfg['cop_tage'])
                    : wp_va_cop($cfg, $erg['roh'], (int) $cfg['cop_tage']);
                $stand['cop_zeit']  = time();
                $stand['cop_grund'] = $c['grund'];
                if ($c['cop'] !== null) {
                    $stand['werte']['COP'] = $c['cop'];
                }
                if ($c['strom'] !== null)  { $stand['werte']['STROM']  = round($c['strom'], 1); }
                if ($c['waerme'] !== null) { $stand['werte']['WAERME'] = round($c['waerme'], 1); }
                if ($c['cop'] === null && $c['grund'] !== '') {
                    wp_log('Arbeitszahl nicht gerechnet: ' . $c['grund'], 'cop_' . $c['grund']);
                }
            } elseif (isset($stand['werte'])) {
                // Nicht faellig: die zuletzt gerechneten Werte stehen lassen,
                // damit sie nicht jede Runde aus dem Abbild verschwinden.
                foreach (array('COP', 'STROM', 'WAERME') as $f) {
                    if (isset($stand['werte_cop'][$f])) {
                        $stand['werte'][$f] = $stand['werte_cop'][$f];
                    }
                }
            }
            $stand['werte_cop'] = array();
            foreach (array('COP', 'STROM', 'WAERME') as $f) {
                if (isset($stand['werte'][$f])) { $stand['werte_cop'][$f] = $stand['werte'][$f]; }
            }
        }

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
