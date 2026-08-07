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
require_once dirname(__DIR__) . '/webfrontend/htmlauth/wp_lib.php';

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
wp_sg_durchsetzen($cfg);

list($ok, $grund) = wp_abrufen($modus === 'jetzt');

flock($fh, LOCK_UN);
fclose($fh);
exit($ok ? 0 : 1);
