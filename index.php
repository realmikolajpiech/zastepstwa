<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/api.php';

initDatabase();

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Strip subfolder prefix so routing works from /zastepstwa_new/
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath && str_starts_with($requestUri, $basePath)) {
    $requestUri = substr($requestUri, strlen($basePath)) ?: '/';
}
$method = $_SERVER['REQUEST_METHOD'];

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: *');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Deleguj wszystkie /api/... do API
if (str_starts_with($requestUri, '/api')) {
    handleApi($requestUri, $method);
}

function jsonResponse($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function serveStaticFile(string $file): void {
    $path = __DIR__ . '/static/' . $file;
    if (!file_exists($path)) {
        http_response_code(404);
        echo "Not found";
        exit;
    }
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $mimeTypes = ['html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json'];
    header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream') . '; charset=utf-8');
    readfile($path);
    exit;
}

// Static file routing
if ($requestUri === '/' || $requestUri === '/index.html') {
    serveStaticFile('index.html');
}
if (preg_match('/^\/(upload|substitutions|plan|api-docs)\.html$/', $requestUri, $m)) {
    serveStaticFile($m[0]);
}
if ($requestUri === '/api/docs') {
    serveStaticFile('api-docs.html');
}

// POST /upload/
if ($requestUri === '/upload/' && $method === 'POST') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['detail' => 'No file uploaded'], 400);
    }

    $filename = $_FILES['file']['name'];
    $tmpPath = $_FILES['file']['tmp_name'];
    $contents = file_get_contents($tmpPath);

    if (preg_match('/\.(html|htm)$/i', $filename)) {
        try {
            $count = parseHtmlAndSave($contents);
            jsonResponse(['message' => "Successfully processed {$count} substitutions from HTML"]);
        } catch (Exception $e) {
            jsonResponse(['detail' => $e->getMessage()], 500);
        }
    } elseif (preg_match('/\.xml$/i', $filename)) {
        try {
            $count = parsePlanXmlAndSave($contents);
            jsonResponse(['message' => "Successfully processed {$count} lessons from XML"]);
        } catch (Exception $e) {
            jsonResponse(['detail' => $e->getMessage()], 500);
        }
    } elseif (preg_match('/\.zip$/i', $filename)) {
        try {
            $zip = new ZipArchive();

            if ($zip->open($tmpPath) !== true) {
                jsonResponse(['detail' => 'Failed to open ZIP file'], 400);
            }

            $htmlFiles = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if (preg_match('/\.(html|htm)$/i', $entryName) && stripos($entryName, 'index') === false) {
                    $htmlFiles[$entryName] = $zip->getFromIndex($i);
                }
            }
            $zip->close();

            $count = parsePlanHtmlFolderAndSave($htmlFiles);
            jsonResponse(['message' => "Successfully processed {$count} lessons from ZIP archive"]);
        } catch (Exception $e) {
            jsonResponse(['detail' => "Error processing ZIP: " . $e->getMessage()], 500);
        }
    } elseif (preg_match('/\.pla$/i', $filename)) {
        jsonResponse(['detail' => 'Format .pla jest binarny i niemożliwy do odczytania bez oprogramowania Optivum. Proszę wyeksportować plan do XML lub HTML (folder) w programie Plan Lekcji.'], 400);
    } else {
        jsonResponse(['detail' => 'Only .html (substitutions), .xml (plan), or .zip (plan HTML folder) files are allowed'], 400);
    }
}

// GET /substitutions/
if ($requestUri === '/substitutions/' && $method === 'GET') {
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;

    if (!$dateFrom || !$dateTo) {
        jsonResponse(['detail' => 'date_from and date_to are required'], 400);
    }

    $substitutions = loadJson('substitutions');
    $teachers = loadJson('teachers');
    $classes = loadJson('classes');
    $classrooms = loadJson('classrooms');

    $result = [];
    foreach ($substitutions as $s) {
        if ($s['date'] >= $dateFrom && $s['date'] <= $dateTo) {
            $ot = findById($teachers, $s['original_teacher_id']);
            $st = findById($teachers, $s['substitute_teacher_id']);
            $cl = findById($classes, $s['class_id']);
            $cr = findById($classrooms, $s['classroom_id']);

            $result[] = [
                'id' => $s['id'],
                'date' => $s['date'],
                'lesson_number' => $s['lesson_number'],
                'subject' => $s['subject'],
                'cause' => $s['cause'],
                'note' => $s['note'],
                'original_teacher_id' => $s['original_teacher_id'],
                'substitute_teacher_id' => $s['substitute_teacher_id'],
                'class_id' => $s['class_id'],
                'classroom_id' => $s['classroom_id'],
                'original_teacher' => $ot,
                'substitute_teacher' => $st,
                'school_class' => $cl,
                'classroom' => $cr,
            ];
        }
    }

    usort($result, function($a, $b) {
        return [$a['date'], $a['lesson_number']] <=> [$b['date'], $b['lesson_number']];
    });

    jsonResponse($result);
}

