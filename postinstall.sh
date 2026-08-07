#!/bin/bash
# Laeuft als Benutzer loxberry, NACH dem Kopieren der Dateien.
#
# Legt Konfigurations- und Datenverzeichnis an und setzt die Rechte. Die
# Zugangsdaten liegen in einer EIGENEN Datei mit 0600 - nicht in der
# Konfiguration, die die Oberflaeche anzeigt.

CFGDIR="$LBPCONFIG"
DATADIR="$LBPDATA"

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
