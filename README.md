# LoxBerry-Plugin Wärmepumpe Cloud

Bindet die **herstellereigenen Cloud-Schnittstellen** moderner Wärmepumpen an
und übersetzt **SG-Ready-Zustände aus Loxone** dorthin.

Gedacht für die verbreitete Lage, dass kein Modbus-TCP-Modul verbaut ist,
sondern nur das WLAN-Modul des Herstellers — und der Miniserver deshalb an der
Wärmepumpe vorbeiregelt.

| Hersteller | Schnittstelle | SG Ready | Was zu beachten ist |
|---|---|---|---|
| **myUplink** (Nibe) | offiziell, OAuth2 (`client_credentials`) | **echt** | Lesen frei, **Schreiben verlangt ein kostenpflichtiges myUplink-Abo** |
| **Daikin Onecta** | offiziell, OAuth2 (Autorisierungscode) | nachgebildet | **200 Aufrufe je Tag**, gleitendes Fenster |
| **MELCloud** (Mitsubishi) | inoffiziell, ContextKey | nachgebildet | **Mindesttakt 180 s** — häufiger sperrt das Konto für Stunden |

## Was das Plugin ehrlich nicht kann

Diese drei Punkte stehen hier, weil sie die Erwartung „Plug-and-Play" begrenzen.
Alle drei sind belegt, keiner ist vermutet, und alle drei werden im Reiter
*Test* als eigene, benannte Prüfzeile geführt — sie scheitern nicht
stillschweigend.

**Echtes SG Ready gibt es nur bei Nibe.** Neuere Firmware macht die beiden
SG-Ready-Register über die Schnittstelle erreichbar: `3032` schaltet die
Bedienung frei, `6008` trägt den Zustand (0 Sperre, 1 normal, 2 günstiger
Strom, 3 Überschuss). Das Plugin *prüft*, ob das Gerät diese beiden Punkte
wirklich anbietet, statt es anzunehmen — ältere Firmware kennt sie nicht.

**Bei Daikin und Mitsubishi ist SG Ready nachgebildet.** Deren Clouds haben
keinen SG-Ready-Eingang. „Sperre" heißt dort **aus**, und *aus* ist nicht
dasselbe wie die EVU-Sperre, die eine Wärmepumpe selbst kennt: sie fährt
Frostschutz und Legionellenschaltung nicht in gleicher Weise weiter. Wer eine
echte Sperre braucht, kommt an den beiden Klemmen am Gerät nicht vorbei. Der
Reiter *SG Ready* sagt je Zustand und je Hersteller, was tatsächlich geschieht.

**Das Daikin-Tagesbudget bestimmt den Takt, nicht der Nutzer.** 200 Aufrufe am
Tag sind ein Aufruf alle 7,2 Minuten. Ein Fünf-Minuten-Takt wären 288 — das
Kontingent wäre vor dem Abend leer, und dann ließe sich auch nichts mehr
schalten. Das Plugin führt deshalb eine Aufrufbuchhaltung über ein gleitendes
24-Stunden-Fenster, hält eine einstellbare Zahl Aufrufe fürs Schalten zurück
und rechnet den kleinstmöglichen Abruftakt daraus aus.

## Zwei Sicherungen gegen eine kalte Wohnung

**Die Sperre läuft von selbst ab.** SG Ready sieht für den Sperrzustand
höchstens zwei Stunden vor. Bleibt der Miniserver hängen, während gerade
gesperrt ist, stünde die Heizung sonst unbegrenzt still. Nach der eingestellten
Zeit fällt das Plugin von sich aus auf Normalbetrieb zurück und schreibt es ins
Protokoll. Die Baustein-Liste im Reiter *Einbindung in Loxone* führt dieselbe
Begrenzung ein zweites Mal in Loxone — sie greift auch, wenn der LoxBerry steht.

