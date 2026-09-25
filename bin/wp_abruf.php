#!/usr/bin/env php
<?php
/**
 * Waermepumpe Cloud fuer LoxBerry - der Abrufdienst
 *
 * Laeuft aus cron.01min, entscheidet aber selbst, ob eine Anfrage an die
 * Herstellercloud tatsaechlich hinausgeht. Der Minutentakt ist der Herzschlag,
 * nicht der Abruftakt.
 *
 * WARUM KEIN DAUERLAEUFER
 * Es gibt nichts zu empfangen. Alle drei Schnittstellen werden abgefragt, sie
 * schieben nichts. Ein Dauerlaeufer waere ein Prozess mehr, der beim
 * Plugin-Update haengen bleiben kann - ohne einen einzigen Vorteil.
 *
 * Aufrufe:
 *   wp_abruf.php                aus dem Cron
 *   wp_abruf.php jetzt          Takt umgehen (nicht das Tagesbudget)
 *   wp_abruf.php zeile          die Statuszeile ausgeben, ohne abzurufen
 *   wp_abruf.php --mqtt-leeren  aus der Deinstallation: zurueckbehaltene
 *                               MQTT-Themen der Linie leeren
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
/* Die Bibliothek finden - NICHT ueber eine feste Zahl von ".." nach oben.
 *
 * Im entpackten Archiv liegen bin/ und webfrontend/ nebeneinander, auf dem
 * installierten LoxBerry in GETRENNTEN Baeumen:
 *
 *     <LoxBerry-Wurzel>/bin/plugins/<ordner>/wp_abruf.php
 *     <LoxBerry-Wurzel>/webfrontend/htmlauth/plugins/<ordner>/wp_lib.php
 *
 * dirname(__DIR__) ergibt dort <LoxBerry-Wurzel>/bin/plugins - gesucht wurde
 * bis 0.9.8 also .../bin/plugins/webfrontend/htmlauth/wp_lib.php. Die gibt es
 * nicht: der Dienst brach bei JEDEM Cron-Lauf mit einem fatalen Fehler ab
 * (gefunden am 16.08.2026 mit Werkzeuge/installationslage_pruefen.py).
 *
 * Welche Lage gilt, entscheidet seit 0.9.23 der EIGENE Ablageort, nicht die
 * Reihenfolge von Versuchen: liegt diese Datei unter .../plugins/<ordner>,
 * ist sie installiert, sonst liegt sie in einem ausgepackten Archiv. Bis
 * 0.9.22 wurden drei Kandidaten der Reihe nach probiert. In WSL gemessen
 * (Pruefung-WaermepumpeCloud-0.9.23): aus einem Archiv unter der
 * Laufwerkswurzel war der zweite /webfrontend/htmlauth/plugins/bin/wp_lib.php,
 * und was dort lag, lief als Bibliothek (Fall T4); mit LBHOMEDIR wurde aus
 * einem Archiv heraus die Bibliothek der Anlage geladen. Bauart
 * ZendureSolarFlow 0.9.26.
 */
if (basename(dirname(__DIR__)) === 'plugins') {
    $wp_lib = dirname(dirname(dirname(__DIR__)))
            . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/wp_lib.php';
} else {
    $wp_lib = dirname(__DIR__) . '/webfrontend/htmlauth/wp_lib.php';
}
if (!is_file($wp_lib)) {
    fwrite(STDERR, "Waermepumpe Cloud: wp_lib.php nicht gefunden. Gesucht wurde unter:\n  " . $wp_lib . "\n");
    exit(1);
}
require_once $wp_lib;

$modus = isset($argv[1]) ? (string) $argv[1] : 'takt';

/* Deinstallation: vor der Wurzelpruefung unten, weil wp_mqtt_leeren() eine
 * fehlende Wurzel selbst meldet (Rueckgabe 2) - und vor allem, was schreibt:
 * es wird nichts angelegt und nichts protokolliert. */
if ($modus === '--mqtt-leeren') {
    exit(wp_mqtt_leeren());
}

/* Ohne Wurzel oder aus einem Archiv heraus: nichts tun (wp_keine_wurzel_abbruch()
 * in wp_lib.php, dort die Messung). */
wp_keine_wurzel_abbruch('wp_abruf.php');

if ($modus === 'zeile') {
    echo wp_zeile(wp_stand(), wp_config()) . "\n";
    exit(0);
}

/* Nur ein Lauf gleichzeitig. Ein zweiter Lauf wuerde dieselbe Anfrage noch
   einmal stellen - bei Daikin kostet das ein Stueck Tagesbudget. */
$sperre = wp_tmpdir() . '/abruf.lock';
$fh = @fopen($sperre, 'c');
if ($fh === false) { exit(1); }
if (!flock($fh, LOCK_EX | LOCK_NB)) { exit(0); }

$cfg = wp_config();
if ($cfg['hersteller'] === '') {
    // Noch nicht eingerichtet - das ist kein Fehler, nur nichts zu tun.
    flock($fh, LOCK_UN);
    fclose($fh);
    exit(0);
}

/* Erst schalten, dann lesen. Andersherum zeigte der Abruf noch den Zustand
   von vor dem Schaltbefehl, und die Statuszeile in Loxone haette eine Minute
   lang das Gegenteil dessen behauptet, was gerade angewiesen wurde. */
/* try/finally um die eigentliche Arbeit.
 *
 * Das Betriebssystem gibt eine Sperre frei, sobald der Prozess endet - auch
 * bei einem Absturz. Das ist richtig und der Grund, warum hier nie etwas
 * haengen geblieben ist. Der Block ist trotzdem da, und zwar fuer den Fall,
 * der NICHT das Prozessende ist: seit PHP 7 sind die meisten frueheren
 * fatalen Fehler Error-Ausnahmen. Die laufen durch finally, das Skript kann
 * danach noch etwas tun - und ohne finally waere die Sperre bis zum
 * Prozessende gehalten, obwohl die Arbeit laengst abgebrochen ist.
 *
 * Ausserdem sagt der Block dem naechsten Leser, dass zwischen Sperren und
 * Entsperren nichts hinzukommen darf, was daran vorbeifuehrt. */
$ok = false;
try {
    /* Erst schalten, dann lesen - siehe oben. */
    // Die Abo-Datei fuer das MQTT-Gateway auf dem eingestellten Praefix
    // halten (nach einem Update kommt sie mit der Vorgabe aus dem Archiv).
    wp_abo_nachziehen($cfg);
    wp_sg_durchsetzen($cfg);
    list($ok, $grund) = wp_abrufen($modus === 'jetzt');
} finally {
    flock($fh, LOCK_UN);
    fclose($fh);
}
exit($ok ? 0 : 1);
