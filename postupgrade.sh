#!/bin/bash
# Waermepumpe Cloud - postupgrade
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# Spielt zurueck, was preupgrade.sh gesichert hat - und zwar nur, wenn die
# Datei im Zielordner keinen INHALT traegt (siehe wp_inhalt unten). Eine
# vorhandene, eingerichtete Datei wird nicht ueberschrieben: sie ist die
# aktuellere.
#
# Zum Sicherungsort und zu den Argumenten siehe preupgrade.sh.

ARGV1=$1
ARGV3=$3
ARGV5=$5
ARGV6=$6

PFOLDER="${ARGV3:-waermepumpe}"
# Wurzel wie in preupgrade.sh (dort die Begruendung). Bis 0.9.22 stand hier
# BASE="${5:-$LBHOMEDIR}" ohne Pruefung; ohne beides legte das Skript
# config/plugins/<ordner> ab der Laufwerkswurzel an und loeschte dort
# config/plugins/<ordner>.upgrade mit rm -rf (in WSL gemessen,
# Pruefung-WaermepumpeCloud-0.9.23, Fall K2).
wp_wurzel_suchen() {
    wp_v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd -P)
    wp_i=0
    while [ -n "$wp_v" ] && [ "$wp_v" != "/" ] && [ "$wp_i" -lt 8 ]; do
        if [ -d "$wp_v/config/plugins" ] && [ -d "$wp_v/data/plugins" ] \
           && [ -f "$wp_v/config/system/general.json" ]; then
            echo "$wp_v"
            return 0
        fi
        wp_v=$(dirname "$wp_v")
        wp_i=$((wp_i + 1))
    done
    return 1
}
BASE="${ARGV5:-}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
       && [ -d "$LBHOMEDIR/data/plugins" ]; then
        BASE="$LBHOMEDIR"
    else
        BASE=$(wp_wurzel_suchen) || BASE=""
    fi
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden: weder als fuenftes"
    echo "<WARNING> Argument noch in \$LBHOMEDIR, und oberhalb dieses Skripts traegt kein"
    echo "<WARNING> Verzeichnis config/plugins, data/plugins und config/system/general.json."
    echo "<WARNING> Es wurde nichts zurueckgespielt und nichts entfernt."
    exit 1
fi
# Pluginordner anhaengen - siehe preupgrade.sh.
CFGDIR="${LBPCONFIG:-$BASE/config/plugins}/$PFOLDER"
# Der Sicherungsort wird aus DEMSELBEN Argument gerechnet wie in
# preupgrade.sh - siehe die ausfuehrliche Begruendung dort. Ein Merker
# .upgrade_pfad im Konfigurationsordner stand hier bis 02.09.2026 an erster
# Stelle; purge_installation entfernt dieses Verzeichnis, bevor dieses Skript
# laeuft, der Zweig war also tot.
#
# Der Rueckfallweg liegt seit 0.9.17 NEBEN dem Pluginordner. Er lag bis 0.9.16
# darin - und damit in dem Verzeichnis, das purge_installation abraeumt, bevor
# dieses Skript laeuft. Die ausfuehrliche Begruendung samt Fundstellen steht in
# preupgrade.sh; beide Skripte rechnen den Pfad aus DENSELBEN Angaben.
RUECKFALL="${LBPCONFIG:-$BASE/config/plugins}/$PFOLDER.upgrade"
if [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
    SICHERUNG="$ARGV6/waermepumpe_upgrade"
else
    SICHERUNG="$RUECKFALL"
fi

# ---------------------------------------------------------------------------
# Traegt eine Datei INHALT?
#
# BERICHTIGT in 0.9.23. Bis 0.9.22 wurde zurueckgespielt, wenn das Ziel fehlte,
# leer oder genau "{}" war - eine Frage nach der Form. Zwei Lagen fielen
# durch, beide in WSL gemessen (Pruefung-WaermepumpeCloud-0.9.23):
#   Z11  eine abgeschnittene waermepumpe.json ist weder leer noch "{}"; sie
#        blieb stehen, der Hersteller war danach unlesbar, die heile
#        Sicherung wurde verworfen;
#   Z4   laeuft der Minutentakt in der Luecke zwischen purge_installation und
#        postinstall.sh und fehlt die Zweitschrift neben dem Ordner, legt die
#        Bibliothek eine Konfiguration mit NEUEM Aktionstoken und ohne
#        Hersteller an. Auch die ist nicht "{}" - Hersteller und Token der
#        Anlage waren nach dem Update fort, jede Adresse im Miniserver auf 403.
#
# Inhalt heisst jetzt (gelesen von PHP, nicht per Muster):
#   waermepumpe.json  lesbares JSON-Objekt mit eingetragenem Hersteller
#   geheim.json       lesbares JSON-Objekt mit mindestens einem nicht leeren
#                     Zugangsdatum
# und eine Sicherung zaehlt fuer waermepumpe.json schon mit lesbarem
# Aktionstoken (ohne Hersteller ist das Token der Wert, an dem in Loxone die
# Adressen haengen).
#
# Warum nicht einfach immer zurueckspielen: laeuft der Takt in der Luecke,
# kann er ein Erneuerungsmerkmal der Herstellercloud einloesen und das NEUE
# nach geheim.json schreiben (Fall Z6). Die Sicherung traegt dann das alte,
# schon verbrauchte - sie darf das neue nicht ueberschreiben.
# ---------------------------------------------------------------------------
wp_inhalt() {   # $1 Datei, $2 config|config_sicherung|geheim -> 0 ja, 1 nein
    [ -f "$1" ] && [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 1
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d) || !$d) { exit(1); }
        $h = isset($d["hersteller"]) && is_string($d["hersteller"]) && $d["hersteller"] !== "";
        $t = isset($d["aktionstoken"]) && is_string($d["aktionstoken"])
             && preg_match("/^[A-Za-z0-9]{24,}$/", $d["aktionstoken"]);
        if ($argv[2] === "config") { exit($h ? 0 : 1); }
        if ($argv[2] === "config_sicherung") { exit(($h || $t) ? 0 : 1); }
        foreach (array("client_id", "client_secret", "benutzer", "passwort", "refresh_token",
                       "redirect_uri", "va_refresh", "ems_token") as $k) {
            if (isset($d[$k]) && is_string($d[$k]) && trim($d[$k]) !== "") { exit(0); }
        }
        exit(1);' "$1" "$2" >/dev/null 2>&1
}

