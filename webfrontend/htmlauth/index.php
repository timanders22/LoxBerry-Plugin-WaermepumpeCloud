<?php
/**
 * Waermepumpe Cloud fuer LoxBerry - Bedienoberflaeche
 *
 * NUR Oberflaeche. Der Datenabruf steht in bin/wp_abruf.php, der Endpunkt
 * fuer den Miniserver in webfrontend/html/index.php. Drei Aufgaben, drei
 * Dateien - ein Plugin, das beim Aufruf der Oberflaeche die Herstellercloud
 * anfragt, waere bei Daikin nach ein paar Klicks am Tagesbudget.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/wp_lib.php';
require_once __DIR__ . '/wp_test.php';

$p = wp_paths();
if (file_exists($p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $p['home'] . '/libs/phplib/loxberry_web.php';
}

/* Drei Stellen gehoeren immer zusammen: Reiterleiste, Bereich (sm-seite mit
   gleicher id) und diese Positivliste. Fehlt ein Name hier, ist der Reiter
   sichtbar und anklickbar - aber nach jedem Absenden springt die Seite
   zurueck auf Einstellungen. */
$wp_muster = '/^tab-(settings|sgready|mqtt|loxone|test|log)$/';
$wp_tab = preg_match($wp_muster, (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? (string) $_POST['activetab'] : 'tab-settings';
if (isset($_GET['form']) && preg_match($wp_muster, 'tab-' . $_GET['form'])) {
    $wp_tab = 'tab-' . $_GET['form'];
}

$wp_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';
$wp_meldungen = array();
$wp_fehler = array();
$wp_testausgabe = '';

/** Ein Formularfeld holen: nur Steuerzeichen entfernen, sonst nichts.
 *  Ein preg_replace auf eine Positivliste zerstoert eingefuegte Adressen -
 *  aus http://... wird dann http192168 und niemand sieht, warum. */
function wp_g($feld, $vorgabe = '')
{
    if (!isset($_POST[$feld])) { return $vorgabe; }
    return trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $_POST[$feld]));
}

$wp_cfg = wp_config();
$wp_geh = wp_geheim();

/* ================= Vorlage herunterladen ================= */
if ($wp_post && isset($_POST['download'])) {
    $v = ($_POST['download'] === 'xml_out') ? wp_vorlage_aus() : wp_vorlage_ein();
    header('Content-Type: application/x-download');
    // Die Anfuehrungszeichen um den Dateinamen sind Pflicht - ohne sie bricht
    // jeder Name, der ein Leerzeichen enthaelt.
    header('Content-Disposition: attachment; filename="' . $v[0] . '"');
    header('Content-Length: ' . strlen($v[1]));
    echo $v[1];
    exit;
}

/* ================= Zugangsdaten speichern ================= */
if ($wp_post && isset($_POST['speichern_zugang'])) {
    $neu = $wp_geh;
    $h = wp_g('hersteller');
    if (!wp_hersteller_info($h)) {
        $wp_fehler[] = wp_t('EINST.FEHLER_HERSTELLER');
    } else {
        $wp_cfg['hersteller'] = $h;
    }

    if ($h === 'melcloud') {
        $b = wp_g('benutzer');
        if ($b !== '') {
            if (!filter_var($b, FILTER_VALIDATE_EMAIL)) {
                $wp_fehler[] = wp_t('EINST.FEHLER_EMAIL');
            } else {
                $neu['benutzer'] = $b;
            }
        }
        // Leere Felder loeschen nichts: kommt das Passwortfeld leer zurueck,
        // bleibt das gespeicherte stehen. Sonst stuende irgendwann ein leeres
        // Passwort in der Anmeldung, und die Ursache waere von aussen nicht
        // zu sehen.
        $pw = wp_g('passwort');
        if ($pw !== '') { $neu['passwort'] = $pw; }
        if (!empty($_POST['passwort_loeschen'])) { $neu['passwort'] = ''; }
        if ($neu['passwort'] !== '' && $neu['benutzer'] === '') {
            $wp_fehler[] = wp_t('EINST.FEHLER_PW_OHNE_BENUTZER');
        }
    } else {
        $ci = wp_g('client_id');
        if ($ci !== '') {
            // Beide Anbieter geben eine GUID aus. Ist die Form erkennbar
            // falsch, wird hier abgewiesen - statt den Nutzer in eine
            // Fehlermeldung des Anbieters laufen zu lassen.
            if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $ci)) {
                $wp_fehler[] = wp_t('EINST.FEHLER_CLIENTID');
            } else {
                $neu['client_id'] = $ci;
            }
        }
        $cs = wp_g('client_secret');
        if ($cs !== '') {
            if (strlen($cs) < 16) {
                $wp_fehler[] = wp_t('EINST.FEHLER_SECRET');
            } else {
                $neu['client_secret'] = $cs;
            }
        }
        if (!empty($_POST['secret_loeschen'])) { $neu['client_secret'] = ''; }

        if ($h === 'onecta') {
            $ru = wp_g('redirect_uri');
            if ($ru !== '') {
                if (!preg_match('#^https?://#i', $ru)) {
                    $wp_fehler[] = wp_t('EINST.FEHLER_REDIRECT');
                } else {
                    $neu['redirect_uri'] = $ru;
                }
            }
        }
    }

    if (!$wp_fehler) {
        $okA = wp_geheim_write($neu);
        $okB = wp_config_write($wp_cfg);
        if ($okA && $okB) {
            $wp_meldungen[] = wp_t('EINST.GESPEICHERT');
            wp_log('Zugangsdaten gespeichert (Hersteller ' . $wp_cfg['hersteller'] . ')');
        } else {
            $wp_fehler[] = sprintf(wp_t('EINST.FEHLER_SPEICHERN'), wp_e($p['configdir']));
        }
    }
    $wp_cfg = wp_config();
    $wp_geh = wp_geheim();
    $wp_tab = 'tab-settings';
}