// GET /plan/classes
if ($requestUri === '/plan/classes' && $method === 'GET') {
    $classes = loadJson('classes');
    usort($classes, fn($a, $b) => strcmp($a['name'], $b['name']));
    jsonResponse($classes);
}

// GET /plan/teachers
if ($requestUri === '/plan/teachers' && $method === 'GET') {
    $teachers = loadJson('teachers');
    usort($teachers, fn($a, $b) => strcmp($a['name'], $b['name']));
    jsonResponse($teachers);
}

// GET /plan/classrooms
if ($requestUri === '/plan/classrooms' && $method === 'GET') {
    $classrooms = loadJson('classrooms');
    usort($classrooms, fn($a, $b) => strcmp($a['name'], $b['name']));
    jsonResponse($classrooms);
}

// GET /plan/class/{id}?day=
if (preg_match('#^/plan/class/(\d+)$#', $requestUri, $matches) && $method === 'GET') {
    $classId = (int)$matches[1];
    $day = (int)($_GET['day'] ?? 0);

    $lessons = loadJson('lessons');
    $teachers = loadJson('teachers');
    $classes = loadJson('classes');
    $classrooms = loadJson('classrooms');

    $result = [];
    foreach ($lessons as $l) {
        if ($l['class_id'] === $classId && $l['day_of_week'] === $day) {
            $result[] = buildLessonResponse($l, $teachers, $classes, $classrooms);
        }
    }

    usort($result, fn($a, $b) => $a['lesson_number'] <=> $b['lesson_number']);
    jsonResponse($result);
}

// GET /plan/teacher/{id}?day=
if (preg_match('#^/plan/teacher/(\d+)$#', $requestUri, $matches) && $method === 'GET') {
    $teacherId = (int)$matches[1];
    $day = (int)($_GET['day'] ?? 0);

    $lessons = loadJson('lessons');
    $teachers = loadJson('teachers');
    $classes = loadJson('classes');
    $classrooms = loadJson('classrooms');

    $result = [];
    foreach ($lessons as $l) {
        if ($l['teacher_id'] === $teacherId && $l['day_of_week'] === $day) {
            $result[] = buildLessonResponse($l, $teachers, $classes, $classrooms);
        }
    }

    usort($result, fn($a, $b) => $a['lesson_number'] <=> $b['lesson_number']);
    jsonResponse($result);
}

// GET /plan/classroom/{id}?day=
if (preg_match('#^/plan/classroom/(\d+)$#', $requestUri, $matches) && $method === 'GET') {
    $classroomId = (int)$matches[1];
    $day = (int)($_GET['day'] ?? 0);

    $lessons = loadJson('lessons');
    $teachers = loadJson('teachers');
    $classes = loadJson('classes');
    $classrooms = loadJson('classrooms');

    $result = [];
    foreach ($lessons as $l) {
        if ($l['classroom_id'] === $classroomId && $l['day_of_week'] === $day) {
            $result[] = buildLessonResponse($l, $teachers, $classes, $classrooms);
        }
    }

    usort($result, fn($a, $b) => $a['lesson_number'] <=> $b['lesson_number']);
    jsonResponse($result);
}

// 404 fallback
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['detail' => 'Not found']);

function buildLessonResponse(array $l, array $teachers, array $classes, array $classrooms): array {
    return [
        'id' => $l['id'],
        'teacher_id' => $l['teacher_id'],
        'class_id' => $l['class_id'],
        'classroom_id' => $l['classroom_id'],
        'subject' => $l['subject'],
        'day_of_week' => $l['day_of_week'],
        'lesson_number' => $l['lesson_number'],
        'teacher' => findById($teachers, $l['teacher_id']),
        'school_class' => findById($classes, $l['class_id']),
        'classroom' => findById($classrooms, $l['classroom_id']),
    ];
}
