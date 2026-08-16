# LoxBerry-Plugin Wärmepumpe

Bindet moderne Wärmepumpen an den Miniserver an und übersetzt **SG-Ready-Zustände
aus Loxone** dorthin — über die **herstellereigene Cloud** oder, bei Bosch und
seinen Marken, über ein **Gateway im eigenen Netz**.

Gedacht für die verbreitete Lage, dass kein Modbus-TCP-Modul verbaut ist,
sondern nur das WLAN-Modul des Herstellers — und der Miniserver deshalb an der
Wärmepumpe vorbeiregelt.

> **Zum Namen:** der Ordner heißt weiterhin `WaermepumpeCloud`. Mit EMS-ESP ist
> das nicht mehr ganz wahr — ein Umbenennen bräche aber jede bestehende
> Installation (der Ordnername ist die Plugin-Kennung). Der Titel in der
> Plugin-Verwaltung nennt seit 0.9.4 alle fünf Wege.

| Hersteller | Schnittstelle | SG Ready | Was zu beachten ist |
|---|---|---|---|
| **myUplink** (Nibe) | offiziell, OAuth2 (`client_credentials`) | **echt** | Lesen frei, **Schreiben verlangt ein kostenpflichtiges myUplink-Abo** |
| **Daikin Onecta** | offiziell, OAuth2 (Autorisierungscode) | nachgebildet | **200 Aufrufe je Tag**, gleitendes Fenster |
| **MELCloud** (Mitsubishi) | inoffiziell, ContextKey | nachgebildet | **Mindesttakt 180 s** — häufiger sperrt das Konto für Stunden |
| **myVAILLANT** (Vaillant, Saunier Duval, Bulex, Glow-worm, DemirDöküm) | inoffiziell, OpenID Connect mit PKCE | nachgebildet | **Anmeldung mit den App-Zugangsdaten**, kein API-Schlüssel; der sensoCOMFORT meldet ohnehin nur alle 5 min |
| **EMS-ESP** (Bosch, Buderus, Junkers, Nefit, Worcester, Sieger) | **lokal**, HTTP/JSON über ein Gateway am EMS-Bus | nachgebildet, echt mit zwei Relais | **Kein Konto, keine Ratenbegrenzung.** Braucht ein Gateway (z. B. BBQKees); Lesen geht ohne alles, Schreiben mit einem Zugriffsmerkmal |

### EMS-ESP im Besonderen

Der einzige Weg in diesem Plugin, der ohne fremden Dienst auskommt. Damit
fallen die drei Dinge weg, an denen die Cloud-Anbindungen hängen: Anmeldung,
Aufrufbudget und die Möglichkeit, dass der Hersteller die Schnittstelle
abschaltet.

Drei Dinge, die man vorher wissen sollte:

* **Eine Bosch-Wärmepumpe meldet sich als `boiler`**, nicht als `heatpump`.
  Unter `heatpump` läuft ein anderes Modul. Wer dort sucht, findet nichts.
* **SG Ready lässt sich über den Bus nicht setzen.** Die Klemmen 1 und 4 kann
  das Gateway nur *lesen* (`hpin1` … `hpin4` sind schreibgeschützt). Die
  Nachbildung schaltet stattdessen `boiler.heatingactivated` ab bzw. hebt
  `thermostat.hc<n>.seltemp` an. Wer die echten Klemmen fahren will, legt zwei
  GPIO des Gateways als *Digital out* an und führt sie über je ein Relais oder
  einen Optokoppler an die Eingänge — der Gateway-Hersteller weist dabei
  ausdrücklich darauf hin, dass die Platinen dafür nicht gebaut sind.
* **Die Arbeitszahl kommt aus zwei Gesamtzählern** (`nrgtotal` erzeugte Wärme,
  `metertotal` eingesetzter Strom, beide nur bei den neueren Modellen). Das
  Plugin schreibt stündlich einen Stand fort und rechnet über die Differenz —
  der Quotient der Gesamtstände wäre die Arbeitszahl seit Inbetriebnahme und
  im Februar so hoch wie im Juli.

