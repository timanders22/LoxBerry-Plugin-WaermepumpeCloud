#!/bin/bash
# Nach dem Update: Rechte wieder festziehen. Ein Update, das die
# Zugangsdatendatei auf 0644 zuruecksetzt, faellt sonst niemandem auf.
chmod 0600 "$LBPCONFIG/waermepumpe.json" 2>/dev/null
chmod 0600 "$LBPCONFIG/geheim.json" 2>/dev/null
rm -f "$LBPCONFIG/geheim.json.vorher" 2>/dev/null
echo "<OK> Update abgeschlossen."
exit 0
