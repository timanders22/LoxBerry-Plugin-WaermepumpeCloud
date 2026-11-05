#!/bin/bash
# Waermepumpe Cloud - preupgrade
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# ---------------------------------------------------------------------------
# WAS HIER BIS 0.9.0 SCHIEFGING
#
# Gesichert wurde nur geheim.json, und zwar als geheim.json.vorher NEBEN das
# Original in denselben Ordner. Zurueckgespielt wurde die Kopie nie:
# postupgrade.sh loeschte sie bloss wieder. Eine Sicherung, die nur angelegt
# und dann weggeworfen wird, ist keine - sie ist eine zweite Ausfertigung der
# Zugangsdaten, die eine Weile herumliegt.
#
# ZU DEN ARGUMENTEN, weil hier oft das Falsche angenommen wird: der Installer
# ruft dieses Skript so auf (sbin/plugininstall.pl)
#   cd "$tempfolder" && "$script" "$tempfile" "$pname" "$pfolder" \
#                       "$pversion" "$lbhomedir" "$tempfolder"
# $1 ist $tempfile - eine ZUFALLSKENNUNG aus zehn Zeichen (&generate(10)),
# KEIN Pfad. Der absolute Arbeitsordner kommt als SECHSTES Argument. Er liegt
# unter data/system/tmp und wird vom Installer selbst aufgeraeumt, und zwar
# erst NACH postupgrade.
#
# BRAUCHT ES DIE SICHERUNG UEBERHAUPT? Ja - und hier stand bis 0.9.16 das
# Gegenteil ("LoxBerry loescht config/plugins/<ordner> beim Upgrade nicht").
# Der Satz war falsch, und er widersprach dem Absatz weiter unten in derselben
# Datei. Nachgemessen an sbin/plugininstall.pl (Commit 666baf1de87a):
#
#   :858   if ($isupgrade) {
#   :886       &purge_installation;        <- IM Upgrade-Zweig, nicht nur beim
#                                             Deinstallieren
#   :1629/:1631  darin rm -rf auf config/plugins/<ordner>/ UND
#                data/plugins/<ordner>/, im Rumpf, ohne Pruefung auf "all"
#   :916/:920    ERST DANACH wird config/plugins/<ordner>/ neu angelegt
#
# Beim Upgrade ueberlebt in config/ und data/ also NICHTS. Die Sicherung ist
# damit kein zweiter Boden, sondern der einzige - und sie muss an einen Ort,
# den purge_installation nicht trifft.
# ---------------------------------------------------------------------------

ARGV1=$1
ARGV3=$3
ARGV5=$5
ARGV6=$6

