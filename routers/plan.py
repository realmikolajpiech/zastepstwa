from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session
from typing import List, Optional
from datetime import date
import crud, models, schemas, database

router = APIRouter(
    prefix="/plan",
    tags=["plan"],
)

@router.get("/classes", response_model=List[schemas.SchoolClass])
def read_classes(db: Session = Depends(database.get_db)):
    return db.query(models.SchoolClass).all()

@router.get("/teachers", response_model=List[schemas.Teacher])
def read_teachers(db: Session = Depends(database.get_db)):
    return db.query(models.Teacher).all()

@router.get("/class/{class_id}", response_model=List[schemas.Lesson])
def read_plan_class(
    class_id: int, 
    day: int, # 1-5
    db: Session = Depends(database.get_db)
):
    return crud.get_lessons_by_class(db, class_id, day)

@router.get("/teacher/{teacher_id}", response_model=List[schemas.Lesson])
def read_plan_teacher(
    teacher_id: int, 
    day: int,
    db: Session = Depends(database.get_db)
):
    return crud.get_lessons_by_teacher(db, teacher_id, day)