Ein Knopf im Reiter *Test* fragt das Gateway, **was sich schreiben lässt**
(`/api/<gerät>/commands`). Das ist der Vorteil gegenüber den vier Wolken: die
Liste kommt vom Gerät und stimmt auch bei einem Modell, das dieses Plugin nie
gesehen hat.

## Neu in 0.9.12

**Die Bedienoberfläche war auf dem Gerät nicht erreichbar.** Jeder Aufruf von
`/admin/plugins/waermepumpe/` endete mit **HTTP 500 und leerem Rumpf** — in
0.9.11 und in allen Fassungen davor.

`webfrontend/htmlauth/index.php` nannte seine Pfadvariable `$p`:

```php
$p = wp_paths();
if (file_exists($p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $p['home'] . '/libs/phplib/loxberry_web.php';   // <- scheiterte
}
```

`require_once` bindet im **gleichen Gültigkeitsbereich** ein, und die
LoxBerry-Bibliothek legt unter genau diesem Namen etwas eigenes ab. Am Gerät
gemessen (LoxBerry 4.0.0.14):

```
$p vorher : array("home" => "/opt/loxberry")
$p nachher: array("", "libs", "phplib", "loxberry_system.php")
```

Danach gibt es kein `$p['home']` mehr. Die **zweite** Zeile suchte deshalb
unter `/libs/phplib/loxberry_web.php` — ab dem Wurzelverzeichnis. Tückisch ist
die Reihenfolge: `file_exists()` und das erste `require_once` gehen noch mit
richtigem Pfad durch, erst das zweite scheitert. Wer die Meldung liest, sucht
bei Zeile 18; der Fehler steckt in Zeile 15.

**Behoben, zweifach abgesichert:** die Variable heißt jetzt `$wp_p` — so machen
es 38 von 41 Plugins im Bestand —, **und** der Heimatpfad wird vor dem
Einbinden in eine eigene Zeichenkette gerettet. Damit trägt es auch, wenn eine
künftige Bibliotheksfassung wieder etwas anderes überschreibt.

**Warum das keine Prüfung gefunden hat**, und was daraus folgt: die
LoxBerry-Attrappe der Prüfkette setzte `$p` nicht. Die Oberfläche rendert
dagegen grün — in allen sechs Reitern, unter PHP 7.4 und 8.4. Die Attrappe
wurde deshalb nachgezogen; sie überschreibt jetzt dieselben globalen Namen wie
das Original. Der erste Lauf danach hat die 0.9.11 sofort rot gemeldet — **und
dazu einen zweiten Fehler gefunden**, den ich beim Beheben selbst eingebaut
hatte: die Vorschau-Knopfreihe im Reiter *Test* benutzte `$wp_pv` noch als
`$wp_p` und überschrieb damit den Pfad für den Reiter *Logdateien*. Beides ist
korrigiert.

Sonst ändert diese Fassung nichts. Alle Funktionen aus 0.9.11 sind unverändert.

## Neu in 0.9.11

Diese Fassung behebt **vier Fehler, die im Betrieb Schaden anrichten**, und
nimmt sieben Funktionen auf. Alles darunter ist nachgemessen, nicht abgeleitet —
einschließlich des einen Punktes, der bis zuletzt offen war und am Gerät selbst
beantwortet wurde (siehe *Die MELCloud-Bitmaske* am Ende dieses Abschnitts).

### Vier Fehler, die repariert werden mussten

**Die Deinstallation hat den Datenordner *aller* Plugins gelöscht.**
`uninstall/uninstall` bildete `DATADIR="${LBPDATA:-…}"` ohne Pluginordner.
`LBPDATA` ist die Umgebungsvariable des *Installers* und zeigt auf
`<home>/data/plugins` — ohne den Ordnernamen. Das `rm -rf` traf damit das
gemeinsame Verzeichnis: Zwischenstände, Token-Dateien und Anmeldemarken jedes
installierten Plugins. `2>/dev/null` verschluckte jede Meldung, und das Skript
meldete danach `<OK>`. In einer Attrappe nachgestellt: vier Plugins drin, vier
Plugins weg. Dieselbe Verwechslung steckte in `postinstall.sh`, `preupgrade.sh`
und `postupgrade.sh` — die Sicherung vor einem Update sicherte deshalb zwei
Streudateien und meldete dafür Erfolg. Alle vier Stellen hängen den Ordner
jetzt an, und vor dem `rm -rf` steht eine Prüfung, die abweist statt zu raten.

