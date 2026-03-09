<?php

/**
 * Zastępstwa API v1
 *
 * Autoryzacja: nagłówek X-API-Key lub parametr ?api_key=
 * Klucz: asiakatalizator
 *
 * Dostępne endpointy:
 *   GET /api/                          - informacje o API
 *   GET /api/stats                     - statystyki systemu
 *   GET /api/teachers                  - lista nauczycieli
 *   GET /api/teachers/{id}             - szczegóły nauczyciela + plan
 *   GET /api/classes                   - lista klas
 *   GET /api/classes/{id}              - szczegóły klasy + plan
 *   GET /api/classrooms                - lista sal
 *   GET /api/substitutions             - zastępstwa (filtry: date, date_from, date_to, class_id, teacher_id, limit, offset)
 *   GET /api/substitutions/today       - dzisiejsze zastępstwa
 *   GET /api/substitutions/upcoming    - nadchodzące zastępstwa (7 dni)
 *   GET /api/plan/class/{id}           - pełny plan klasy (wszystkie dni)
 *   GET /api/plan/teacher/{id}         - pełny plan nauczyciela (wszystkie dni)
 *   GET /api/search?q=                 - szukaj nauczycieli i klas
 */

require_once __DIR__ . '/database.php';

define('API_KEY', 'asiakatalizator');
define('API_VERSION', '1.0.0');
define('APP_NAME', 'Zastępstwa API');

// ── Autoryzacja ──────────────────────────────────────────────────────────────

function checkApiKey(): void {
    $headerKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $queryKey  = $_GET['api_key'] ?? '';
    if ($headerKey !== API_KEY && $queryKey !== API_KEY) {
        apiError('Brak lub nieprawidłowy klucz API. Podaj nagłówek X-API-Key lub parametr ?api_key=', 401);
    }
}

// ── Pomocnicze ───────────────────────────────────────────────────────────────

function apiResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function apiError(string $message, int $code = 400, array $extra = []): void {
    apiResponse(array_merge(['error' => true, 'message' => $message, 'code' => $code], $extra), $code);
}

function paginate(array $items, int $limit, int $offset): array {
    $total = count($items);
    $slice = array_values(array_slice($items, $offset, $limit > 0 ? $limit : null));
    return [
        'total'  => $total,
        'limit'  => $limit > 0 ? $limit : $total,
        'offset' => $offset,
        'count'  => count($slice),
        'data'   => $slice,
    ];
}

function getPaginationParams(): array {
    $limit  = max(0, (int)($_GET['limit']  ?? 0));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    return [$limit, $offset];
}

function getHour(int $lessonNumber): ?array {
    $hours = loadJson('hours');
    foreach ($hours as $h) {
        if ((int)$h['nr'] === $lessonNumber) return $h;
    }
    return null;
}

function dayName(int $day): string {
    return ['Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota', 'Niedziela'][$day - 1] ?? "Dzień $day";
}

function buildSubstitution(array $s, array $teachers, array $classes, array $classrooms): array {
    $ot = findById($teachers, $s['original_teacher_id']);
    $st = findById($teachers, $s['substitute_teacher_id']);
    $cl = findById($classes, $s['class_id']);
    $cr = findById($classrooms, $s['classroom_id']);

    $hour = getHour($s['lesson_number']);

    return [
        'id'             => $s['id'],
        'date'           => $s['date'],
        'lesson_number'  => $s['lesson_number'],
        'lesson_start'   => $hour['start'] ?? ($s['lesson_start_time'] ?? null),
        'lesson_end'     => $hour['end']   ?? ($s['lesson_end_time']   ?? null),
        'subject'        => $s['subject'],
        'cause'          => $s['cause'],
        'note'           => $s['note'] ?: null,
        'has_substitute' => $s['substitute_teacher_id'] !== null,
        'original_teacher' => $ot ? [
            'id'         => $ot['id'],
            'name'       => $ot['name'],
            'short_name' => $ot['short_name'],
        ] : null,
        'substitute_teacher' => $st ? [
            'id'         => $st['id'],
            'name'       => $st['name'],
            'short_name' => $st['short_name'],
        ] : null,
        'class' => $cl ? [
            'id'        => $cl['id'],
            'name'      => $cl['name'],
            'full_name' => $cl['full_name'] ?? $cl['name'],
        ] : null,
        'classroom' => $cr ? [
            'id'   => $cr['id'],
            'name' => $cr['name'],
        ] : null,
    ];
}

