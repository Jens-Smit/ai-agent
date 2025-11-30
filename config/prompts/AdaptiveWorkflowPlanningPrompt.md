# Adaptive Workflow Planning Prompt

## 🎯 Ziel
Erstelle **selbst-heilende Workflows** die sich automatisch an fehlende Daten und Fehler anpassen.

## 🔄 Kern-Konzepte

### 1. Smart Retry mit Varianten-Generation

Statt fixer Retry-Steps: **Generiere Suchvarianten vorher und iteriere**

```json
{
  "steps": [
    {
      "type": "analysis",
      "description": "Extrahiere Job-Parameter aus Lebenslauf",
      "output_format": {
        "type": "object",
        "fields": {
          "job_title": "string",
          "job_location": "string",
          "skills": "array"
        }
      }
    },
    {
      "type": "tool_call",
      "tool": "generate_search_variants",
      "description": "Generiere Smart-Search-Varianten (Jobtitel-Fallbacks + Radius-Eskalation)",
      "parameters": {
        "base_title": "{{step_4.result.job_title}}",
        "base_location": "{{step_4.result.job_location}}",
        "skills": "{{step_4.result.skills}}"
      }
    },
    {
      "type": "tool_call",
      "tool": "job_search",
      "description": "Versuch 1: Suche mit erster Variante",
      "parameters": {
        "what": "{{search_variants_list[0].what}}",
        "where": "{{search_variants_list[0].where}}",
        "radius": "{{search_variants_list[0].radius}}"
      }
    },
    {
      "type": "decision",
      "description": "Prüfe Suchergebnis - ist Qualität gut genug oder retry?",
      "output_format": {
        "type": "object",
        "fields": {
          "has_results": "boolean",
          "quality_score": "integer",
          "should_retry": "boolean",
          "next_variant_index": "integer"
        }
      }
    },
    {
      "type": "tool_call",
      "tool": "job_search",
      "description": "Versuch 2: Falls nötig mit nächster Variante",
      "skip_if": "{{step_7.result.should_retry}} == false",
      "parameters": {
        "what": "{{search_variants_list[{{step_7.result.next_variant_index}}].what}}",
        "where": "{{search_variants_list[{{step_7.result.next_variant_index}}].where}}",
        "radius": "{{search_variants_list[{{step_7.result.next_variant_index}}].radius}}"
      }
    }
  ]
}
```

### 2. Platzhalter-System

**Unterstützte Patterns:**

- **Nested Access:** `{{step_5.result.jobs[0].company}}`
- **Array-Index:** `{{search_variants_list[0].what}}`
- **Fallback-Chain:** `{{step_3.result.resume_id||step_2.result.doc_id||"default"}}`
- **Conditional Skip:** `skip_if: "{{step_X.result.has_results}} == true"`

### 3. Context-Struktur

Executor baut folgenden Context auf:

```json
{
  "step_1": {
    "result": { "documents": [...] }
  },
  "step_2": {
    "result": { "resume_id": "3" }
  },
  "search_variants_list": [
    {
      "strategy": "title_location_radius",
      "priority": 0,
      "what": "Geschäftsführer",
      "where": "Sereetz",
      "radius": 0,
      "description": "Geschäftsführer in Sereetz"
    },
    {
      "strategy": "title_location_radius",
      "priority": 1,
      "what": "Geschäftsführer",
      "where": "Sereetz",
      "radius": 10,
      "description": "Geschäftsführer in Sereetz (+10km)"
    },
    {
      "strategy": "title_location_radius",
      "priority": 10,
      "what": "Niederlassungsleiter",
      "where": "Sereetz",
      "radius": 0,
      "description": "Niederlassungsleiter in Sereetz"
    }
  ],
  "search_variants_count": 15
}
```

## 📋 Workflow-Templates

### Template A: Job-Suche mit Smart Retry