mkdir -p "$CFGDIR" 2>/dev/null
WP_ZEIT=$(date +%Y%m%d%H%M%S 2>/dev/null)
WP_OFFEN=""          # Dateien, deren Rueckholung trotz Inhalt der Sicherung scheiterte
for f in waermepumpe.json geheim.json; do
    ZIEL="$CFGDIR/$f"
    QUELLE="$SICHERUNG/$f"
    [ -f "$QUELLE" ] || continue
    if [ "$f" = waermepumpe.json ]; then ART_Z=config; ART_Q=config_sicherung; else ART_Z=geheim; ART_Q=geheim; fi
    if wp_inhalt "$ZIEL" "$ART_Z"; then
        echo "<INFO> $f ist eingerichtet und bleibt, wie es ist (nicht aus der Sicherung ueberschrieben)."
        continue
    fi
    if ! wp_inhalt "$QUELLE" "$ART_Q"; then
        continue    # die Sicherung traegt selbst nichts - nichts zurueckzuholen
    fi
    # Der verdraengte Stand bleibt lesbar liegen, wenn er mehr als "{}" war.
    if [ -f "$ZIEL" ] && [ -s "$ZIEL" ] && [ "$(tr -d ' \r\n\t' < "$ZIEL" 2>/dev/null)" != "{}" ]; then
        if cp -p "$ZIEL" "$ZIEL.verdraengt.$WP_ZEIT" 2>/dev/null; then
            chmod 0600 "$ZIEL.verdraengt.$WP_ZEIT" 2>/dev/null
            echo "<INFO> Der bisherige Inhalt von $f trug keine Einstellungen; er liegt als $f.verdraengt.$WP_ZEIT daneben."
        fi
    fi
    if [ ! -d "$ZIEL" ] && cp -p "$QUELLE" "$ZIEL" 2>/dev/null && wp_inhalt "$ZIEL" "$ART_Q"; then
        echo "<OK> $f aus der Update-Sicherung wiederhergestellt."
    else
        echo "<WARNING> $f liess sich nicht aus der Update-Sicherung zurueckholen ($ZIEL)."
        WP_OFFEN="$WP_OFFEN $f"
    fi
done

# Rechte wieder festziehen. Ein Update, das die Zugangsdatendatei auf 0644
# zuruecksetzt, faellt sonst niemandem auf.
chmod 0600 "$CFGDIR/waermepumpe.json" 2>/dev/null
chmod 0600 "$CFGDIR/geheim.json" 2>/dev/null