/* ================= Onecta: Code einloesen ================= */
if ($wp_post && isset($_POST['onecta_code'])) {
    $code = wp_g('code');
    if ($code === '') {
        $wp_fehler[] = wp_t('EINST.FEHLER_CODE_LEER');
    } else {
        // Wer die ganze Adresse aus der Adresszeile einfuegt, meint den Code
        // darin - also herausloesen, statt die Eingabe abzuweisen.
        if (preg_match('/[?&]code=([^&\s]+)/', $code, $m)) { $code = urldecode($m[1]); }
        list($ok, $grund) = wp_oc_code_einloesen($code);
        if ($ok) {
            $wp_meldungen[] = wp_t('EINST.CODE_OK');
            wp_log('Onecta: Anmeldung abgeschlossen');
        } else {
            $wp_fehler[] = sprintf(wp_t('EINST.CODE_FEHL'), wp_e($grund));
        }
    }
    $wp_geh = wp_geheim();
    $wp_tab = 'tab-settings';
}

/* ================= Geraet und Takt speichern ================= */
if ($wp_post && isset($_POST['speichern_geraet'])) {
    $wp_stand_vor = wp_stand();
    $wp_cfg['geraet']   = preg_replace('/[^A-Za-z0-9_\-]/', '', wp_g('geraet'));
    $wp_cfg['gebaeude'] = preg_replace('/[^A-Za-z0-9_\-]/', '', wp_g('gebaeude'));
    $wp_cfg['system']   = preg_replace('/[^A-Za-z0-9_\-]/', '', wp_g('system'));

    // Den MELCloud-Geraetetyp aus der Suche uebernehmen, statt ihn erfragen
    // zu lassen. Er entscheidet, ob ueberhaupt geschrieben werden darf -
    // aber niemand kann wissen, ob seine Anlage in der Zaehlweise von
    // Mitsubishi eine 0 oder eine 1 ist.
    if ($wp_cfg['hersteller'] === 'melcloud' && $wp_cfg['geraet'] !== '') {
        $wp_gefunden = false;
        foreach ($wp_stand_vor['geraete'] as $wp_d) {
            if ((string) $wp_d['id'] === $wp_cfg['geraet']) {
                $wp_cfg['geraetetyp'] = isset($wp_d['typ']) ? (int) $wp_d['typ'] : -1;
                if ($wp_cfg['gebaeude'] === '' && $wp_d['system'] !== '') {
                    $wp_cfg['gebaeude'] = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $wp_d['system']);
                }
                $wp_gefunden = true;
                break;
            }
        }
        if (!$wp_gefunden) { $wp_cfg['geraetetyp'] = -1; }
    }

    $takt = (int) wp_g('takt', '300');
    $info = wp_hersteller_info($wp_cfg['hersteller']);
    if ($info && $takt < (int) $info['mindesttakt']) {
        $wp_fehler[] = sprintf(wp_t('EINST.FEHLER_TAKT'), $takt, (int) $info['mindesttakt'],
                               wp_e($info['name']));
    } else {
        $wp_cfg['takt'] = $takt;
    }

    $res = (int) wp_g('budget_schreiben', '40');
    if ($info && !empty($info['budget'])) {
        if ($res < 0 || $res > (int) $info['budget'] - 10) {
            $wp_fehler[] = sprintf(wp_t('EINST.FEHLER_RESERVE'), (int) $info['budget'] - 10);
        } else {
            $wp_cfg['budget_schreiben'] = $res;
        }
    }

    $wp_cfg['mqtt_ein'] = !empty($_POST['mqtt_ein']) ? 1 : 0;
    $t = preg_replace('#[^A-Za-z0-9_/\-]#', '', wp_g('mqtt_topic', 'waermepumpe'));
    if ($t === '') { $wp_fehler[] = wp_t('EINST.FEHLER_TOPIC'); } else { $wp_cfg['mqtt_topic'] = $t; }

    if (!$wp_fehler) {
        if (wp_config_write($wp_cfg)) { $wp_meldungen[] = wp_t('EINST.GESPEICHERT'); }
        else { $wp_fehler[] = sprintf(wp_t('EINST.FEHLER_SPEICHERN'), wp_e($p['configdir'])); }
    }
    $wp_cfg = wp_config();
    $wp_tab = 'tab-settings';
}

/* ================= SG Ready speichern ================= */
if ($wp_post && isset($_POST['speichern_sg'])) {
    $wp_cfg['sg_ein']     = !empty($_POST['sg_ein']) ? 1 : 0;
    $wp_cfg['ww_boost_4'] = !empty($_POST['ww_boost_4']) ? 1 : 0;

    foreach (array('anhebung_3' => 15, 'anhebung_4' => 15) as $f => $max) {
        $v = (int) wp_g($f, '0');
        if ($v < 0 || $v > $max) {
            $wp_fehler[] = sprintf(wp_t('EINST.FEHLER_BEREICH'), wp_e(wp_t('SG.L_' . strtoupper($f))), 0, $max);
        } else {
            $wp_cfg[$f] = $v;
        }
    }
    $sp = (int) wp_g('sperre_max', (string) WP_SPERRE_MAX);
    if ($sp < 5 || $sp > 720) {
        $wp_fehler[] = sprintf(wp_t('EINST.FEHLER_BEREICH'), wp_e(wp_t('SG.L_SPERRE')), 5, 720);
    } else {
        $wp_cfg['sperre_max'] = $sp;
    }
    $bs = wp_g('basis_soll', '');
    if ($bs !== '') {
        $bs = (float) str_replace(',', '.', $bs);
        if ($bs < 0 || $bs > 90) {
            $wp_fehler[] = sprintf(wp_t('EINST.FEHLER_BEREICH'), wp_e(wp_t('SG.L_BASIS')), 0, 90);
        } else {
            $wp_cfg['basis_soll'] = $bs;
        }
    }

    if (!$wp_fehler) {
        if (wp_config_write($wp_cfg)) { $wp_meldungen[] = wp_t('SG.GESPEICHERT'); }
        else { $wp_fehler[] = sprintf(wp_t('EINST.FEHLER_SPEICHERN'), wp_e($p['configdir'])); }
    }
    $wp_cfg = wp_config();
    $wp_tab = 'tab-sgready';
}

