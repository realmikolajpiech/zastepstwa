from sqlalchemy import Column, Integer, String, Date, ForeignKey
from sqlalchemy.orm import relationship
from database import Base

class Teacher(Base):
    __tablename__ = "teachers"

    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(100), index=True)
    short_name = Column(String(10), unique=True, index=True)

    lessons = relationship("Lesson", back_populates="teacher")
    substitutions_as_absent = relationship("Substitution", back_populates="original_teacher", foreign_keys="[Substitution.original_teacher_id]")
    substitutions_as_sub = relationship("Substitution", back_populates="substitute_teacher", foreign_keys="[Substitution.substitute_teacher_id]")

class SchoolClass(Base):
    __tablename__ = "classes"

    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(100), unique=True, index=True)

    lessons = relationship("Lesson", back_populates="school_class")
    substitutions = relationship("Substitution", back_populates="school_class")

class Classroom(Base):
    __tablename__ = "classrooms"

    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(50), unique=True)

    lessons = relationship("Lesson", back_populates="classroom")
    substitutions = relationship("Substitution", back_populates="classroom")

class Lesson(Base):
    __tablename__ = "lessons"

    id = Column(Integer, primary_key=True, index=True)
    teacher_id = Column(Integer, ForeignKey("teachers.id"))
    class_id = Column(Integer, ForeignKey("classes.id"))
    classroom_id = Column(Integer, ForeignKey("classrooms.id"), nullable=True)
    subject = Column(String(100))
    day_of_week = Column(Integer) # 1=Monday, 5=Friday
    lesson_number = Column(Integer)

    teacher = relationship("Teacher", back_populates="lessons")
    school_class = relationship("SchoolClass", back_populates="lessons")
    classroom = relationship("Classroom", back_populates="lessons")

class Substitution(Base):
    __tablename__ = "substitutions"

    id = Column(Integer, primary_key=True, index=True)
    date = Column(Date, index=True)
    lesson_number = Column(Integer)
    original_teacher_id = Column(Integer, ForeignKey("teachers.id"), nullable=True)
    substitute_teacher_id = Column(Integer, ForeignKey("teachers.id"), nullable=True)
    class_id = Column(Integer, ForeignKey("classes.id"))
    classroom_id = Column(Integer, ForeignKey("classrooms.id"), nullable=True)
    subject = Column(String(100))
    cause = Column(String(255))
    note = Column(String(255))

    original_teacher = relationship("Teacher", foreign_keys=[original_teacher_id], back_populates="substitutions_as_absent")
    substitute_teacher = relationship("Teacher", foreign_keys=[substitute_teacher_id], back_populates="substitutions_as_sub")
    school_class = relationship("SchoolClass", back_populates="substitutions")
    classroom = relationship("Classroom", back_populates="substitutions")
