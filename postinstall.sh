#!/bin/bash
# Laeuft als Benutzer loxberry, NACH dem Kopieren der Dateien.
#
# Legt Konfigurations- und Datenverzeichnis an und setzt die Rechte. Die
# Zugangsdaten liegen in einer EIGENEN Datei mit 0600 - nicht in der
# Konfiguration, die die Oberflaeche anzeigt.

# Aufruf: command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# ACHTUNG, hier lag bis 0.9.10 ein schwerer Fehler: $LBPCONFIG und $LBPDATA
# sind die Umgebungsvariablen des INSTALLERS und zeigen auf
#     <home>/config/plugins   bzw.   <home>/data/plugins
# also OHNE den Pluginordner. Die gleichnamigen Perl-Variablen ($lbpdatadir)
# enthalten ihn - wer beide verwechselt, landet eine Ebene daneben.
#
# Bis 0.9.10 stand hier CFGDIR="$LBPCONFIG". Damit landeten waermepumpe.json
# und geheim.json flach in <home>/config/plugins, neben allen Pluginordnern,
# und chmod 0755 traf das gemeinsame Verzeichnis. Derselbe Fehler hat im
# uninstall den Datenordner ALLER Plugins geloescht.
#
# Deshalb: Umgebung nehmen, wenn sie da ist - aber den Pluginordner selbst
# anhaengen. Genauso machen es LoxoneIcons, Octopus und neun weitere Linien.
ARGV3=$3
ARGV5=$5
ARGV6=$6
PFOLDER="${ARGV3:-waermepumpe}"
# Wurzel wie in preupgrade.sh (dort die Begruendung): fuenftes Argument,
# sonst $LBHOMEDIR mit config/plugins und data/plugins darunter, sonst die
# Suche mit config/system/general.json. Bis 0.9.22 genuegte ein beliebiges
# vorhandenes Verzeichnis in $LBHOMEDIR - dort wurde config/plugins/<ordner>
# angelegt (in WSL gemessen, Pruefung-WaermepumpeCloud-0.9.23, Fall K4).
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
    echo "<FAIL> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden: weder als fuenftes"
    echo "<FAIL> Argument noch in \$LBHOMEDIR, und oberhalb dieses Skripts traegt kein"
    echo "<FAIL> Verzeichnis config/plugins, data/plugins und config/system/general.json."
    echo "<FAIL> Es wurde nichts angelegt."
    exit 1
fi
CFGDIR="${LBPCONFIG:-$BASE/config/plugins}/$PFOLDER"
DATADIR="${LBPDATA:-$BASE/data/plugins}/$PFOLDER"

mkdir -p "$CFGDIR" "$DATADIR"
chmod 0755 "$CFGDIR" "$DATADIR"

# Was dieses Skript wirklich getan hat, wird mitgezaehlt - die Schlusszeilen
# nennen nur das (Regeln/06: ein Hakenskript meldet, was es nachgelesen hat).
# Bis 0.9.19 stand hier bei JEDEM Upgrade "Konfiguration angelegt" und der
# Rat, einen Hersteller zu waehlen - gemessen im Installationsprotokoll vom
# 08.09.2026, auf einer Anlage mit eingetragenem Hersteller.
ANGELEGT=""

# Konfiguration: sichtbare Einstellungen
if [ ! -f "$CFGDIR/waermepumpe.json" ]; then
    echo '{}' > "$CFGDIR/waermepumpe.json"
    ANGELEGT="$ANGELEGT waermepumpe.json"
fi
chmod 0600 "$CFGDIR/waermepumpe.json"

# Zugangsdaten: eigene Datei, nur fuer den Eigentuemer lesbar.
if [ ! -f "$CFGDIR/geheim.json" ]; then
    echo '{}' > "$CFGDIR/geheim.json"
    ANGELEGT="$ANGELEGT geheim.json"
fi
chmod 0600 "$CFGDIR/geheim.json"

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

# Altlast aus Fassungen bis 0.9.10: dort legte dieses Skript waermepumpe.json
# FLACH in config/plugins ab, neben alle Pluginordner (siehe Kopf). Am Geraet
# gemessen 17.09.2026: <home>/config/plugins/waermepumpe.json, 3 Byte "{}",
# Stand 16.08.2026. Entfernt wird sie nur, wenn sie genau diesen leeren Inhalt
# traegt - eine Datei mit Inhalt wird genannt und bleibt liegen.
ALT="$(dirname "$CFGDIR")/waermepumpe.json"
if [ -f "$ALT" ]; then
    if [ "$(tr -d ' \r\n\t' < "$ALT" 2>/dev/null)" = "{}" ]; then
        rm -f "$ALT" && [ ! -e "$ALT" ] \
            && echo "<INFO> Leere Altlast $ALT aus einer Fassung bis 0.9.10 entfernt."
    else
        echo "<WARNING> $ALT stammt aus einer Fassung bis 0.9.10 und ist nicht leer - bitte von Hand ansehen."
    fi
fi

if [ -n "$ANGELEGT" ]; then
    echo "<OK> Angelegt unter $CFGDIR:$ANGELEGT (0600)."
else
    echo "<OK> Konfiguration unter $CFGDIR vorhanden, Rechte 0600 gesetzt."
fi

# Die Erstanleitung nur, wenn wirklich noch nichts eingerichtet ist.
#
# BERICHTIGT in 0.9.23. Bis 0.9.22 stand sie hier, sobald waermepumpe.json
# genau "{}" war - und das ist sie bei JEDEM Upgrade: postinstall.sh laeuft
# vor postupgrade.sh, purge_installation hat den Konfigordner eben geleert,
# und die Zeilen weiter oben haben "{}" angelegt. Das Protokoll riet also nach
# jedem Update, den Hersteller neu zu waehlen, obwohl postupgrade.sh die
# Einstellungen gleich danach zurueckholte (in WSL gemessen,
# Pruefung-WaermepumpeCloud-0.9.23, Fall Z2).
#
# "Eingerichtet" heisst: ein lesbares JSON-Objekt mit eingetragenem Hersteller
# - ohne ihn tut der Abrufdienst nichts (bin/wp_abruf.php). Gelesen von PHP,
# nicht per Muster: eine abgeschnittene Datei enthaelt den Hersteller auch.
# Die Update-Sicherung liegt dort, wo preupgrade.sh sie abgelegt hat
# (sechstes Argument, sonst der Rueckfallweg neben dem Konfigordner).
wp_hersteller_da() {
    [ -f "$1" ] && [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 1
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        exit(is_array($d) && isset($d["hersteller"]) && is_string($d["hersteller"])
             && $d["hersteller"] !== "" ? 0 : 1);' "$1" >/dev/null 2>&1
}
WP_UPDSICH="${LBPCONFIG:-$BASE/config/plugins}/$PFOLDER.upgrade"
if [ -n "$ARGV6" ] && [ -d "$ARGV6/waermepumpe_upgrade" ]; then
    WP_UPDSICH="$ARGV6/waermepumpe_upgrade"
fi
if wp_hersteller_da "$CFGDIR/waermepumpe.json"; then
    echo "<OK> Die Konfiguration ist eingerichtet (Hersteller eingetragen)."
elif wp_hersteller_da "$WP_UPDSICH/waermepumpe.json"; then
    echo "<OK> Installation abgeschlossen. Die Einstellungen holt postupgrade.sh gleich aus der Update-Sicherung zurueck."
else
    echo "<INFO> Weiter in der Oberflaeche: Reiter Einstellungen, Hersteller waehlen."
fi
exit 0
