<?php

require_once __DIR__ . '/database.php';

function parseHtmlAndSave(string $contents): int {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $contents, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $count = 0;

    $substitutions = loadJson('substitutions');

    $tables = $dom->getElementsByTagName('table');

    foreach ($tables as $table) {
        $rows = $table->getElementsByTagName('tr');
        if ($rows->length < 3) continue;

        $dateHeader = trim($rows->item(0)->textContent);

        $dateStr = null;
        if (strpos($dateHeader, 'Dzień:') !== false) {
            $parts = explode('Dzień:', $dateHeader);
            $afterDay = trim($parts[1] ?? '');
            $beforeParen = explode('(', $afterDay);
            $dateStr = trim($beforeParen[0]);
        } elseif (strpos($dateHeader, 'Okres:') !== false) {
            continue;
        }

        if (!$dateStr) continue;

        $dateObj = DateTime::createFromFormat('d.m.Y', $dateStr);
        if (!$dateObj) continue;
        $dateFormatted = $dateObj->format('Y-m-d');

        for ($i = 2; $i < $rows->length; $i++) {
            $row = $rows->item($i);
            $cells = [];
            foreach ($row->childNodes as $child) {
                if ($child->nodeName === 'td' || $child->nodeName === 'th') {
                    $cells[] = trim($child->textContent);
                }
            }

            if (count($cells) !== 8) continue;

            try {
                $lessonText = $cells[0];
                if (!$lessonText || strpos($lessonText, ',') === false) continue;

                $lessonParts = explode(',', $lessonText);
                $lessonNum = (int)trim($lessonParts[0]);
                if ($lessonNum <= 0) continue;

                $originalTeacherName = trim($cells[1]);
                $classNameRaw = trim($cells[2]);
                $classParts = explode('|', $classNameRaw);
                $className = trim($classParts[0] ?? '');
                $classGroup = isset($classParts[1]) ? trim($classParts[1]) : null;

                $subject = trim($cells[3]);
                $classroomName = trim($cells[4]);
                $zastepcaRaw = trim($cells[5]);
                $cause = trim($cells[6]);
                $note = trim($cells[7]);

                if ($classGroup) {
                    if (preg_match('/\d+(?:\/\d+)?/', $classGroup, $m)) {
                        $groupValue = $m[0];
                        $groupText = "Grupa: {$groupValue}";
                        $note = $note ? "{$note}. {$groupText}" : $groupText;
                    }
                }

                if (!$className || !$subject) continue;

                $originalTeacherName = ($originalTeacherName && !in_array($originalTeacherName, ['-', 'wakat'])) ? $originalTeacherName : null;
                $classroomName = ($classroomName && $classroomName !== '-') ? $classroomName : null;
                $cause = ($cause && $cause !== '-') ? $cause : '';
                $note = ($note && $note !== '-') ? $note : '';

                $statusKeywords = ['uczniowie', 'okienko', 'zastępstwo', 'wakat'];
                $isStatusMessage = false;
                foreach ($statusKeywords as $kw) {
                    if (stripos($zastepcaRaw, $kw) !== false) {
                        $isStatusMessage = true;
                        break;
                    }
                }

                $substituteTeacherName = null;
                if ($zastepcaRaw) {
                    if (!$isStatusMessage) {
                        $substituteTeacherName = $zastepcaRaw;
                    } else {
                        if ($note) {
                            if (stripos($note, $zastepcaRaw) === false) {
                                $note = "{$zastepcaRaw}. {$note}";
                            }
                        } else {
                            $note = $zastepcaRaw;
                        }
                    }
                }

                [$classId] = getOrCreateClass($className);

                $classroomId = null;
                if ($classroomName) {
                    [$classroomId] = getOrCreateClassroom($classroomName);
                }

                $originalTeacherId = null;
                if ($originalTeacherName) {
                    [$originalTeacherId] = getOrCreateTeacher($originalTeacherName);
                }

                $substituteTeacherId = null;
                if ($substituteTeacherName) {
                    [$substituteTeacherId] = getOrCreateTeacher($substituteTeacherName);
                }

                $substitutions[] = [
                    'id' => nextId($substitutions),
                    'date' => $dateFormatted,
                    'lesson_number' => $lessonNum,
                    'original_teacher_id' => $originalTeacherId,
                    'substitute_teacher_id' => $substituteTeacherId,
                    'class_id' => $classId,
                    'classroom_id' => $classroomId,
                    'subject' => $subject,
                    'cause' => $cause,
                    'note' => $note,
                ];
                $count++;
            } catch (Exception $e) {
                continue;
            }
        }
    }

    saveJson('substitutions', $substitutions);
    return $count;
}