```json
{
  "steps": [
    // 1. Dokumente listen
    {
      "type": "tool_call",
      "tool": "user_document_list",
      "description": "Liste ALLE Dokumente",
      "parameters": {}
    },
    
    // 2. Lebenslauf identifizieren
    {
      "type": "analysis",
      "description": "Identifiziere Lebenslauf aus Liste",
      "output_format": {
        "type": "object",
        "fields": {
          "resume_id": "string",
          "confidence": "string"
        }
      }
    },
    
    // 3. Lebenslauf lesen (mit Fallback)
    {
      "type": "tool_call",
      "tool": "user_document_read",
      "description": "Lese Lebenslauf",
      "parameters": {
        "identifier": "{{step_2.result.resume_id}}"
      }
    },
    
    // 4. KRITISCH: Parameter extrahieren mit strengem Format
    {
      "type": "analysis",
      "description": "Extrahiere Job-Parameter - GIB KONKRETE WERTE ZURÜCK",
      "output_format": {
        "type": "object",
        "fields": {
          "job_title": "string",
          "job_location": "string",
          "skills": "array",
          "experience_years": "string"
        }
      },
      "validation": {
        "required": ["job_title", "job_location"],
        "min_length": {
          "job_title": 3,
          "job_location": 3
        }
      }
    },
    
    // 5. Generiere Suchvarianten
    {
      "type": "tool_call",
      "tool": "generate_search_variants",
      "description": "Generiere intelligente Suchvarianten",
      "parameters": {
        "base_title": "{{step_4.result.job_title}}",
        "base_location": "{{step_4.result.job_location}}",
        "skills": "{{step_4.result.skills}}"
      }
    },
    
    // 6-10: Iterative Job-Suche
    {
      "type": "tool_call",
      "tool": "job_search",
      "description": "Job-Suche Versuch 1",
      "parameters": {
        "what": "{{search_variants_list[0].what}}",
        "where": "{{search_variants_list[0].where}}"
      }
    },
    {
      "type": "decision",
      "description": "Evaluiere Ergebnis Versuch 1",
      "output_format": {
        "type": "object",
        "fields": {
          "has_results": "boolean",
          "quality_score": "integer",
          "should_retry": "boolean"
        }
      }
    },
    {
      "type": "tool_call",
      "tool": "job_search",
      "description": "Job-Suche Versuch 2 (falls nötig)",
      "skip_if": "{{step_7.result.should_retry}} == false",
      "parameters": {
        "what": "{{search_variants_list[1].what}}",
        "where": "{{search_variants_list[1].where}}"
      }
    },
    {
      "type": "decision",
      "description": "Wähle beste Ergebnisse aus allen Versuchen",
      "output_format": {
        "type": "object",
        "fields": {
          "final_job_title": "string",
          "final_company": "string",
          "final_job_url": "string"
        }
      }
    }
  ]
}
```

## 🔧 Tool: generate_search_variants

Wird vom Executor erkannt und ruft `SmartJobSearchStrategy` auf:

**Input:**
```json
{
  "base_title": "Geschäftsführer",
  "base_location": "Sereetz",
  "skills": ["Personalführung", "PHP", "Marketing"]
}
```

**Output in Context:**
```json
{
  "search_variants_list": [
    {"what": "Geschäftsführer", "where": "Sereetz", "radius": 0, "priority": 0},
    {"what": "Geschäftsführer", "where": "Sereetz", "radius": 10, "priority": 1},
    {"what": "Geschäftsführer", "where": "Sereetz", "radius": 20, "priority": 2},
    {"what": "Niederlassungsleiter", "where": "Sereetz", "radius": 0, "priority": 10},
    {"what": "Betriebsleiter", "where": "Sereetz", "radius": 0, "priority": 20},
    {"what": "Personalführung", "where": "Sereetz", "radius": 0, "priority": 100}
  ]
}
```

## ✅ Validation Rules

Agent MUSS diese Rules prüfen:

1. **Keine leeren Parameter-Extraktion**
   - Wenn analysis `job_title: ""` liefert → FEHLER
   - Agent muss aus Dokumenten konkrete Werte extrahieren

2. **Platzhalter müssen auflösbar sein**
   - Vor Tool-Call: Prüfe ob alle `{{...}}` auflösbar
   - Falls nicht: Füge Fallback-Step ein

3. **Decision-Steps müssen boolean flags liefern**
   - `has_results`, `should_retry` PFLICHT
   - Keine Text-Antworten ohne Struktur

## 🚨 Error Recovery

Bei leeren Extraktionen:

```json
{
  "type": "analysis",
  "description": "FALLBACK: Extrahiere aus GESAMTEM Lebenslauf-Text konkrete Jobtitel",
  "force_concrete_extraction": true,
  "output_format": {
    "type": "object",
    "fields": {
      "most_recent_job_title": "string",
      "most_recent_employer": "string",
      "work_location": "string"
    }
  }
}
```

## 💡 Best Practices

1. **Varianten VOR Loop generieren** - Nicht in jedem Retry neu berechnen
2. **Quality-Score nutzen** - Nicht nur `job_count > 0`
3. **Max 5 Retry-Versuche** - Dann beste auswählen
4. **Skip-Logic verwenden** - Spart unnötige Tool-Calls
5. **Fallback-Chains** - `{{step_3.result||step_2.result||"fallback"}}`
