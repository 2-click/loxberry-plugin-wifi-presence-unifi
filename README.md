# LoxBerry Plugin für UniFi Presence
![Icon](https://github.com/2-click/loxberry-plugin-wifi-presence-unifi/blob/main/icons/icon_256.png)

Dieses Loxberry Plugin ruft Verbindungsdaten zu WLAN-Geräten über die UniFi API ab ab und stellt sie über das Loxberry MQTT Plugin dem Miniserver zur Verfügung.

Das vorhandene Plugin von Ronald Marske benötigt den Node Express Server, welches wiederum unter Debian 12 nicht mehr funktioniert. Da weder das Plugin für den Express Server noch für die UniFi Integration mehr gewartet werden, habe ich die Entwicklung dieses Plugins gestartet. Ich werde es simpel halten, sodass es möglichst wenig Abhängigkeiten gibt. So erhoffe ich mir, dass es lange störungsfrei funktionieren wird. 


Dieses Plugin arbeitet mit einem Intervall. Es ruft alle 60 Sekunden die verbundenen Geräte per API bei einem UniFi Controller ab. So entsteht zwar ein Zeitversatz und auch die Performance könnte besser sein, aber auf der anderen Seite ist das Plugin so besonders robust, leichtgewichtig und zukunftssicher. 

