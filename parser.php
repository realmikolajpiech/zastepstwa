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

                $lessonStartTime = null;
                $lessonEndTime = null;
                if (isset($lessonParts[1])) {
                    $timeStr = trim($lessonParts[1]);
                    $timeParts = explode('-', $timeStr);
                    if (count($timeParts) === 2) {
                        $lessonStartTime = trim($timeParts[0]);
                        $lessonEndTime = trim($timeParts[1]);
                    }
                }

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
                    'lesson_start_time' => $lessonStartTime,
                    'lesson_end_time' => $lessonEndTime,
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

    // Build name maps from lista.html
    $classNameMap = [];   // "o36.html" => "4TMe 4technik mechatronik"
    $teacherNameMap = []; // "n9.html"  => ["name" => "A.Skoczek (SK)", "short" => "SK"]

    foreach ($htmlFiles as $filename => $content) {
        if (basename($filename) === 'lista.html') {
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            foreach ($dom->getElementsByTagName('a') as $link) {
                $base = basename($link->getAttribute('href'));
                $text = trim($link->textContent);
                if (preg_match('/^o\d+\.html$/i', $base)) {
                    $classNameMap[$base] = $text;
                } elseif (preg_match('/^n\d+\.html$/i', $base)) {
                    $short = '';
                    if (preg_match('/\((\w+)\)\s*$/', $text, $m)) {
                        $short = $m[1];
                    }
                    $teacherNameMap[$base] = ['name' => $text, 'short' => $short];
                }
            }
            break;
        }
    }

    // Extract lesson hours from any class plan file and save to hours.json
    $hoursExtracted = false;
    foreach ($htmlFiles as $filename => $contents) {
        $base = basename($filename);
        if (!preg_match('/^o\d+\.html$/i', $base)) continue;

        $domH = new DOMDocument();
        @$domH->loadHTML('<?xml encoding="UTF-8">' . $contents, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpathH = new DOMXPath($domH);
        $tables = $xpathH->query('//table[@class="tabela"]');
        if ($tables->length === 0) continue;
        $planTable = $tables->item(0);
        $rows = $planTable->getElementsByTagName('tr');
        $hours = [];
        for ($ri = 1; $ri < $rows->length; $ri++) {
            $row = $rows->item($ri);
            $cells = [];
            foreach ($row->childNodes as $child) {
                if ($child->nodeName === 'td' || $child->nodeName === 'th') {
                    $cells[] = $child;
                }
            }
            if (count($cells) < 2) continue;
            $nrText = trim($cells[0]->textContent);
            preg_match('/\d+/', $nrText, $nm);
            if (!isset($nm[0])) continue;
            $nr = (int)$nm[0];
            $godzText = trim($cells[1]->textContent);
            // Format: " 7:15- 8:00"
            if (preg_match('/(\d+:\d+)\s*-\s*(\d+:\d+)/', $godzText, $tm)) {
                $hours[] = ['nr' => $nr, 'start' => $tm[1], 'end' => $tm[2]];
            }
        }
        if (!empty($hours)) {
            saveJson('hours', $hours);
            $hoursExtracted = true;
            break;
        }
    }

    // Process only class plan files (o*.html)
    foreach ($htmlFiles as $filename => $contents) {
        $base = basename($filename);
        if (!preg_match('/^o\d+\.html$/i', $base)) continue;

        try {
            // Resolve class name
            $fullClassName = $classNameMap[$base] ?? null;
            if (!$fullClassName) {
                $dom = new DOMDocument();
                @$dom->loadHTML('<?xml encoding="UTF-8">' . $contents, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                $titles = $dom->getElementsByTagName('title');
                if ($titles->length > 0 && preg_match('/[-–]\s*(\S+)/', $titles->item(0)->textContent, $m)) {
                    $fullClassName = $m[1];
                }
            }
            if (!$fullClassName) continue;

            // Use short class name (first word) for consistency with substitutions
            $className = explode(' ', $fullClassName)[0];
            [$classId] = getOrCreateClass($className);

            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $contents, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new DOMXPath($dom);

            // Find timetable (table.tabela)
            $tables = $xpath->query('//table[@class="tabela"]');
            if ($tables->length === 0) continue;
            $planTable = $tables->item(0);

            $rows = $planTable->getElementsByTagName('tr');

            for ($ri = 1; $ri < $rows->length; $ri++) {
                $row = $rows->item($ri);

                $cells = [];
                foreach ($row->childNodes as $child) {
                    if ($child->nodeName === 'td' || $child->nodeName === 'th') {
                        $cells[] = $child;
                    }
                }

                // Columns: 0=Nr, 1=Godz, 2=Mon, 3=Tue, 4=Wed, 5=Thu, 6=Fri
                if (count($cells) < 7) continue;

                $lessonNumText = trim($cells[0]->textContent);
                preg_match('/\d+/', $lessonNumText, $nm);
                if (!isset($nm[0])) continue;
                $lessonNum = (int)$nm[0];
                if ($lessonNum <= 0) continue;

                for ($di = 0; $di < 5; $di++) {
                    $dayOfWeek = $di + 1;
                    $cell = $cells[$di + 2];

                    // Each <span class="p"> is one lesson entry
                    foreach ($xpath->query('.//span[@class="p"]', $cell) as $subjectSpan) {
                        $subject = trim($subjectSpan->textContent);
                        if (!$subject) continue;

                        // Teacher and classroom are siblings within the same container
                        $container = $subjectSpan->parentNode;
                        $teacherNodes = $xpath->query('.//a[@class="n"]', $container);
                        $classroomNodes = $xpath->query('.//a[@class="s"]', $container);

                        if ($teacherNodes->length === 0) continue;

                        $teacherAnchor = $teacherNodes->item(0);
                        $teacherShort = trim($teacherAnchor->textContent);
                        $teacherFile = basename($teacherAnchor->getAttribute('href'));

                        $classroomName = $classroomNodes->length > 0
                            ? trim($classroomNodes->item(0)->textContent)
                            : '';

                        // Resolve full teacher name from lista map
                        $teacherName = $teacherShort;
                        $teacherShortName = $teacherShort;
                        if ($teacherFile && isset($teacherNameMap[$teacherFile])) {
                            $teacherName = $teacherNameMap[$teacherFile]['name'];
                            $teacherShortName = $teacherNameMap[$teacherFile]['short'] ?: $teacherShort;
                        }

                        [$teacherId] = getOrCreateTeacher($teacherName, $teacherShortName);

                        $classroomId = null;
                        if ($classroomName !== '' && $classroomName !== '-') {
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
                    }
                }
            }
        } catch (Exception $e) {
            continue;
        }
    }

    saveJson('lessons', $lessons);
    return $count;
}
