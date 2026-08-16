<?php
/**
 * Waermepumpe Cloud fuer LoxBerry - die Aktionen des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone: traegt die Einrichtung?
 * Sie geht die Kette von unten nach oben durch - Hersteller, Zugangsdaten,
 * Anmeldung, Geraet, Daten, Feldzuordnung, SG Ready, MQTT, Token. Der erste
 * Kreuz-Eintrag ist in aller Regel die Ursache.
 */

function wp_pruefzeile($stand, $frage, $antwort)
{
    return array((int) $stand, $frage, $antwort);
}

function wp_pruefungen()
{
    $cfg = wp_config();
    $g = wp_geheim();
    $stand = wp_stand();
    $z = array();

    /* ---- Hersteller gewaehlt? ---- */
    $info = wp_hersteller_info($cfg['hersteller']);
    if (!$info) {
        $z[] = wp_pruefzeile(0, wp_t('TEST.F_HERSTELLER'), wp_t('TEST.A_HERSTELLER_KEINER'));
        return $z;   // ohne Hersteller ist jede weitere Frage sinnlos
    }
    $z[] = wp_pruefzeile(1, wp_t('TEST.F_HERSTELLER'),
        sprintf(wp_t('TEST.A_HERSTELLER_OK'), wp_e($info['name'])));

    /* ---- Zugangsdaten hinterlegt? Form pruefen, Wert nie zeigen. ---- */
    if ($cfg['hersteller'] === 'emsesp') {
        /* EMS-ESP braucht zum LESEN gar nichts. Ohne Zugriffsmerkmal laeuft
         * die Anzeige vollstaendig; nur Schreiben geht nicht. Deshalb ist ein
         * fehlendes Merkmal hier kein Kreuz, sondern ein Hinweis - solange
         * SG Ready ausgeschaltet ist. */
        $hat = trim((string) $g['ems_token']) !== '';
        if ($hat) {
            $z[] = wp_pruefzeile(1, wp_t('TEST.F_ZUGANG'),
                sprintf(wp_t('TEST.A_ZUGANG_EMS'), wp_e(wp_maske($g['ems_token']))));
        } else {
            $z[] = wp_pruefzeile(empty($cfg['sg_ein']) ? -1 : 0, wp_t('TEST.F_ZUGANG'),
                wp_t(empty($cfg['sg_ein']) ? 'TEST.A_ZUGANG_EMS_LESEN'
                                           : 'TEST.A_ZUGANG_EMS_FEHLT'));
        }
    } elseif ($cfg['hersteller'] === 'melcloud' || $cfg['hersteller'] === 'vaillant') {
        $da = $g['benutzer'] !== '' && $g['passwort'] !== '';
        $z[] = wp_pruefzeile($da ? 1 : 0, wp_t('TEST.F_ZUGANG'),
            $da ? sprintf(wp_t('TEST.A_ZUGANG_MELCLOUD'), wp_e($g['benutzer']),
                          wp_e(wp_maske($g['passwort'])))
                : wp_t('TEST.A_ZUGANG_FEHLT'));
    } else {
        $da = $g['client_id'] !== '' && $g['client_secret'] !== '';
        $z[] = wp_pruefzeile($da ? 1 : 0, wp_t('TEST.F_ZUGANG'),
            $da ? sprintf(wp_t('TEST.A_ZUGANG_OAUTH'), wp_e(wp_maske($g['client_id'])),
                          wp_e(wp_maske($g['client_secret'])))
                : wp_t('TEST.A_ZUGANG_FEHLT'));
    }

    /* ---- Bei Onecta zusaetzlich: Erneuerungsmerkmal aus der Anmeldung ---- */
    if ($cfg['hersteller'] === 'onecta') {
        $z[] = wp_pruefzeile($g['refresh_token'] !== '' ? 1 : 0, wp_t('TEST.F_ONECTA_ANMELDUNG'),
            $g['refresh_token'] !== '' ? wp_t('TEST.A_ONECTA_ANMELDUNG_OK')
                                       : wp_t('TEST.A_ONECTA_ANMELDUNG_FEHLT'));
    }

    /* ---- Antwortet die Cloud? ---- */
    if ($cfg['hersteller'] === 'emsesp') {
        /* Kein Anmeldeschritt - stattdessen die Frage, die hier zaehlt:
         * antwortet das Gateway ueberhaupt? */
        list($d, $grund) = wp_ems_holen($cfg, 'system/info');
        if (is_array($d)) {
            $fassung = isset($d['version']) ? (string) $d['version'] : '?';
            $z[] = wp_pruefzeile(1, wp_t('TEST.F_GATEWAY'),
                sprintf(wp_t('TEST.A_GATEWAY_OK'), wp_e($fassung)));
        } else {
            $z[] = wp_pruefzeile(0, wp_t('TEST.F_GATEWAY'),
                sprintf(wp_t('TEST.A_GATEWAY_FEHL'), wp_e(wp_meld($grund))));
        }
    } else {

    $token = '';
    switch ($cfg['hersteller']) {
        case 'myuplink': $token = wp_mu_token(); break;
        case 'onecta':   $token = wp_oc_token(); break;
        case 'melcloud': $token = wp_ml_schluessel(); break;
        case 'vaillant': $token = wp_va_token(); break;
    }
    if ($token !== '') {
        $z[] = wp_pruefzeile(1, wp_t('TEST.F_ANMELDUNG'), wp_t('TEST.A_ANMELDUNG_OK'));
    } elseif ($cfg['hersteller'] === 'onecta' && wp_oc_abgelaufen()) {
        // Wichtiger Unterschied: hier sind die Zugangsdaten in Ordnung, nur
        // die Anmeldung ist verfallen. Wer jetzt an Client ID und Secret
        // herumbessert, sucht an der falschen Stelle.
        $z[] = wp_pruefzeile(0, wp_t('TEST.F_ANMELDUNG'), wp_t('TEST.A_ANMELDUNG_ABGELAUFEN'));
    } else {
        $z[] = wp_pruefzeile(0, wp_t('TEST.F_ANMELDUNG'), wp_t('TEST.A_ANMELDUNG_FEHL'));
    }

    }   /* Ende: alles ausser EMS-ESP */

    /* ---- Geraet gewaehlt? ---- */
    if ($cfg['hersteller'] === 'emsesp') {
        /* Bei EMS-ESP gibt es nichts auszuwaehlen - gelesen wird immer
         * boiler, und der Heizkreis ist eine Zahl, keine Kennung. Gefragt
         * wird deshalb, ob unter boiler tatsaechlich etwas steht. */
        list($d, $grund) = wp_ems_holen($cfg, 'boiler/values');
        if (is_array($d) && $d) {
            $z[] = wp_pruefzeile(1, wp_t('TEST.F_GERAET'),
                sprintf(wp_t('TEST.A_GERAET_EMS'), count($d), (int) $cfg['ems_hc']));
        } else {
            $z[] = wp_pruefzeile(0, wp_t('TEST.F_GERAET'), wp_t('TEST.A_GERAET_EMS_LEER'));
        }
    } elseif ($cfg['geraet'] === '') {
        $z[] = wp_pruefzeile(0, wp_t('TEST.F_GERAET'), wp_t('TEST.A_GERAET_KEINS'));
    } elseif ($cfg['hersteller'] === 'melcloud' && $cfg['gebaeude'] === '') {
        // MELCloud braucht beides. Fehlt die Gebaeudekennung, antwortet der
        // Dienst mit einer leeren Seite statt mit einem Fehler - das sucht
        // man lange an der falschen Stelle.
        $z[] = wp_pruefzeile(0, wp_t('TEST.F_GERAET'), wp_t('TEST.A_GERAET_OHNE_GEBAEUDE'));
    } else {
        $z[] = wp_pruefzeile(1, wp_t('TEST.F_GERAET'),
            sprintf(wp_t('TEST.A_GERAET_OK'), wp_e($cfg['geraet'])));
    }

    /* ---- MELCloud: passt der Geraetetyp zum Schreibbefehl? ---- */
    if ($cfg['hersteller'] === 'melcloud') {
        $typen = wp_ml_typen();
        $typ = (int) $cfg['geraetetyp'];
        if ($typ === 1) {
            $z[] = wp_pruefzeile(1, wp_t('TEST.F_GERAETETYP'),
                sprintf(wp_t('TEST.A_GERAETETYP_OK'), wp_e($typen[1])));
        } elseif ($typ === -1) {
            $z[] = wp_pruefzeile(-1, wp_t('TEST.F_GERAETETYP'), wp_t('TEST.A_GERAETETYP_UNBEKANNT'));
        } else {
            // Hier wird bewusst NICHT geschrieben. Ein Klimageraet verlangt
            // einen anderen Endpunkt mit anderen Feldern; ein Schreibbefehl
            // im falschen Format waere schlimmer als gar keiner.
            $z[] = wp_pruefzeile(0, wp_t('TEST.F_GERAETETYP'),
                sprintf(wp_t('TEST.A_GERAETETYP_FALSCH'),
                        wp_e(isset($typen[$typ]) ? $typen[$typ] : ('Typ ' . $typ))));
        }
    }

    /* ---- Liegen Daten vor, und wie alt sind sie? ---- */
    if ((int) $stand['zeit'] === 0) {
        $z[] = wp_pruefzeile(0, wp_t('TEST.F_DATEN'), wp_t('TEST.A_DATEN_KEINE'));
    } else {
        $alter = time() - (int) $stand['zeit'];
        $frisch = $alter <= (int) $cfg['takt'] * 3;
        $z[] = wp_pruefzeile($frisch ? 1 : 0, wp_t('TEST.F_DATEN'),
            sprintf($frisch ? wp_t('TEST.A_DATEN_OK') : wp_t('TEST.A_DATEN_ALT'),
                    wp_zeitspanne($alter), (int) $cfg['takt']));
    }

    /* ---- Feldzuordnung: welcher Kandidat hat gegriffen? ---- */
    $treffer = 0;
    $moeglich = 0;
    $ohne = array();
    foreach (wp_felder() as $name => $d) {
        if (!wp_kandidaten($name, $cfg)) { continue; }   // fuer diesen Hersteller nicht vorgesehen
        $moeglich++;
        if (isset($stand['werte'][$name])) { $treffer++; } else { $ohne[] = $name; }
    }
    if ($moeglich === 0) {
        $z[] = wp_pruefzeile(-1, wp_t('TEST.F_ZUORDNUNG'), wp_t('TEST.A_ZUORDNUNG_KEINE'));
    } elseif ($treffer === $moeglich) {
        $z[] = wp_pruefzeile(1, wp_t('TEST.F_ZUORDNUNG'),
            sprintf(wp_t('TEST.A_ZUORDNUNG_OK'), $treffer, $moeglich));
    } else {
        $z[] = wp_pruefzeile($treffer > 0 ? -1 : 0, wp_t('TEST.F_ZUORDNUNG'),
            sprintf(wp_t('TEST.A_ZUORDNUNG_TEIL'), $treffer, $moeglich, wp_e(implode(', ', $ohne))));
    }

    /* ---- SG Ready: echt oder nachgebildet? ---- */
    if (empty($cfg['sg_ein'])) {
        $z[] = wp_pruefzeile(-1, wp_t('TEST.F_SGREADY'), wp_t('TEST.A_SGREADY_AUS'));
    } elseif (empty($cfg['sg_angefordert'])) {
        $z[] = wp_pruefzeile(-1, wp_t('TEST.F_SGREADY'), wp_t('TEST.A_SGREADY_WARTET'));
    } elseif (wp_sg_echt($cfg['hersteller'])) {
        // Bei Nibe wird geprueft, ob es die beiden Register wirklich gibt -
        // nicht angenommen, dass die Nummern aus der Modbus-Liste auch in der
        // Punkteliste stehen.
        if (!is_array($stand['roh'])) {
            $z[] = wp_pruefzeile(0, wp_t('TEST.F_SGREADY'), wp_t('TEST.A_SGREADY_UNGEPRUEFT'));
        } elseif (wp_mu_sg_moeglich($stand['roh'])) {
            $r = wp_mu_sg_register();
            $z[] = wp_pruefzeile(1, wp_t('TEST.F_SGREADY'),
                sprintf(wp_t('TEST.A_SGREADY_ECHT'), $r['frei'], $r['zustand']));
        } else {
            $r = wp_mu_sg_register();
            $z[] = wp_pruefzeile(0, wp_t('TEST.F_SGREADY'),
                sprintf(wp_t('TEST.A_SGREADY_REGISTER_FEHLT'), $r['frei'], $r['zustand']));
        }
    } elseif ($cfg['hersteller'] === 'emsesp' && $cfg['ems_sg_art'] === 'klemmen') {
        /* Der Weg ueber die Klemmen steht und faellt mit zwei Dingen: den
         * beiden GPIO-Nummern und dem Zugriffsmerkmal. Fehlt eines davon,
         * wird nichts geschaltet - und zwar still, denn ein POST ohne
         * Merkmal beantwortet das Gateway mit 401, und das sieht in der
         * Zusammenfassung aus wie ein Netzproblem. */
        $p1 = (int) $cfg['ems_gpio1'];
        $p4 = (int) $cfg['ems_gpio4'];
        if ($p1 <= 0 || $p4 <= 0) {
            $z[] = wp_pruefzeile(0, wp_t('TEST.F_SGREADY'), wp_t('TEST.A_SGREADY_EMS_GPIO_FEHLT'));
        } elseif (trim((string) $g['ems_token']) === '') {
            $z[] = wp_pruefzeile(0, wp_t('TEST.F_SGREADY'), wp_t('TEST.A_SGREADY_EMS_TOKEN'));
        } else {
            $z[] = wp_pruefzeile(1, wp_t('TEST.F_SGREADY'),
                sprintf(wp_t('TEST.A_SGREADY_EMS_KLEMMEN'), $p1, $p4));
        }
    } elseif ($cfg['hersteller'] === 'emsesp') {
        $z[] = wp_pruefzeile(-1, wp_t('TEST.F_SGREADY'), wp_t('TEST.A_SGREADY_EMS_NACHBILDUNG'));
    } else {
        $z[] = wp_pruefzeile(-1, wp_t('TEST.F_SGREADY'),
            sprintf(wp_t('TEST.A_SGREADY_NACHGEBILDET'), wp_e($info['name'])));
    }

    /* ---- Grundsollwert fuer die Nachbildung ---- */
    if (!wp_sg_echt($cfg['hersteller']) && !empty($cfg['sg_ein'])) {
        $b = (float) $cfg['basis_soll'];
        $z[] = wp_pruefzeile($b > 0 ? 1 : 0, wp_t('TEST.F_BASIS'),
            $b > 0 ? sprintf(wp_t('TEST.A_BASIS_OK'), $b) : wp_t('TEST.A_BASIS_FEHLT'));
    }

    /* ---- Tagesbudget ---- */
    if (!empty($info['budget'])) {
        $rest = wp_budget_rest($cfg['hersteller']);
        $anteil = $rest / max(1, (int) $info['budget']);
        $z[] = wp_pruefzeile($anteil > 0.2 ? 1 : 0, wp_t('TEST.F_BUDGET'),
            sprintf(wp_t('TEST.A_BUDGET'), $rest, (int) $info['budget'],
                    (int) $cfg['takt'], (int) $cfg['budget_schreiben']));
    }

    /* ---- Takt gegen die Untergrenze ---- */
    $z[] = wp_pruefzeile((int) $cfg['takt'] >= (int) $info['mindesttakt'] ? 1 : 0, wp_t('TEST.F_TAKT'),
        sprintf(wp_t('TEST.A_TAKT'), (int) $cfg['takt'], (int) $info['mindesttakt']));

    /* ---- MQTT ---- */
    $m = wp_mqtt_zustand();
    if (empty($cfg['mqtt_ein'])) {
        $z[] = wp_pruefzeile(-1, wp_t('TEST.F_MQTT'), wp_t('TEST.A_MQTT_AUS'));
    } elseif (!$m['gefunden']) {
        $z[] = wp_pruefzeile(0, wp_t('TEST.F_MQTT'), wp_t('TEST.A_MQTT_KEIN_ABSCHNITT'));
    } elseif (!$m['udpport']) {
        $z[] = wp_pruefzeile(0, wp_t('TEST.F_MQTT'), wp_t('TEST.A_MQTT_KEIN_PORT'));
    } elseif (!$m['autostart']) {
        $z[] = wp_pruefzeile(0, wp_t('TEST.F_MQTT'), wp_t('TEST.A_MQTT_KEIN_AUTOSTART'));
    } else {
        $z[] = wp_pruefzeile(1, wp_t('TEST.F_MQTT'),
            sprintf(wp_t('TEST.A_MQTT_OK'), (int) $m['udpport'], wp_e($cfg['mqtt_topic'])));
    }

    /* ---- Vorlage wohlgeformt? ---- */
    // Gehoert hierher, nicht erst in die Pruefung vor dem Ausliefern: eine
    // kaputte Vorlage merkt der Anwender sonst erst in Loxone Config, und
    // dort sucht er den Fehler bei sich.
    $gut = true;
    foreach (array(wp_vorlage_ein(), wp_vorlage_aus()) as $v) {
        $alt = libxml_use_internal_errors(true);
        if (simplexml_load_string($v[1]) === false) { $gut = false; }
        libxml_clear_errors();
        libxml_use_internal_errors($alt);
    }
    $z[] = wp_pruefzeile($gut ? 1 : 0, wp_t('TEST.F_VORLAGE'),
        $gut ? wp_t('TEST.A_VORLAGE_OK') : wp_t('TEST.A_VORLAGE_KAPUTT'));

    /* ---- Verdichtertakt: wird er ueberhaupt gezaehlt, und wie fein? ----
     *
     * Der Zaehler kostet keinen zusaetzlichen Abruf, aber er sieht genauer aus,
     * als er ist: gezaehlt wird im Abruftakt, und ein Verdichterlauf, der
     * kuerzer ist als dieser Takt, faellt zwischen zwei Abrufe. TAKTE ist
     * deshalb eine Untergrenze. Das gehoert vor die Augen des Anwenders und
     * nicht in eine Fussnote - sonst hielte er die Zahl fuer eine Messung. */
    $kandidaten = wp_kandidaten('KOMPRESSOR', $cfg);
    if (!$kandidaten) {
        $z[] = wp_pruefzeile(-1, wp_t('TEST.F_TAKT_ZAEHLER'),
            sprintf(wp_t('TEST.A_TAKT_KEIN_FELD'), wp_e($info['name'])));
    } elseif (!isset($stand['werte']['KOMPRESSOR'])) {
        $z[] = wp_pruefzeile(0, wp_t('TEST.F_TAKT_ZAEHLER'), wp_t('TEST.A_TAKT_NOCH_NICHTS'));
    } else {
        $t = isset($stand['takt_zaehler']) && is_array($stand['takt_zaehler'])
            ? $stand['takt_zaehler'] : array();
        $z[] = wp_pruefzeile(1, wp_t('TEST.F_TAKT_ZAEHLER'),
            sprintf(wp_t('TEST.A_TAKT_OK'),
                (int) (isset($t['starts']) ? $t['starts'] : 0),
                (int) $cfg['takt'],
                (int) round((int) $cfg['takt'] / 60)));
    }

    /* ---- Reiterleiste, Bereiche und Positivliste stimmen ueberein? ----
     *
     * Diese Zeile ist der Ersatz fuer eine Pruefung, die es nicht mehr gibt:
     * Leiste und Bereiche werden in index.php aus EINEM Feld erzeugt, und
     * hausstandard_pruefen.py findet die Reiter deshalb nicht mehr - es meldet
     * "trifft nicht zu" statt "geprueft". Ein Kreuz an der ersten Stelle einer
     * Pruefkette, das nichts bedeutet, ist schlimmer als keine Pruefung.
     *
     * Die Aufloesung nach REGELN_1, Abschnitt 11, lautet: ausschreiben ODER die
     * Uebereinstimmung im Reiter Test pruefen lassen. Hier steht das Zweite. */
    $quelle = @file_get_contents(__DIR__ . '/index.php');
    if ($quelle === false) {
        $z[] = wp_pruefzeile(0, wp_t('TEST.F_REITER'), wp_t('TEST.A_REITER_UNLESBAR'));
    } else {
        $liste = array();
        if (preg_match('/\$wp_reiter_ids\s*=\s*array\(([^)]*)\)/', $quelle, $m)) {
            preg_match_all("/'([a-z0-9]+)'/", $m[1], $mm);
            $liste = $mm[1];
        }
        preg_match_all('/id="tab-([a-z0-9]+)"/', $quelle, $mb);
        $bereiche = array_values(array_unique($mb[1]));

        /* Die Beschriftung wird aus der ECHTEN Zuordnung in index.php gelesen,
         * nicht aus strtoupper($r) geraten: der Reiter heisst 'settings', sein
         * Schluessel aber REITER.EINSTELLUNGEN. Der erste Entwurf dieser
         * Pruefung hat genau das geraten und daraufhin dauerhaft ein Kreuz
         * gezeigt - ein Kreuz an der ersten Stelle einer Pruefkette, das nichts
         * bedeutet, ist schlimmer als keine Pruefung. Gemessen am 16.08.2026. */
        $schluessel = array();
        if (preg_match('/\$wp_beschriftung\s*=\s*array\((.*?)\);/s', $quelle, $mz)) {
            preg_match_all("/'([a-z0-9]+)'\s*=>\s*'(REITER\.[A-Z0-9_]+)'/", $mz[1], $ms,
                           PREG_SET_ORDER);
            foreach ($ms as $paar) { $schluessel[$paar[1]] = $paar[2]; }
        }
        $fehlend = array();
        foreach ($liste as $r) {
            if (!isset($schluessel[$r]) || wp_t($schluessel[$r]) === $schluessel[$r]) {
                $fehlend[] = $r;
            }
        }
        sort($liste); sort($bereiche);
        $gleich = $liste && $liste === $bereiche && !$fehlend;
        $z[] = wp_pruefzeile($gleich ? 1 : 0, wp_t('TEST.F_REITER'),
            $gleich ? sprintf(wp_t('TEST.A_REITER_OK'), count($liste))
                    : sprintf(wp_t('TEST.A_REITER_ABWEICHUNG'),
                        wp_e(implode(', ', $liste)), wp_e(implode(', ', $bereiche)),
                        wp_e($fehlend ? implode(', ', $fehlend) : '-')));
    }

    /* ---- Stoerungen in Folge ---- */
    $folge = (int) (isset($stand['fehler_folge']) ? $stand['fehler_folge'] : 0);
    if ($folge === 0) {
        $z[] = wp_pruefzeile((int) $stand['zeit'] > 0 ? 1 : -1, wp_t('TEST.F_STOERUNG'),
            (int) $stand['zeit'] > 0 ? wp_t('TEST.A_STOERUNG_KEINE')
                                     : wp_t('TEST.A_STOERUNG_NOCH_NICHTS'));
    } else {
        $z[] = wp_pruefzeile(0, wp_t('TEST.F_STOERUNG'),
            sprintf(wp_t('TEST.A_STOERUNG'), $folge,
                wp_e((string) (isset($stand['fehler_letzt']) ? $stand['fehler_letzt'] : '?')),
                (int) $folge * max(1, (int) $cfg['takt']) / 60));
    }

    /* ---- Token ---- */
    $gut = preg_match('/^[A-Za-z0-9]{24,}$/', (string) $cfg['aktionstoken']) ? 1 : 0;
    $z[] = wp_pruefzeile($gut, wp_t('TEST.F_TOKEN'),
        $gut ? wp_t('TEST.A_TOKEN_OK') : wp_t('TEST.A_TOKEN_FEHLT'));

    /* ---- Selbsttest des Endpunkts ----
     *
     * Die Adresse gehoert sichtbar hierher, nicht nur in die Hilfe: sie ist der
     * einzige Weg, die im Miniserver eingetragene Adresse zu pruefen, OHNE die
     * Waermepumpe zu schalten. */
    if ($gut) {
        $z[] = wp_pruefzeile(1, wp_t('TEST.F_SELFTEST'),
            sprintf(wp_t('TEST.A_SELFTEST'), wp_e(wp_endpunkt('status') . '&selftest=1')));
    }

    return $z;
}