/* ================= Feldzuordnung speichern ================= */
if ($wp_post && isset($_POST['speichern_zuordnung'])) {
    $text = isset($_POST['zuordnung']) ? (string) $_POST['zuordnung'] : '';
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    if (strlen($text) > WP_ZUORDNUNG_MAX) {
        $wp_fehler[] = sprintf(wp_t('TEST.FEHLER_ZUORDNUNG_LANG'), WP_ZUORDNUNG_MAX);
    } else {
        $bekannt = array_keys(wp_felder());
        foreach (wp_zuordnung_lesen($text) as $n => $v) {
            if (!in_array($n, $bekannt, true)) {
                $wp_fehler[] = sprintf(wp_t('TEST.FEHLER_ZUORDNUNG_FELD'), wp_e($n),
                                       wp_e(implode(', ', $bekannt)));
            }
        }
        if (!$wp_fehler) {
            $wp_cfg['zuordnung'] = $text;
            if (wp_config_write($wp_cfg)) { $wp_meldungen[] = wp_t('TEST.ZUORDNUNG_GESPEICHERT'); }
            else { $wp_fehler[] = sprintf(wp_t('EINST.FEHLER_SPEICHERN'), wp_e($p['configdir'])); }
        }
    }
    $wp_cfg = wp_config();
    $wp_tab = 'tab-test';
}

/* ================= Test-Aktionen ================= */
if ($wp_post && isset($_POST['test'])) {
    list($ok, $text) = wp_test_aktion((string) $_POST['test'], wp_g('zusatz'));
    $wp_testausgabe = $text;
    if (!$ok) { $wp_fehler[] = $text; $wp_testausgabe = ''; }
    $wp_cfg = wp_config();
    $wp_tab = 'tab-test';
}

/* ================= Protokoll leeren ================= */
if ($wp_post && isset($_POST['log_leeren'])) {
    @file_put_contents($p['logdir'] . '/waermepumpe.log', '');
    $wp_meldungen[] = wp_t('LOG.GELEERT');
    $wp_tab = 'tab-log';
}

$wp_stand = wp_stand();
$wp_info  = wp_hersteller_info($wp_cfg['hersteller']);