function buildLesson(array $l, array $teachers, array $classes, array $classrooms): array {
    $t    = findById($teachers, $l['teacher_id']);
    $cl   = findById($classes, $l['class_id']);
    $cr   = findById($classrooms, $l['classroom_id']);
    $hour = getHour($l['lesson_number']);

    return [
        'id'            => $l['id'],
        'day_of_week'   => $l['day_of_week'],
        'day_name'      => dayName($l['day_of_week']),
        'lesson_number' => $l['lesson_number'],
        'lesson_start'  => $hour['start'] ?? null,
        'lesson_end'    => $hour['end']   ?? null,
        'subject'       => $l['subject'],
        'teacher' => $t ? [
            'id'         => $t['id'],
            'name'       => $t['name'],
            'short_name' => $t['short_name'],
        ] : null,
        'class' => $cl ? [
            'id'        => $cl['id'],
            'name'      => $cl['name'],
            'full_name' => $cl['full_name'] ?? $cl['name'],
        ] : null,
        'classroom' => $cr ? [
            'id'   => $cr['id'],
            'name' => $cr['name'],
        ] : null,
    ];
}

function groupLessonsByDay(array $lessons): array {
    $days = [];
    foreach ($lessons as $l) {
        $d = $l['day_of_week'];
        if (!isset($days[$d])) {
            $days[$d] = ['day' => $d, 'day_name' => dayName($d), 'lessons' => []];
        }
        $days[$d]['lessons'][] = $l;
    }
    ksort($days);
    foreach ($days as &$day) {
        usort($day['lessons'], fn($a, $b) => $a['lesson_number'] <=> $b['lesson_number']);
    }
    return array_values($days);
}

// ── Router ───────────────────────────────────────────────────────────────────

