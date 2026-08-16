#!/bin/bash
# Waermepumpe Cloud - postupgrade
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# Spielt zurueck, was preupgrade.sh gesichert hat - und zwar nur, wenn die
# Datei im Zielordner FEHLT oder LEER ist. Eine vorhandene, gefuellte
# Konfiguration wird nicht ueberschrieben: sie ist die aktuellere.
#
# Zum Sicherungsort und zu den Argumenten siehe preupgrade.sh.

ARGV1=$1
ARGV3=$3
ARGV5=$5
ARGV6=$6

PFOLDER="${ARGV3:-waermepumpe}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Pluginordner anhaengen - siehe preupgrade.sh.
CFGDIR="${LBPCONFIG:-$BASE/config/plugins}/$PFOLDER"
MERKER="$CFGDIR/.upgrade_pfad"

if [ -r "$MERKER" ]; then
    SICHERUNG=$(cat "$MERKER")
elif [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
    SICHERUNG="$ARGV6/waermepumpe_upgrade"
else
    SICHERUNG="$CFGDIR/.upgrade"
fi

mkdir -p "$CFGDIR" 2>/dev/null
for f in waermepumpe.json geheim.json; do
    ZIEL="$CFGDIR/$f"
    QUELLE="$SICHERUNG/$f"
    [ -f "$QUELLE" ] || continue
    INHALT=$(cat "$ZIEL" 2>/dev/null)
    if [ ! -s "$ZIEL" ] || [ "$INHALT" = "{}" ]; then
        cp -a "$QUELLE" "$ZIEL" && echo "<OK> $f aus der Sicherung wiederhergestellt."
    fi
done

# Rechte wieder festziehen. Ein Update, das die Zugangsdatendatei auf 0644
# zuruecksetzt, faellt sonst niemandem auf.
chmod 0600 "$CFGDIR/waermepumpe.json" 2>/dev/null
chmod 0600 "$CFGDIR/geheim.json" 2>/dev/null

rm -f "$MERKER" 2>/dev/null
# Der Arbeitsordner des Installers wird von LoxBerry selbst aufgeraeumt.
# Nur der Rueckfallweg im Konfigordner gehoert uns - und dort liegen
# Zugangsdaten, die nicht liegen bleiben duerfen.
case "$SICHERUNG" in
    "$CFGDIR"/*) rm -rf "$SICHERUNG" ;;
esac

echo "<OK> Update abgeschlossen."
exit 0