if (class_exists('LBWeb', false)) {
    LBWeb::lbheader(wp_t('ALLG.TITEL'), 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Und beim
   Auswahlfeld liegt das unsichtbare <select> ueber dem Knopf und faengt die
   Klicks ab; wer es gestaltet, schiebt es weg. Deshalb wird ausschliesslich
   der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln - bewusst ein anderer Name als sm-knopfreihe. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }

.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar. Ohne diese zwei Zeilen stehen
   alle sechs Reiter untereinander, sobald das Skript nicht laeuft. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #e0a0a0; background: #fdecec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-stufe { display: inline-block; min-width: 26px; text-align: center; font-weight: 700;
    border-radius: 4px; padding: 1px 6px; background: #eef3e6; }
</style>

<div class="sm-wrap">

<?php if ($wp_meldungen) { ?>
<div class="sm-hinweis"><ul style="margin:0;padding-left:18px;">
<?php foreach ($wp_meldungen as $wp_m) { ?><li><?= $wp_m ?></li><?php } ?></ul></div>
<?php } ?>
<?php if ($wp_fehler) { ?>
<div class="sm-fehler"><ul style="margin:0;padding-left:18px;">
<?php foreach ($wp_fehler as $wp_f) { ?><li><?= $wp_f ?></li><?php } ?></ul></div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. Der Link
     traegt die Adresse - jeder Reiter ist verlinkbar, die Zurueck-Taste tut
     das Erwartete, und faellt das Skript aus, bleibt die Seite bedienbar. -->
<div class="sm-tabs">
	<a class="sm-tab" data-ziel="tab-settings" href="index.php?form=settings"><?= wp_e(wp_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab" data-ziel="tab-sgready"  href="index.php?form=sgready"><?= wp_e(wp_t('REITER.SGREADY')) ?></a>
	<a class="sm-tab" data-ziel="tab-mqtt"     href="index.php?form=mqtt"><?= wp_e(wp_t('REITER.MQTT')) ?></a>
	<a class="sm-tab" data-ziel="tab-loxone"   href="index.php?form=loxone"><?= wp_e(wp_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab" data-ziel="tab-test"     href="index.php?form=test"><?= wp_e(wp_t('REITER.TEST')) ?></a>
	<a class="sm-tab" data-ziel="tab-log"      href="index.php?form=log"><?= wp_e(wp_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite" id="tab-settings">

<div class="sm-kacheln">
  <div class="sm-kachel"><?= wp_e(wp_t('KACHEL.HERSTELLER')) ?>
    <b><?= wp_e($wp_info ? $wp_info['name'] : wp_t('ALLG.KEINER')) ?></b></div>
  <div class="sm-kachel"><?= wp_e(wp_t('KACHEL.DATEN')) ?>
    <b class="<?= (int) $wp_stand['ok'] ? 'sm-an' : 'sm-aus' ?>"><?php
      echo (int) $wp_stand['zeit'] > 0 ? wp_e(wp_zeitspanne(time() - (int) $wp_stand['zeit']))
                                       : wp_e(wp_t('ALLG.NIE')); ?></b></div>
  <div class="sm-kachel"><?= wp_e(wp_t('KACHEL.STUFE')) ?>
    <b><?= (int) ($wp_stand['stufe_gesetzt'] ?: $wp_cfg['sg_stufe']) ?></b></div>
<?php if ($wp_info && !empty($wp_info['budget'])) { ?>
  <div class="sm-kachel"><?= wp_e(wp_t('KACHEL.BUDGET')) ?>
    <b><?= (int) wp_budget_rest($wp_cfg['hersteller']) ?> / <?= (int) $wp_info['budget'] ?></b></div>
<?php } ?>
</div>

<h2><?= wp_e(wp_t('EINST.H_HERSTELLER')) ?></h2>
<div class="sm-warnung"><?= wp_t('EINST.HERSTELLER_TEXT') ?></div>

<table class="sm-tbl">
<tr><th><?= wp_e(wp_t('EINST.T_HERSTELLER')) ?></th><th><?= wp_e(wp_t('EINST.T_ART')) ?></th>
    <th><?= wp_e(wp_t('EINST.T_SGREADY')) ?></th><th><?= wp_e(wp_t('EINST.T_GRENZE')) ?></th></tr>
<tr><td>myUplink (Nibe)</td><td><?= wp_e(wp_t('EINST.ART_OFFIZIELL')) ?></td>
    <td><span class="sm-an"><?= wp_e(wp_t('EINST.SG_ECHT')) ?></span></td>
    <td><?= wp_t('EINST.GRENZE_MYUPLINK') ?></td></tr>
<tr><td>Daikin Onecta</td><td><?= wp_e(wp_t('EINST.ART_OFFIZIELL')) ?></td>
    <td><?= wp_e(wp_t('EINST.SG_NACHGEBILDET')) ?></td>
    <td><?= wp_t('EINST.GRENZE_ONECTA') ?></td></tr>
<tr><td>MELCloud (Mitsubishi)</td><td><?= wp_e(wp_t('EINST.ART_INOFFIZIELL')) ?></td>
    <td><?= wp_e(wp_t('EINST.SG_NACHGEBILDET')) ?></td>
    <td><?= wp_t('EINST.GRENZE_MELCLOUD') ?></td></tr>
</table>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<div class="sm-feld">
  <label for="wp_hersteller"><?= wp_e(wp_t('EINST.L_HERSTELLER')) ?></label>
  <select data-role="none" name="hersteller" id="wp_hersteller">
    <option value=""><?= wp_e(wp_t('EINST.O_BITTE_WAEHLEN')) ?></option>
<?php foreach (wp_hersteller() as $wp_k => $wp_h) { ?>
    <option value="<?= wp_e($wp_k) ?>"<?= $wp_cfg['hersteller'] === $wp_k ? ' selected' : '' ?>><?= wp_e($wp_h['name']) ?></option>
<?php } ?>
  </select>
</div>

<?php if ($wp_cfg['hersteller'] === 'melcloud') { ?>
<div class="sm-feld">
  <label for="wp_benutzer"><?= wp_e(wp_t('EINST.L_BENUTZER')) ?></label>
  <input data-role="none" type="email" name="benutzer" id="wp_benutzer" value="<?= wp_e($wp_geh['benutzer']) ?>" size="40">
  <div class="sm-hilfe"><?= wp_t('EINST.H_BENUTZER') ?></div>
</div>
<div class="sm-feld">
  <label for="wp_passwort"><?= wp_e(wp_t('EINST.L_PASSWORT')) ?></label>
  <input data-role="none" type="password" name="passwort" id="wp_passwort" value="" size="40"
         placeholder="<?= wp_e($wp_geh['passwort'] !== '' ? wp_t('EINST.P_GESETZT') : wp_t('EINST.P_LEER')) ?>">
  <div class="sm-hilfe"><?= wp_t('EINST.H_PASSWORT') ?></div>
  <label style="font-weight:400;"><input data-role="none" type="checkbox" name="passwort_loeschen" value="1">
    <?= wp_e(wp_t('EINST.L_PW_LOESCHEN')) ?></label>
</div>
<?php } elseif ($wp_cfg['hersteller'] !== '') { ?>
<div class="sm-feld">
  <label for="wp_client_id"><?= wp_e(wp_t('EINST.L_CLIENTID')) ?></label>
  <input data-role="none" type="text" name="client_id" id="wp_client_id" value="<?= wp_e($wp_geh['client_id']) ?>" size="44">
  <div class="sm-hilfe"><?= sprintf(wp_t('EINST.H_CLIENTID'), wp_e($wp_info['portal'])) ?></div>
</div>
<div class="sm-feld">
  <label for="wp_client_secret"><?= wp_e(wp_t('EINST.L_SECRET')) ?></label>
  <input data-role="none" type="password" name="client_secret" id="wp_client_secret" value="" size="44"
         placeholder="<?= wp_e($wp_geh['client_secret'] !== '' ? wp_t('EINST.P_GESETZT') : wp_t('EINST.P_LEER')) ?>">
  <div class="sm-hilfe"><?= wp_t('EINST.H_SECRET') ?></div>
  <label style="font-weight:400;"><input data-role="none" type="checkbox" name="secret_loeschen" value="1">
    <?= wp_e(wp_t('EINST.L_SECRET_LOESCHEN')) ?></label>
</div>
<?php } ?>

<?php if ($wp_cfg['hersteller'] === 'onecta') { ?>
<div class="sm-feld">
  <label for="wp_redirect"><?= wp_e(wp_t('EINST.L_REDIRECT')) ?></label>
  <input data-role="none" type="text" name="redirect_uri" id="wp_redirect" size="52"
         value="<?= wp_e($wp_geh['redirect_uri']) ?>">
  <div class="sm-hilfe"><?= wp_t('EINST.H_REDIRECT') ?></div>
</div>
<?php } ?>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_zugang" value="1"><?= wp_e(wp_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<?php if ($wp_cfg['hersteller'] === 'onecta') { ?>
<h2><?= wp_e(wp_t('EINST.H_ONECTA_ANMELDUNG')) ?></h2>
<div class="sm-step"><?= wp_t('EINST.ONECTA_TEXT') ?></div>
<?php if ($wp_geh['client_id'] !== '' && $wp_geh['redirect_uri'] !== '') { ?>
<div class="sm-hilfe"><?= wp_e(wp_t('EINST.ONECTA_ADRESSE')) ?></div>
<div class="sm-pre"><?= wp_e(wp_oc_anmeldeadresse()) ?></div>
<?php } else { ?>
<div class="sm-warnung"><?= wp_t('EINST.ONECTA_UNVOLLSTAENDIG') ?></div>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<div class="sm-feld">
  <label for="wp_code"><?= wp_e(wp_t('EINST.L_CODE')) ?></label>
  <input data-role="none" type="text" name="code" id="wp_code" size="52" value="">
  <div class="sm-hilfe"><?= wp_t('EINST.H_CODE') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="onecta_code" value="1"><?= wp_e(wp_t('EINST.K_CODE')) ?></button>
</div>
</form>
<?php } ?>

<?php if ($wp_cfg['hersteller'] !== '') { ?>
<h2><?= wp_e(wp_t('EINST.H_GERAET')) ?></h2>
<div class="sm-hilfe"><?= wp_t('EINST.GERAET_TEXT') ?></div>
<?php if (!empty($wp_stand['geraete'])) { ?>
<table class="sm-tbl">
<tr><th><?= wp_e(wp_t('EINST.T_NAME')) ?></th><th><?= wp_e(wp_t('EINST.T_KENNUNG')) ?></th><th><?= wp_e(wp_t('EINST.T_SYSTEM')) ?></th></tr>
<?php foreach ($wp_stand['geraete'] as $wp_d) { ?>
<tr><td><?= wp_e($wp_d['name']) ?></td><td><span class="sm-mono"><?= wp_e($wp_d['id']) ?></span></td>
    <td><span class="sm-mono"><?= wp_e($wp_d['system']) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<div class="sm-feld">
  <label for="wp_geraet"><?= wp_e(wp_t('EINST.L_GERAET')) ?></label>
  <input data-role="none" type="text" name="geraet" id="wp_geraet" value="<?= wp_e($wp_cfg['geraet']) ?>" size="44">
</div>
<?php if ($wp_cfg['hersteller'] === 'melcloud') { ?>
<div class="sm-feld">
  <label for="wp_gebaeude"><?= wp_e(wp_t('EINST.L_GEBAEUDE')) ?></label>
  <input data-role="none" type="text" name="gebaeude" id="wp_gebaeude" value="<?= wp_e($wp_cfg['gebaeude']) ?>" size="16">
  <div class="sm-hilfe"><?= wp_t('EINST.H_GEBAEUDE') ?></div>
</div>
<?php } ?>
<?php if ($wp_cfg['hersteller'] === 'myuplink') { ?>
<div class="sm-feld">
  <label for="wp_system"><?= wp_e(wp_t('EINST.L_SYSTEM')) ?></label>
  <input data-role="none" type="text" name="system" id="wp_system" value="<?= wp_e($wp_cfg['system']) ?>" size="44">
  <div class="sm-hilfe"><?= wp_t('EINST.H_SYSTEM') ?></div>
</div>
<?php } ?>

<div class="sm-feld">
  <label for="wp_takt"><?= wp_e(wp_t('EINST.L_TAKT')) ?></label>
  <input data-role="none" type="number" name="takt" id="wp_takt" value="<?= (int) $wp_cfg['takt'] ?>"
         min="<?= (int) $wp_info['mindesttakt'] ?>" max="3600" step="10">
  <div class="sm-hilfe"><?= sprintf(wp_t('EINST.H_TAKT'), (int) $wp_info['mindesttakt'], wp_e($wp_info['name'])) ?></div>
</div>

<?php if (!empty($wp_info['budget'])) { ?>
<div class="sm-feld">
  <label for="wp_reserve"><?= wp_e(wp_t('EINST.L_RESERVE')) ?></label>
  <input data-role="none" type="number" name="budget_schreiben" id="wp_reserve"
         value="<?= (int) $wp_cfg['budget_schreiben'] ?>" min="0" max="<?= (int) $wp_info['budget'] - 10 ?>" step="5">
  <div class="sm-hilfe"><?= sprintf(wp_t('EINST.H_RESERVE'), (int) $wp_info['budget'],
      (int) $wp_cfg['budget_schreiben'],
      (int) $wp_info['budget'] - (int) $wp_cfg['budget_schreiben'],
      wp_takt_aus_budget((int) $wp_info['budget'], (int) $wp_cfg['budget_schreiben'])) ?></div>
</div>
<?php } ?>

<div class="sm-feld">
  <label style="font-weight:400;"><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= $wp_cfg['mqtt_ein'] ? ' checked' : '' ?>>
    <?= wp_e(wp_t('EINST.L_MQTT_EIN')) ?></label>
</div>
<div class="sm-feld">
  <label for="wp_topic"><?= wp_e(wp_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" name="mqtt_topic" id="wp_topic" value="<?= wp_e($wp_cfg['mqtt_topic']) ?>" size="30">
</div>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_geraet" value="1"><?= wp_e(wp_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<?php } ?>
</div>

<!-- ================= Reiter: SG Ready ================= -->
<div class="sm-seite" id="tab-sgready">
<h2><?= wp_e(wp_t('SG.H_TITEL')) ?></h2>
<div class="sm-step"><?= wp_t('SG.ERKLAERUNG') ?></div>

<table class="sm-tbl">
<tr><th><?= wp_e(wp_t('SG.T_STUFE')) ?></th><th><?= wp_e(wp_t('SG.T_KLEMMEN')) ?></th>
    <th><?= wp_e(wp_t('SG.T_BEDEUTUNG')) ?></th><th><?= wp_e(wp_t('SG.T_HIER')) ?></th></tr>
<?php for ($wp_s = 1; $wp_s <= WP_STUFEN; $wp_s++) {
    list($wp_k1, $wp_k2) = wp_sg_klemmen($wp_s); ?>
<tr>
  <td><span class="sm-stufe"><?= $wp_s ?></span></td>
  <td><span class="sm-mono"><?= $wp_k1 ?>:<?= $wp_k2 ?></span></td>
  <td><?= wp_e(wp_t('SG.STUFE' . $wp_s)) ?></td>
  <td><?= $wp_cfg['hersteller'] === '' ? '&mdash;'
        : wp_t('SG.WIRKUNG_' . strtoupper($wp_cfg['hersteller']) . '_' . $wp_s) ?></td>
</tr>
<?php } ?>
</table>

<?php if ($wp_cfg['hersteller'] !== '' && !wp_sg_echt($wp_cfg['hersteller'])) { ?>
<div class="sm-warnung"><?= sprintf(wp_t('SG.NACHBILDUNG_WARNUNG'), wp_e($wp_info['name'])) ?></div>
<?php } elseif ($wp_cfg['hersteller'] === 'myuplink') { ?>
<div class="sm-hinweis"><?= wp_t('SG.ECHT_HINWEIS') ?></div>
<?php } ?>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-sgready">
<div class="sm-feld">
  <label style="font-weight:400;"><input data-role="none" type="checkbox" name="sg_ein" value="1"<?= $wp_cfg['sg_ein'] ? ' checked' : '' ?>>
    <?= wp_e(wp_t('SG.L_EIN')) ?></label>
  <div class="sm-hilfe"><?= wp_t('SG.H_EIN') ?></div>
</div>

<div class="sm-feld">
  <label for="wp_sperre"><?= wp_e(wp_t('SG.L_SPERRE')) ?></label>
  <input data-role="none" type="number" name="sperre_max" id="wp_sperre" value="<?= (int) $wp_cfg['sperre_max'] ?>" min="5" max="720" step="5">
  <div class="sm-hilfe"><?= wp_t('SG.H_SPERRE') ?></div>
</div>

<h3><?= wp_e(wp_t('SG.H_NACHBILDUNG')) ?></h3>
<div class="sm-hilfe"><?= wp_t('SG.H_NACHBILDUNG_TEXT') ?></div>

<div class="sm-feld">
  <label for="wp_basis"><?= wp_e(wp_t('SG.L_BASIS')) ?></label>
  <input data-role="none" type="text" name="basis_soll" id="wp_basis" value="<?= $wp_cfg['basis_soll'] > 0 ? wp_e($wp_cfg['basis_soll']) : '' ?>" size="8">
  <div class="sm-hilfe"><?= wp_t('SG.H_BASIS') ?></div>
</div>
<div class="sm-feld">
  <label for="wp_anh3"><?= wp_e(wp_t('SG.L_ANHEBUNG_3')) ?></label>
  <input data-role="none" type="number" name="anhebung_3" id="wp_anh3" value="<?= (int) $wp_cfg['anhebung_3'] ?>" min="0" max="15">
</div>
<div class="sm-feld">
  <label for="wp_anh4"><?= wp_e(wp_t('SG.L_ANHEBUNG_4')) ?></label>
  <input data-role="none" type="number" name="anhebung_4" id="wp_anh4" value="<?= (int) $wp_cfg['anhebung_4'] ?>" min="0" max="15">
</div>
<div class="sm-feld">
  <label style="font-weight:400;"><input data-role="none" type="checkbox" name="ww_boost_4" value="1"<?= $wp_cfg['ww_boost_4'] ? ' checked' : '' ?>>
    <?= wp_e(wp_t('SG.L_WWBOOST')) ?></label>
  <div class="sm-hilfe"><?= wp_t('SG.H_WWBOOST') ?></div>
</div>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_sg" value="1"><?= wp_e(wp_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<?php if ($wp_stand['stufe_quittiert'] !== '') { ?>
<div class="sm-hinweis"><?= sprintf(wp_t('SG.ZULETZT'),
    (int) $wp_stand['stufe_gesetzt'],
    wp_e(date('d.m.Y H:i', (int) $wp_stand['stufe_zeit'])),
    '<span class="sm-mono">' . wp_e($wp_stand['stufe_quittiert']) . '</span>') ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite" id="tab-mqtt">
<h2><?= wp_e(wp_t('MQTT.H_TITEL')) ?></h2>
<?php $wp_m = wp_mqtt_zustand(); ?>
<?php if (!$wp_m['gefunden']) { ?>
<div class="sm-warnung"><?= wp_t('MQTT.KEIN_ABSCHNITT') ?></div>
<?php } elseif (!$wp_m['autostart']) { ?>
<div class="sm-warnung"><?= wp_t('MQTT.KEIN_AUTOSTART') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= sprintf(wp_t('MQTT.OK'), (int) $wp_m['udpport']) ?></div>
<?php } ?>

<h3><?= wp_e(wp_t('MQTT.H_ABO')) ?></h3>
<div class="sm-hilfe"><?= wp_t('MQTT.ABO_TEXT') ?></div>
<div class="sm-pre"><?= wp_e($wp_cfg['mqtt_topic']) ?>/#</div>
<div class="sm-warnung"><?= wp_t('MQTT.ABO_WARNUNG') ?></div>

<h3><?= wp_e(wp_t('MQTT.H_THEMEN')) ?></h3>
<table class="sm-tbl">
<tr><th><?= wp_e(wp_t('MQTT.T_THEMA')) ?></th><th><?= wp_e(wp_t('MQTT.T_BEDEUTUNG')) ?></th><th><?= wp_e(wp_t('MQTT.T_WERT')) ?></th></tr>
<?php foreach (wp_statusfelder() as $wp_n => $wp_d) { ?>
<tr><td><span class="sm-mono"><?= wp_e($wp_cfg['mqtt_topic']) ?>/<?= wp_e($wp_n) ?></span></td>
    <td><?= wp_t($wp_d[3]) ?></td>
    <td><?= isset($wp_stand['werte'][$wp_n]) ? wp_e($wp_stand['werte'][$wp_n]) : '&mdash;' ?></td></tr>
<?php } ?>
</table>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite" id="tab-loxone">
<h2><?= wp_e(wp_t('LOX.H_TITEL')) ?></h2>
<div class="sm-step"><b>1.</b> <?= wp_t('LOX.S1') ?></div>
<div class="sm-step"><b>2.</b> <?= wp_t('LOX.S2') ?>
  <div class="sm-pre"><?= wp_e($wp_cfg['mqtt_topic']) ?>/#</div>
  <?= wp_t('LOX.S2_WARNUNG') ?></div>
<div class="sm-step"><b>3.</b> <?= wp_t('LOX.S3') ?>
  <div class="sm-pre"><?= wp_e(wp_endpunkt('status')) ?></div>
  <?= wp_t('LOX.S3_HINWEIS') ?>
  <table class="sm-tbl">
  <tr><th><?= wp_e(wp_t('LOX.T_TITEL')) ?></th><th><?= wp_e(wp_t('LOX.T_ART')) ?></th><th><?= wp_e(wp_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (wp_statusfelder() as $wp_n => $wp_d) { ?>
  <tr><td><span class="sm-mono">WP_<?= wp_e($wp_n) ?></span></td>
      <td><?= $wp_d[0] ? wp_e(wp_t('LOX.ANALOG')) : wp_e(wp_t('LOX.DIGITAL')) ?></td>
      <td><?= wp_t($wp_d[3]) ?></td></tr>
<?php } ?>
  </table>
</div>
<div class="sm-step"><b>4.</b> <?= wp_t('LOX.S4') ?>
  <div class="sm-pre"><?= wp_e(wp_endpunkt('sgready')) ?>&amp;stufe=1 .. 4</div>
  <?= wp_t('LOX.S4_KLEMMEN') ?>
  <div class="sm-pre"><?= wp_e(wp_endpunkt('sgready')) ?>&amp;k1=1&amp;k2=0</div>
</div>
<div class="sm-step"><b>5.</b> <?= wp_t('LOX.S5') ?></div>

<h3><?= wp_e(wp_t('LOX.H_VORLAGE')) ?></h3>
<div class="sm-hilfe"><?= wp_t('LOX.VORLAGE_TEXT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= wp_t('LEGENDE.TECHNIK') ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="download" value="xml_in">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= wp_e(wp_t('LOX.K_VORLAGE_EIN')) ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="download" value="xml_out">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= wp_e(wp_t('LOX.K_VORLAGE_AUS')) ?></button>
</form>
</div>

<h3><?= wp_e(wp_t('LOX.H_BAUSTEINE')) ?></h3>
<div class="sm-hilfe"><?= wp_t('LOX.BAUSTEINE_TEXT') ?></div>
<table class="sm-tbl">
<tr><th>#</th><th><?= wp_e(wp_t('LOX.T_BAUSTEIN')) ?></th><th><?= wp_e(wp_t('LOX.T_NAME')) ?></th>
    <th><?= wp_e(wp_t('LOX.T_PARAMETER')) ?></th><th><?= wp_e(wp_t('LOX.T_VERBINDEN')) ?></th></tr>
<?php for ($wp_b = 1; $wp_b <= 8; $wp_b++) { ?>
<tr><td><?= $wp_b ?></td><td><?= wp_t('BAUSTEIN.B' . $wp_b . '_TYP') ?></td>
    <td><span class="sm-mono"><?= wp_t('BAUSTEIN.B' . $wp_b . '_NAME') ?></span></td>
    <td><?= wp_t('BAUSTEIN.B' . $wp_b . '_PARAM') ?></td>
    <td><?= wp_t('BAUSTEIN.B' . $wp_b . '_VERB') ?></td></tr>
<?php } ?>
</table>
<div class="sm-hinweis"><?= wp_t('LOX.BAUSTEINE_ERLAEUTERUNG') ?></div>

<div class="sm-step"><b><?= wp_e(wp_t('LOX.H_GEGENPROBE')) ?></b><br><?= wp_t('LOX.GEGENPROBE') ?></div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite" id="tab-test">
<h2><?= wp_e(wp_t('TEST.H_SELBSTTEST')) ?></h2>
<div class="sm-hilfe"><?= wp_t('TEST.SELBSTTEST_TEXT') ?></div>
<?php
$wp_pr = wp_pruefungen();
$wp_schlecht = 0;
foreach ($wp_pr as $wp_z) { if ($wp_z[0] === 0) { $wp_schlecht++; } }
?>
<div class="<?= $wp_schlecht ? 'sm-warnung' : 'sm-hinweis' ?>">
<?= sprintf(wp_t($wp_schlecht ? 'TEST.SELBSTTEST_FEHL' : 'TEST.SELBSTTEST_OK'),
            count($wp_pr) - $wp_schlecht, count($wp_pr)) ?>
</div>
<table class="sm-tbl">
<tr><th style="width:34px;"></th><th style="width:34%;"><?= wp_e(wp_t('TEST.T_FRAGE')) ?></th><th><?= wp_e(wp_t('TEST.T_ANTWORT')) ?></th></tr>
<?php foreach ($wp_pr as $wp_z) { ?>
<tr><td style="text-align:center;"><?= $wp_z[0] === 1 ? '<span class="sm-an">&#10004;</span>'
        : ($wp_z[0] === 0 ? '<span class="sm-aus">&#10008;</span>' : '<b>i</b>') ?></td>
    <td><?= $wp_z[1] ?></td><td><?= $wp_z[2] ?></td></tr>
<?php } ?>
</table>

<h2><?= wp_e(wp_t('TEST.H_KNOEPFE')) ?></h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= wp_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= wp_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= wp_t('LEGENDE.AKTION') ?></span>
</div>

<div class="sm-knopfreihe">
<?php foreach (array('geraete' => 'lesen', 'abruf' => 'lesen', 'zeile' => 'lesen') as $wp_a => $wp_farbe) { ?>
<form action="index.php" method="post" style="margin:0;"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <button data-role="none" class="sm-btn sm-b-<?= $wp_farbe ?>" type="submit" name="test" value="<?= $wp_a ?>"><?= wp_e(wp_t('TEST.K_' . strtoupper($wp_a))) ?></button>
</form>
<?php } ?>
</div>
<div class="sm-knopfreihe">
<?php foreach (array('wege', 'roh') as $wp_a) { ?>
<form action="index.php" method="post" style="margin:0;"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="<?= $wp_a ?>"><?= wp_e(wp_t('TEST.K_' . strtoupper($wp_a))) ?></button>
</form>
<?php } ?>
<form action="index.php" method="post" style="margin:0;"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="token"><?= wp_e(wp_t('TEST.K_TOKEN')) ?></button>
</form>
</div>

<h3><?= wp_e(wp_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= wp_t('TEST.SCHALTEN_TEXT') ?></div>
<div class="sm-knopfreihe">
<?php for ($wp_s = 1; $wp_s <= WP_STUFEN; $wp_s++) { ?>
<form action="index.php" method="post" style="margin:0;"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="sg<?= $wp_s ?>"><?= $wp_s ?> &mdash; <?= wp_e(wp_t('SG.STUFE' . $wp_s)) ?></button>
</form>
<?php } ?>
</div>

<?php if ($wp_testausgabe !== '') { ?>
<div class="sm-hinweis"><?= $wp_testausgabe ?></div>
<?php } ?>

<h2><?= wp_e(wp_t('TEST.H_ZUORDNUNG')) ?></h2>
<div class="sm-step"><?= wp_t('TEST.ZUORDNUNG_TEXT') ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-feld">
  <label for="wp_zuordnung"><?= wp_e(wp_t('TEST.L_ZUORDNUNG')) ?></label>
  <textarea data-role="none" name="zuordnung" id="wp_zuordnung" rows="6" cols="60"><?= wp_e($wp_cfg['zuordnung']) ?></textarea>
  <div class="sm-hilfe"><?= sprintf(wp_t('TEST.HILFE_ZUORDNUNG'), wp_e(implode(', ', array_keys(wp_felder())))) ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_zuordnung" value="1"><?= wp_e(wp_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite" id="tab-log">
<h2><?= wp_e(wp_t('LOG.H_TITEL')) ?></h2>
<div class="sm-hilfe"><?= wp_t('LOG.MASKE_HINWEIS') ?></div>
<?php
$wp_logdatei = $p['logdir'] . '/waermepumpe.log';
$wp_zeilen = is_file($wp_logdatei) ? array_slice(file($wp_logdatei), -200) : array();
if (!$wp_zeilen) { ?>
<div class="sm-hinweis"><?= wp_e(wp_t('LOG.LEER')) ?></div>
<?php } else { ?>
<div class="sm-pre"><pre><?= wp_e(implode('', $wp_zeilen)) ?></pre></div>
<?php } ?>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="activetab" value="tab-log">
  <div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= wp_t('LEGENDE.AKTION') ?></span></div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= wp_e(wp_t('LOG.K_LEEREN')) ?></button>
  </div>
</form>
<?php if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) { echo LBWeb::loglist_html(); } ?>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($wp_tab) ?>);
})();
</script>
<?php
if (class_exists('LBWeb', false)) { LBWeb::lbfooter(); }