# Rechte BEIDER Zweitschriften neben dem Ordner nachziehen (NEU 0.9.20).
#
# Am Geraet gemessen (17.09.2026): waermepumpe.backup.waermepumpe.json lag mit
# -rw-rw-r-- da, Stand 17.08.2026, mit dem Aktionstoken darin. Kein
# Hakenskript hatte sie je angefasst, und die PHP-Seite setzt 0600 nur, wenn
# sie die Datei neu schreibt. Gemeldet wird, was nachgelesen wurde, nicht was
# beabsichtigt war (Regeln/06).
for ZW in "$(dirname "$CFGDIR")/$PFOLDER.backup.waermepumpe.json" \
          "$(dirname "$CFGDIR")/$PFOLDER.backup.geheim.json"; do
    [ -f "$ZW" ] || continue
    VORHER=$(stat -c %a "$ZW" 2>/dev/null)
    chmod 0600 "$ZW" 2>/dev/null
    NACHHER=$(stat -c %a "$ZW" 2>/dev/null)
    if [ "$VORHER" != "$NACHHER" ]; then
        echo "<INFO> Rechte von $(basename "$ZW"): $VORHER -> $NACHHER."
    elif [ "$NACHHER" != "600" ]; then
        echo "<WARNING> Rechte von $(basename "$ZW") stehen auf $NACHHER und liessen sich nicht aendern."
    fi
done

# Hier stand "rm -f $MERKER". Mit dem Merker ist auch das entfallen - die
# Variable gab es danach nicht mehr, und "rm -f ''" ist kein Aufraeumen.
# Der Arbeitsordner des Installers wird von LoxBerry selbst aufgeraeumt.
# Nur der Rueckfallweg gehoert uns - und dort liegt geheim.json.
#
# ACHTUNG BEIM NACHZIEHEN: bis 0.9.16 stand hier ein Muster auf "$CFGDIR"/*.
# Mit dem Umzug des Rueckfallwegs NEBEN den Ordner trifft dieses Muster nicht
# mehr - die Zugangsdaten waeren liegen geblieben. Aufgeraeumt wird deshalb
# genau der berechnete Pfad, und zwar nur, wenn er wirklich der Rueckfallweg
# ist: den Arbeitsordner des Installers fasst dieses Skript nicht an.
#
# Und nur, wenn die Rueckholung nach Inhalt gelang (BERICHTIGT in 0.9.23).
# Bis 0.9.22 fiel der Rueckfallweg ohne Bedingung - scheiterte das
# Zurueckkopieren, gab es danach weder Konfiguration noch Sicherung (in WSL
# gemessen, Pruefung-WaermepumpeCloud-0.9.23, Fall Z7). Bauart MarstekVenus
# 1.1.15. Er traegt Zugangsdaten; uninstall raeumt ihn weg.
if [ -n "$WP_OFFEN" ]; then
    echo "<WARNING> Die Update-Sicherung bleibt liegen - nicht angekommen ist:$WP_OFFEN"
    echo "<WARNING>   $SICHERUNG"
    if [ "$SICHERUNG" != "$RUECKFALL" ]; then
        echo "<WARNING> Sie liegt im Arbeitsordner des Installers, den LoxBerry gleich aufraeumt -"
        echo "<WARNING> bitte vorher sichern oder die Einstellungen in der Oberflaeche pruefen."
    fi
elif [ "$SICHERUNG" = "$RUECKFALL" ]; then
    rm -rf "$RUECKFALL"
fi
# Und die Altlast: eine Sicherung, die eine Fassung bis 0.9.16 IN den
# Konfigordner gelegt hat. Sie ueberlebt zwar kein Upgrade, aber ein
# abgebrochener Lauf kann sie hinterlassen haben.
rm -rf "$CFGDIR/.upgrade" 2>/dev/null

# Die Schlusszeile sagt, was nachgelesen wurde (BERICHTIGT in 0.9.23; bis
# 0.9.22 stand hier unbedingt "<OK> Update abgeschlossen.", gemessen Fall Z3).
if wp_inhalt "$CFGDIR/waermepumpe.json" config; then
    echo "<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen."
elif [ -n "$WP_OFFEN" ]; then
    echo "<WARNING> Aktualisierung abgeschlossen, die Einstellungen sind aber NICHT zurueckgekommen."
else
    echo "<INFO> Aktualisierung abgeschlossen. Es ist noch kein Hersteller eingetragen -"
    echo "<INFO> weiter in der Oberflaeche: Reiter Einstellungen, Hersteller waehlen."
fi
exit 0
