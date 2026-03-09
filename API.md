# Zastępstwa API v1.0


Autoryzacja przez nagłówek lub parametr URL:
```
X-API-Key: asiakatalizator
# lub
?api_key=asiakatalizator
```

Base URL: `zstib.edu.pl/plan-lekcji/api`

---

## Endpointy

### `GET /api/`
Informacje o API i lista endpointów. **Nie wymaga klucza.**

---

### `GET /api/stats`
Statystyki systemu.

**Odpowiedź:**
```json
{
  "counts": {
    "teachers": 99,
    "classes": 48,
    "classrooms": 64,
    "lessons": 3654,
    "substitutions": 92
  },
  "substitutions": {
    "today": 3,
    "earliest_date": "2026-03-02",
    "latest_date": "2026-03-06",
    "top_causes": {
      "Zwolnienie lekarskie": 53,
      "Erasmus-opieka": 29
    }
  },
  "generated_at": "2026-03-09T12:00:00+00:00"
}
```

---

### `GET /api/teachers`
Lista wszystkich nauczycieli (posortowana alfabetycznie).

**Parametry:**
| Param | Opis |
|---|---|
| `q` | Szukaj po nazwisku lub skrócie |
| `limit` | Ile wyników (0 = wszystkie) |
| `offset` | Pominięcie N wyników (paginacja) |

**Odpowiedź:**
```json
{
  "total": 99,
  "limit": 10,
  "offset": 0,
  "count": 10,
  "data": [
    { "id": 1, "name": "Aleksiewicz-Drab Marta", "short_name": "Aleksiewic" }
  ]
}
```

---

### `GET /api/teachers/{id}`
Szczegóły nauczyciela wraz z planem tygodniowym i ostatnimi zastępstwami (30 dni).

**Odpowiedź:**
```json
{
  "id": 3,
  "name": "Kowalski Oskar",
  "short_name": "Kowalski O",
  "plan": [
    {
      "day": 1,
      "day_name": "Poniedziałek",
      "lessons": [
        {
          "id": 10,
          "lesson_number": 2,
          "subject": "Matematyka",
          "class": { "id": 5, "name": "3TB", "full_name": "3 technik budownictwa" },
          "classroom": { "id": 12, "name": "104" }
        }
      ]
    }
  ],
  "plan_flat": [ ... ],
  "recent_substitutions": [ ... ]
}
```

---

### `GET /api/classes`
Lista wszystkich klas.

**Parametry:** `q`, `limit`, `offset` (jak w `/api/teachers`)

**Odpowiedź:**
```json
{
  "total": 48,
  "data": [
    { "id": 1, "name": "3TB", "full_name": "3 technik budownictwa" }
  ]
}
```

---

### `GET /api/classes/{id}`
Szczegóły klasy wraz z planem tygodniowym i ostatnimi zastępstwami (30 dni).

Struktura odpowiedzi analogiczna do `GET /api/teachers/{id}`.

---

### `GET /api/classrooms`
Lista wszystkich sal lekcyjnych.

**Parametry:** `limit`, `offset`

---

### `GET /api/substitutions`
Zastępstwa z filtrami.

**Parametry:**
| Param | Opis |
|---|---|
| `date` | Jeden dzień, np. `2026-03-05` |
| `date_from` | Zakres od |
| `date_to` | Zakres do |
| `class_id` | Filtruj po klasie |
| `teacher_id` | Filtruj po nauczycielu (pierwotnym lub zastępującym) |
| `limit` | Paginacja |
| `offset` | Paginacja |

**Przykład:** `GET /api/substitutions?date_from=2026-03-04&date_to=2026-03-06&api_key=asiakatalizator`

**Odpowiedź:**
```json
{
  "total": 18,
  "count": 18,
  "data": [
    {
      "id": 5,
      "date": "2026-03-04",
      "lesson_number": 2,
      "subject": "Fizyka",
      "cause": "Zwolnienie lekarskie",
      "note": null,
      "has_substitute": true,
      "original_teacher": { "id": 6, "name": "Obal Krzysztof", "short_name": "Obal Krzys" },
      "substitute_teacher": { "id": 3, "name": "Kowalski Oskar", "short_name": "Kowalski O" },
      "class": { "id": 1, "name": "3TB", "full_name": "3 technik budownictwa" },
      "classroom": { "id": 4, "name": "105" }
    }
  ],
  "filters": {
    "date_from": "2026-03-04",
    "date_to": "2026-03-06",
    "class_id": null,
    "teacher_id": null
  }
}
```

---

### `GET /api/substitutions/today`
Skrót — zastępstwa na dzisiaj.

**Odpowiedź:**
```json
{
  "date": "2026-03-09",
  "count": 3,
  "data": [ ... ]
}
```

---

### `GET /api/substitutions/upcoming`
Zastępstwa na najbliższe dni, pogrupowane po datach.

**Parametry:**
| Param | Opis | Domyślnie |
|---|---|---|
| `days` | Liczba dni (1–30) | `7` |

**Odpowiedź:**
```json
{
  "from": "2026-03-09",
  "to": "2026-03-16",
  "days": 7,
  "total_count": 20,
  "by_date": [
    {
      "date": "2026-03-10",
      "count": 8,
      "substitutions": [ ... ]
    }
  ]
}
```

---

### `GET /api/plan/class/{id}`
Pełny tygodniowy plan klasy (wszystkie dni naraz).

**Odpowiedź:**
```json
{
  "class": { "id": 1, "name": "3TB", "full_name": "3 technik budownictwa" },
  "total_lessons": 30,
  "week_plan": [
    {
      "day": 1,
      "day_name": "Poniedziałek",
      "lessons": [
        {
          "lesson_number": 1,
          "subject": "Matematyka",
          "teacher": { "id": 3, "name": "Kowalski Oskar" },
          "classroom": { "id": 12, "name": "104" }
        }
      ]
    }
  ]
}
```

---

### `GET /api/plan/teacher/{id}`
Pełny tygodniowy plan nauczyciela. Struktura analogiczna do `/api/plan/class/{id}`.

---

### `GET /api/search?q=`
Szukaj jednocześnie po nauczycielach i klasach. Minimalna fraza: 2 znaki.

**Przykład:** `GET /api/search?q=Kowal&api_key=asiakatalizator`

**Odpowiedź:**
```json
{
  "query": "Kowal",
  "teachers": [
    { "id": 3, "name": "Kowalski Oskar", "short_name": "Kowalski O" }
  ],
  "classes": [],
  "total": 1
}
```

---

## Błędy

| Kod | Znaczenie |
|---|---|
| `400` | Błędne parametry |
| `401` | Brak lub nieprawidłowy klucz API |
| `404` | Zasób nie istnieje |

```json
{
  "error": true,
  "message": "Brak lub nieprawidłowy klucz API.",
  "code": 401
}
```