**Ohne Grundsollwert wird nicht angehoben.** Die Nachbildung hebt den Sollwert
an; ohne gemerkten Ausgangswert würde sich die Anhebung bei jedem Durchlauf
weiter aufaddieren. Das Plugin merkt sich den Wert beim ersten Abruf im
Normalbetrieb und hebt vorher gar nicht an.

## Feldzuordnung ohne Gerät

Dieses Plugin ist ohne Wärmepumpe entstanden. Die genaue Gestalt der Antwort
hängt am Modell, und ein geratener Pfad scheitert still. Deshalb:

* je Feld **mehrere Kandidatenpfade**, der erste Treffer gewinnt
* der Reiter *Test* sagt für **jedes** Feld, welcher Kandidat gegriffen hat —
  oder dass keiner gegriffen hat
* die Rohantwort lässt sich ansehen
* wer den richtigen Pfad darin findet, trägt ihn von Hand nach; eine eigene
  Zuordnung **ersetzt** die Kandidaten, statt sie zu ergänzen (sonst griffe bei
  einem Tippfehler still wieder die Vorgabe)

Pfadformen: `a.b.c`, `a[embeddedId=climateControl].b`, bei myUplink `#40004`
für eine Parameternummer oder `~aussen|outdoor` für eine Namenssuche, jeweils
optional mit Faktor: `#2305|0.0001`.

## Zwei Richtungen

| Richtung | Weg |
|---|---|
| Wärmepumpe → Loxone | MQTT (Regelweg) und ein virtueller HTTP-Eingang mit einer Statuszeile |
| Loxone → Wärmepumpe | Token-Endpunkt, vier virtuelle Ausgänge (einer je SG-Ready-Zustand) |

Der HTTP-Eingang **löst keinen Cloud-Abruf aus** — er liest nur den
Zwischenstand. Ein zu häufig fragender Eingang kann damit weder das
Daikin-Budget leeren noch das MELCloud-Konto aussperren.

## Einrichten

1. *Einstellungen* → Hersteller wählen, Zugangsdaten eintragen.
   Bei Daikin zusätzlich die einmalige Anmeldung über den Browser.
2. *Test* → **Geräte suchen**, Kennung übernehmen, speichern.
3. *Test* → **Jetzt abrufen**, dann **Feldwege zeigen**.
4. *SG Ready* → ansehen, was die vier Zustände hier bewirken; Grenzen setzen.
5. *MQTT* → das Abo ins Gateway eintragen
   (System → MQTT Gateway → Subscriptions). **Ohne diesen Eintrag kommt am
   Miniserver nichts an.**
6. *Einbindung in Loxone* → beide Vorlagen herunterladen und einlesen.

## Zugangsdaten

Liegen in einer **eigenen Datei** unter `$LBPCONFIG/geheim.json` mit Rechten
`0600` — getrennt von der Konfiguration, die die Oberfläche anzeigt. Im
Protokoll und in der Selbstprüfung erscheinen sie nur maskiert: Länge ja,
Inhalt nein. Beim Deinstallieren werden sie gelöscht.

## Verzeichnis

```
bin/wp_abruf.php                   Abrufdienst (aus cron.01min)
webfrontend/htmlauth/index.php     Bedienoberflaeche, sechs Reiter
webfrontend/htmlauth/wp_lib.php    Konfiguration, drei Hersteller-Adapter,
                                   SG-Ready-Uebersetzer, Vorlagen
webfrontend/htmlauth/wp_test.php   Selbstpruefung und Test-Aktionen
webfrontend/html/index.php         Token-Endpunkt fuer den Miniserver
```

## Fassung 0.9.1 — nachgemessen und korrigiert

### Der Plugin-Ordner wird ermittelt, nicht nur geraten

`wp_paths()` nahm `LBPPLUGINDIR` und fiel andernfalls sofort auf den festen
Namen `waermepumpe` zurück. Hängt LoxBerry bei einer Zweitinstallation einen
Zähler an (`waermepumpe_01`), zeigten deren Pfade damit auf die **erste**
Installation — gemeinsame `geheim.json` mit den Erneuerungsmerkmalen dreier
Herstellerclouds, gemeinsames Protokoll, gemeinsamer Datenordner.

