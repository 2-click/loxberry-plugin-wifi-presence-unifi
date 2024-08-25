# LoxBerry Plugin für UniFi Presence

Dieses Loxberry Plugin ruft Verbindungsdaten zu WLAN-Geräten über die UniFi API ab ab und stellt sie über das Loxberry MQTT Plugin dem Miniserver zur Verfügung.

Das vorhandene Plugin von Ronald Marske benötigt den Node Express Server, welches wiederum unter Debian 12 nicht mehr funktioniert. Da weder das Plugin für den Express Server noch für die UniFi Integration mehr gewartet werden, habe ich die Entwicklung dieses Plugins gestartet. Ich werde es simpel halten, sodass es möglichst wenig Abhängigkeiten gibt. So erhoffe ich mir, dass es lange störungsfrei funktionieren wird. 

