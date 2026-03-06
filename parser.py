"""
Parser module for handling HTML and XML imports of substitutions and schedules.
"""

from bs4 import BeautifulSoup
from xml.etree import ElementTree as ET
from datetime import datetime
import re
import crud, models, schemas
from sqlalchemy.orm import Session


def parse_html_and_save(contents: bytes, db: Session) -> int:
    """
    Parse HTML file containing substitutions and save to database.
    Handles multi-table format with one table per day.
    
    Args:
        contents: Raw HTML file contents
        db: Database session
        
    Returns:
        Number of substitutions processed
    """
    try:
        soup = BeautifulSoup(contents, 'html.parser')
        count = 0
        
        # Find all tables
        tables = soup.find_all('table')
        
        for table in tables:
            rows = table.find_all('tr')
            if len(rows) < 3:
                continue
            
            # First row contains the date info (e.g., "Dzień: 02.03.2026 (pon.)")
            date_header = rows[0].get_text(strip=True)
            
            # Extract date from header like "Dzień: 02.03.2026 (pon.)"
            date_str = None
            if 'Dzień:' in date_header:
                # Format: "Dzień: 02.03.2026 (pon.)"
                parts = date_header.split('Dzień:')[1].strip().split('(')[0].strip()
                date_str = parts
            elif 'Okres:' in date_header:
                # Skip period headers, get date from next table
                continue
            
            if not date_str:
                continue
            
            # Parse date
            try:
                date_obj = datetime.strptime(date_str, "%d.%m.%Y").date()
            except ValueError:
                continue
            
            # Second row contains headers, skip it
            # Data rows start from row 2
            for row in rows[2:]:
                cells = row.find_all(['td', 'th'])
                if len(cells) != 8:
                    continue
                
                try:
                    # Column mapping (exact from HTML):
                    # 0: Lekcja (lesson number with time, e.g., "1, 07:15-08:00")
                    # 1: Nauczyciel/wakat (original teacher - who is absent)
                    # 2: Oddział (class)
                    # 3: Przedmiot (subject)
                    # 4: Sala (classroom)
                    # 5: Zastępca (substitute - can be teacher name or status message)
                    # 6: Powód (cause - reason for absence)
                    # 7: Uwagi (notes - additional info)
                    
                    lesson_text = cells[0].get_text(strip=True) if cells[0] else ""
                    if not lesson_text or ',' not in lesson_text:
                        continue
                    
                    # Extract lesson number from "1, 07:15-08:00"
                    try:
                        lesson_num = int(lesson_text.split(',')[0].strip())
                    except (ValueError, IndexError):
                        continue
                    
                    original_teacher_name = (cells[1].get_text(strip=True) if cells[1] else "").strip()
                    class_name_raw = (cells[2].get_text(strip=True) if cells[2] else "").strip()
                    # Extract main class name and group if exists
                    # E.g., "2bme|2/2" -> class_name="2bme", group="2/2"
                    class_parts = class_name_raw.split('|')
                    class_name = class_parts[0].strip() if class_parts else ""
                    class_group = class_parts[1].strip() if len(class_parts) > 1 else None
                    
                    subject = (cells[3].get_text(strip=True) if cells[3] else "").strip()
                    classroom_name = (cells[4].get_text(strip=True) if cells[4] else "").strip()
                    zastepca_raw = (cells[5].get_text(strip=True) if cells[5] else "").strip()
                    cause = (cells[6].get_text(strip=True) if cells[6] else "").strip()
                    note = (cells[7].get_text(strip=True) if cells[7] else "").strip()
                    
                    # Add class group to notes if it exists
                    if class_group:
                        # Extract only numeric part from group (e.g., "jn1" -> "1", "2/2" -> "2/2")
                        numeric_group = re.search(r'\d+(?:/\d+)?', class_group)
                        if numeric_group:
                            group_text = f"Grupa: {numeric_group.group()}"
                            if note:
                                note = f"{note}. {group_text}"
                            else:
                                note = group_text
                    
                    # Validate required fields
                    if not class_name or not subject:
                        continue
                    
                    # Normalize empty values to None where appropriate
                    original_teacher_name = original_teacher_name if original_teacher_name and original_teacher_name not in ['-', 'wakat'] else None
                    classroom_name = classroom_name if classroom_name and classroom_name not in ['-'] else None
                    cause = cause if cause and cause not in ['-'] else ""
                    note = note if note and note not in ['-'] else ""
                    
                    # Determine if zastępca column contains a real teacher name or a status message
                    status_keywords = ['uczniowie', 'okienko', 'zastępstwo', 'wakat']
                    is_status_message = any(keyword.lower() in zastepca_raw.lower() for keyword in status_keywords)
                    
                    # Extract substitute teacher
                    substitute_teacher_name = None
                    if zastepca_raw:
                        if not is_status_message:
                            # It's a real teacher name
                            substitute_teacher_name = zastepca_raw
                        else:
                            # It's a status message - add to notes
                            if note:
                                if zastepca_raw.lower() not in note.lower():
                                    note = f"{zastepca_raw}. {note}".strip()
                            else:
                                note = zastepca_raw
                    
                    # Get or create school class
                    school_class = crud.get_class_by_name(db, class_name)
                    if not school_class:
                        school_class = crud.create_class(db, schemas.SchoolClassCreate(name=class_name))
                    
                    # Get or create classroom if specified
                    classroom_id = None
                    if classroom_name:
                        classroom = crud.get_classroom_by_name(db, classroom_name)
                        if not classroom:
                            classroom = crud.create_classroom(db, schemas.ClassroomCreate(name=classroom_name))
                        classroom_id = classroom.id
                    
                    # Get or create original teacher (the absent one)
                    original_teacher_id = None
                    if original_teacher_name:
                        original_teacher = crud.get_teacher_by_name(db, original_teacher_name)
                        if not original_teacher:
                            short_name = original_teacher_name[:10]
                            original_teacher = crud.create_teacher(
                                db, 
                                schemas.TeacherCreate(name=original_teacher_name, short_name=short_name)
                            )
                        original_teacher_id = original_teacher.id
                    
                    # Get or create substitute teacher (the replacement)
                    substitute_teacher_id = None
                    if substitute_teacher_name:
                        substitute_teacher = crud.get_teacher_by_name(db, substitute_teacher_name)
                        if not substitute_teacher:
                            short_name = substitute_teacher_name[:10]
                            substitute_teacher = crud.create_teacher(
                                db,
                                schemas.TeacherCreate(name=substitute_teacher_name, short_name=short_name)
                            )
                        substitute_teacher_id = substitute_teacher.id
                    
                    # Create substitution record
                    substitution = schemas.SubstitutionCreate(
                        date=date_obj,
                        lesson_number=lesson_num,
                        original_teacher_id=original_teacher_id,
                        substitute_teacher_id=substitute_teacher_id,
                        class_id=school_class.id,
                        classroom_id=classroom_id,
                        subject=subject,
                        cause=cause,
                        note=note
                    )
                    
                    crud.create_substitution(db, substitution)
                    count += 1
                    
                except Exception as e:
                    continue
        
        return count
        
    except Exception as e:
        raise Exception(f"Error parsing HTML: {str(e)}")