**Der Loxone-Endpunkt hat auf keiner Installation je funktioniert.**
`webfrontend/html/index.php` suchte seine Bibliothek über
`dirname(__DIR__) . '/htmlauth/wp_lib.php'`. Im entpackten Archiv geht das auf,
auf dem installierten LoxBerry liegen `html/` und `htmlauth/` in getrennten
Bäumen — der Aufruf endete dort mit einem fatalen Fehler, Rückgabewert 255 und
**leerer** Antwort, weil `display_errors` zwei Zeilen vorher abgeschaltet wird.
Betroffen waren beide Richtungen: die Statuszeile *und* das Schalten über die
vier virtuellen Ausgänge. Der virtuelle Eingang behielt seinen letzten Wert, und
in der App sah alles normal aus. Dieselbe Zeile hatte bis 0.9.8 den Abrufdienst
lahmgelegt und wurde dort mit 0.9.9 berichtigt — die zweite Stelle blieb stehen.
Jetzt gilt dieselbe Kandidatenliste; findet keiner die Bibliothek, antwortet der
Endpunkt mit `WP;OK=0;GRUND=BIBLIOTHEK_FEHLT` und nennt die gesuchten Pfade.

**Der Menüeintrag zeigte `ARRAY(0x…)`.** Der `TITLE` in `plugin.cfg` enthielt
Kommas. `plugininstall.pl` liest die Datei mit Config::Simple, und dessen
`parse_ini_file` zerlegt jeden Wert an `\s*,\s*` zu einer Liste; `param()` gibt
eine mehrelementige Liste im skalaren Kontext als Referenz zurück. Die landete
als Titel in der Plugin-Datenbank, und `plugininstall.cgi` brach mit
*„attempt to set parameter 'plugindb_title' with an array ref"* ab. Der Titel
heißt jetzt **Wärmepumpe Cloud** — kommafrei und unter der Grenze von 25
Zeichen, ab der der Installer kürzt.

**Drei virtuelle Eingänge bekamen nie einen Wert, und MQTT schwieg bei
Störungen.** Die Loxone-Vorlage und die Themen-Tabelle liefen über
`wp_statusfelder()`, die Statuszeile aber über `wp_felder()` — `COP`, `STROM`
und `WAERME` standen deshalb in der Importdatei und kamen in der Zeile nie vor.
Umgekehrt fehlte `ALTER` über MQTT, und der MQTT-Block stand innerhalb von
`if ($erg['ok'])`: auf dem Weg, den dieses Plugin selbst den Regelweg nennt, kam
bei einer Störung **nichts** an. Beides hat jetzt **eine** Quelle,
`wp_ausgabewerte()`.

### Sieben neue Funktionen

| Funktion | Was sie beantwortet |
|---|---|
| **Verdichtertakt** (`WP_TAKTE`, `WP_LAUFZEIT`, `WP_LAUFANTEIL`) | Wie oft startet der Verdichter, wie lang läuft er? Häufiges Takten ist der teuerste Betriebsfehler und in keiner Hersteller-App zu sehen |
| **Spreizung** (`WP_SPREIZUNG`) | Vorlauf minus Rücklauf — die Zahl für Volumenstrom und Pumpe |
| **Störungszähler** (`WP_STOERUNG`) | Fehlgeschlagene Abrufe **in Folge**: trennt „hakt kurz" von „seit Stunden tot" |
| **`?selftest=1`** | Prüft das Token, **ohne zu schalten** |
| **Wirksamkeitsnachweis** | Was Vorlauf und Leistungsaufnahme vor einem SG-Ready-Wechsel waren — und 15 Minuten danach |
| **Vorschau** | Was Zustand 1 bis 4 an *dieser* Anlage auslösen würde, ohne etwas zu senden |
| **Warmwasser-Zwangsladung** (`WP_WW_BOOST`) | Speicher aus PV-Überschuss laden, ohne den Heizkreis anzufassen |

