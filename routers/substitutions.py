from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session
from typing import List
from datetime import date
import crud, models, schemas, database

router = APIRouter(
    prefix="/substitutions",
    tags=["substitutions"],
    responses={404: {"description": "Not found"}},
)

@router.get("/", response_model=List[schemas.Substitution])
def read_substitutions(
    date_from: date, 
    date_to: date, 
    db: Session = Depends(database.get_db)
):
    subs = crud.get_substitutions(db, date_from=date_from, date_to=date_to)
    return subs