def parse_plan_xml_and_save(contents: bytes, db: Session) -> int:
    """
    Parse XML file containing school schedule and save to database.
    
    Args:
        contents: Raw XML file contents
        db: Database session
        
    Returns:
        Number of lessons processed
    """
    try:
        root = ET.fromstring(contents)
        count = 0
        
        # Common XML structure for Optivum exported plans
        # Adjust XPath based on actual XML structure
        
        # Process lessons/classes
        for lesson_elem in root.findall('.//lesson'):
            try:
                # Extract attributes - adjust based on actual XML structure
                day_of_week = int(lesson_elem.get('day', 1))
                lesson_number = int(lesson_elem.get('hour', 1))
                subject = lesson_elem.get('subject', '')
                
                teacher_name = lesson_elem.get('teacher', '')
                class_name = lesson_elem.get('class', '')
                classroom_name = lesson_elem.get('classroom')
                
                if not (teacher_name and class_name and subject):
                    continue
                
                # Get or create teacher
                teacher = crud.get_teacher_by_name(db, teacher_name)
                if not teacher:
                    short_name = teacher_name[:10]
                    teacher = crud.create_teacher(
                        db,
                        schemas.TeacherCreate(name=teacher_name, short_name=short_name)
                    )
                
                # Get or create school class
                school_class = crud.get_class_by_name(db, class_name)
                if not school_class:
                    school_class = crud.create_class(db, schemas.SchoolClassCreate(name=class_name))
                
                # Get or create classroom
                classroom_id = None
                if classroom_name:
                    classroom = crud.get_classroom_by_name(db, classroom_name)
                    if not classroom:
                        classroom = crud.create_classroom(db, schemas.ClassroomCreate(name=classroom_name))
                    classroom_id = classroom.id
                
                # Create lesson
                lesson = models.Lesson(
                    teacher_id=teacher.id,
                    class_id=school_class.id,
                    classroom_id=classroom_id,
                    subject=subject,
                    day_of_week=day_of_week,
                    lesson_number=lesson_number
                )
                
                db.add(lesson)
                count += 1
                
            except (ValueError, AttributeError) as e:
                continue
        
        db.commit()
        return count
        
    except ET.ParseError as e:
        raise Exception(f"Error parsing XML: {str(e)}")
    except Exception as e:
        raise Exception(f"Error processing XML: {str(e)}")