PFOLDER="${ARGV3:-waermepumpe}"
# 05.11.2026: ein Ordnername mit "/" oder "." waere ein Pfad hinaus (dieselbe Formel wie MGiSmart und Govee).
case "$PFOLDER" in
    ''|.|..|*/*) echo "<FAIL> Unbrauchbarer Ordnername '$PFOLDER' - nichts angefasst."; exit 2 ;;
esac
# Wurzel, in dieser Reihenfolge (Regeln/06: ohne brauchbare Wurzel warnen
# statt vollziehen):
#   1. das fuenfte Argument - der Installer uebergibt dort die LoxBerry-Wurzel,
#   2. $LBHOMEDIR, wenn darunter config/plugins und data/plugins liegen,
#   3. vom eigenen Ablageort aufwaerts das erste Verzeichnis mit
#      config/plugins, data/plugins UND config/system/general.json.
# Bis 0.9.22 stand hier BASE="${5:-$LBHOMEDIR}" ohne jede Pruefung. Ohne
# beides legte das Skript den Rueckfallweg config/plugins/<ordner>.upgrade ab
# der Laufwerkswurzel an (in WSL gemessen, Pruefung-WaermepumpeCloud-0.9.23,
# Fall K1). Dieselbe Suche steht in postinstall.sh, postupgrade.sh und
# uninstall. Bauart BatterieBMS 0.9.28.
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
    echo "<WARNING> Es wurde nichts gesichert."
    exit 2
fi

# ---------- Upgrade-Marke, als Erstes (Bauliste I1, Entscheidung 1) ----------
# Daran erkennt preinstall.sh die Aktualisierung (kein Altersvergleich) und
# laesst die Zweitschriften liegen; postupgrade.sh entfernt sie wieder,
# uninstall ebenfalls. Bis 0.9.26 gab es keine Marke - die Linie konnte Update
# und Neuinstallation nicht unterscheiden (waermepumpe_agenten/installer
# Befund 1). Sie liegt NEBEN dem Datenordner: im Ordner loeschte sie
# purge_installation mit.
mkdir -p "$BASE/data/plugins" 2>/dev/null
WP_MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
date +%s > "$WP_MARKE" 2>/dev/null
if [ -s "$WP_MARKE" ]; then
    echo "<INFO> Aktualisierung vermerkt ($WP_MARKE)."
else
    # 05.11.2026: ohne Marke haelt die Installation das Update fuer eine Neuinstallation und legt die Einstellungen beiseite; bis hierher nur eine Warnung.
    rm -f "$WP_MARKE" 2>/dev/null
    echo "<FAIL> Die Marke $WP_MARKE liess sich nicht anlegen - die Aktualisierung wird abgebrochen,"
    echo "<FAIL> die bisherige Fassung bleibt unveraendert installiert."
    exit 2
fi
# ---------- Update-Schutz (05.11.2026, Bauart Govee 0.9.34) ----------
# plugininstall.pl bricht nur bei Rueckgabewert >1 vor purge_installation ab. Die Kopie entsteht als <Ziel>.neu.<pid>, traegt 0600,
# BEVOR Inhalt hineinkommt, wird mit cmp verglichen und erst dann per mv an ihren Platz gestellt: eine heile Sicherung bleibt heil.
ZS_FEHL=""
zs_hinlegen() {   # $1 Quelle, $2 Ziel
    [ -d "$2" ] && return 1
    zs_neu="$2.neu.$$"
    rm -f "$zs_neu" 2>/dev/null
    if { : > "$zs_neu"; } 2>/dev/null && chmod 600 "$zs_neu" 2>/dev/null \
       && cp "$1" "$zs_neu" 2>/dev/null && cmp -s "$1" "$zs_neu" \
       && mv -f "$zs_neu" "$2" 2>/dev/null; then
        return 0
    fi
    rm -f "$zs_neu" 2>/dev/null
    return 1
}
zs_sichere() {   # $1 Quelle, $2 Ziel: fehlt die Datei oder ist sie leer, ist ein Fehlschlag keiner
    [ -f "$1" ] || return 0
    zs_hinlegen "$1" "$2" && return 0
    [ -s "$1" ] || return 1
    echo "<WARNING> $(basename "$1") liess sich nicht sichern ($2)."
    ZS_FEHL="$ZS_FEHL $(basename "$1")"
    return 1
}
zs_schluss() {   # Fehlschlag gesammelt: Marke weg, Abbruch vor purge_installation
    [ -n "$ZS_FEHL" ] || return 0
    echo "<FAIL> Nicht gesichert:$ZS_FEHL - die Aktualisierung wird abgebrochen, damit der Purge die Originale nicht loescht."
    echo "<FAIL> Die bisherige Fassung bleibt installiert. Bitte Platz und Rechte unter config/plugins und data/plugins pruefen."
    rm -f "$WP_MARKE" 2>/dev/null
    exit 2
}
# $LBPCONFIG zeigt auf <home>/config/plugins, OHNE Pluginordner - deshalb wird
# er angehaengt. Bis 0.9.10 stand er nur im Rueckfallzweig; war die Variable
# gesetzt (der Regelfall), sicherte dieses Skript zwei Streudateien eine Ebene
# darueber und meldete dafuer "<OK> 2 Datei(en) gesichert". Eine Sicherung,
# die Erfolg meldet und das Falsche sichert, ist schlimmer als keine.
CFGDIR="${LBPCONFIG:-$BASE/config/plugins}/$PFOLDER"

# Der Rueckfallweg liegt NEBEN dem Pluginordner, nicht darin.
#
# Bis 0.9.16 stand hier "$CFGDIR/.upgrade" - also IN dem Verzeichnis, das
# purge_installation abraeumt (Fundstellen im Kopf dieser Datei). Ohne
# sechstes Argument war die Sicherung damit weg, bevor postupgrade.sh sie
# zurueckspielen konnte. Nachgestellt mit dem Prueflauf, der den Abraeumschritt
# mitmacht: waermepumpe.json kam ueber die Zweitschrift daneben zurueck,
# geheim.json NICHT - Zugangsdaten, Erneuerungsmerkmale und der
# EMS-Schluessel waren nach jedem Update weg.
#
# "$PFOLDER.upgrade" liegt im selben Verzeichnis, wird aber von
# "rm -rf <ordner>/" nicht getroffen - derselbe Kunstgriff wie bei der
# Zweitschrift <ordner>.backup.waermepumpe.json. Aufgeraeumt wird er in
# postupgrade.sh, und das uninstall raeumt ihn ebenfalls ab: dort liegen
# Zugangsdaten, und was neben dem Ordner liegt, ueberlebt sonst auch die
# Deinstallation.
RUECKFALL="${LBPCONFIG:-$BASE/config/plugins}/$PFOLDER.upgrade"
# Einen Rest aus einem frueheren, abgebrochenen Lauf nicht liegen lassen -
# auch dann nicht, wenn derselbe Ort gleich wieder die Sicherung aufnimmt
# (Entscheidung 1: nie einen Bestand aus einem frueheren Vorgang einspielen;
# bis 0.9.26 blieb ohne sechstes Argument eine Datei aus einem frueheren Lauf
# liegen, wenn sie diesmal nicht gesichert wurde).
rm -rf "${RUECKFALL:?}" 2>/dev/null
if [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
    SICHERUNG="$ARGV6/waermepumpe_upgrade"
else
    echo "<INFO> Kein Arbeitsordner uebergeben - Rueckfall neben den Konfigordner."
    SICHERUNG="$RUECKFALL"
fi
mkdir -p "$SICHERUNG" 2>/dev/null
chmod 0700 "$SICHERUNG" 2>/dev/null

# HIER STAND EIN MERKER .upgrade_pfad IM KONFIGURATIONSORDNER, den
# postupgrade.sh als ersten von drei Wegen lesen sollte.
#
# Er kann dort nie ankommen: purge_installation entfernt genau dieses
# Verzeichnis, bevor postupgrade laeuft. Nachgestellt: nach preupgrade da,
# nach dem Abraeumen weg. Der Zweig war tot und das rm -f darauf ebenfalls.
# Beide Skripte rechnen den Pfad ohnehin aus DEMSELBEN Argument aus.
#
# Ausgebaut am 02.09.2026. Die Schwesterlinie Smartmeter classic hatte
# denselben Merker schon in 2.3.14 aus demselben Grund entfernt.
#
# DER ZWEITE PUNKT AN DERSELBEN STELLE ist mit 0.9.17 behoben: der
# Rueckfallweg zeigte nach "$CFGDIR/.upgrade" und damit ebenfalls in den
# Ordner, den purge_installation abraeumt. Er liegt jetzt daneben (siehe
# oben bei RUECKFALL).

GESICHERT=0
for f in waermepumpe.json geheim.json; do
    if [ -f "$CFGDIR/$f" ]; then
        zs_sichere "$CFGDIR/$f" "$SICHERUNG/$f" && GESICHERT=$((GESICHERT+1))
    fi
done

# Altlast aus 0.9.0: die nie zurueckgespielte Kopie neben dem Original.
rm -f "$CFGDIR/geheim.json.vorher" 2>/dev/null

# Gruppe 4 / MQTT (0.9.35): die Befehlsbruecke anhalten. Die Marke liegt schon (oben) - der Waechter im
# Minutentakt startet sie bis postupgrade.sh nicht neu; es ist dieselbe Marke, die befehlsbruecke.sh liest.
# Bis 0.9.34 gab es keine Bruecke (dann fehlt die Datei).
BBSH="$BASE/bin/plugins/$PFOLDER/befehlsbruecke.sh"
if [ -f "$BBSH" ]; then
    echo "<INFO> Befehlsbruecke: $(timeout 40 bash "$BBSH" stop 2>&1 | head -n 1)"
fi
zs_schluss
echo "<OK> preupgrade abgeschlossen ($GESICHERT Datei(en) gesichert nach $SICHERUNG)."
exit 0
