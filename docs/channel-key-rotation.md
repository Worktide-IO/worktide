# Channel Credential Key Rotation

## Problem

Channel `authConfig` (OAuth-Tokens, IMAP-Passwörter, API-Keys) wird mit
`APP_SECRET` als Schlüsselmaterial via BLAKE2b + SecretBox (libsodium)
verschlüsselt in der Datenbank gespeichert (`Channel::authConfig`).

Wird `APP_SECRET` rotiert (z.B. Security-Incident, Deployment-Pipeline-Wechsel,
reguläre Rotation), sind **alle gespeicherten Credentials unlesbar** — alle
Channel-Adapter verlieren ihre Verbindung. Es gibt keine Re-Encryption-Funktion.

## Anforderung

Ein Mechanismus, der bei APP_SECRET-Rotation die gespeicherten Credentials
automatisch oder manuell neu verschlüsseln kann, ohne dass der Admin jeden
Channel manuell neu konfigurieren muss.

---

## Lösung: Envelope Encryption mit separatem Key-Encryption-Key (KEK)

Statt `APP_SECRET` direkt als Verschlüsselungsschlüssel zu verwenden, führen
wir einen **dedizierten Data-Encryption-Key (DEK)** ein, der pro Workspace
(oder global) existiert und mit dem aktuellen `APP_SECRET` (KEK) verschlüsselt
in der Datenbank gespeichert wird.

### Datenmodell

```sql
CREATE TABLE encryption_keys (
    id          BINARY(16) NOT NULL PRIMARY KEY,  -- UUIDv7
    workspace   BINARY(16) DEFAULT NULL,           -- NULL = global fallback
    created_at  DATETIME(6) NOT NULL,
    -- Der DEK, mit dem aktuellen KEK (APP_SECRET) verschlüsselt (SecretBox)
    encrypted_dek   BLOB NOT NULL,
    -- Der KEK-Version (z.B. SHA256 von APP_SECRET) zum Erkennen ob Rotation nötig
    kek_fingerprint VARCHAR(64) NOT NULL,
    active      BOOLEAN NOT NULL DEFAULT TRUE,
    rotated_at  DATETIME(6) DEFAULT NULL           -- Zeitpunkt der letzten Rotation
) ENGINE=InnoDB;
```

### Ablauf

#### Normalbetrieb (keine Rotation)

1. Beim Start prüft `SecretBox` ob ein aktiver DEK in `encryption_keys` existiert
2. Wenn ja: DEK mit `APP_SECRET` entschlüsseln, im Memory-Cache behalten
3. Wenn nein: neuen DEK generieren, mit `APP_SECRET` verschlüsseln, speichern
4. Alle `authConfig`-Operationen verwenden den DEK, nicht `APP_SECRET`

#### Rotation (APP_SECRET ändert sich)

1. `kek_fingerprint` weicht vom neuen `APP_SECRET` ab → Erkennung
2. Alle Channel-Credentials:
   - Mit altem DEK entschlüsseln
   - Mit **neuem** DEK neu verschlüsseln
   - `kek_fingerprint` updaten
3. **Kein Credential-Verlust**, keine manuelle Channel-Konfiguration nötig

### CLI-Command

```bash
# Status anzeigen: KEK-Fingerprint, Anzahl Channels, letzte Rotation
php bin/console channel:encryption:status

# DEK neu generieren (z.B. nach APP_SECRET-Rotation)
php bin/console channel:encryption:rotate
```

### SecretBox-Änderungen

Aktuelle `SecretBox`-Klasse (`src/Service/Channels/SecretBox.php`):

- `encrypt(array $plaintext): string` — encrypts with DEK
- `decrypt(string $ciphertext): array` — decrypts with DEK
- `rotateKey(string $oldKek, string $newKek): void` — re-encrypts DEK
- `needsRotation(string $kek): bool` — checks fingerprint

### Backward Compatibility

- Existierende `authConfig`-Felder wurden mit `APP_SECRET` direkt verschlüsselt
- Migration: `channel:encryption:rotate` erkennt alte Einträge (kein Envelope),
  entschlüsselt sie mit altem `APP_SECRET` und verschlüsselt mit neuem DEK
- **Empfehlung**: Migration vor der ersten Rotation ausführen

---

## Nicht-Lösungen (verworfen)

| Ansatz | Problem |
|--------|---------|
| `APP_SECRET` direkt rotieren und alle Credentials neu verschlüsseln | Benötigt Zugriff auf den alten `APP_SECRET` zur Entschlüsselung — dieser ist nach Rotation weg |
| Credentials unverschlüsselt speichern | Security-Regression |
| Pro-Workspace `APP_SECRET` in Umgebungsvariablen | Unwartbar bei vielen Workspaces |
| Vault-Lösung (Hashicorp Vault) | Hohe Betriebskomplexität für aktuelle Projektgröße |

---

## Status

**Entwurf** — noch nicht implementiert. Nächste Schritte:
1. Migration für `encryption_keys`-Tabelle erstellen
2. `SecretBox` um Envelope-Logik erweitern
3. CLI-Command implementieren
4. Backward-Compatibility-Migration für bestehende Einträge
