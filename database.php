<?php

define('DATA_DIR', __DIR__ . '/data');

function loadJson(string $name): array {
    $path = DATA_DIR . "/{$name}.json";
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function saveJson(string $name, array $data): void {
    $path = DATA_DIR . "/{$name}.json";
    $result = file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($result === false) {
        throw new Exception("Nie można zapisać pliku '{$name}.json'. Sprawdź uprawnienia katalogu: " . DATA_DIR);
    }
}

function initDatabase(): void {
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0777, true)) {
            throw new Exception("Nie można utworzyć katalogu danych: " . DATA_DIR);
        }
    }
    foreach (['teachers', 'classes', 'classrooms', 'lessons', 'substitutions'] as $file) {
        $path = DATA_DIR . "/{$file}.json";
        if (!file_exists($path)) {
            if (file_put_contents($path, '[]') === false) {
                throw new Exception("Nie można zainicjować pliku '{$file}.json'. Sprawdź uprawnienia katalogu: " . DATA_DIR);
            }
        }
    }
}

function nextId(array $items): int {
    if (empty($items)) return 1;
    return max(array_column($items, 'id')) + 1;
}

function getOrCreateTeacher(string $name, ?string $shortName = null): array {
    $teachers = loadJson('teachers');
    foreach ($teachers as $t) {
        if ($t['name'] === $name) return [$t['id'], $teachers];
    }
    $id = nextId($teachers);
    $teacher = ['id' => $id, 'name' => $name, 'short_name' => $shortName ?? mb_substr($name, 0, 10)];
    $teachers[] = $teacher;
    saveJson('teachers', $teachers);
    return [$id, $teachers];
}

function getOrCreateClass(string $name): array {
    $classes = loadJson('classes');
    foreach ($classes as $c) {
        if ($c['name'] === $name) return [$c['id'], $classes];
    }
    $id = nextId($classes);
    $class = ['id' => $id, 'name' => $name];
    $classes[] = $class;
    saveJson('classes', $classes);
    return [$id, $classes];
}

function getOrCreateClassroom(string $name): array {
    $classrooms = loadJson('classrooms');
    foreach ($classrooms as $cr) {
        if ($cr['name'] === $name) return [$cr['id'], $classrooms];
    }
    $id = nextId($classrooms);
    $classroom = ['id' => $id, 'name' => $name];
    $classrooms[] = $classroom;
    saveJson('classrooms', $classrooms);
    return [$id, $classrooms];
}

function findById(array $items, ?int $id): ?array {
    if ($id === null) return null;
    foreach ($items as $item) {
        if ($item['id'] === $id) return $item;
    }
    return null;
}
