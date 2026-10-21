<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Waermepumpe Cloud.
 *
 * Gruppe 4 / MQTT (0.9.35; Bauvorschrift wz_bild/GRUPPE4_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe4_wp.txt
 * Abschnitt 3, ENTSCHEIDUNGEN_GRUPPE4_MQTT.md; Muster vb_g1_bruecke_bau/beispiel/bin/befehle_zuordnung.php und
 * vb_g3_eb_bau/bin/befehle_zuordnung.php). Die Bruecke selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich die
 * Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist nur diese Datei.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 * 'tun' ruft DIESELBE Funktion wie der HTTP-Endpunkt webfrontend/html/index.php - wp_befehl_ausfuehren() - mit Quelle
 * 'mqtt' (Wertpruefung, Haken "SG Ready aktiv", Befehlsbremse X-7, Wiederholregel, sofortiges Durchsetzen) und gibt
 * array(bool ok, string grund) zurueck (wp_befehl_bruecke()). Die Token-Pruefung des Endpunkts ersetzen die Anmeldung
 * am Broker und die beiden Haken (Entwurf gemeinsam E13).
 *
 * sgready ist EIN Name mit Auswahl 1|2|3|4 statt vier Pulsen (Entwurf wp E1): die Bruecke legt je Name zusammen -
 * stehen "2" und "3" im selben Lesepuffer, gilt nur der neueste Wunsch, ein Zwischenstand geht nicht in die Cloud.
 * Die Vorlage erzeugt trotzdem je Stufe einen digitalen Befehl. ww_boost ist ein Schalter 0/1 mit Ein- und
 * Ausbefehl wie bisher ueber HTTP. Beide greifen in Heizung bzw. Warmwasser ein und schreiben in die Hersteller-Cloud:
 * zweiter Haken "Waermepumpe ueber MQTT steuern" (befehle_mqtt_steuern, ab Werk aus, E3). sgready braucht daneben
 * weiter "SG Ready aktiv" (sg_ein) wie ueber HTTP - das prueft wp_befehl_ausfuehren(). ww_boost steht auch fuer Nibe
 * in der Tabelle (E9): die Tabelle wird beim Start geladen und waere nach einem Herstellerwechsel veraltet; bei
 * myuplink quittiert die Bruecke "abgelehnt: NICHT_UNTERSTUETZT".
 */
if (!function_exists('wp_befehl_ausfuehren')) {
    $wp_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $wp_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/wp_lib.php';
    } else {
        $wp_kand[] = dirname(__DIR__) . '/webfrontend/htmlauth/wp_lib.php';
    }
    foreach ($wp_kand as $wp_k) {
        if (is_file($wp_k)) { require_once $wp_k; break; }
    }
}
$wp_ohne_sperre = 'Die Sperrminute gilt je Name UND Wert: Sperre, Normalbetrieb, Sperre binnen 60 s liesse die zweite Sperre '
    . 'abprallen, die Waermepumpe liefe weiter; ebenso Warmwasser ein, aus, ein. Das Plugin bremst selbst: eine Stufe, die '
    . 'schon gilt, geht nicht hinaus (X-7, UNVERAENDERT), derselbe Warmwasser-Wert binnen 60 s ebenso nicht, ein '
    . 'gescheiterter Befehl nur nach der Wiederholregel (Entwurf wp E2).';
return array(
    'fassung' => 1,
    'linie'   => 'waermepumpe',
    'plugin'  => 'Wärmepumpe Cloud',
    'geladen' => function_exists('wp_config') && function_exists('wp_befehl_ausfuehren') && function_exists('wp_befehl_bruecke'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'einstellungen' => function () {
        return wp_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        wp_log('Befehlsbruecke ' . ($stufe === 'INFO' ? '' : $stufe . ': ') . $text);
    },
    'befehle' => array(
        'sgready'  => array('art' => 'auswahl', 'werte' => array('1', '2', '3', '4'), 'eingreifend' => true,
                            'sperre_s' => 0, 'begruendung' => $wp_ohne_sperre,
                            'titel' => 'Wärmepumpe Cloud SG Ready (MQTT)', 'kachel' => 'BEFEHL.K_SG',
                            'quittung' => 'ausgefuehrt',
                            'tun' => function ($w, $t) {
                                return wp_befehl_bruecke(wp_befehl_ausfuehren('sgready', array('stufe' => (string) $w), 'mqtt'));
                            }),
        'ww_boost' => array('art' => 'schalter', 'eingreifend' => true, 'sperre_s' => 0, 'begruendung' => $wp_ohne_sperre,
                            'titel' => 'Wärmepumpe Cloud Warmwasser-Zwangsladung (MQTT)', 'kachel' => 'BEFEHL.K_WW',
                            'quittung' => 'ausgefuehrt',
                            'tun' => function ($w, $t) {
                                return wp_befehl_bruecke(wp_befehl_ausfuehren('ww_boost', array('ein' => (string) $w), 'mqtt'));
                            }),
    ),
);