def parse_plan_html_folder_and_save(html_files: dict, db: Session) -> int:
    """
    Parse HTML files from a folder/ZIP archive containing school schedule.
    
    Args:
        html_files: Dictionary of {filename: file_contents}
        db: Database session
        
    Returns:
        Number of lessons processed
    """
    try:
        count = 0
        
        for filename, contents in html_files.items():
            try:
                soup = BeautifulSoup(contents, 'html.parser')
                
                # Extract class name from filename (e.g., o1.html -> O1)
                class_name = filename.replace('.html', '').replace('.htm', '').upper()
                
                # Get or create school class
                school_class = crud.get_class_by_name(db, class_name)
                if not school_class:
                    school_class = crud.create_class(db, schemas.SchoolClassCreate(name=class_name))
                
                # Find all table rows
                rows = soup.find_all('tr')
                
                for row in rows[1:]:  # Skip header
                    cells = row.find_all(['td', 'th'])
                    if len(cells) < 3:
                        continue
                    
                    try:
                        # Parse columns - adjust based on actual HTML structure
                        # Expected: LessonNumber, Day(or Times), TeacherName, Subject, Classroom
                        
                        lesson_num_text = cells[0].get_text(strip=True)
                        lesson_num = int(''.join(filter(str.isdigit, lesson_num_text)) or 1)
                        
                        # Try to find day information
                        day_of_week = 1  # Default to Monday
                        day_text = cells[1].get_text(strip=True).lower() if len(cells) > 1 else ''
                        
                        # Parse day if it contains Polish day names
                        day_map = {
                            'poniedziałek': 1, 'pon': 1,
                            'wtorek': 2, 'wt': 2,
                            'środa': 3, 'śr': 3,
                            'czwartek': 4, 'czw': 4,
                            'piątek': 5, 'pt': 5
                        }
                        for day_name, day_num in day_map.items():
                            if day_name in day_text:
                                day_of_week = day_num
                                break
                        
                        # Get teacher and subject
                        teacher_name = cells[2].get_text(strip=True) if len(cells) > 2 else ''
                        subject = cells[3].get_text(strip=True) if len(cells) > 3 else ''
                        classroom_name = cells[4].get_text(strip=True) if len(cells) > 4 else None
                        
                        if not teacher_name or not subject:
                            continue
                        
                        # Get or create teacher
                        teacher = crud.get_teacher_by_name(db, teacher_name)
                        if not teacher:
                            short_name = teacher_name[:10]
                            teacher = crud.create_teacher(
                                db,
                                schemas.TeacherCreate(name=teacher_name, short_name=short_name)
                            )
                        
                        # Get or create classroom
                        classroom_id = None
                        if classroom_name and classroom_name != '-':
                            classroom = crud.get_classroom_by_name(db, classroom_name)
                            if not classroom:
                                classroom = crud.create_classroom(db, schemas.ClassroomCreate(name=classroom_name))
                            classroom_id = classroom.id
                        
                        # Create lesson
                        lesson = models.Lesson(
                            teacher_id=teacher.id,
                            class_id=school_class.id,
                            classroom_id=classroom_id,
                            subject=subject,
                            day_of_week=day_of_week,
                            lesson_number=lesson_num
                        )
                        
                        db.add(lesson)
                        count += 1
                        
                    except (ValueError, IndexError):
                        continue
                
                db.commit()
                
            except Exception as e:
                continue
        
        return count
        
    except Exception as e:
        raise Exception(f"Error processing HTML folder: {str(e)}")