function parsePlanXmlAndSave(string $contents): int {
    $xml = @simplexml_load_string($contents);
    if (!$xml) {
        throw new Exception("Error parsing XML");
    }

    $count = 0;
    $lessons = loadJson('lessons');

    foreach ($xml->xpath('.//lesson') as $lessonElem) {
        try {
            $dayOfWeek = (int)($lessonElem['day'] ?? 1);
            $lessonNumber = (int)($lessonElem['hour'] ?? 1);
            $subject = (string)($lessonElem['subject'] ?? '');
            $teacherName = (string)($lessonElem['teacher'] ?? '');
            $className = (string)($lessonElem['class'] ?? '');
            $classroomName = (string)($lessonElem['classroom'] ?? '') ?: null;

            if (!$teacherName || !$className || !$subject) continue;

            [$teacherId] = getOrCreateTeacher($teacherName);
            [$classId] = getOrCreateClass($className);

            $classroomId = null;
            if ($classroomName) {
                [$classroomId] = getOrCreateClassroom($classroomName);
            }

            $lessons[] = [
                'id' => nextId($lessons),
                'teacher_id' => $teacherId,
                'class_id' => $classId,
                'classroom_id' => $classroomId,
                'subject' => $subject,
                'day_of_week' => $dayOfWeek,
                'lesson_number' => $lessonNumber,
            ];
            $count++;
        } catch (Exception $e) {
            continue;
        }
    }

    saveJson('lessons', $lessons);
    return $count;
}

function parsePlanHtmlFolderAndSave(array $htmlFiles): int {
    $count = 0;
    $lessons = loadJson('lessons');
    $dayMap = [
        'poniedziałek' => 1, 'pon' => 1,
        'wtorek' => 2, 'wt' => 2,
        'środa' => 3, 'śr' => 3,
        'czwartek' => 4, 'czw' => 4,
        'piątek' => 5, 'pt' => 5,
    ];

    foreach ($htmlFiles as $filename => $contents) {
        try {
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $contents, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

            $className = strtoupper(preg_replace('/\.(html|htm)$/i', '', basename($filename)));
            [$classId] = getOrCreateClass($className);

            $rows = $dom->getElementsByTagName('tr');
            $first = true;
            foreach ($rows as $row) {
                if ($first) { $first = false; continue; }

                $cells = [];
                foreach ($row->childNodes as $child) {
                    if ($child->nodeName === 'td' || $child->nodeName === 'th') {
                        $cells[] = trim($child->textContent);
                    }
                }

                if (count($cells) < 3) continue;

                try {
                    $lessonNumText = $cells[0];
                    preg_match('/\d+/', $lessonNumText, $m);
                    $lessonNum = isset($m[0]) ? (int)$m[0] : 1;

                    $dayOfWeek = 1;
                    $dayText = mb_strtolower($cells[1] ?? '');
                    foreach ($dayMap as $dayName => $dayNum) {
                        if (mb_strpos($dayText, $dayName) !== false) {
                            $dayOfWeek = $dayNum;
                            break;
                        }
                    }

                    $teacherName = $cells[2] ?? '';
                    $subject = $cells[3] ?? '';
                    $classroomName = ($cells[4] ?? '') ?: null;

                    if (!$teacherName || !$subject) continue;

                    [$teacherId] = getOrCreateTeacher($teacherName);

                    $classroomId = null;
                    if ($classroomName && $classroomName !== '-') {
                        [$classroomId] = getOrCreateClassroom($classroomName);
                    }

                    $lessons[] = [
                        'id' => nextId($lessons),
                        'teacher_id' => $teacherId,
                        'class_id' => $classId,
                        'classroom_id' => $classroomId,
                        'subject' => $subject,
                        'day_of_week' => $dayOfWeek,
                        'lesson_number' => $lessonNum,
                    ];
                    $count++;
                } catch (Exception $e) {
                    continue;
                }
            }
        } catch (Exception $e) {
            continue;
        }
    }

    saveJson('lessons', $lessons);
    return $count;
}
