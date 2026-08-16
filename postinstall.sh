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
PFOLDER="${ARGV3:-waermepumpe}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    echo "<FAIL> Das Basisverzeichnis von LoxBerry wurde nicht uebergeben (\$5)."
    exit 1
fi
CFGDIR="${LBPCONFIG:-$BASE/config/plugins}/$PFOLDER"
DATADIR="${LBPDATA:-$BASE/data/plugins}/$PFOLDER"

mkdir -p "$CFGDIR" "$DATADIR"
chmod 0755 "$CFGDIR" "$DATADIR"

# Konfiguration: sichtbare Einstellungen
if [ ! -f "$CFGDIR/waermepumpe.json" ]; then
    echo '{}' > "$CFGDIR/waermepumpe.json"
fi
chmod 0600 "$CFGDIR/waermepumpe.json"

# Zugangsdaten: eigene Datei, nur fuer den Eigentuemer lesbar.
if [ ! -f "$CFGDIR/geheim.json" ]; then
    echo '{}' > "$CFGDIR/geheim.json"
fi
chmod 0600 "$CFGDIR/geheim.json"

echo "<OK> Konfiguration unter $CFGDIR angelegt (0600)."
echo "<INFO> Weiter in der Oberflaeche: Reiter Einstellungen, Hersteller waehlen."
exit 0