function wp_zeitspanne($s)
{
    $s = (int) $s;
    if ($s < 90) { return $s . ' s'; }
    if ($s < 5400) { return round($s / 60) . ' min'; }
    return round($s / 3600, 1) . ' h';
}

/**
 * Die Knopf-Aktionen des Reiters Test.
 * Rueckgabe: array(ok, Meldung)
 */
function wp_test_aktion($was, $zusatz = '')
{
    $cfg = wp_config();

    switch ($was) {

        case 'geraete':
            $l = wp_geraete_suchen($cfg['hersteller']);
            if (!$l) { return array(0, wp_t('TEST.M_GERAETE_KEINE')); }
            $stand = wp_stand();
            $stand['geraete'] = $l;
            wp_stand_write($stand);
            $t = array();
            foreach ($l as $d) {
                $t[] = wp_e($d['name']) . ' &mdash; <span class="sm-mono">' . wp_e($d['id']) . '</span>'
                     . ($d['system'] !== '' ? ' (' . wp_e($d['system']) . ')' : '');
            }
            return array(1, sprintf(wp_t('TEST.M_GERAETE_OK'), count($l)) . '<br>' . implode('<br>', $t));

        case 'abruf':
            list($ok, $grund) = wp_abrufen(true);
            if (!$ok) { return array(0, sprintf(wp_t('TEST.M_ABRUF_FEHL'), wp_e($grund))); }
            $stand = wp_stand();
            return array(1, sprintf(wp_t('TEST.M_ABRUF_OK'), count($stand['werte'])));

        case 'zeile':
            return array(1, '<span class="sm-mono">' . wp_e(wp_zeile(wp_stand(), $cfg)) . '</span>');

        case 'wege':
            // Zeigt je Feld, welcher Kandidatenpfad gegriffen hat. Das ist die
            // wichtigste Auskunft dieses Plugins, solange die genaue Gestalt
            // der Antwort vom Geraetemodell abhaengt.
            $stand = wp_stand();
            $t = array('<table class="sm-tbl"><tr><th>' . wp_e(wp_t('TEST.T_FELD')) . '</th><th>'
                     . wp_e(wp_t('TEST.T_WEG')) . '</th><th>' . wp_e(wp_t('TEST.T_WERT')) . '</th></tr>');
            foreach (wp_felder() as $name => $d) {
                $kand = wp_kandidaten($name, $cfg);
                if (!$kand) { continue; }
                $weg = isset($stand['wege'][$name]) ? $stand['wege'][$name] : '';
                $wert = isset($stand['werte'][$name]) ? $stand['werte'][$name] : null;
                $t[] = '<tr><td><span class="sm-mono">' . wp_e($name) . '</span></td>'
                     . '<td>' . ($weg !== '' ? '<span class="sm-mono">' . wp_e($weg) . '</span>'
                                             : '<span class="sm-aus">' . wp_e(wp_t('TEST.KEIN_WEG')) . '</span>') . '</td>'
                     . '<td>' . ($wert === null ? '&mdash;' : wp_e($wert)) . '</td></tr>';
            }
            $t[] = '</table>';
            return array(1, implode('', $t));

        case 'roh':
            $stand = wp_stand();
            if (!is_array($stand['roh'])) { return array(0, wp_t('TEST.M_ROH_KEINE')); }
            $js = json_encode($stand['roh'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($js) > 20000) { $js = substr($js, 0, 20000) . "\n... (gekuerzt)"; }
            return array(1, '<div class="sm-pre"><pre>' . wp_e($js) . '</pre></div>');

        case 'befehle':
            /* Fragt das Gateway, WAS es schreiben kann - statt es zu raten.
             *
             * Das ist der Vorteil dieser Anbindung gegenueber den drei
             * Wolken: die Liste kommt vom Geraet selbst und stimmt deshalb
             * auch bei einem Modell, das dieses Plugin nie gesehen hat. Wer
             * einen anderen Hebel fuer die SG-Ready-Nachbildung braucht,
             * findet ihn hier und nicht in einem Forum. */
            if ($cfg['hersteller'] !== 'emsesp') {
                return array(0, wp_t('TEST.M_BEFEHLE_NUR_EMS'));
            }
            $t = array();
            $summe = 0;
            foreach (array('boiler', 'thermostat') as $ger) {
                list($liste, $grund) = wp_ems_befehle($cfg, $ger);
                if (!$liste) {
                    $t[] = '<b>' . wp_e($ger) . '</b>: ' . wp_e(wp_meld($grund));
                    continue;
                }
                $summe += count($liste);
                $t[] = '<b>' . wp_e($ger) . '</b> (' . count($liste) . '):<br><span class="sm-mono">'
                     . wp_e(implode(', ', $liste)) . '</span>';
            }
            if ($summe === 0) { return array(0, implode('<br>', $t)); }
            return array(1, sprintf(wp_t('TEST.M_BEFEHLE_OK'), $summe) . '<br>' . implode('<br>', $t));

        case 'sg1':
        case 'sg2':
        case 'sg3':
        case 'sg4':
            $stufe = (int) substr($was, 2);
            $cfg['sg_stufe'] = $stufe;
            $cfg['sg_angefordert'] = 1;   // ein Knopfdruck ist eine Anforderung
            wp_config_write($cfg);
            list($ok, $grund, $getan) = wp_sg_durchsetzen($cfg);
            if (!$ok) {
                return array(0, sprintf(wp_t('TEST.M_SG_FEHL'), $stufe, wp_e($grund)));
            }
            return array(1, sprintf(wp_t('TEST.M_SG_OK'), $stufe,
                wp_e(wp_t('SG.STUFE' . $stufe)), wp_e($getan)));

        case 'ww_ein':
        case 'ww_aus':
            if (!wp_ww_moeglich($cfg['hersteller'])) {
                $wpi = wp_hersteller_info($cfg['hersteller']);
                return array(0, sprintf(wp_t('TEST.M_WW_NICHT'), wp_e($wpi ? $wpi['name'] : '?')));
            }
            $ein = ($was === 'ww_ein');
            list($ok, $grund, $wastext) = wp_ww_boost($cfg, $ein);
            if (!$ok) {
                return array(0, sprintf(wp_t('TEST.M_WW_FEHL'),
                    wp_t($ein ? 'TEST.WW_EIN' : 'TEST.WW_AUS'), wp_e(wp_meld($grund))));
            }
            wp_log('Warmwasser-Zwangsladung ueber den Reiter Test ' . ($ein ? 'ein' : 'aus'));
            return array(1, sprintf(wp_t('TEST.M_WW_OK'),
                wp_t($ein ? 'TEST.WW_EIN' : 'TEST.WW_AUS'), wp_e($wastext)));

        case 'probe1':
        case 'probe2':
        case 'probe3':
        case 'probe4':
            /* Vorschau: was WUERDE dieser Zustand an dieser Anlage ausloesen?
             *
             * Es wird derselbe Weg gegangen wie im Ernstfall - wp_sg_anwenden()
             * baut die Beschreibung wie immer -, nur geben die acht
             * Schreibstellen "PROBE" zurueck, statt zu senden. Die Konfiguration
             * wird NICHT angefasst: sg_stufe und sg_angefordert bleiben, wie sie
             * sind, sonst waere die Vorschau selbst eine Anforderung.
             *
             * try/finally, damit der Schalter auch dann zurueckfaellt, wenn in
             * der Tiefe eine Ausnahme fliegt. Bliebe er stehen, wuerde der
             * naechste echte Schaltbefehl im selben Prozess still nichts tun -
             * ein Befehl, der genau dann nichts tut, wenn er gebraucht wird. */
            $stufe = (int) substr($was, 5);
            $ok = 0; $grund = 'AUSNAHME'; $getan = '';
            wp_probe(true);
            try {
                list($ok, $grund, $getan) = wp_sg_anwenden($cfg, $stufe, wp_stand());
            } catch (Exception $e) {
                $grund = $e->getMessage();
            } finally {
                /* IMMER zuruecksetzen. Bliebe der Schalter stehen, taete der
                 * naechste echte Schaltbefehl im selben Prozess still nichts -
                 * ein Befehl, der genau dann nichts tut, wenn er gebraucht
                 * wird, ist schlimmer als keiner. */
                wp_probe(false);
            }
            if (!$ok) {
                return array(0, sprintf(wp_t('TEST.M_PROBE_FEHL'), $stufe, wp_e(wp_meld($grund))));
            }
            $wpi = wp_hersteller_info($cfg['hersteller']);
            return array(1, sprintf(wp_t('TEST.M_PROBE_OK'), $stufe,
                wp_e(wp_t('SG.STUFE' . $stufe)), wp_e($wpi ? $wpi['name'] : '?'),
                wp_e($getan !== '' ? $getan : wp_t('TEST.PROBE_NICHTS')),
                wp_t(wp_sg_echt($cfg['hersteller']) ? 'TEST.PROBE_ECHT' : 'TEST.PROBE_NACHBILDUNG')));

        case 'token':
            $neu = wp_config();
            $neu['aktionstoken'] = wp_token();
            if (wp_config_write($neu)) {
                wp_log('Zugriffstoken neu erzeugt');
                return array(1, wp_t('TEST.M_TOKEN_OK'));
            }
            return array(0, wp_t('TEST.M_TOKEN_FEHL'));
    }
    return array(0, wp_t('TEST.M_UNBEKANNT'));
}
