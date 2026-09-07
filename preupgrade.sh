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
BASE="${ARGV5:-$LBHOMEDIR}"
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
if [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
    SICHERUNG="$ARGV6/waermepumpe_upgrade"
    # Einen Rest aus einem frueheren, abgebrochenen Lauf nicht liegen lassen.
    rm -rf "$RUECKFALL" 2>/dev/null
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
        cp -a "$CFGDIR/$f" "$SICHERUNG/$f" 2>/dev/null && GESICHERT=$((GESICHERT+1))
        chmod 0600 "$SICHERUNG/$f" 2>/dev/null
    fi
done

# Altlast aus 0.9.0: die nie zurueckgespielte Kopie neben dem Original.
rm -f "$CFGDIR/geheim.json.vorher" 2>/dev/null

echo "<OK> preupgrade abgeschlossen ($GESICHERT Datei(en) gesichert nach $SICHERUNG)."
exit 0