**Zum Verdichtertakt gehört eine Einschränkung, und sie steht auch in der
Oberfläche:** gezählt wird im Abruftakt. Ein Verdichterlauf, der kürzer ist als
dieser Takt, fällt zwischen zwei Abrufe. `WP_TAKTE` ist deshalb eine
**Untergrenze**, keine genaue Zahl. Wer genau zählen will, legt den
Verdichterkontakt auf einen Digitaleingang des Miniservers.

**Die Vorschau geht denselben Weg wie der Ernstfall.** Nicht eine zweite
Beschreibungsfunktion — die liefe früher oder später auseinander, und dann
zeigte die Vorschau etwas anderes an, als der Ernstfall tut. Statt dessen geben
die acht Schreibstellen im Probemodus `PROBE` zurück. Gemessen gegen ein
Gerät, das Zugriffe mitschreibt: Vorschau **0** Zugriffe, Ernstfall **3** — bei
wortgleicher Beschreibung.

**Die Warmwasser-Zwangsladung gibt es nicht bei myUplink.** Für Nibe ist in
diesem Plugin kein belegter Parameter dafür hinterlegt. Der Endpunkt antwortet
dort mit `HTTP 501 … GRUND=NICHT_UNTERSTUETZT`, und die Importdatei enthält den
Ausgang gar nicht erst — ein Baustein, der nur Absagen erntet, wäre schlimmer
als keiner. Eine geratene Parameternummer stünde in Loxone und sähe aus wie eine
Funktion.

### Fünf weitere Korrekturen

* **Der unangemeldete Endpunkt schrieb.** Ein Aufruf ganz ohne Token legte zwei
  Dateien an und erzeugte das Aktionstoken. Er liest jetzt nur.
* **Eingaben wurden still zurechtgebogen.** Aus `wärmepumpe/haus` wurde
  `wrmepumpe/haus`, und der Benutzer las „Einstellungen gespeichert." Dasselbe
  traf Geräte-, Gebäude- und System-Kennung. Wird jetzt abgewiesen und benannt.
* **Eine Beanstandung verhinderte das Speichern *aller* Felder.** Ein Heizkreis
  von 99 warf den im selben Formular gültig geänderten Takt weg. Gemeldet wird
  weiter, blockiert nicht mehr.
* **Der Reiter Logdateien zeigte alles in einer Zeile**, weil `implode('')`
  zusammenklebte, was `rtrim` von den Zeilenenden befreit hatte.
* **Der Ersatzweg ohne `php-curl` folgte Umleitungen** und schickte
  `Authorization: Bearer …` an das Umleitungsziel erneut mit. Gemessen gegen
  einen Server, der `/a` auf `/b` umleitet.

Dazu: die Nebendatei beim atomaren Schreiben trägt jetzt die Prozessnummer
(drei Prozesse schreiben dieselben Dateien), die Rechte werden **vor** dem
Inhalt gesetzt, der Knopf *Token neu erzeugen* ist orange statt grau, und die
Zweitschrift der Konfiguration überlebt die Deinstallation nicht mehr — sie
holte bisher bei einer Neuinstallation den alten Stand samt Token zurück.

### Die MELCloud-Bitmaske — nachgemessen, kein Befund

`wp_ml_flags()` führt zwei Werte, die oberhalb von `PHP_INT_MAX` einer
**32-Bit**-Ganzzahl liegen (`SetTemperatureZone1` = 8 589 934 592 und
`SetTankWaterTemperature` = 281 474 976 710 688). Auf einem 32-Bit-PHP würde der
Übersetzer daraus Gleitkommazahlen machen, und die `EffectiveFlags` wären dann
womöglich falsch — MELCloud übernähme das betroffene Feld stillschweigend nicht,
und der Schreibbefehl meldete trotzdem HTTP 200.

