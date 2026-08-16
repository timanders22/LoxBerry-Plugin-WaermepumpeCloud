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
 *   wp_abruf.php            aus dem Cron
 *   wp_abruf.php jetzt      Takt umgehen (nicht das Tagesbudget)
 *   wp_abruf.php zeile      die Statuszeile ausgeben, ohne abzurufen
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
/* Die Bibliothek ueber eine Kandidatenliste finden - NICHT ueber eine feste
 * Zahl von ".." nach oben.
 *
 * Im entpackten Archiv liegen bin/ und webfrontend/ nebeneinander, auf dem
 * installierten LoxBerry in GETRENNTEN Baeumen:
 *
 *     /opt/loxberry/bin/plugins/<ordner>/wp_abruf.php
 *     /opt/loxberry/webfrontend/htmlauth/plugins/<ordner>/wp_lib.php
 *
 * dirname(__DIR__) ergibt dort /opt/loxberry/bin/plugins - gesucht wurde also
 * /opt/loxberry/bin/plugins/webfrontend/htmlauth/wp_lib.php. Die gibt es nicht: der
 * Dienst brach bei JEDEM Cron-Lauf mit einem fatalen Fehler ab, und weil die
 * Cron-Zeile nach /dev/null schreibt, stand das nirgends.
 *
 * Gefunden am 16.08.2026 mit Werkzeuge/installationslage_pruefen.py, nachdem
 * dieselbe Zeile den Hintergrunddienst des Abfahrts-Assistenten von 1.5.0 bis
 * 1.5.7 lahmgelegt hatte.
 */
$wp_lb = getenv('LBHOMEDIR');
$wp_ordner = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
$wp_kandidaten = array();
if ($wp_lb) {
    $wp_kandidaten[] = $wp_lb . '/webfrontend/htmlauth/plugins/' . $wp_ordner . '/wp_lib.php';
}
// installiert, ohne dass die Umgebungsvariablen gesetzt waeren:
// .../bin/plugins/<ordner>  ->  .../webfrontend/htmlauth/plugins/<ordner>
$wp_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                 . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/wp_lib.php';
// entpacktes Archiv: bin/ und webfrontend/ liegen nebeneinander
$wp_kandidaten[] = dirname(__DIR__) . '/webfrontend/htmlauth/wp_lib.php';

$wp_lib = '';
foreach ($wp_kandidaten as $wp_kand) {
    if (is_file($wp_kand)) { $wp_lib = $wp_kand; break; }
}
if ($wp_lib === '') {
    fwrite(STDERR, "Waermepumpe Cloud: wp_lib.php nicht gefunden. Gesucht wurde in:\n");
    foreach ($wp_kandidaten as $wp_kand) { fwrite(STDERR, '  ' . $wp_kand . "\n"); }
    exit(1);
}
require_once $wp_lib;

$modus = isset($argv[1]) ? (string) $argv[1] : 'takt';

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
    wp_sg_durchsetzen($cfg);
    list($ok, $grund) = wp_abrufen($modus === 'jetzt');
} finally {
    flock($fh, LOCK_UN);
    fclose($fh);
}
exit($ok ? 0 : 1);
