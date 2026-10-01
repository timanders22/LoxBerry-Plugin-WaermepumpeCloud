#!/bin/bash
# Waermepumpe Cloud - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
# Laeuft als Benutzer loxberry, VOR dem Kopieren der Dateien.
#
# Neu im Durchgangsbau vom 02.10.2026 (Bauliste I1, X-1, Entscheidung 1 vom
# 29.09.2026; Muster: Govee 0.9.24). Bis 0.9.26 tat dieses Skript nichts.
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: die Zweitschriften braucht die
# Selbstheilung waehrend der Aktualisierung.
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Reste einer
# frueheren Installation gehen nach <name>.alt, gemeldet mit genau einer
# <WARNING>:
#   config/plugins/<ordner>.backup.waermepumpe.json  Konfiguration mit Aktionstoken
#   config/plugins/<ordner>.backup.geheim.json       Zugangsdaten, Erneuerungsmerkmale
#   config/plugins/<ordner>.upgrade/                 Rueckfallweg einer Aktualisierung
#   data/plugins/<ordner>.bestand/                   Budgetlisten, Anmeldelaufzeit,
#                                                    vorgemerkte MQTT-Praefixe
# Gemessen bis 0.9.26 (waermepumpe_agenten/installer Befund 1, Fall N1): eine
# frische Installation ueber solche Reste spielte Hersteller, altes
# Aktionstoken, E-Mail, Kennwort und das myVAILLANT-Erneuerungsmerkmal ohne
# Warnung wieder ein, und das Protokoll riet dabei, den Hersteller zu waehlen.
#
# Warum schon hier und nicht erst in postinstall.sh: der Installer kopiert die
# Cron-Datei und die Oberflaeche erst NACH diesem Skript. Liegen die Reste dann
# schon unter .alt, findet die Selbstheilung der Bibliothek (wp_config(),
# wp_geheim()) keine Zweitschrift - auch nicht, wenn der Minutentakt vor
# postinstall.sh laeuft. .alt liest sie nie; uninstall raeumt es ab.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-waermepumpe}"
BASE="${ARGV5:-${LBHOMEDIR:-}}"

# Ohne config/plugins, data/plugins UND config/system/general.json wird nichts
# angefasst (Regeln/06, dieselbe Bedingung wie die Wurzelsuche der uebrigen
# Hakenskripte).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun.
    exit 0
fi

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.waermepumpe.json" \
            "$BASE/config/plugins/$PFOLDER.backup.geheim.json" \
            "$BASE/config/plugins/$PFOLDER.upgrade" \
            "$BASE/data/plugins/$PFOLDER.bestand"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
            if [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ]; then chmod 600 "$ZIEL.alt" 2>/dev/null; fi
            if [ -d "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ]; then chmod 700 "$ZIEL.alt" 2>/dev/null; fi
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    WP_TEXT="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && WP_TEXT="$WP_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && WP_TEXT="$WP_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$WP_TEXT"
fi
exit 0