**Am Gerät gemessen (16.08.2026, LoxBerry 4.0.0.14):**

```
$ php -r 'echo PHP_INT_SIZE, "\n";'
8
```

Damit ist `PHP_INT_MAX` = 9 223 372 036 854 775 807 — vier Größenordnungen über
dem größten Flaggenwert. Gegengerechnet unter PHP 7.4.33 und 8.4.24: alle fünf
Flaggen bleiben `integer`, und die Masken, die dieses Plugin tatsächlich bildet,
kommen als Ganzzahlen heraus:

| Fall | `EffectiveFlags` |
|---|---|
| Sperre (Zustand 1) | `1` |
| Warmwasser-Zwangsladung | `65536` |
| Anheben (Zustand 3 und 4) | `8589934593` |
| Anheben mit Warmwasser | `281483566710817` |

**Der Verdacht hat sich damit aufgelöst.** Er stünde hier trotzdem, wenn jemand
dieses Plugin auf einem 32-Bit-System betreibt — dort wäre die Rechnung erneut
zu führen.

## Neu in 0.9.9

**Der Abrufdienst konnte nie starten.** `bin/wp_abruf.php` suchte seine Programmbibliothek
ueber `dirname(__DIR__) . '/webfrontend/htmlauth/…'`. Im entpackten Archiv
liegen `bin/` und `webfrontend/` nebeneinander, auf dem installierten
LoxBerry in getrennten Baeumen — der Aufruf endete dort bei jedem Cron-Lauf
mit `Failed opening required`. Weil die Cron-Zeile nach `/dev/null` schreibt,
stand das nirgends. Damit wurden seit der Einfuehrung des Dienstes keine Werte geholt.

Die Bibliothek wird jetzt ueber eine Kandidatenliste gesucht; findet keiner
sie, schreibt der Dienst auf die Fehlerausgabe, **welche Datei er wo gesucht
hat**, und endet mit Rueckgabewert 1 statt stillschweigend.

Nach dem Update einmal von Hand pruefen:

```bash
php /opt/loxberry/bin/plugins/<ordner>/wp_abruf.php; echo "Rueckgabewert: $?"
```

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

## myVAILLANT im Einzelnen