function handleApi(string $path, string $method): void {
    // Usuń /api z początku
    $path = preg_replace('#^/api#', '', $path) ?: '/';

    // GET /api/  →  informacje o API (bez autoryzacji)
    if ($path === '/' || $path === '') {
        apiResponse([
            'name'        => APP_NAME,
            'version'     => API_VERSION,
            'description' => 'API systemu zastępstw szkolnych. Autoryzacja przez nagłówek X-API-Key lub ?api_key=',
            'auth'        => [
                'type'   => 'api_key',
                'header' => 'X-API-Key',
                'param'  => 'api_key',
            ],
            'endpoints' => [
                ['method' => 'GET', 'path' => '/api/',                       'description' => 'Informacje o API'],
                ['method' => 'GET', 'path' => '/api/hours',                  'description' => 'Godziny lekcji (nr, start, end)'],
                ['method' => 'GET', 'path' => '/api/stats',                  'description' => 'Statystyki systemu'],
                ['method' => 'GET', 'path' => '/api/teachers',               'description' => 'Lista wszystkich nauczycieli', 'params' => ['limit', 'offset', 'q (szukaj)']],
                ['method' => 'GET', 'path' => '/api/teachers/{id}',          'description' => 'Szczegóły nauczyciela wraz z planem'],
                ['method' => 'GET', 'path' => '/api/classes',                'description' => 'Lista wszystkich klas', 'params' => ['limit', 'offset', 'q (szukaj)']],
                ['method' => 'GET', 'path' => '/api/classes/{id}',           'description' => 'Szczegóły klasy wraz z planem'],
                ['method' => 'GET', 'path' => '/api/classrooms',             'description' => 'Lista wszystkich sal', 'params' => ['limit', 'offset']],
                ['method' => 'GET', 'path' => '/api/substitutions',          'description' => 'Zastępstwa z filtrami', 'params' => ['date', 'date_from', 'date_to', 'class_id', 'teacher_id', 'limit', 'offset']],
                ['method' => 'GET', 'path' => '/api/substitutions/today',    'description' => 'Dzisiejsze zastępstwa'],
                ['method' => 'GET', 'path' => '/api/substitutions/upcoming', 'description' => 'Zastępstwa na najbliższe 7 dni'],
                ['method' => 'GET', 'path' => '/api/plan/class/{id}',        'description' => 'Pełny tygodniowy plan klasy (wszystkie dni)'],
                ['method' => 'GET', 'path' => '/api/plan/teacher/{id}',      'description' => 'Pełny tygodniowy plan nauczyciela (wszystkie dni)'],
                ['method' => 'GET', 'path' => '/api/search',                 'description' => 'Szukaj nauczycieli i klas', 'params' => ['q (wymagane)']],
            ],
        ]);
    }

    // Wszystkie dalsze endpointy wymagają autoryzacji
    checkApiKey();

    // ── GET /api/hours ──────────────────────────────────────────────────────
    if ($path === '/hours' && $method === 'GET') {
        $hours = loadJson('hours');
        usort($hours, fn($a, $b) => $a['nr'] <=> $b['nr']);
        apiResponse(['count' => count($hours), 'data' => $hours]);
    }

    // ── GET /api/stats ──────────────────────────────────────────────────────
    if ($path === '/stats' && $method === 'GET') {
        $teachers      = loadJson('teachers');
        $classes       = loadJson('classes');
        $classrooms    = loadJson('classrooms');
        $lessons       = loadJson('lessons');
        $substitutions = loadJson('substitutions');

        $dates = array_column($substitutions, 'date');
        sort($dates);
        $today = date('Y-m-d');
        $todayCount = count(array_filter($substitutions, fn($s) => $s['date'] === $today));

        $causeCount = [];
        foreach ($substitutions as $s) {
            $cause = $s['cause'] ?: 'Brak przyczyny';
            $causeCount[$cause] = ($causeCount[$cause] ?? 0) + 1;
        }
        arsort($causeCount);
        $topCauses = array_slice($causeCount, 0, 5, true);

        apiResponse([
            'counts' => [
                'teachers'      => count($teachers),
                'classes'       => count($classes),
                'classrooms'    => count($classrooms),
                'lessons'       => count($lessons),
                'substitutions' => count($substitutions),
            ],
            'substitutions' => [
                'today'          => $todayCount,
                'earliest_date'  => $dates[0] ?? null,
                'latest_date'    => end($dates) ?: null,
                'top_causes'     => $topCauses,
            ],
            'generated_at' => date('c'),
        ]);
    }

    // ── GET /api/teachers ──────────────────────────────────────────────────
    if ($path === '/teachers' && $method === 'GET') {
        $teachers = loadJson('teachers');
        usort($teachers, fn($a, $b) => strcmp($a['name'], $b['name']));

        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $teachers = array_values(array_filter($teachers, fn($t) =>
                mb_stripos($t['name'], $q) !== false || mb_stripos($t['short_name'], $q) !== false
            ));
        }

        [$limit, $offset] = getPaginationParams();
        apiResponse(paginate($teachers, $limit, $offset));
    }

    // ── GET /api/teachers/{id} ────────────────────────────────────────────
    if (preg_match('#^/teachers/(\d+)$#', $path, $m) && $method === 'GET') {
        $id       = (int)$m[1];
        $teachers = loadJson('teachers');
        $teacher  = findById($teachers, $id);
        if (!$teacher) apiError("Nauczyciel o ID $id nie istnieje", 404);

        $lessons    = loadJson('lessons');
        $classes    = loadJson('classes');
        $classrooms = loadJson('classrooms');

        $myLessons = array_values(array_filter($lessons, fn($l) => $l['teacher_id'] === $id));
        $myLessons = array_map(fn($l) => buildLesson($l, $teachers, $classes, $classrooms), $myLessons);

        $substitutions = loadJson('substitutions');
        $recentSubs = array_filter($substitutions, fn($s) =>
            ($s['original_teacher_id'] === $id || $s['substitute_teacher_id'] === $id)
            && $s['date'] >= date('Y-m-d', strtotime('-30 days'))
        );
        $recentSubs = array_values(array_map(
            fn($s) => buildSubstitution($s, $teachers, $classes, $classrooms),
            $recentSubs
        ));
        usort($recentSubs, fn($a, $b) => [$b['date'], $b['lesson_number']] <=> [$a['date'], $a['lesson_number']]);

        apiResponse([
            'id'         => $teacher['id'],
            'name'       => $teacher['name'],
            'short_name' => $teacher['short_name'],
            'plan'       => groupLessonsByDay($myLessons),
            'plan_flat'  => $myLessons,
            'recent_substitutions' => $recentSubs,
        ]);
    }

    // ── GET /api/classes ──────────────────────────────────────────────────
    if ($path === '/classes' && $method === 'GET') {
        $classes = loadJson('classes');
        usort($classes, fn($a, $b) => strcmp($a['name'], $b['name']));

        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $classes = array_values(array_filter($classes, fn($c) =>
                mb_stripos($c['name'], $q) !== false ||
                mb_stripos($c['full_name'] ?? '', $q) !== false
            ));
        }

        [$limit, $offset] = getPaginationParams();
        apiResponse(paginate($classes, $limit, $offset));
    }

    // ── GET /api/classes/{id} ─────────────────────────────────────────────
    if (preg_match('#^/classes/(\d+)$#', $path, $m) && $method === 'GET') {
        $id      = (int)$m[1];
        $classes = loadJson('classes');
        $class   = findById($classes, $id);
        if (!$class) apiError("Klasa o ID $id nie istnieje", 404);

        $lessons    = loadJson('lessons');
        $teachers   = loadJson('teachers');
        $classrooms = loadJson('classrooms');

        $myLessons = array_values(array_filter($lessons, fn($l) => $l['class_id'] === $id));
        $myLessons = array_map(fn($l) => buildLesson($l, $teachers, $classes, $classrooms), $myLessons);

        $substitutions = loadJson('substitutions');
        $recentSubs = array_filter($substitutions, fn($s) =>
            $s['class_id'] === $id && $s['date'] >= date('Y-m-d', strtotime('-30 days'))
        );
        $recentSubs = array_values(array_map(
            fn($s) => buildSubstitution($s, $teachers, $classes, $classrooms),
            $recentSubs
        ));
        usort($recentSubs, fn($a, $b) => [$b['date'], $b['lesson_number']] <=> [$a['date'], $a['lesson_number']]);

        apiResponse([
            'id'        => $class['id'],
            'name'      => $class['name'],
            'full_name' => $class['full_name'] ?? $class['name'],
            'plan'      => groupLessonsByDay($myLessons),
            'plan_flat' => $myLessons,
            'recent_substitutions' => $recentSubs,
        ]);
    }

    // ── GET /api/classrooms ──────────────────────────────────────────────
    if ($path === '/classrooms' && $method === 'GET') {
        $classrooms = loadJson('classrooms');
        usort($classrooms, fn($a, $b) => strcmp($a['name'], $b['name']));

        [$limit, $offset] = getPaginationParams();
        apiResponse(paginate($classrooms, $limit, $offset));
    }

    // ── GET /api/substitutions ────────────────────────────────────────────
    if ($path === '/substitutions' && $method === 'GET') {
        $substitutions = loadJson('substitutions');
        $teachers   = loadJson('teachers');
        $classes    = loadJson('classes');
        $classrooms = loadJson('classrooms');

        $date      = $_GET['date']      ?? null;
        $dateFrom  = $_GET['date_from'] ?? null;
        $dateTo    = $_GET['date_to']   ?? null;
        $classId   = isset($_GET['class_id'])   ? (int)$_GET['class_id']   : null;
        $teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : null;

        // Jeśli podano samo date, traktuj jako zakres jednego dnia
        if ($date) { $dateFrom = $date; $dateTo = $date; }

        $filtered = array_filter($substitutions, function ($s) use ($dateFrom, $dateTo, $classId, $teacherId) {
            if ($dateFrom && $s['date'] < $dateFrom) return false;
            if ($dateTo   && $s['date'] > $dateTo)   return false;
            if ($classId !== null   && $s['class_id']           !== $classId)   return false;
            if ($teacherId !== null && $s['original_teacher_id'] !== $teacherId
                                    && $s['substitute_teacher_id'] !== $teacherId) return false;
            return true;
        });

        $result = array_values(array_map(
            fn($s) => buildSubstitution($s, $teachers, $classes, $classrooms),
            $filtered
        ));

        usort($result, fn($a, $b) => [$a['date'], $a['lesson_number']] <=> [$b['date'], $b['lesson_number']]);

        [$limit, $offset] = getPaginationParams();
        $paged = paginate($result, $limit, $offset);

        $paged['filters'] = [
            'date'       => $date,
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
            'class_id'   => $classId,
            'teacher_id' => $teacherId,
        ];

        apiResponse($paged);
    }

    // ── GET /api/substitutions/today ──────────────────────────────────────
    if ($path === '/substitutions/today' && $method === 'GET') {
        $today         = date('Y-m-d');
        $substitutions = loadJson('substitutions');
        $teachers      = loadJson('teachers');
        $classes       = loadJson('classes');
        $classrooms    = loadJson('classrooms');

        $result = array_values(array_filter($substitutions, fn($s) => $s['date'] === $today));
        $result = array_map(fn($s) => buildSubstitution($s, $teachers, $classes, $classrooms), $result);
        usort($result, fn($a, $b) => $a['lesson_number'] <=> $b['lesson_number']);

        apiResponse([
            'date'  => $today,
            'count' => count($result),
            'data'  => array_values($result),
        ]);
    }

    // ── GET /api/substitutions/upcoming ──────────────────────────────────
    if ($path === '/substitutions/upcoming' && $method === 'GET') {
        $days = max(1, min(30, (int)($_GET['days'] ?? 7)));
        $from = date('Y-m-d');
        $to   = date('Y-m-d', strtotime("+{$days} days"));

        $substitutions = loadJson('substitutions');
        $teachers      = loadJson('teachers');
        $classes       = loadJson('classes');
        $classrooms    = loadJson('classrooms');

        $result = array_values(array_filter($substitutions, fn($s) => $s['date'] >= $from && $s['date'] <= $to));
        $result = array_map(fn($s) => buildSubstitution($s, $teachers, $classes, $classrooms), $result);
        usort($result, fn($a, $b) => [$a['date'], $a['lesson_number']] <=> [$b['date'], $b['lesson_number']]);

        // Grupuj po datach
        $byDate = [];
        foreach ($result as $s) {
            $d = $s['date'];
            if (!isset($byDate[$d])) $byDate[$d] = ['date' => $d, 'count' => 0, 'substitutions' => []];
            $byDate[$d]['substitutions'][] = $s;
            $byDate[$d]['count']++;
        }

        apiResponse([
            'from'        => $from,
            'to'          => $to,
            'days'        => $days,
            'total_count' => count($result),
            'by_date'     => array_values($byDate),
        ]);
    }

    // ── GET /api/plan/class/{id} ──────────────────────────────────────────
    if (preg_match('#^/plan/class/(\d+)$#', $path, $m) && $method === 'GET') {
        $id      = (int)$m[1];
        $classes = loadJson('classes');
        $class   = findById($classes, $id);
        if (!$class) apiError("Klasa o ID $id nie istnieje", 404);

        $lessons    = loadJson('lessons');
        $teachers   = loadJson('teachers');
        $classrooms = loadJson('classrooms');

        $myLessons = array_values(array_filter($lessons, fn($l) => $l['class_id'] === $id));
        $myLessons = array_map(fn($l) => buildLesson($l, $teachers, $classes, $classrooms), $myLessons);

        apiResponse([
            'class'      => ['id' => $class['id'], 'name' => $class['name'], 'full_name' => $class['full_name'] ?? $class['name']],
            'week_plan'  => groupLessonsByDay($myLessons),
            'total_lessons' => count($myLessons),
        ]);
    }

    // ── GET /api/plan/teacher/{id} ────────────────────────────────────────
    if (preg_match('#^/plan/teacher/(\d+)$#', $path, $m) && $method === 'GET') {
        $id       = (int)$m[1];
        $teachers = loadJson('teachers');
        $teacher  = findById($teachers, $id);
        if (!$teacher) apiError("Nauczyciel o ID $id nie istnieje", 404);

        $lessons    = loadJson('lessons');
        $classes    = loadJson('classes');
        $classrooms = loadJson('classrooms');

        $myLessons = array_values(array_filter($lessons, fn($l) => $l['teacher_id'] === $id));
        $myLessons = array_map(fn($l) => buildLesson($l, $teachers, $classes, $classrooms), $myLessons);

        apiResponse([
            'teacher'       => ['id' => $teacher['id'], 'name' => $teacher['name'], 'short_name' => $teacher['short_name']],
            'week_plan'     => groupLessonsByDay($myLessons),
            'total_lessons' => count($myLessons),
        ]);
    }

    // ── GET /api/search ───────────────────────────────────────────────────
    if ($path === '/search' && $method === 'GET') {
        $q = trim($_GET['q'] ?? '');
        if ($q === '') apiError('Parametr ?q= jest wymagany', 400);
        if (mb_strlen($q) < 2) apiError('Fraza musi mieć co najmniej 2 znaki', 400);

        $teachers = loadJson('teachers');
        $classes  = loadJson('classes');

        $foundTeachers = array_values(array_filter($teachers, fn($t) =>
            mb_stripos($t['name'], $q) !== false || mb_stripos($t['short_name'], $q) !== false
        ));
        $foundClasses = array_values(array_filter($classes, fn($c) =>
            mb_stripos($c['name'], $q) !== false || mb_stripos($c['full_name'] ?? '', $q) !== false
        ));

        apiResponse([
            'query'    => $q,
            'teachers' => $foundTeachers,
            'classes'  => $foundClasses,
            'total'    => count($foundTeachers) + count($foundClasses),
        ]);
    }

    // ── 404 ───────────────────────────────────────────────────────────────
    apiError("Endpoint '$path' nie istnieje. Sprawdź GET /api/ po listę dostępnych endpointów.", 404);
}
