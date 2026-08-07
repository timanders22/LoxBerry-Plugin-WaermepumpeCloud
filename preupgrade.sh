#!/bin/bash
# Vor dem Update: die Zugangsdaten in Sicherheit bringen. LoxBerry raeumt bei
# einem Update das Plugin-Verzeichnis, nicht aber $LBPCONFIG - trotzdem wird
# hier eine Kopie gezogen, weil ein missglueckter Lauf sonst die einzige
# Ausfertigung der Zugangsdaten mitnimmt.
if [ -f "$LBPCONFIG/geheim.json" ]; then
    cp -a "$LBPCONFIG/geheim.json" "$LBPCONFIG/geheim.json.vorher" 2>/dev/null
    chmod 0600 "$LBPCONFIG/geheim.json.vorher" 2>/dev/null
fi
exit 0