Zwischen beiden steht jetzt der Ablageort dieser Datei: installiert liegt sie
unter `htmlauth/plugins/<ordner>/`. Der feste Name greift erst, wenn auch das
nachweislich keinen Plugin-Ordner ergibt.

Dreizehn Punkte aus einer Durchsicht. Sieben trafen zu, drei teilweise, drei
nicht. Alles wurde nachgestellt, bevor etwas geändert wurde.

### Die Sicherung beim Upgrade wurde nie zurückgespielt

Trifft zu, und zwar deutlicher als beschrieben. `preupgrade.sh` legte
`geheim.json.vorher` **neben das Original in denselben Ordner**, und
`postupgrade.sh` löschte die Kopie anschließend nur wieder. Eine Sicherung,
die angelegt und dann weggeworfen wird, ist keine — sie ist eine zweite
Ausfertigung der Zugangsdaten, die eine Weile herumliegt. Die
Hauptkonfiguration `waermepumpe.json` wurde gar nicht erst gesichert.

Jetzt werden beide Dateien gesichert und zurückgespielt, und zwar nur, wenn
die Datei im Ziel fehlt oder leer ist — eine vorhandene, gefüllte
Konfiguration ist die aktuellere. Beide Wege nachgestellt (mit und ohne
Arbeitsordner), Ergebnis jeweils: beide Dateien wiederhergestellt, Rechte
0600, kein Merker und keine `.vorher`-Altlast übrig.

Zum Sicherungsort eine Berichtigung: der übliche Zusatz, man solle `$1`
nehmen, das sei der Pfad des Installers, trifft nicht zu. `$1` ist eine
zehnstellige Zufallskennung (`&generate(10)` in `plugininstall.pl`); der
absolute Arbeitsordner kommt als **sechstes** Argument.

### `Undefined array key` im Webhook

Trifft zu. Die Prüfschleife arbeitete mit einem Ersatzwert, die Zeile danach
griff unmittelbar auf `$_GET['k1']` und `$_GET['k2']` zu. Da die Bedingung
darüber ausdrücklich zulässt, dass nur **eine** Klemme genannt wird, war das
leicht zu treffen. Gemessen, jeweils bisher gegen jetzt:

| Aufruf | bisher | jetzt |
|---|---|---|
| nur `k1=1` | `k1=1 k2=0` + **Notice/Warning** | `k1=1 k2=0`, keine Meldung |
| nur `k2=1` | `k1=0 k2=1` + **Notice/Warning** | `k1=0 k2=1`, keine Meldung |
| `k1[]=1` | abgewiesen + **Array to string conversion** | sauber abgewiesen |

Beides landete mitten in der Klartextantwort, die Loxone einliest.

### Weitere zutreffende Punkte

**Keine Log-Rotation.** `wp_log()` hängte unbegrenzt an — bei einem Lauf je
Minute rund eine halbe Million Zeilen im Jahr. Jetzt wird ab 512 kB auf die
letzten 300 Zeilen gekürzt.

**Token-Dateien nicht atomar.** Fünf Stellen schrieben mit
`file_put_contents`, obwohl `wp_json_schreiben()` (temp + rename) daneben
liegt: die drei Token-Dateien, die MELCloud-Schlüsseldatei und die
Zustandsdatei. Dazu kam eine, die die Durchsicht nicht nannte — die
**Budgetliste**. In der steht, wie viele Abrufe des Tagesbudgets verbraucht
sind; eine halb geschriebene Datei lässt den Zähler auf null zurückfallen,
und dann wird munter weiter abgerufen, bis der Hersteller sperrt.

