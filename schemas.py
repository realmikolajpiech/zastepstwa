from typing import List, Optional
from pydantic import BaseModel
from datetime import date

class TeacherBase(BaseModel):
    name: str
    short_name: str

class TeacherCreate(TeacherBase):
    pass

class Teacher(TeacherBase):
    id: int

    class Config:
        from_attributes = True

class SchoolClassBase(BaseModel):
    name: str

class SchoolClassCreate(SchoolClassBase):
    pass

class SchoolClass(SchoolClassBase):
    id: int

    class Config:
        from_attributes = True

class ClassroomBase(BaseModel):
    name: str

class ClassroomCreate(ClassroomBase):
    pass

class Classroom(ClassroomBase):
    id: int

    class Config:
        from_attributes = True

class LessonBase(BaseModel):
    teacher_id: int
    class_id: int
    classroom_id: Optional[int] = None
    subject: str
    day_of_week: int
    lesson_number: int

class LessonCreate(LessonBase):
    pass

class Lesson(LessonBase):
    id: int
    teacher: Teacher
    school_class: SchoolClass
    classroom: Optional[Classroom] = None

    class Config:
        from_attributes = True

class SubstitutionBase(BaseModel):
    date: date
    lesson_number: int
    original_teacher_id: Optional[int] = None
    substitute_teacher_id: Optional[int] = None
    class_id: int
    classroom_id: Optional[int] = None
    subject: str
    cause: str
    note: str

class SubstitutionCreate(SubstitutionBase):
    pass

class Substitution(SubstitutionBase):
    id: int
    original_teacher: Optional[Teacher] = None
    substitute_teacher: Optional[Teacher] = None
    school_class: SchoolClass
    classroom: Optional[Classroom] = None

    class Config:
        from_attributes = True