**Es gibt keine offene Schnittstelle.** Angemeldet wird mit denselben
Zugangsdaten wie in der App, über einen OpenID-Connect-Fluss mit PKCE gegen
einen Keycloak unter `identity.vaillant-group.com`. Alles, was das Plugin
dafür tut, ist der quelloffenen Bibliothek
[myPyllant](https://github.com/signalkraft/myPyllant) entnommen — Adressen,
Kopfzeilen, Feldnamen. Nichts davon ist geraten, und Vaillant kann es
jederzeit ändern.

Der Anmeldefluss läuft in drei Schritten, und zwei Fallstricke stehen als
Kommentar im Quelltext, weil sie beide lautlos scheitern:

* **Kekse.** Der erste Schritt setzt Sitzungskekse, der zweite gelingt nur mit
  ihnen. Ohne Keksglas antwortet der Keycloak mit einer neuen Anmeldeseite
  statt mit einer Umleitung — was von außen wie ein falsches Passwort
  aussieht. Deshalb braucht dieser Weg `curl`; der Rückfallweg über
  Datenströme führt keine Kekse.
* **Umleitungen.** Der Code steht in der Kopfzeile `Location`. Wer curl folgen
  lässt, sieht ihn nie.

**Marke und Land bestimmen den Anmeldebereich** (`vaillant-germany-b2c` und so
fort). Ein falsches Land meldet kein falsches Passwort, sondern ein
unbekanntes Konto — deshalb sind beide Listen vollständig hinterlegt und
werden nicht frei eingetippt.

**Der Warmwasserkreis zählt ab 255**, nicht ab 0. Das ist kein Tippfehler und
der erste Verdächtige, wenn die Warmwasser-Aufheizung wirkungslos bleibt.

### Angehoben wird über die Schnellabweichung, nicht über den Sollwert

Für die Zustände 3 und 4 setzt das Plugin eine *Schnellabweichung* (Quick
Veto) mit Laufzeit, keinen Handbetriebs-Sollwert. Der Unterschied ist eine
dritte Sicherung neben den beiden weiter unten: **die Schnellabweichung läuft
von selbst ab.** Bleibt der Miniserver hängen, während gerade angehoben ist,
fällt die Anlage nach der eingestellten Zeit von allein zurück, statt das Haus
unbegrenzt weiterzuheizen. Das Zeitprogramm der Anlage bleibt unangetastet.

### Gesperrt wird nicht

Zustand 1 beendet nur, was das Plugin selbst angehoben hat, und schreibt sonst
**nichts**. Die Anlage ließe sich über diese Schnittstelle zwar ausschalten,
aber *aus* ist keine EVU-Sperre: Frostschutz und Legionellenschaltung laufen
dann nicht in gleicher Weise weiter. Wer wirklich sperren will, nimmt die
beiden Klemmen am Gerät — bei einer aroTHERM plus ist ohnehin der
Multifunktionseingang der vom Hersteller vorgesehene Weg.

### Arbeitszahl

Aus den Energiezählern der Anlage: **abgegebene Wärme geteilt durch
aufgenommenen Strom**, also `HEAT_GENERATED / CONSUMED_ELECTRICAL_ENERGY`.
Genauso rechnet die offizielle Home-Assistant-Anbindung. Der Zeitraum ist
einstellbar — 1 Tag ist die Tagesarbeitszahl, 365 kommt der Jahresarbeitszahl
nahe.

Gerechnet wird höchstens **einmal je Stunde**, weil jede Zählreihe eine eigene
Anfrage kostet und sich Tageswerte ohnehin kaum bewegen. Und: **liefert die
Anlage nur eine der beiden Reihen, wird nichts gerechnet.** Der Grund steht
dann im Reiter Einstellungen im Klartext. Eine erfundene Zahl wäre schlimmer
als keine — sie stünde in Loxone und sähe richtig aus.

> **Nicht gemessen:** ob eine bestimmte Anlage beide Reihen überhaupt
> herausgibt. Das entscheidet das Gerät, nicht das Plugin. Der erste Handgriff
> nach dem Einrichten ist deshalb ein Blick in den Reiter Einstellungen: steht
> dort eine Arbeitszahl, gibt es beide Reihen.

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

## Fassung 0.9.2 — myVAILLANT als vierter Hersteller

Neu: **myVAILLANT** mit Anmeldung, Zustand, SG-Ready-Nachbildung über die
Schnellabweichung und **Arbeitszahl** aus den Energiezählern. Dazu zwei neue
Messwerte für alle Hersteller, sofern sie sie liefern: **Vorlauf-Sollwert**
und **Heizkurve**.

Geprüft wurde ohne Anlage, gegen die echten PHP-Parser 7.4 und 8.2:
Anmeldebereiche für alle fünf Marken, PKCE (128 Zeichen, Prüfwert
nachgerechnet, URL-sicher), das Herauslösen des Codes aus der App-Umleitung
`enduservaillant.page.link://login?code=…` — ein eigenes Schema mit Punkten,
an dem eine naive Zerlegung scheitert —, die Feldzuordnung gegen eine
nachgebaute Antwort, die Erkennung der Zählreihen und die Oberfläche einmal je
Reiter ohne JavaScript. Dass Passwort und Erneuerungsmerkmal in **keiner**
Ausgabe auftauchen, ist eine eigene Prüfzeile.

**Was damit nicht geprüft ist:** alles, was eine Anlage braucht. Ob die
Anmeldung durchgeht, ob die Pfade zu *dieser* Antwort passen und ob die
Zählreihen vorhanden sind, sagt erst das Gerät. Genau dafür führt der Reiter
Test je Feld auf, welcher Kandidat gegriffen hat.

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
