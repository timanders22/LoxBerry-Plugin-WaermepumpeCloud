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

## Lizenz

**GPL-3.0**. Dieses Plugin steht in keiner Verbindung zu Nibe, Daikin Europe
oder Mitsubishi Electric. MELCloud ist eine inoffizielle Schnittstelle, die von
Mitsubishi Electric weder zugesagt noch geprüft ist.
