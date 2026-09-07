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
if [ "$SICHERUNG" = "$RUECKFALL" ]; then
    rm -rf "$RUECKFALL"
fi
# Und die Altlast: eine Sicherung, die eine Fassung bis 0.9.16 IN den
# Konfigordner gelegt hat. Sie ueberlebt zwar kein Upgrade, aber ein
# abgebrochener Lauf kann sie hinterlassen haben.
rm -rf "$CFGDIR/.upgrade" 2>/dev/null

echo "<OK> Update abgeschlossen."
exit 0
