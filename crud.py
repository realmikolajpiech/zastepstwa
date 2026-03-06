from sqlalchemy.orm import Session, joinedload
import models, schemas
from datetime import date
from sqlalchemy import or_

def get_teacher_by_name(db: Session, name: str):
    return db.query(models.Teacher).filter(models.Teacher.name == name).first()

def create_teacher(db: Session, teacher: schemas.TeacherCreate):
    db_teacher = models.Teacher(name=teacher.name, short_name=teacher.short_name)
    db.add(db_teacher)
    db.commit()
    db.refresh(db_teacher)
    return db_teacher

def get_class_by_name(db: Session, name: str):
    return db.query(models.SchoolClass).filter(models.SchoolClass.name == name).first()

def create_class(db: Session, school_class: schemas.SchoolClassCreate):
    db_class = models.SchoolClass(name=school_class.name)
    db.add(db_class)
    db.commit()
    db.refresh(db_class)
    return db_class

def get_classroom_by_name(db: Session, name: str):
    return db.query(models.Classroom).filter(models.Classroom.name == name).first()

def create_classroom(db: Session, classroom: schemas.ClassroomCreate):
    db_room = models.Classroom(name=classroom.name)
    db.add(db_room)
    db.commit()
    db.refresh(db_room)
    return db_room

def create_substitution(db: Session, substitution: schemas.SubstitutionCreate):
    db_sub = models.Substitution(**substitution.dict())
    db.add(db_sub)
    db.commit()
    db.refresh(db_sub)
    return db_sub

def get_substitutions(db: Session, date_from: date, date_to: date):
    return db.query(models.Substitution).options(
        joinedload(models.Substitution.original_teacher),
        joinedload(models.Substitution.substitute_teacher),
        joinedload(models.Substitution.school_class),
        joinedload(models.Substitution.classroom)
    ).filter(
        models.Substitution.date >= date_from,
        models.Substitution.date <= date_to
    ).all()

def get_lessons_by_class(db: Session, class_id: int, day_of_week: int):
    return db.query(models.Lesson).options(
        joinedload(models.Lesson.teacher),
        joinedload(models.Lesson.school_class),
        joinedload(models.Lesson.classroom)
    ).filter(
        models.Lesson.class_id == class_id,
        models.Lesson.day_of_week == day_of_week
    ).all()

def get_lessons_by_teacher(db: Session, teacher_id: int, day_of_week: int):
    return db.query(models.Lesson).options(
        joinedload(models.Lesson.teacher),
        joinedload(models.Lesson.school_class),
        joinedload(models.Lesson.classroom)
    ).filter(
        models.Lesson.teacher_id == teacher_id,
        models.Lesson.day_of_week == day_of_week
    ).all()