**`strlen()` bei UTF-8.** Zählt Bytes; Parameternamen mit Umlauten wurden
früher abgewiesen, als die Grenze es hergibt. Gezählt wird jetzt mit PCRE
(`/./us`) — **nicht** mit `mb_strlen`: mbstring ist eine eigene Erweiterung,
die dieses Plugin nirgends anmeldet und sonst nicht benutzt.

**Unvollständige Bereinigung beim Deinstallieren.** Jetzt geht der ganze
Datenordner weg (dort liegen auch die erneuerbaren Anmeldemarken der drei
Herstellerclouds — ohne Passwort ein gültiger Zugang), dazu die
Arbeitsdateien auf der Ramdisk. Der Hinweis nannte `/tmp/abruf.lock`; die
Datei liegt tatsächlich in `/run/shm/waermepumpe/` — `wp_tmpdir()` legt seit
jeher einen eigenen Unterordner an.

**`try ... finally` um die Sperre.** Eingebaut, allerdings mit anderer
Begründung als angegeben: das Betriebssystem gibt eine Sperre beim
Prozessende immer frei, auch beim Absturz. Der Block hilft für den Fall, der
*nicht* das Prozessende ist — seit PHP 7 sind die meisten früheren fatalen
Fehler `Error`-Ausnahmen, und die laufen durch `finally`.

**`ARCHITECTURE=raspberry,x86`.** Auf `false` gesetzt, weil das Plugin reines
PHP ist. Die Begründung stimmt allerdings nicht: der aktuelle Installer liest
`SYSTEM.ARCHITECTURE` zwar aus (`$parch`), benutzt den Wert danach an **keiner
einzigen Stelle** — eine Installation hat er also nie verhindert. Geändert
wurde, weil der Eintrag schlicht unwahr war.

### Was nicht zutraf

**Fehlende cURL-Zeitgrenzen.** `CURLOPT_TIMEOUT` und `CURLOPT_CONNECTTIMEOUT`
sind gesetzt, und der Ersatzweg über `file_get_contents` bekommt `timeout` im
Stromkontext. Beide Pfade waren schon begrenzt.

**`php-json` in `dpkg/apt` ergänzen.** Seit PHP 8.0 ist JSON fest in den Kern
eingebaut und nicht mehr abwählbar; ein Paket dieses Namens ist dort
bestenfalls ein Übergangspaket. Ein Paket zu verlangen, das es auf dem
Zielsystem womöglich nicht gibt, lässt den ganzen apt-Lauf mit einer Warnung
enden — für nichts. **`php-curl`** ist dagegen ergänzt: das ist die
Erweiterung, die `wp_http()` tatsächlich benutzt.

**`TypeError` bei `?form[]=test`.** Es gibt keinen. Gemessen in 7.4 und 8.1:
es gibt „Array to string conversion" (Notice bzw. Warning), `preg_match`
liefert 0, und der Reiter fällt korrekt auf Einstellungen zurück. Der
vorgeschlagene Guss `(string) $_GET['form']` **hilft auch nicht** — eine
Array-in-Zeichenkette-Umwandlung meldet genau dasselbe. Nur eine
`is_array()`-Prüfung schweigt; die steht jetzt dort.

### Nebenbefund: Reiter nur mit JavaScript

Die sechs Flächen bekamen `sm-active` ausschließlich vom Skript — ohne
JavaScript war keine sichtbar, obwohl der Kommentar darüber das Gegenteil
versprach. Reihenfolge, Positivliste und Beschriftung kommen jetzt aus einem
einzigen Feld, und der Server setzt die Klasse selbst; alle sechs Reiter sind
über `?form=…` erreichbar, unbekannte Werte fallen auf *Einstellungen*
zurück.

## Lizenz

**GPL-3.0**. Dieses Plugin steht in keiner Verbindung zu Nibe, Daikin Europe
oder Mitsubishi Electric. MELCloud ist eine inoffizielle Schnittstelle, die von
Mitsubishi Electric weder zugesagt noch geprüft ist.
