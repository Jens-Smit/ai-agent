# Frontend Dokumentation (NEU V1.1)
## API Endpunkte
- GET /api/v1/data: Liefert { success: boolean, message: string, timestamp: number } (Geändert)
- GET /api/v1/users: Liefert { success: boolean, users: Array<{ id: number, name: string }> } (Neu)
## Frontend Komponenten
- Es existiert eine Hauptkomponente (App.jsx) und ein Daten-Hook (useData.js).
- Neue Komponente (UserList.jsx) wird benötigt.