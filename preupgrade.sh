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
# BRAUCHT ES DIE SICHERUNG UEBERHAUPT? Streng genommen nicht: LoxBerry
# loescht config/plugins/<ordner> beim Upgrade nicht, und dieses Plugin
# liefert keinen config-Ordner mit, den der Installer darueberkopieren
# koennte. Sie bleibt als zweiter Boden - sie kostet nichts, und der Tag
# kommt, an dem doch eine Vorlage mitgeliefert wird.
# ---------------------------------------------------------------------------

ARGV1=$1
ARGV3=$3
ARGV5=$5
ARGV6=$6

PFOLDER="${ARGV3:-waermepumpecloud}"
BASE="${ARGV5:-$LBHOMEDIR}"
CFGDIR="${LBPCONFIG:-$BASE/config/plugins/$PFOLDER}"

if [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
    SICHERUNG="$ARGV6/waermepumpe_upgrade"
else
    echo "<INFO> Kein Arbeitsordner uebergeben - Rueckfall in den Konfigordner."
    SICHERUNG="$CFGDIR/.upgrade"
fi
mkdir -p "$SICHERUNG" 2>/dev/null
chmod 0700 "$SICHERUNG" 2>/dev/null
# Den benutzten Ort hinterlegen, damit postupgrade.sh ihn nicht raten muss.
echo "$SICHERUNG" > "$CFGDIR/.upgrade_pfad" 2>/dev/null

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
