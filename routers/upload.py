from fastapi import APIRouter, UploadFile, File, Depends, HTTPException
from sqlalchemy.orm import Session
import crud, database, parser
from typing import List
import zipfile
import io

router = APIRouter(
    prefix="/upload",
    tags=["upload"],
)

@router.post("/")
async def upload_file(
    file: UploadFile = File(...), 
    db: Session = Depends(database.get_db)
):
    if file.filename.endswith('.html') or file.filename.endswith('.htm'):
        contents = await file.read()
        try:
            count = parser.parse_html_and_save(contents, db)
            return {"message": f"Successfully processed {count} substitutions from HTML"}
        except Exception as e:
            raise HTTPException(status_code=500, detail=str(e))
            
    elif file.filename.endswith('.xml'):
        contents = await file.read()
        try:
            count = parser.parse_plan_xml_and_save(contents, db)
            return {"message": f"Successfully processed {count} lessons from XML"}
        except Exception as e:
            raise HTTPException(status_code=500, detail=str(e))
            
    elif file.filename.endswith('.zip'):
        contents = await file.read()
        try:
            with zipfile.ZipFile(io.BytesIO(contents)) as z:
                html_files = {}
                for filename in z.namelist():
                    if filename.endswith('.html') or filename.endswith('.htm'):
                        # Skip if it's index or frames
                        if 'index' in filename: continue
                        
                        with z.open(filename) as f:
                            html_files[filename] = f.read()
                            
                count = parser.parse_plan_html_folder_and_save(html_files, db)
                return {"message": f"Successfully processed {count} lessons from ZIP archive"}
        except Exception as e:
            raise HTTPException(status_code=500, detail=f"Error processing ZIP: {str(e)}")
    
    elif file.filename.endswith('.pla'):
         raise HTTPException(status_code=400, detail="Format .pla jest binarny i niemożliwy do odczytania bez oprogramowania Optivum. Proszę wyeksportować plan do XML lub HTML (folder) w programie Plan Lekcji.")
         
    else:
        raise HTTPException(status_code=400, detail="Only .html (substitutions), .xml (plan), or .zip (plan HTML folder) files are allowed")
